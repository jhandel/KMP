<?php

declare(strict_types=1);

namespace App\Services\WorkflowEngine;

use App\Model\Entity\WorkflowApproval;
use App\Model\Entity\WorkflowExecutionLog;
use App\Model\Entity\WorkflowInstance;
use App\Services\ServiceResult;
use App\Services\WorkflowEngine\Conditions\CoreConditions;
use App\Services\WorkflowRegistry\WorkflowActionRegistry;
use App\Services\WorkflowRegistry\WorkflowConditionRegistry;
use Cake\Datasource\ConnectionManager;
use Cake\I18n\DateTime;
use Cake\Log\Log;
use Cake\ORM\TableRegistry;
use Throwable;

/**
 * Default workflow execution engine.
 *
 * Executes workflow graphs by traversing nodes, invoking actions/conditions
 * from the registries, and managing instance lifecycle state.
 */
class DefaultWorkflowEngine implements WorkflowEngineInterface
{
    /**
     * @inheritDoc
     */
    public function startWorkflow(
        string $workflowSlug,
        array $triggerData = [],
        ?int $startedBy = null,
        ?string $entityType = null,
        ?int $entityId = null,
    ): ServiceResult {
        try {
            $definitionsTable = TableRegistry::getTableLocator()->get('WorkflowDefinitions');
            $versionsTable = TableRegistry::getTableLocator()->get('WorkflowVersions');
            $instancesTable = TableRegistry::getTableLocator()->get('WorkflowInstances');

            // Find active definition with a published version
            $workflowDef = $definitionsTable->find()
                ->where([
                    'slug' => $workflowSlug,
                    'is_active' => true,
                    'current_version_id IS NOT' => null,
                ])
                ->first();

            if (!$workflowDef) {
                return new ServiceResult(false, "No active workflow found for slug '{$workflowSlug}'.");
            }

            $version = $versionsTable->get($workflowDef->current_version_id);
            $definition = $version->definition;

            if (empty($definition['nodes'])) {
                return new ServiceResult(false, 'Workflow definition has no nodes.');
            }

            // Create instance
            $instance = $instancesTable->newEntity([
                'workflow_definition_id' => $workflowDef->id,
                'workflow_version_id' => $version->id,
                'entity_type' => $entityType ?? $workflowDef->entity_type,
                'entity_id' => $entityId,
                'status' => WorkflowInstance::STATUS_RUNNING,
                'context' => [
                    'trigger' => $triggerData,
                    'nodes' => [],
                    '_internal' => [],
                ],
                'active_nodes' => [],
                'started_by' => $startedBy,
                'started_at' => DateTime::now(),
            ]);

            if (!$instancesTable->save($instance)) {
                return new ServiceResult(false, 'Failed to create workflow instance.');
            }

            // Find and execute trigger nodes
            $triggerNodes = $this->findNodesByType($definition, 'trigger');
            $logsTable = TableRegistry::getTableLocator()->get('WorkflowExecutionLogs');

            foreach ($triggerNodes as $triggerNodeId => $triggerNode) {
                // Log trigger node as completed
                $log = $logsTable->newEntity([
                    'workflow_instance_id' => $instance->id,
                    'node_id' => $triggerNodeId,
                    'node_type' => 'trigger',
                    'attempt_number' => 1,
                    'status' => WorkflowExecutionLog::STATUS_COMPLETED,
                    'input_data' => $triggerData,
                    'output_data' => $triggerData,
                    'started_at' => DateTime::now(),
                    'completed_at' => DateTime::now(),
                ]);
                $logsTable->save($log);

                // Store trigger output in context
                $context = $instance->context;
                $context['nodes'][$triggerNodeId] = ['result' => $triggerData];
                $instance->context = $context;

                // Follow trigger outputs
                $targets = $this->getNodeOutputTargets($definition, $triggerNodeId, 'default');
                foreach ($targets as $targetNodeId) {
                    $this->executeNode($instance, $targetNodeId, $definition);
                }
            }

            $this->updateInstance($instance, []);

            return new ServiceResult(true, null, ['instanceId' => $instance->id]);
        } catch (Throwable $e) {
            Log::error("WorkflowEngine::startWorkflow failed: {$e->getMessage()}");

            return new ServiceResult(false, "Failed to start workflow: {$e->getMessage()}");
        }
    }

    /**
     * @inheritDoc
     */
    public function resumeWorkflow(
        int $instanceId,
        string $nodeId,
        string $outputPort,
        array $additionalData = [],
    ): ServiceResult {
        try {
            $instancesTable = TableRegistry::getTableLocator()->get('WorkflowInstances');
            $instance = $instancesTable->get($instanceId, contain: ['WorkflowVersions']);

            if ($instance->status !== WorkflowInstance::STATUS_WAITING) {
                return new ServiceResult(false, "Instance {$instanceId} is not in waiting state.");
            }

            // Merge additional data into context
            $context = $instance->context ?? [];
            if (!empty($additionalData)) {
                $context['resumeData'] = $additionalData;
                $instance->context = $context;
            }

            $instance->status = WorkflowInstance::STATUS_RUNNING;
            $this->updateInstance($instance, []);

            $definition = $instance->workflow_version->definition;

            // Mark the waiting log as completed
            $logsTable = TableRegistry::getTableLocator()->get('WorkflowExecutionLogs');
            $waitingLog = $logsTable->find()
                ->where([
                    'workflow_instance_id' => $instanceId,
                    'node_id' => $nodeId,
                    'status' => WorkflowExecutionLog::STATUS_WAITING,
                ])
                ->order(['id' => 'DESC'])
                ->first();

            if ($waitingLog) {
                $waitingLog->status = WorkflowExecutionLog::STATUS_COMPLETED;
                $waitingLog->completed_at = DateTime::now();
                $waitingLog->output_data = $additionalData;
                $logsTable->save($waitingLog);
            }

            // Remove node from active_nodes
            $activeNodes = $instance->active_nodes ?? [];
            $activeNodes = array_values(array_filter($activeNodes, fn($n) => $n !== $nodeId));
            $instance->active_nodes = $activeNodes;

            // Follow the specified output port
            $targets = $this->getNodeOutputTargets($definition, $nodeId, $outputPort);
            foreach ($targets as $targetNodeId) {
                $this->executeNode($instance, $targetNodeId, $definition);
            }

            $this->updateInstance($instance, []);

            return new ServiceResult(true, null, ['instanceId' => $instanceId]);
        } catch (Throwable $e) {
            Log::error("WorkflowEngine::resumeWorkflow failed: {$e->getMessage()}");

            return new ServiceResult(false, "Failed to resume workflow: {$e->getMessage()}");
        }
    }

    /**
     * @inheritDoc
     */
    public function cancelWorkflow(int $instanceId, ?string $reason = null): ServiceResult
    {
        try {
            $instancesTable = TableRegistry::getTableLocator()->get('WorkflowInstances');
            $instance = $instancesTable->get($instanceId);

            if ($instance->isTerminal()) {
                return new ServiceResult(false, "Instance {$instanceId} is already in terminal state '{$instance->status}'.");
            }

            $instance->status = WorkflowInstance::STATUS_CANCELLED;
            $instance->completed_at = DateTime::now();
            if ($reason) {
                $errorInfo = $instance->error_info ?? [];
                $errorInfo['cancellation_reason'] = $reason;
                $instance->error_info = $errorInfo;
            }

            if (!$instancesTable->save($instance)) {
                return new ServiceResult(false, 'Failed to save cancelled instance.');
            }

            // Cancel any pending approvals
            $approvalsTable = TableRegistry::getTableLocator()->get('WorkflowApprovals');
            $pendingApprovals = $approvalsTable->find()
                ->where([
                    'workflow_instance_id' => $instanceId,
                    'status' => WorkflowApproval::STATUS_PENDING,
                ])
                ->all();

            foreach ($pendingApprovals as $approval) {
                $approval->status = WorkflowApproval::STATUS_CANCELLED;
                $approvalsTable->save($approval);
            }

            return new ServiceResult(true, null, ['instanceId' => $instanceId]);
        } catch (Throwable $e) {
            Log::error("WorkflowEngine::cancelWorkflow failed: {$e->getMessage()}");

            return new ServiceResult(false, "Failed to cancel workflow: {$e->getMessage()}");
        }
    }

    /**
     * @inheritDoc
     */
    public function getInstanceState(int $instanceId): ?array
    {
        try {
            $instancesTable = TableRegistry::getTableLocator()->get('WorkflowInstances');
            $instance = $instancesTable->get($instanceId, contain: [
                'WorkflowDefinitions',
                'WorkflowVersions',
                'WorkflowExecutionLogs',
                'WorkflowApprovals',
            ]);

            return [
                'id' => $instance->id,
                'status' => $instance->status,
                'context' => $instance->context,
                'active_nodes' => $instance->active_nodes,
                'error_info' => $instance->error_info,
                'started_at' => $instance->started_at,
                'completed_at' => $instance->completed_at,
                'definition_name' => $instance->workflow_definition->name ?? null,
                'version_number' => $instance->workflow_version->version_number ?? null,
                'execution_logs' => array_map(fn($log) => [
                    'node_id' => $log->node_id,
                    'node_type' => $log->node_type,
                    'status' => $log->status,
                    'started_at' => $log->started_at,
                    'completed_at' => $log->completed_at,
                    'error_message' => $log->error_message,
                ], $instance->workflow_execution_logs ?? []),
                'approvals' => array_map(fn($a) => [
                    'node_id' => $a->node_id,
                    'status' => $a->status,
                    'required_count' => $a->required_count,
                    'approved_count' => $a->approved_count,
                    'rejected_count' => $a->rejected_count,
                ], $instance->workflow_approvals ?? []),
            ];
        } catch (Throwable $e) {
            Log::error("WorkflowEngine::getInstanceState failed: {$e->getMessage()}");

            return null;
        }
    }

    /**
     * @inheritDoc
     */
    public function dispatchTrigger(
        string $eventName,
        array $eventData = [],
        ?int $triggeredBy = null,
    ): array {
        $results = [];

        try {
            $definitionsTable = TableRegistry::getTableLocator()->get('WorkflowDefinitions');

            $activeDefinitions = $definitionsTable->find()
                ->where([
                    'is_active' => true,
                    'current_version_id IS NOT' => null,
                ])
                ->contain(['CurrentVersion'])
                ->all();

            $versionsTable = TableRegistry::getTableLocator()->get('WorkflowVersions');

            foreach ($activeDefinitions as $def) {
                $version = $def->current_version;
                if (!$version || empty($version->definition['nodes'])) {
                    continue;
                }

                $definition = $version->definition;
                $triggerNodes = $this->findNodesByType($definition, 'trigger');

                foreach ($triggerNodes as $triggerNode) {
                    $triggerEvent = $triggerNode['config']['event'] ?? $triggerNode['config']['eventName'] ?? null;
                    if ($triggerEvent === $eventName) {
                        $results[] = $this->startWorkflow(
                            $def->slug,
                            $eventData,
                            $triggeredBy,
                        );
                        break; // Only start once per definition
                    }
                }
            }
        } catch (Throwable $e) {
            Log::error("WorkflowEngine::dispatchTrigger failed: {$e->getMessage()}");
            $results[] = new ServiceResult(false, "Trigger dispatch error: {$e->getMessage()}");
        }

        return $results;
    }

    /**
     * Execute a single workflow node and advance to connected outputs.
     *
     * @param \App\Model\Entity\WorkflowInstance $instance The workflow instance
     * @param string $nodeId The node ID to execute
     * @param array $definition The workflow definition graph
     * @return void
     */
    protected function executeNode(WorkflowInstance $instance, string $nodeId, array $definition): void
    {
        $logsTable = TableRegistry::getTableLocator()->get('WorkflowExecutionLogs');

        $node = $definition['nodes'][$nodeId] ?? null;
        if (!$node) {
            Log::error("WorkflowEngine: Node '{$nodeId}' not found in definition.");

            return;
        }

        $nodeType = $node['type'] ?? 'unknown';
        $nodeConfig = $node['config'] ?? [];

        // Add to active nodes
        $activeNodes = $instance->active_nodes ?? [];
        if (!in_array($nodeId, $activeNodes, true)) {
            $activeNodes[] = $nodeId;
            $instance->active_nodes = $activeNodes;
        }

        // Create execution log
        $log = $logsTable->newEntity([
            'workflow_instance_id' => $instance->id,
            'node_id' => $nodeId,
            'node_type' => $nodeType,
            'attempt_number' => 1,
            'status' => WorkflowExecutionLog::STATUS_RUNNING,
            'input_data' => $this->resolveInputData($instance->context ?? [], $nodeConfig),
            'started_at' => DateTime::now(),
        ]);
        $logsTable->save($log);

        try {
            switch ($nodeType) {
                case 'action':
                    $this->executeActionNode($instance, $nodeId, $node, $log, $definition);
                    break;

                case 'condition':
                    $this->executeConditionNode($instance, $nodeId, $node, $log, $definition);
                    break;

                case 'approval':
                    $this->executeApprovalNode($instance, $nodeId, $node, $log);
                    break;

                case 'fork':
                    $this->executeForkNode($instance, $nodeId, $log, $definition);
                    break;

                case 'join':
                    $this->executeJoinNode($instance, $nodeId, $node, $log, $definition);
                    break;

                case 'loop':
                    $this->executeLoopNode($instance, $nodeId, $node, $log, $definition);
                    break;

                case 'delay':
                    $this->executeDelayNode($instance, $nodeId, $node, $log);
                    break;

                case 'end':
                    $this->executeEndNode($instance, $nodeId, $log);
                    break;

                case 'subworkflow':
                    $this->executeSubworkflowNode($instance, $nodeId, $node, $log);
                    break;

                default:
                    $log->status = WorkflowExecutionLog::STATUS_FAILED;
                    $log->error_message = "Unknown node type: {$nodeType}";
                    $log->completed_at = DateTime::now();
                    $logsTable->save($log);
                    break;
            }
        } catch (Throwable $e) {
            Log::error("WorkflowEngine: Node '{$nodeId}' execution failed: {$e->getMessage()}");

            $log->status = WorkflowExecutionLog::STATUS_FAILED;
            $log->error_message = $e->getMessage();
            $log->completed_at = DateTime::now();
            $logsTable->save($log);

            $instance->status = WorkflowInstance::STATUS_FAILED;
            $instance->completed_at = DateTime::now();
            $instance->error_info = [
                'failed_node' => $nodeId,
                'error' => $e->getMessage(),
            ];
            $this->updateInstance($instance, []);
        }
    }

    /**
     * Execute an action node via the WorkflowActionRegistry.
     */
    protected function executeActionNode(
        WorkflowInstance $instance,
        string $nodeId,
        array $node,
        WorkflowExecutionLog $log,
        array $definition,
    ): void {
        $logsTable = TableRegistry::getTableLocator()->get('WorkflowExecutionLogs');
        $actionName = $node['config']['action'] ?? null;

        if (!$actionName) {
            throw new \RuntimeException("Action node '{$nodeId}' has no action configured.");
        }

        $actionConfig = WorkflowActionRegistry::getAction($actionName);
        if (!$actionConfig) {
            throw new \RuntimeException("Action '{$actionName}' not found in registry.");
        }

        $serviceClass = $actionConfig['serviceClass'];
        $serviceMethod = $actionConfig['serviceMethod'];

        $service = new $serviceClass();
        $context = $instance->context ?? [];
        $result = $service->{$serviceMethod}($context, $node['config']);

        // Store result in context
        $context['nodes'][$nodeId] = ['result' => $result];
        $instance->context = $context;

        $log->status = WorkflowExecutionLog::STATUS_COMPLETED;
        $log->output_data = $result;
        $log->completed_at = DateTime::now();
        $logsTable->save($log);

        $this->removeFromActiveNodes($instance, $nodeId);
        $this->advanceToOutputs($instance, $nodeId, 'default', $definition);
    }

    /**
     * Execute a condition node and follow the matching output port.
     */
    protected function executeConditionNode(
        WorkflowInstance $instance,
        string $nodeId,
        array $node,
        WorkflowExecutionLog $log,
        array $definition,
    ): void {
        $logsTable = TableRegistry::getTableLocator()->get('WorkflowExecutionLogs');
        $conditionName = $node['config']['condition'] ?? null;
        $context = $instance->context ?? [];
        $result = false;

        if ($conditionName) {
            $conditionConfig = WorkflowConditionRegistry::getCondition($conditionName);
            if ($conditionConfig) {
                $evaluatorClass = $conditionConfig['evaluatorClass'];
                $evaluatorMethod = $conditionConfig['evaluatorMethod'];
                $evaluator = new $evaluatorClass();
                $result = (bool)$evaluator->{$evaluatorMethod}($context, $node['config']);
            } else {
                throw new \RuntimeException("Condition '{$conditionName}' not found in registry.");
            }
        } else {
            // Handle inline expression evaluation
            $evaluator = new CoreConditions();
            $result = $evaluator->evaluateExpression($context, $node['config']);
        }

        $outputPort = $result ? 'true' : 'false';

        $context['nodes'][$nodeId] = ['result' => $result, 'port' => $outputPort];
        $instance->context = $context;

        $log->status = WorkflowExecutionLog::STATUS_COMPLETED;
        $log->output_data = ['result' => $result, 'port' => $outputPort];
        $log->completed_at = DateTime::now();
        $logsTable->save($log);

        $this->removeFromActiveNodes($instance, $nodeId);
        $this->advanceToOutputs($instance, $nodeId, $outputPort, $definition);
    }

    /**
     * Execute an approval node — creates approval record, sets instance to WAITING.
     */
    protected function executeApprovalNode(
        WorkflowInstance $instance,
        string $nodeId,
        array $node,
        WorkflowExecutionLog $log,
    ): void {
        $logsTable = TableRegistry::getTableLocator()->get('WorkflowExecutionLogs');
        $approvalsTable = TableRegistry::getTableLocator()->get('WorkflowApprovals');

        $config = $node['config'] ?? [];

        $approval = $approvalsTable->newEntity([
            'workflow_instance_id' => $instance->id,
            'node_id' => $nodeId,
            'execution_log_id' => $log->id,
            'approver_type' => $config['approverType'] ?? WorkflowApproval::APPROVER_TYPE_PERMISSION,
            'approver_config' => $config['approverConfig'] ?? [],
            'required_count' => $config['requiredCount'] ?? 1,
            'approved_count' => 0,
            'rejected_count' => 0,
            'status' => WorkflowApproval::STATUS_PENDING,
            'allow_parallel' => $config['allowParallel'] ?? false,
            'deadline' => !empty($config['deadline']) ? new DateTime($config['deadline']) : null,
            'escalation_config' => $config['escalationConfig'] ?? null,
        ]);
        $approvalsTable->save($approval);

        $log->status = WorkflowExecutionLog::STATUS_WAITING;
        $logsTable->save($log);

        $instance->status = WorkflowInstance::STATUS_WAITING;
        $this->updateInstance($instance, []);
    }

    /**
     * Execute a fork node — marks complete and executes all output targets.
     */
    protected function executeForkNode(
        WorkflowInstance $instance,
        string $nodeId,
        WorkflowExecutionLog $log,
        array $definition,
    ): void {
        $logsTable = TableRegistry::getTableLocator()->get('WorkflowExecutionLogs');

        $log->status = WorkflowExecutionLog::STATUS_COMPLETED;
        $log->completed_at = DateTime::now();
        $logsTable->save($log);

        $this->removeFromActiveNodes($instance, $nodeId);

        // Execute all output targets (parallel paths)
        $allTargets = $this->getAllOutputTargets($definition, $nodeId);
        foreach ($allTargets as $targetNodeId) {
            $this->executeNode($instance, $targetNodeId, $definition);
        }
    }

    /**
     * Execute a join node — waits for all input paths before advancing.
     */
    protected function executeJoinNode(
        WorkflowInstance $instance,
        string $nodeId,
        array $node,
        WorkflowExecutionLog $log,
        array $definition,
    ): void {
        $logsTable = TableRegistry::getTableLocator()->get('WorkflowExecutionLogs');
        $context = $instance->context ?? [];

        // Track join state
        $joinKey = "_internal.joinState.{$nodeId}";
        $joinState = $context['_internal']['joinState'][$nodeId] ?? [];
        $completedInputs = $joinState['completedInputs'] ?? [];

        // Find which edges lead into this join node
        $expectedInputs = $this->getNodeInputSources($definition, $nodeId);
        $completedInputs = array_unique(array_merge($completedInputs, [$this->findIncomingSource($definition, $nodeId, $instance)]));

        $context['_internal']['joinState'][$nodeId] = ['completedInputs' => $completedInputs];
        $instance->context = $context;

        if (count($completedInputs) >= count($expectedInputs)) {
            // All inputs completed — advance
            $log->status = WorkflowExecutionLog::STATUS_COMPLETED;
            $log->completed_at = DateTime::now();
            $logsTable->save($log);

            $this->removeFromActiveNodes($instance, $nodeId);
            $this->advanceToOutputs($instance, $nodeId, 'default', $definition);
        } else {
            // Still waiting for other paths
            $log->status = WorkflowExecutionLog::STATUS_WAITING;
            $logsTable->save($log);
        }
    }

    /**
     * Execute a loop node — iterates until max count or exit condition.
     */
    protected function executeLoopNode(
        WorkflowInstance $instance,
        string $nodeId,
        array $node,
        WorkflowExecutionLog $log,
        array $definition,
    ): void {
        $logsTable = TableRegistry::getTableLocator()->get('WorkflowExecutionLogs');
        $context = $instance->context ?? [];
        $config = $node['config'] ?? [];

        $maxIterations = (int)($config['maxIterations'] ?? 10);
        $iterationKey = "_internal.loopState.{$nodeId}.iteration";
        $currentIteration = $context['_internal']['loopState'][$nodeId]['iteration'] ?? 0;
        $currentIteration++;

        $context['_internal']['loopState'][$nodeId] = ['iteration' => $currentIteration];
        $instance->context = $context;

        $log->status = WorkflowExecutionLog::STATUS_COMPLETED;
        $log->output_data = ['iteration' => $currentIteration, 'maxIterations' => $maxIterations];
        $log->completed_at = DateTime::now();
        $logsTable->save($log);

        $this->removeFromActiveNodes($instance, $nodeId);

        // Check exit condition
        $shouldExit = $currentIteration >= $maxIterations;
        if (!$shouldExit && !empty($config['exitCondition'])) {
            $evaluator = new CoreConditions();
            $shouldExit = $evaluator->evaluateExpression($context, ['expression' => $config['exitCondition']]);
        }

        $outputPort = $shouldExit ? 'exit' : 'continue';
        $this->advanceToOutputs($instance, $nodeId, $outputPort, $definition);
    }

    /**
     * Execute a delay node — sets instance to WAITING for later resumption.
     */
    protected function executeDelayNode(
        WorkflowInstance $instance,
        string $nodeId,
        array $node,
        WorkflowExecutionLog $log,
    ): void {
        $logsTable = TableRegistry::getTableLocator()->get('WorkflowExecutionLogs');

        $log->status = WorkflowExecutionLog::STATUS_WAITING;
        $log->output_data = ['delayConfig' => $node['config'] ?? []];
        $logsTable->save($log);

        $instance->status = WorkflowInstance::STATUS_WAITING;
        $this->updateInstance($instance, []);
    }

    /**
     * Execute an end node — completes this path, finishes instance if no active nodes remain.
     */
    protected function executeEndNode(
        WorkflowInstance $instance,
        string $nodeId,
        WorkflowExecutionLog $log,
    ): void {
        $logsTable = TableRegistry::getTableLocator()->get('WorkflowExecutionLogs');

        $log->status = WorkflowExecutionLog::STATUS_COMPLETED;
        $log->completed_at = DateTime::now();
        $logsTable->save($log);

        $this->removeFromActiveNodes($instance, $nodeId);

        // If no more active nodes, complete the instance
        $activeNodes = $instance->active_nodes ?? [];
        if (empty($activeNodes)) {
            $instance->status = WorkflowInstance::STATUS_COMPLETED;
            $instance->completed_at = DateTime::now();
            $this->updateInstance($instance, []);
        }
    }

    /**
     * Execute a subworkflow node — starts a child workflow, sets instance to WAITING.
     */
    protected function executeSubworkflowNode(
        WorkflowInstance $instance,
        string $nodeId,
        array $node,
        WorkflowExecutionLog $log,
    ): void {
        $logsTable = TableRegistry::getTableLocator()->get('WorkflowExecutionLogs');
        $config = $node['config'] ?? [];
        $childSlug = $config['workflowSlug'] ?? null;

        if (!$childSlug) {
            throw new \RuntimeException("Subworkflow node '{$nodeId}' has no workflowSlug configured.");
        }

        $childResult = $this->startWorkflow(
            $childSlug,
            $instance->context['trigger'] ?? [],
            $instance->started_by,
            $instance->entity_type,
            $instance->entity_id,
        );

        $context = $instance->context ?? [];
        $context['nodes'][$nodeId] = [
            'result' => $childResult->data,
            'childInstanceId' => $childResult->data['instanceId'] ?? null,
        ];
        $instance->context = $context;

        $log->status = WorkflowExecutionLog::STATUS_WAITING;
        $log->output_data = $childResult->data;
        $logsTable->save($log);

        $instance->status = WorkflowInstance::STATUS_WAITING;
        $this->updateInstance($instance, []);
    }

    // -------------------------------------------------------------------------
    // Helper methods
    // -------------------------------------------------------------------------

    /**
     * Resolve a context value using a dot-path (e.g., '$.trigger.officer.id').
     *
     * @param array $context The workflow context
     * @param string $path Dot-separated path, optionally prefixed with '$.'
     * @return mixed
     */
    protected function resolveContextValue(array $context, string $path): mixed
    {
        return CoreConditions::resolveFieldPath($context, $path);
    }

    /**
     * Set a value in the context at the given dot-path.
     *
     * @param array &$context The workflow context (by reference)
     * @param string $path Dot-separated path
     * @param mixed $value The value to set
     * @return void
     */
    protected function setContextValue(array &$context, string $path, mixed $value): void
    {
        if (str_starts_with($path, '$.')) {
            $path = substr($path, 2);
        }

        $segments = explode('.', $path);
        $current = &$context;

        foreach ($segments as $i => $segment) {
            if ($i === count($segments) - 1) {
                $current[$segment] = $value;
            } else {
                if (!isset($current[$segment]) || !is_array($current[$segment])) {
                    $current[$segment] = [];
                }
                $current = &$current[$segment];
            }
        }
    }

    /**
     * Get target node IDs for a given node's output port.
     *
     * @param array $definition The workflow definition
     * @param string $nodeId Source node ID
     * @param string $port Output port name
     * @return array<string> Target node IDs
     */
    protected function getNodeOutputTargets(array $definition, string $nodeId, string $port): array
    {
        $targets = [];
        $edges = $definition['edges'] ?? [];

        foreach ($edges as $edge) {
            $edgeSource = $edge['source'] ?? $edge['from'] ?? null;
            $edgePort = $edge['sourcePort'] ?? $edge['port'] ?? 'default';
            $edgeTarget = $edge['target'] ?? $edge['to'] ?? null;

            if ($edgeSource === $nodeId && $edgePort === $port && $edgeTarget !== null) {
                $targets[] = $edgeTarget;
            }
        }

        return $targets;
    }

    /**
     * Get all output targets for a node regardless of port.
     *
     * @param array $definition The workflow definition
     * @param string $nodeId Source node ID
     * @return array<string> Target node IDs
     */
    protected function getAllOutputTargets(array $definition, string $nodeId): array
    {
        $targets = [];
        $edges = $definition['edges'] ?? [];

        foreach ($edges as $edge) {
            $edgeSource = $edge['source'] ?? $edge['from'] ?? null;
            $edgeTarget = $edge['target'] ?? $edge['to'] ?? null;

            if ($edgeSource === $nodeId && $edgeTarget !== null) {
                $targets[] = $edgeTarget;
            }
        }

        return array_unique($targets);
    }

    /**
     * Save instance changes to the database.
     *
     * @param \App\Model\Entity\WorkflowInstance $instance The instance to save
     * @param array $changes Additional field changes to apply
     * @return void
     */
    protected function updateInstance(WorkflowInstance $instance, array $changes): void
    {
        $instancesTable = TableRegistry::getTableLocator()->get('WorkflowInstances');

        foreach ($changes as $field => $value) {
            $instance->{$field} = $value;
        }

        $instancesTable->save($instance);
    }

    /**
     * Find all nodes of a given type in the definition.
     *
     * @param array $definition The workflow definition
     * @param string $type Node type to find
     * @return array Matching nodes keyed by node ID
     */
    protected function findNodesByType(array $definition, string $type): array
    {
        $matches = [];

        foreach ($definition['nodes'] as $nodeId => $node) {
            if (($node['type'] ?? '') === $type) {
                $matches[$nodeId] = $node;
            }
        }

        return $matches;
    }

    /**
     * Remove a node from the instance's active_nodes list.
     */
    protected function removeFromActiveNodes(WorkflowInstance $instance, string $nodeId): void
    {
        $activeNodes = $instance->active_nodes ?? [];
        $activeNodes = array_values(array_filter($activeNodes, fn($n) => $n !== $nodeId));
        $instance->active_nodes = $activeNodes;
    }

    /**
     * Advance execution to all targets of a node's output port.
     */
    protected function advanceToOutputs(
        WorkflowInstance $instance,
        string $nodeId,
        string $port,
        array $definition,
    ): void {
        $targets = $this->getNodeOutputTargets($definition, $nodeId, $port);
        foreach ($targets as $targetNodeId) {
            $this->executeNode($instance, $targetNodeId, $definition);
        }
    }

    /**
     * Resolve input data for a node from context using configured mappings.
     *
     * @param array $context The workflow context
     * @param array $nodeConfig The node's configuration
     * @return array Resolved input data
     */
    protected function resolveInputData(array $context, array $nodeConfig): array
    {
        $inputMappings = $nodeConfig['inputMappings'] ?? [];
        $resolved = [];

        foreach ($inputMappings as $key => $path) {
            if (is_string($path)) {
                $resolved[$key] = $this->resolveContextValue($context, $path);
            } else {
                $resolved[$key] = $path;
            }
        }

        return $resolved;
    }

    /**
     * Get all source node IDs that have edges leading into the given node.
     *
     * @param array $definition The workflow definition
     * @param string $nodeId The target node ID
     * @return array<string> Source node IDs
     */
    protected function getNodeInputSources(array $definition, string $nodeId): array
    {
        $sources = [];
        $edges = $definition['edges'] ?? [];

        foreach ($edges as $edge) {
            $edgeTarget = $edge['target'] ?? $edge['to'] ?? null;
            $edgeSource = $edge['source'] ?? $edge['from'] ?? null;

            if ($edgeTarget === $nodeId && $edgeSource !== null) {
                $sources[] = $edgeSource;
            }
        }

        return array_unique($sources);
    }

    /**
     * Find which source node most recently completed execution leading into a join.
     *
     * @param array $definition The workflow definition
     * @param string $joinNodeId The join node ID
     * @param \App\Model\Entity\WorkflowInstance $instance The workflow instance
     * @return string The most recently completed source node ID
     */
    protected function findIncomingSource(array $definition, string $joinNodeId, WorkflowInstance $instance): string
    {
        $logsTable = TableRegistry::getTableLocator()->get('WorkflowExecutionLogs');
        $inputSources = $this->getNodeInputSources($definition, $joinNodeId);

        // Find the most recently completed source node
        $latestLog = $logsTable->find()
            ->where([
                'workflow_instance_id' => $instance->id,
                'node_id IN' => $inputSources,
                'status' => WorkflowExecutionLog::STATUS_COMPLETED,
            ])
            ->order(['completed_at' => 'DESC'])
            ->first();

        return $latestLog ? $latestLog->node_id : ($inputSources[0] ?? 'unknown');
    }
}
