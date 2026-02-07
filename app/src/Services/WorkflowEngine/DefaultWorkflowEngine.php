<?php

declare(strict_types=1);

namespace App\Services\WorkflowEngine;

use App\Model\Entity\WorkflowInstance;
use App\Model\Entity\WorkflowApproval;
use App\Model\Entity\WorkflowState;
use App\Model\Entity\WorkflowTransition;
use App\Services\ServiceResult;
use App\Services\WorkflowEngine\Actions\ActionInterface;
use App\Services\WorkflowEngine\Conditions\ConditionInterface;
use Cake\Log\Log;
use Cake\ORM\TableRegistry;

/**
 * Core orchestration service for the KMP workflow system.
 *
 * Manages workflow lifecycle: starting instances, executing transitions,
 * evaluating conditions, running actions, and processing scheduled transitions.
 *
 * @see \App\Services\WorkflowEngine\WorkflowEngineInterface
 */
class DefaultWorkflowEngine implements WorkflowEngineInterface
{
    protected RuleEvaluatorInterface $ruleEvaluator;
    protected ActionExecutorInterface $actionExecutor;
    protected VisibilityEvaluatorInterface $visibilityEvaluator;

    public function __construct(
        RuleEvaluatorInterface $ruleEvaluator,
        ActionExecutorInterface $actionExecutor,
        VisibilityEvaluatorInterface $visibilityEvaluator,
    ) {
        $this->ruleEvaluator = $ruleEvaluator;
        $this->actionExecutor = $actionExecutor;
        $this->visibilityEvaluator = $visibilityEvaluator;
    }

    /**
     * Start a workflow for an entity.
     * Finds the workflow definition by slug, creates an instance at the initial state.
     */
    public function startWorkflow(string $workflowSlug, string $entityType, int $entityId, ?int $initiatedBy = null): ServiceResult
    {
        $definitionsTable = TableRegistry::getTableLocator()->get('WorkflowDefinitions');
        $statesTable = TableRegistry::getTableLocator()->get('WorkflowStates');
        $instancesTable = TableRegistry::getTableLocator()->get('WorkflowInstances');

        $definition = $definitionsTable->findBySlug($workflowSlug);
        if (!$definition) {
            return new ServiceResult(false, "Workflow definition '{$workflowSlug}' not found or not active.");
        }

        // Check if entity already has an active workflow instance
        $existing = $instancesTable->find()
            ->where([
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'completed_at IS' => null,
            ])
            ->first();
        if ($existing) {
            return new ServiceResult(false, "Entity already has an active workflow instance.", $existing);
        }

        $initialState = $statesTable->findInitialState($definition->id);
        if (!$initialState) {
            return new ServiceResult(false, "Workflow '{$workflowSlug}' has no initial state defined.");
        }

        $instance = $instancesTable->newEntity([
            'workflow_definition_id' => $definition->id,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'current_state_id' => $initialState->id,
            'previous_state_id' => null,
            'context' => json_encode([]),
            'started_at' => new \DateTime(),
            'completed_at' => null,
            'created_by' => $initiatedBy,
        ]);

        if (!$instancesTable->save($instance)) {
            return new ServiceResult(false, "Failed to create workflow instance.", $instance->getErrors());
        }

        $this->logTransition($instance->id, null, $initialState->id, null, $initiatedBy, 'manual', 'Workflow started');

        // Execute on_enter_actions for initial state
        $context = $this->buildContext($instance, $initialState, null, $initiatedBy);
        $enterActions = $initialState->decoded_on_enter_actions ?? [];
        if (!empty($enterActions)) {
            $this->actionExecutor->execute($enterActions, $context);
        }

        return new ServiceResult(true, null, $instance);
    }

    /**
     * Attempt a named transition on a workflow instance.
     */
    public function transition(int $instanceId, string $transitionSlug, ?int $triggeredBy = null, array $context = []): ServiceResult
    {
        $instancesTable = TableRegistry::getTableLocator()->get('WorkflowInstances');
        $transitionsTable = TableRegistry::getTableLocator()->get('WorkflowTransitions');
        $statesTable = TableRegistry::getTableLocator()->get('WorkflowStates');

        $instance = $instancesTable->get($instanceId, contain: ['CurrentState', 'WorkflowDefinitions']);
        if (!$instance) {
            return new ServiceResult(false, "Workflow instance not found.");
        }
        if ($instance->completed_at !== null) {
            return new ServiceResult(false, "Workflow instance is already completed.");
        }

        $transition = $transitionsTable->find()
            ->where([
                'WorkflowTransitions.workflow_definition_id' => $instance->workflow_definition_id,
                'WorkflowTransitions.from_state_id' => $instance->current_state_id,
                'WorkflowTransitions.slug' => $transitionSlug,
            ])
            ->contain(['ToState'])
            ->first();

        if (!$transition) {
            return new ServiceResult(false, "Transition '{$transitionSlug}' not available from current state.");
        }

        // Evaluate conditions
        $evalContext = $this->buildContext($instance, $instance->current_state, $transition, $triggeredBy);
        $evalContext = array_merge($evalContext, $context);

        $conditions = $transition->decoded_conditions;
        if (!empty($conditions)) {
            if (!$this->ruleEvaluator->evaluate($conditions, $evalContext)) {
                return new ServiceResult(false, "Transition conditions not met.");
            }
        }

        $connection = $instancesTable->getConnection();

        return $connection->transactional(function () use ($instance, $transition, $statesTable, $instancesTable, $triggeredBy, $evalContext) {
            $fromState = $instance->current_state;
            $toState = $transition->to_state;

            // Execute on_exit_actions for current state
            $exitActions = $fromState->decoded_on_exit_actions ?? [];
            if (!empty($exitActions)) {
                $exitResult = $this->actionExecutor->execute($exitActions, $evalContext);
                if (!$exitResult->success) {
                    return new ServiceResult(false, "Exit actions failed: {$exitResult->reason}");
                }
            }

            // Execute transition actions
            $transitionActions = $transition->decoded_actions ?? [];
            $actionContext = $evalContext;
            $actionContext['from_state'] = $fromState->toArray();
            $actionContext['to_state'] = $toState->toArray();
            $actionContext['transition'] = $transition->toArray();

            $contextUpdates = [];
            if (!empty($transitionActions)) {
                $actionResult = $this->actionExecutor->execute($transitionActions, $actionContext);
                if (!$actionResult->success) {
                    return new ServiceResult(false, "Transition actions failed: {$actionResult->reason}");
                }
                // Collect context updates from actions
                if (is_array($actionResult->data)) {
                    foreach ($actionResult->data as $result) {
                        if (isset($result['data']['context_updates'])) {
                            $contextUpdates = array_merge($contextUpdates, $result['data']['context_updates']);
                        }
                    }
                }
            }

            // Update instance state
            $instanceContext = json_decode($instance->context ?? '{}', true) ?: [];
            $instanceContext = array_merge($instanceContext, $contextUpdates);

            $instance->previous_state_id = $instance->current_state_id;
            $instance->current_state_id = $toState->id;
            $instance->context = json_encode($instanceContext);

            // Mark completed if entering a terminal state
            if ($toState->state_type === WorkflowState::TYPE_TERMINAL) {
                $instance->completed_at = new \DateTime();
            }

            if (!$instancesTable->save($instance)) {
                return new ServiceResult(false, "Failed to update workflow instance.", $instance->getErrors());
            }

            $this->logTransition(
                $instance->id,
                $fromState->id,
                $toState->id,
                $transition->id,
                $triggeredBy,
                $transition->trigger_type,
                null,
                $instanceContext,
            );

            // Execute on_enter_actions for new state
            $enterActions = $toState->decoded_on_enter_actions ?? [];
            if (!empty($enterActions)) {
                $enterContext = $actionContext;
                $enterContext['instance'] = $instance->toArray();
                $this->actionExecutor->execute($enterActions, $enterContext);
            }

            return new ServiceResult(true, null, [
                'instance' => $instance,
                'from_state' => $fromState,
                'to_state' => $toState,
                'transition' => $transition,
            ]);
        });
    }

    /**
     * Get available transitions for current user on a workflow instance.
     */
    public function getAvailableTransitions(int $instanceId, ?int $userId = null): ServiceResult
    {
        $instancesTable = TableRegistry::getTableLocator()->get('WorkflowInstances');
        $transitionsTable = TableRegistry::getTableLocator()->get('WorkflowTransitions');

        $instance = $instancesTable->get($instanceId, contain: ['CurrentState']);
        if ($instance->completed_at !== null) {
            return new ServiceResult(true, null, []);
        }

        $transitions = $transitionsTable->find()
            ->where([
                'from_state_id' => $instance->current_state_id,
                'trigger_type' => WorkflowTransition::TRIGGER_MANUAL,
            ])
            ->contain(['ToState'])
            ->orderBy(['priority' => 'ASC'])
            ->all()
            ->toArray();

        if ($userId === null) {
            return new ServiceResult(true, null, $transitions);
        }

        // Filter by conditions
        $context = $this->buildContext($instance, $instance->current_state, null, $userId);
        $available = [];
        foreach ($transitions as $transition) {
            $conditions = $transition->decoded_conditions;
            if (empty($conditions) || $this->ruleEvaluator->evaluate($conditions, $context)) {
                $available[] = $transition;
            }
        }

        return new ServiceResult(true, null, $available);
    }

    /**
     * Get workflow instance for an entity.
     */
    public function getInstanceForEntity(string $entityType, int $entityId): ServiceResult
    {
        $instancesTable = TableRegistry::getTableLocator()->get('WorkflowInstances');

        $instance = $instancesTable->find()
            ->where([
                'WorkflowInstances.entity_type' => $entityType,
                'WorkflowInstances.entity_id' => $entityId,
            ])
            ->contain(['CurrentState', 'PreviousState', 'WorkflowDefinitions'])
            ->orderByDesc('started_at')
            ->first();

        if (!$instance) {
            return new ServiceResult(false, "No workflow instance found for {$entityType}#{$entityId}.");
        }

        return new ServiceResult(true, null, $instance);
    }

    /**
     * Get current state of a workflow instance.
     */
    public function getCurrentState(int $instanceId): ServiceResult
    {
        $instancesTable = TableRegistry::getTableLocator()->get('WorkflowInstances');

        $instance = $instancesTable->get($instanceId, contain: [
            'CurrentState' => ['WorkflowVisibilityRules', 'WorkflowApprovalGates'],
        ]);

        return new ServiceResult(true, null, $instance->current_state);
    }

    /**
     * Register a custom condition type (delegates to RuleEvaluator).
     */
    public function registerConditionType(string $name, ConditionInterface $condition): void
    {
        $this->ruleEvaluator->registerConditionType($name, $condition);
    }

    /**
     * Register a custom action type (delegates to ActionExecutor).
     */
    public function registerActionType(string $name, ActionInterface $action): void
    {
        $this->actionExecutor->registerActionType($name, $action);
    }

    /**
     * Process scheduled/automatic transitions and approval gate timeouts.
     */
    public function processScheduledTransitions(): ServiceResult
    {
        $instancesTable = TableRegistry::getTableLocator()->get('WorkflowInstances');
        $transitionsTable = TableRegistry::getTableLocator()->get('WorkflowTransitions');

        $activeInstances = $instancesTable->find()
            ->where(['completed_at IS' => null])
            ->contain(['CurrentState'])
            ->all();

        $processed = 0;
        $errors = [];

        foreach ($activeInstances as $instance) {
            $autoTransitions = $transitionsTable->find()
                ->where([
                    'from_state_id' => $instance->current_state_id,
                    'trigger_type IN' => [
                        WorkflowTransition::TRIGGER_AUTOMATIC,
                        WorkflowTransition::TRIGGER_SCHEDULED,
                    ],
                ])
                ->contain(['ToState'])
                ->orderBy(['priority' => 'ASC'])
                ->all();

            foreach ($autoTransitions as $transition) {
                $context = $this->buildContext($instance, $instance->current_state, $transition, null);
                $conditions = $transition->decoded_conditions;

                if (empty($conditions) || $this->ruleEvaluator->evaluate($conditions, $context)) {
                    $result = $this->transition($instance->id, $transition->slug, null, ['trigger' => 'scheduled']);
                    if ($result->success) {
                        $processed++;
                        break; // Only one transition per instance per run
                    } else {
                        $errors[] = "Instance {$instance->id}: {$result->reason}";
                    }
                }
            }
        }

        $this->processApprovalTimeouts();

        return new ServiceResult(true, "Processed {$processed} scheduled transitions.", [
            'processed' => $processed,
            'errors' => $errors,
        ]);
    }

    /**
     * Build context array for condition evaluation and action execution.
     */
    protected function buildContext(
        WorkflowInstance $instance,
        ?WorkflowState $currentState,
        ?WorkflowTransition $transition,
        ?int $userId,
    ): array {
        $context = [
            'user_id' => $userId,
            'user_permissions' => $userId ? $this->getUserPermissions($userId) : [],
            'user_roles' => $userId ? $this->getUserRoles($userId) : [],
            'entity_type' => $instance->entity_type,
            'entity_id' => $instance->entity_id,
            'instance' => $instance->toArray(),
            'instance_context' => json_decode($instance->context ?? '{}', true) ?: [],
            'state' => $currentState ? $currentState->toArray() : [],
            'state_entered_at' => $instance->modified ?? $instance->started_at,
        ];

        $entity = $this->loadEntity($instance->entity_type, $instance->entity_id);
        if ($entity) {
            $context['entity'] = $entity->toArray();
            $context['entity_object'] = $entity;
            $context['entity_table'] = TableRegistry::getTableLocator()->get($instance->entity_type);
        }

        // Load approval gate status for approval-type states
        if ($currentState && $currentState->state_type === WorkflowState::TYPE_APPROVAL) {
            $context['approval_gates'] = $this->getApprovalGateStatus($currentState->id, $instance->id, $context);
        }

        if ($transition) {
            $context['transition'] = $transition->toArray();
        }

        return $context;
    }

    /**
     * Log a workflow transition.
     */
    protected function logTransition(
        int $instanceId,
        ?int $fromStateId,
        int $toStateId,
        ?int $transitionId,
        ?int $triggeredBy,
        string $triggerType,
        ?string $notes = null,
        ?array $contextSnapshot = null,
    ): void {
        $logsTable = TableRegistry::getTableLocator()->get('WorkflowTransitionLogs');
        $log = $logsTable->newEntity([
            'workflow_instance_id' => $instanceId,
            'from_state_id' => $fromStateId,
            'to_state_id' => $toStateId,
            'transition_id' => $transitionId,
            'triggered_by' => $triggeredBy,
            'trigger_type' => $triggerType,
            'context_snapshot' => $contextSnapshot ? json_encode($contextSnapshot) : null,
            'notes' => $notes,
            'created' => new \DateTime(),
        ]);
        $logsTable->save($log);
    }

    /**
     * Load an entity by type and ID.
     */
    protected function loadEntity(string $entityType, int $entityId): ?\Cake\Datasource\EntityInterface
    {
        try {
            $table = TableRegistry::getTableLocator()->get($entityType);

            return $table->get($entityId);
        } catch (\Exception $e) {
            Log::warning("WorkflowEngine: Could not load entity {$entityType}#{$entityId}: {$e->getMessage()}");

            return null;
        }
    }

    /**
     * Get user permissions from KMP's permission system.
     */
    protected function getUserPermissions(int $userId): array
    {
        try {
            $memberPermissionsTable = TableRegistry::getTableLocator()->get('MemberPermissions');
            $permissions = $memberPermissionsTable->find()
                ->where(['member_id' => $userId, 'is_allowed' => true])
                ->contain(['Permissions'])
                ->all();

            return array_map(fn($mp) => $mp->permission->name ?? '', $permissions->toArray());
        } catch (\Exception $e) {
            Log::warning("WorkflowEngine: Could not load permissions for user {$userId}: {$e->getMessage()}");

            return [];
        }
    }

    /**
     * Get user roles.
     */
    protected function getUserRoles(int $userId): array
    {
        try {
            $memberRolesTable = TableRegistry::getTableLocator()->get('MemberRoles');
            $roles = $memberRolesTable->find()
                ->where(['member_id' => $userId])
                ->contain(['Roles'])
                ->all();

            return array_map(fn($mr) => $mr->role->name ?? '', $roles->toArray());
        } catch (\Exception $e) {
            Log::warning("WorkflowEngine: Could not load roles for user {$userId}: {$e->getMessage()}");

            return [];
        }
    }

    /**
     * Resolve the required approval count for a gate, supporting dynamic sources.
     */
    protected function resolveThreshold(\App\Model\Entity\WorkflowApprovalGate $gate, array $context): int
    {
        $config = $gate->decoded_threshold_config;

        return match ($config['type'] ?? 'fixed') {
            'app_setting' => (int)\App\KMP\StaticHelpers::getAppSetting(
                $config['key'] ?? '',
                (string)($config['default'] ?? $gate->required_count),
            ),
            'entity_field' => (int)(($context['entity'] ?? [])[$config['field'] ?? ''] ?? ($config['default'] ?? $gate->required_count)),
            default => (int)($config['value'] ?? $gate->required_count),
        };
    }

    /**
     * Get approval gate status for a state, with dynamic threshold resolution.
     */
    protected function getApprovalGateStatus(int $stateId, int $instanceId, array $context = []): array
    {
        $gatesTable = TableRegistry::getTableLocator()->get('WorkflowApprovalGates');
        $approvalsTable = TableRegistry::getTableLocator()->get('WorkflowApprovals');

        $gates = $gatesTable->find()
            ->where(['workflow_state_id' => $stateId])
            ->all();

        $status = [];
        foreach ($gates as $gate) {
            $resolvedCount = $this->resolveThreshold($gate, $context);

            // Count unique approvers only
            $approvals = $approvalsTable->find()
                ->where([
                    'workflow_instance_id' => $instanceId,
                    'approval_gate_id' => $gate->id,
                    'decision' => WorkflowApproval::DECISION_APPROVED,
                ])
                ->select(['approver_id'])
                ->distinct(['approver_id'])
                ->count();

            $denials = $approvalsTable->find()
                ->where([
                    'workflow_instance_id' => $instanceId,
                    'approval_gate_id' => $gate->id,
                    'decision' => WorkflowApproval::DECISION_DENIED,
                ])
                ->select(['approver_id'])
                ->distinct(['approver_id'])
                ->count();

            $isMet = match ($gate->approval_type) {
                'threshold' => $approvals >= $resolvedCount,
                'unanimous' => $approvals >= $resolvedCount && $denials === 0,
                'any_one' => $approvals >= 1,
                'chain' => $approvals >= $resolvedCount,
                default => false,
            };

            $isDenied = match ($gate->approval_type) {
                'unanimous' => $denials > 0,
                'threshold' => false, // TODO: check reachability if we know total approver pool
                default => false,
            };

            $status[$gate->id] = [
                'gate' => $gate,
                'approved_count' => $approvals,
                'denied_count' => $denials,
                'required_count' => $resolvedCount,
                'is_met' => $isMet,
                'is_denied' => $isDenied,
            ];
        }

        return $status;
    }

    /**
     * Record an approval decision, enforcing unique approvers.
     * Auto-fires satisfaction/denial transitions when configured.
     */
    public function recordApproval(int $instanceId, int $gateId, int $approverId, string $decision, ?string $notes = null): ServiceResult
    {
        if (!in_array($decision, WorkflowApproval::VALID_DECISIONS, true)) {
            return new ServiceResult(false, "Invalid decision: {$decision}");
        }

        $approvalsTable = TableRegistry::getTableLocator()->get('WorkflowApprovals');
        $gatesTable = TableRegistry::getTableLocator()->get('WorkflowApprovalGates');
        $instancesTable = TableRegistry::getTableLocator()->get('WorkflowInstances');

        $instance = $instancesTable->get($instanceId);
        $gate = $gatesTable->get($gateId);

        // Check for existing approval from this user on this gate+instance
        $existing = $approvalsTable->find()
            ->where([
                'workflow_instance_id' => $instanceId,
                'approval_gate_id' => $gateId,
                'approver_id' => $approverId,
                'decision IS NOT' => null,
            ])
            ->first();

        if ($existing) {
            return new ServiceResult(false, 'This approver has already recorded a decision for this gate.');
        }

        // Check for a pending token-based approval row to update
        $pending = $approvalsTable->find()
            ->where([
                'workflow_instance_id' => $instanceId,
                'approval_gate_id' => $gateId,
                'approver_id' => $approverId,
                'decision IS' => null,
            ])
            ->first();

        if ($pending) {
            $pending->decision = $decision;
            $pending->notes = $notes;
            $pending->responded_at = new \DateTime();
            $approvalsTable->save($pending);
        } else {
            $approval = $approvalsTable->newEntity([
                'workflow_instance_id' => $instanceId,
                'approval_gate_id' => $gateId,
                'approver_id' => $approverId,
                'decision' => $decision,
                'notes' => $notes,
                'requested_at' => new \DateTime(),
                'responded_at' => new \DateTime(),
            ]);
            if (!$approvalsTable->save($approval)) {
                return new ServiceResult(false, 'Failed to save approval record.');
            }
        }

        // Re-evaluate gate status
        $context = $this->buildContext($instance, null, null, $approverId);
        $gateStatus = $this->getApprovalGateStatus($gate->workflow_state_id, $instanceId, $context);
        $thisGateStatus = $gateStatus[$gateId] ?? null;

        // Auto-transition on satisfaction
        if ($thisGateStatus && $thisGateStatus['is_met'] && $gate->on_satisfied_transition_id) {
            $transitionsTable = TableRegistry::getTableLocator()->get('WorkflowTransitions');
            try {
                $satisfiedTransition = $transitionsTable->get($gate->on_satisfied_transition_id);
                $this->transition($instanceId, $satisfiedTransition->slug, null, ['trigger' => 'approval_gate_satisfied']);
            } catch (\Exception $e) {
                Log::warning("Auto-transition on satisfaction failed for gate {$gateId}: " . $e->getMessage());
            }
        }

        // Auto-transition on denial
        if ($thisGateStatus && $thisGateStatus['is_denied'] && $gate->on_denied_transition_id) {
            $transitionsTable = TableRegistry::getTableLocator()->get('WorkflowTransitions');
            try {
                $deniedTransition = $transitionsTable->get($gate->on_denied_transition_id);
                $this->transition($instanceId, $deniedTransition->slug, null, ['trigger' => 'approval_gate_denied']);
            } catch (\Exception $e) {
                Log::warning("Auto-transition on denial failed for gate {$gateId}: " . $e->getMessage());
            }
        }

        return new ServiceResult(true, null, ['gate_status' => $thisGateStatus]);
    }

    /**
     * Generate a secure approval token for a specific approver.
     */
    public function generateApprovalToken(int $instanceId, int $gateId, int $approverId, ?int $approvalOrder = null): ServiceResult
    {
        $approvalsTable = TableRegistry::getTableLocator()->get('WorkflowApprovals');

        // Check for existing pending token
        $existing = $approvalsTable->find()
            ->where([
                'workflow_instance_id' => $instanceId,
                'approval_gate_id' => $gateId,
                'approver_id' => $approverId,
            ])
            ->first();

        if ($existing && $existing->decision !== null) {
            return new ServiceResult(false, 'Approver already has a recorded decision.');
        }

        if ($existing && $existing->token) {
            return new ServiceResult(true, null, ['token' => $existing->token]);
        }

        $token = bin2hex(random_bytes(32));

        $approval = $approvalsTable->newEntity([
            'workflow_instance_id' => $instanceId,
            'approval_gate_id' => $gateId,
            'approver_id' => $approverId,
            'token' => $token,
            'requested_at' => new \DateTime(),
            'approval_order' => $approvalOrder,
        ]);

        if (!$approvalsTable->save($approval)) {
            return new ServiceResult(false, 'Failed to generate approval token.');
        }

        return new ServiceResult(true, null, ['token' => $token, 'approval_id' => $approval->id]);
    }

    /**
     * Resolve an approval by its secure token.
     */
    public function resolveApprovalByToken(string $token, string $decision, ?string $notes = null): ServiceResult
    {
        if (!in_array($decision, WorkflowApproval::VALID_DECISIONS, true)) {
            return new ServiceResult(false, "Invalid decision: {$decision}");
        }

        $approvalsTable = TableRegistry::getTableLocator()->get('WorkflowApprovals');

        $approval = $approvalsTable->find()
            ->where(['token' => $token])
            ->first();

        if (!$approval) {
            return new ServiceResult(false, 'Invalid approval token.');
        }

        if ($approval->responded_at !== null) {
            return new ServiceResult(false, 'This approval has already been responded to.');
        }

        // Use recordApproval for the actual logic (uniqueness, auto-transition)
        return $this->recordApproval(
            $approval->workflow_instance_id,
            $approval->approval_gate_id,
            $approval->approver_id,
            $decision,
            $notes,
        );
    }

    /**
     * Delegate an approval to another user.
     */
    public function delegateApproval(int $approvalId, int $delegateId): ServiceResult
    {
        $approvalsTable = TableRegistry::getTableLocator()->get('WorkflowApprovals');
        $gatesTable = TableRegistry::getTableLocator()->get('WorkflowApprovalGates');

        $original = $approvalsTable->get($approvalId);

        if ($original->responded_at !== null) {
            return new ServiceResult(false, 'Cannot delegate an already-responded approval.');
        }

        $gate = $gatesTable->get($original->approval_gate_id);
        if (!$gate->allow_delegation) {
            return new ServiceResult(false, 'This approval gate does not allow delegation.');
        }

        // Mark original as abstained (delegated)
        $original->decision = WorkflowApproval::DECISION_ABSTAINED;
        $original->notes = "Delegated to member #{$delegateId}";
        $original->responded_at = new \DateTime();
        $approvalsTable->save($original);

        // Generate a new token for the delegate
        $result = $this->generateApprovalToken(
            $original->workflow_instance_id,
            $original->approval_gate_id,
            $delegateId,
            $original->approval_order,
        );

        if (!$result->success) {
            return $result;
        }

        // Link the delegation
        $delegateApproval = $approvalsTable->find()
            ->where([
                'workflow_instance_id' => $original->workflow_instance_id,
                'approval_gate_id' => $original->approval_gate_id,
                'approver_id' => $delegateId,
            ])
            ->first();

        if ($delegateApproval) {
            $delegateApproval->delegated_from_id = $approvalId;
            $approvalsTable->save($delegateApproval);
        }

        return new ServiceResult(true, null, [
            'token' => $result->data['token'],
            'delegated_from' => $approvalId,
        ]);
    }

    /**
     * Process approval gate timeouts.
     */
    protected function processApprovalTimeouts(): void
    {
        $gatesTable = TableRegistry::getTableLocator()->get('WorkflowApprovalGates');
        $instancesTable = TableRegistry::getTableLocator()->get('WorkflowInstances');

        $gatesWithTimeout = $gatesTable->find()
            ->where([
                'timeout_hours IS NOT' => null,
                'timeout_transition_id IS NOT' => null,
            ])
            ->contain(['WorkflowStates'])
            ->all();

        foreach ($gatesWithTimeout as $gate) {
            $instances = $instancesTable->find()
                ->where([
                    'current_state_id' => $gate->workflow_state_id,
                    'completed_at IS' => null,
                ])
                ->all();

            foreach ($instances as $instance) {
                $stateEnteredAt = $instance->modified ?? $instance->started_at;
                $timeoutAt = clone $stateEnteredAt;
                $timeoutAt->modify("+{$gate->timeout_hours} hours");

                if (new \DateTime() > $timeoutAt) {
                    $transitionsTable = TableRegistry::getTableLocator()->get('WorkflowTransitions');
                    $timeoutTransition = $transitionsTable->get($gate->timeout_transition_id);
                    if ($timeoutTransition) {
                        $this->transition($instance->id, $timeoutTransition->slug, null, ['trigger' => 'timeout']);
                    }
                }
            }
        }
    }
}
