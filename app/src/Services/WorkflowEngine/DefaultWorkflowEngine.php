<?php
declare(strict_types=1);

namespace App\Services\WorkflowEngine;

use App\Services\ServiceResult;
use Cake\Log\Log;
use Cake\ORM\TableRegistry;
use Symfony\Component\Workflow\Event\GuardEvent;
use Symfony\Component\Workflow\Exception\NotEnabledTransitionException;

/**
 * Core orchestration service for the KMP workflow system.
 *
 * Wraps symfony/workflow with KMP-specific DB persistence, audit logging,
 * and plugin extensibility for conditions and actions.
 */
class DefaultWorkflowEngine implements WorkflowEngineInterface
{
    private WorkflowBridge $bridge;

    /** @var array<string, callable> Registered condition type evaluators */
    private array $conditionTypes = [];

    /** @var array<string, callable> Registered action type executors */
    private array $actionTypes = [];

    public function __construct(WorkflowBridge $bridge)
    {
        $this->bridge = $bridge;
    }

    public function startWorkflow(string $workflowSlug, string $entityType, int $entityId, ?int $initiatedBy = null): ServiceResult
    {
        $definitionsTable = TableRegistry::getTableLocator()->get('WorkflowDefinitions');
        $statesTable = TableRegistry::getTableLocator()->get('WorkflowStates');
        $instancesTable = TableRegistry::getTableLocator()->get('WorkflowInstances');

        // Find active definition
        $definition = $definitionsTable->findBySlug($workflowSlug);
        if (!$definition) {
            return new ServiceResult(false, "Workflow definition '{$workflowSlug}' not found or not active.");
        }

        // Check for existing active instance
        $existing = $instancesTable->findActiveForEntity($entityType, $entityId);
        if ($existing) {
            return new ServiceResult(false, "Entity already has an active workflow instance.", $existing);
        }

        // Find initial state
        $initialState = $statesTable->findInitialState($definition->id);
        if (!$initialState) {
            return new ServiceResult(false, "Workflow '{$workflowSlug}' has no initial state defined.");
        }

        // Create instance
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

        // Log the initial state entry
        $this->logTransition($instance->id, null, $initialState->id, null, $initiatedBy, 'manual', 'Workflow started');

        return new ServiceResult(true, null, $instance);
    }

    public function transition(int $instanceId, string $transitionSlug, ?int $triggeredBy = null, array $context = []): ServiceResult
    {
        $instancesTable = TableRegistry::getTableLocator()->get('WorkflowInstances');

        try {
            $instance = $instancesTable->get($instanceId, contain: [
                'WorkflowDefinitions',
                'CurrentState',
            ]);
        } catch (\Exception $e) {
            return new ServiceResult(false, "Workflow instance {$instanceId} not found.");
        }

        if ($instance->completed_at !== null) {
            return new ServiceResult(false, "Workflow instance is already completed.");
        }

        // Build the symfony/workflow object
        $workflow = $this->bridge->buildFromDefinition($instance->workflow_definition_id);

        // Create a subject object for symfony/workflow
        $subject = $this->buildSubject($instance);

        // Register guards for condition evaluation
        $this->registerGuards($instance->workflow_definition_id, $workflow);

        // Try to apply the transition
        try {
            if (!$workflow->can($subject, $transitionSlug)) {
                return new ServiceResult(false, "Transition '{$transitionSlug}' is not available from current state.");
            }

            $previousStateId = $instance->current_state_id;
            $workflow->apply($subject, $transitionSlug, array_merge($context, [
                'triggered_by' => $triggeredBy,
            ]));

            // Resolve new state ID
            $newStateSlug = $subject->currentState;
            $statesTable = TableRegistry::getTableLocator()->get('WorkflowStates');
            $newState = $statesTable->find()
                ->where([
                    'WorkflowStates.workflow_definition_id' => $instance->workflow_definition_id,
                    'WorkflowStates.slug' => $newStateSlug,
                ])
                ->first();

            if (!$newState) {
                return new ServiceResult(false, "New state '{$newStateSlug}' not found in definition.");
            }

            // Update instance
            $instance->previous_state_id = $previousStateId;
            $instance->current_state_id = $newState->id;

            // Mark completed if final state
            if ($newState->state_type === 'final') {
                $instance->completed_at = new \DateTime();
            }

            // Update context
            $existingContext = json_decode($instance->context ?? '{}', true) ?? [];
            $instance->context = json_encode(array_merge($existingContext, $context));

            $instancesTable->save($instance);

            // Log transition
            $transitionsTable = TableRegistry::getTableLocator()->get('WorkflowTransitions');
            $transitionRecord = $transitionsTable->find()
                ->where([
                    'WorkflowTransitions.workflow_definition_id' => $instance->workflow_definition_id,
                    'WorkflowTransitions.slug' => $transitionSlug,
                ])
                ->first();

            $this->logTransition(
                $instance->id,
                $previousStateId,
                $newState->id,
                $transitionRecord?->id,
                $triggeredBy,
                'manual',
                $context['notes'] ?? null
            );

            return new ServiceResult(true, null, [
                'instance' => $instance,
                'from_state' => $previousStateId,
                'to_state' => $newState->id,
                'transition' => $transitionSlug,
            ]);
        } catch (NotEnabledTransitionException $e) {
            $blockers = [];
            foreach ($e->getTransitionBlockerList() as $blocker) {
                $blockers[] = $blocker->getMessage();
            }
            return new ServiceResult(false, "Transition blocked: " . implode('; ', $blockers), $blockers);
        } catch (\Exception $e) {
            Log::error("WorkflowEngine transition error: " . $e->getMessage());
            return new ServiceResult(false, "Transition failed: " . $e->getMessage());
        }
    }

    public function getAvailableTransitions(int $instanceId, ?int $userId = null): ServiceResult
    {
        $instancesTable = TableRegistry::getTableLocator()->get('WorkflowInstances');

        try {
            $instance = $instancesTable->get($instanceId, contain: ['CurrentState']);
        } catch (\Exception $e) {
            return new ServiceResult(false, "Workflow instance {$instanceId} not found.");
        }

        if ($instance->completed_at !== null) {
            return new ServiceResult(true, null, []);
        }

        $workflow = $this->bridge->buildFromDefinition($instance->workflow_definition_id);
        $subject = $this->buildSubject($instance);

        $this->registerGuards($instance->workflow_definition_id, $workflow);

        $enabled = $workflow->getEnabledTransitions($subject);

        $transitions = [];
        foreach ($enabled as $t) {
            $meta = $workflow->getDefinition()->getMetadataStore()->getTransitionMetadata($t);
            $transitions[] = [
                'slug' => $t->getName(),
                'label' => $meta['label'] ?? $t->getName(),
                'froms' => $t->getFroms(),
                'tos' => $t->getTos(),
            ];
        }

        return new ServiceResult(true, null, $transitions);
    }

    public function getInstanceForEntity(string $entityType, int $entityId): ServiceResult
    {
        $instancesTable = TableRegistry::getTableLocator()->get('WorkflowInstances');
        $instance = $instancesTable->findActiveForEntity($entityType, $entityId);

        if (!$instance) {
            return new ServiceResult(false, "No active workflow instance for {$entityType}:{$entityId}.");
        }

        return new ServiceResult(true, null, $instance);
    }

    public function getCurrentState(int $instanceId): ServiceResult
    {
        $instancesTable = TableRegistry::getTableLocator()->get('WorkflowInstances');

        try {
            $instance = $instancesTable->get($instanceId, contain: ['CurrentState']);
        } catch (\Exception $e) {
            return new ServiceResult(false, "Workflow instance {$instanceId} not found.");
        }

        return new ServiceResult(true, null, $instance->current_state);
    }

    public function registerConditionType(string $name, callable $evaluator): void
    {
        $this->conditionTypes[$name] = $evaluator;
    }

    public function registerActionType(string $name, callable $executor): void
    {
        $this->actionTypes[$name] = $executor;
    }

    public function processScheduledTransitions(): ServiceResult
    {
        $instancesTable = TableRegistry::getTableLocator()->get('WorkflowInstances');
        $transitionsTable = TableRegistry::getTableLocator()->get('WorkflowTransitions');

        // Find all active instances
        $instances = $instancesTable->find()
            ->where(['completed_at IS' => null])
            ->all();

        $processed = 0;
        foreach ($instances as $instance) {
            $autoTransitions = $transitionsTable->find()
                ->where([
                    'WorkflowTransitions.workflow_definition_id' => $instance->workflow_definition_id,
                    'WorkflowTransitions.is_automatic' => true,
                ])
                ->orderBy(['WorkflowTransitions.priority' => 'ASC'])
                ->all();

            foreach ($autoTransitions as $t) {
                $result = $this->transition($instance->id, $t->slug, null, ['trigger_type' => 'automatic']);
                if ($result->success) {
                    $processed++;
                    break; // Only one auto-transition per instance per run
                }
            }
        }

        return new ServiceResult(true, null, ['processed' => $processed]);
    }

    /**
     * Build a subject object for symfony/workflow from a workflow instance.
     */
    private function buildSubject(object $instance): \stdClass
    {
        $subject = new \stdClass();
        $subject->currentState = $instance->current_state?->slug ?? null;
        $subject->workflowInstanceId = $instance->id;
        $subject->entityType = $instance->entity_type;
        $subject->entityId = $instance->entity_id;
        $subject->context = json_decode($instance->context ?? '{}', true) ?? [];

        return $subject;
    }

    /**
     * Register guard event listeners that evaluate transition conditions from DB.
     */
    private function registerGuards(int $definitionId, $workflow): void
    {
        $dispatcher = $this->bridge->getDispatcher('db-' . $definitionId);
        if (!$dispatcher) {
            return;
        }

        $transitionsTable = TableRegistry::getTableLocator()->get('WorkflowTransitions');
        $transitions = $transitionsTable->find()
            ->where(['WorkflowTransitions.workflow_definition_id' => $definitionId])
            ->all();

        foreach ($transitions as $t) {
            $conditions = $t->decoded_conditions;
            if (empty($conditions)) {
                continue;
            }

            $conditionTypes = $this->conditionTypes;
            $slug = $workflow->getName();

            $dispatcher->addListener(
                "workflow.{$slug}.guard.{$t->slug}",
                function (GuardEvent $event) use ($conditions, $conditionTypes) {
                    $subject = $event->getSubject();
                    $context = (array) ($subject->context ?? []);
                    $context['subject'] = $subject;

                    if (!$this->evaluateConditions($conditions, $context, $conditionTypes)) {
                        $event->setBlocked(true, 'Conditions not met');
                    }
                }
            );
        }
    }

    /**
     * Evaluate a condition tree (supports all/any/not combinators).
     */
    private function evaluateConditions(array $condition, array $context, array $conditionTypes): bool
    {
        if (isset($condition['all'])) {
            foreach ($condition['all'] as $sub) {
                if (!$this->evaluateConditions($sub, $context, $conditionTypes)) {
                    return false;
                }
            }
            return true;
        }

        if (isset($condition['any'])) {
            foreach ($condition['any'] as $sub) {
                if ($this->evaluateConditions($sub, $context, $conditionTypes)) {
                    return true;
                }
            }
            return false;
        }

        if (isset($condition['not'])) {
            return !$this->evaluateConditions($condition['not'], $context, $conditionTypes);
        }

        // Single condition
        $type = $condition['type'] ?? null;
        if ($type && isset($conditionTypes[$type])) {
            return $conditionTypes[$type]($condition, $context);
        }

        // Unknown condition type: fail closed
        return false;
    }

    /**
     * Log a transition to the audit trail.
     */
    private function logTransition(
        int $instanceId,
        ?int $fromStateId,
        int $toStateId,
        ?int $transitionId,
        ?int $triggeredBy,
        string $triggerType,
        ?string $notes
    ): void {
        try {
            $logsTable = TableRegistry::getTableLocator()->get('WorkflowTransitionLogs');
            $log = $logsTable->newEntity([
                'workflow_instance_id' => $instanceId,
                'from_state_id' => $fromStateId,
                'to_state_id' => $toStateId,
                'transition_id' => $transitionId,
                'triggered_by' => $triggeredBy,
                'trigger_type' => $triggerType,
                'context_snapshot' => null,
                'notes' => $notes,
            ]);
            $logsTable->save($log);
        } catch (\Exception $e) {
            Log::warning("WorkflowEngine: Failed to log transition: " . $e->getMessage());
        }
    }
}
