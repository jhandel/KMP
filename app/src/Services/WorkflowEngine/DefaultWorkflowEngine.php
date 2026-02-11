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
use Cake\Core\ContainerInterface;
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
     * Maximum node execution depth to prevent infinite recursion.
     */
    private const MAX_EXECUTION_DEPTH = 200;

    private ContainerInterface $container;

    /**
     * Tracks visited nodes during a single execution pass to detect cycles.
     * Reset at the start of each startWorkflow/resumeWorkflow call.
     *
     * @var array<string, bool>
     */
    private array $visitedNodes = [];

    /**
     * Current execution depth counter.
     */
    private int $executionDepth = 0;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }
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
        // Reset cycle-detection state for this execution pass
        $this->visitedNodes = [];
        $this->executionDepth = 0;

        $connection = ConnectionManager::get('default');
        try {
            return $connection->transactional(function () use ($workflowSlug, $triggerData, $startedBy, $entityType, $entityId) {
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

            // Duplicate instance prevention: skip if a running/waiting instance already exists
            $resolvedEntityType = $entityType ?? $workflowDef->entity_type;
            $duplicateConditions = [
                'workflow_definition_id' => $workflowDef->id,
                'status IN' => [WorkflowInstance::STATUS_RUNNING, WorkflowInstance::STATUS_WAITING],
            ];
            if ($resolvedEntityType !== null) {
                $duplicateConditions['entity_type'] = $resolvedEntityType;
            }
            if ($entityId !== null) {
                $duplicateConditions['entity_id'] = $entityId;
            }
            $existingInstance = $instancesTable->find()
                ->where($duplicateConditions)
                ->first();

            if ($existingInstance) {
                Log::warning(
                    "WorkflowEngine: Duplicate instance prevented for definition '{$workflowSlug}'"
                    . " entity_type={$resolvedEntityType} entity_id={$entityId}"
                    . " — existing instance #{$existingInstance->id} is '{$existingInstance->status}'."
                );

                return new ServiceResult(
                    false,
                    "A workflow instance is already active (#{$existingInstance->id}) for this entity.",
                    ['existingInstanceId' => $existingInstance->id],
                );
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
                    'triggeredBy' => $startedBy,
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
            });
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
        // Reset cycle-detection state for this execution pass
        $this->visitedNodes = [];
        $this->executionDepth = 0;

        $connection = ConnectionManager::get('default');
        try {
            return $connection->transactional(function () use ($instanceId, $nodeId, $outputPort, $additionalData) {
            $instancesTable = TableRegistry::getTableLocator()->get('WorkflowInstances');
            $instance = $instancesTable->get($instanceId, contain: ['WorkflowVersions']);

            if ($instance->status !== WorkflowInstance::STATUS_WAITING) {
                return new ServiceResult(false, "Instance {$instanceId} is not in waiting state.");
            }

            // Merge additional data into context
            $context = $instance->context ?? [];
            if (!empty($additionalData)) {
                $context['resumeData'] = $additionalData;
            }

            // Store approval output in nodes context so $.nodes.<nodeId>.* resolves
            if (!isset($context['nodes'])) {
                $context['nodes'] = [];
            }
            $context['nodes'][$nodeId] = [
                'status' => $outputPort,
                'approverId' => $additionalData['approverId'] ?? null,
                'comment' => $additionalData['comment'] ?? null,
                'rejectionComment' => $additionalData['comment'] ?? null,
                'decision' => $additionalData['decision'] ?? $outputPort,
            ];
            $instance->context = $context;

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
            });
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
        $connection = ConnectionManager::get('default');
        try {
            return $connection->transactional(function () use ($instanceId, $reason) {
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
            });
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
                        // Resolve entity ID from trigger config's entityIdField
                        $entityIdField = $triggerNode['config']['entityIdField'] ?? null;
                        $entityId = $entityIdField ? ($eventData[$entityIdField] ?? null) : null;

                        $results[] = $this->startWorkflow(
                            $def->slug,
                            $eventData,
                            $triggeredBy,
                            null, // entityType from definition
                            $entityId ? (int)$entityId : null,
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
        // Cycle detection: check depth limit
        $this->executionDepth++;
        if ($this->executionDepth > self::MAX_EXECUTION_DEPTH) {
            $msg = "Workflow execution exceeded max depth (" . self::MAX_EXECUTION_DEPTH
                . ") at node '{$nodeId}'. Possible cycle in workflow graph.";
            Log::error("WorkflowEngine: {$msg}");
            $instance->status = WorkflowInstance::STATUS_FAILED;
            $instance->completed_at = DateTime::now();
            $instance->error_info = ['failed_node' => $nodeId, 'error' => $msg];
            $this->updateInstance($instance, []);

            throw new \RuntimeException($msg);
        }

        // Cycle detection: visited-node set (skip for join/loop which are legitimately revisited)
        $node = $definition['nodes'][$nodeId] ?? null;
        $nodeType = $node['type'] ?? 'unknown';

        $allowRevisit = in_array($nodeType, ['join', 'loop'], true);
        if (!$allowRevisit && isset($this->visitedNodes[$nodeId])) {
            $msg = "Cycle detected: node '{$nodeId}' was already visited in this execution path.";
            Log::error("WorkflowEngine: {$msg}");
            $instance->status = WorkflowInstance::STATUS_FAILED;
            $instance->completed_at = DateTime::now();
            $instance->error_info = ['failed_node' => $nodeId, 'error' => $msg];
            $this->updateInstance($instance, []);

            throw new \RuntimeException($msg);
        }
        $this->visitedNodes[$nodeId] = true;

        $logsTable = TableRegistry::getTableLocator()->get('WorkflowExecutionLogs');

        if (!$node) {
            Log::error("WorkflowEngine: Node '{$nodeId}' not found in definition.");

            return;
        }

        $nodeConfig = $node['config'] ?? [];

        // Add to active nodes
        $activeNodes = $instance->active_nodes ?? [];
        if (!in_array($nodeId, $activeNodes, true)) {
            $activeNodes[] = $nodeId;
            $instance->active_nodes = $activeNodes;
        }

        // Retry logic: determine max attempts from node config
        $maxRetries = (int)($nodeConfig['maxRetries'] ?? $nodeConfig['retryCount'] ?? 0);
        $maxAttempts = $maxRetries + 1;
        $lastException = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            // Create execution log for this attempt
            $log = $logsTable->newEntity([
                'workflow_instance_id' => $instance->id,
                'node_id' => $nodeId,
                'node_type' => $nodeType,
                'attempt_number' => $attempt,
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

                // Success — break out of retry loop
                return;
            } catch (Throwable $e) {
                $lastException = $e;
                $log->status = WorkflowExecutionLog::STATUS_FAILED;
                $log->error_message = $e->getMessage();
                $log->completed_at = DateTime::now();
                $logsTable->save($log);

                if ($attempt < $maxAttempts) {
                    // Exponential backoff: 1s, 2s, 4s, ...
                    $backoffSeconds = (int)pow(2, $attempt - 1);
                    Log::warning(
                        "WorkflowEngine: Node '{$nodeId}' attempt {$attempt}/{$maxAttempts} failed, "
                        . "retrying in {$backoffSeconds}s: {$e->getMessage()}"
                    );
                    sleep($backoffSeconds);
                }
            }
        }

        // All attempts exhausted — mark instance as failed
        Log::error("WorkflowEngine: Node '{$nodeId}' failed after {$maxAttempts} attempt(s): {$lastException->getMessage()}");

        $instance->status = WorkflowInstance::STATUS_FAILED;
        $instance->completed_at = DateTime::now();
        $instance->error_info = [
            'failed_node' => $nodeId,
            'error' => $lastException->getMessage(),
            'attempts' => $maxAttempts,
        ];
        $this->updateInstance($instance, []);

        throw $lastException;
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

        // Async execution: queue the action instead of running inline
        $isAsync = !empty($node['config']['isAsync']) || !empty($actionConfig['isAsync']);
        if ($isAsync) {
            $log->status = WorkflowExecutionLog::STATUS_WAITING;
            $log->output_data = ['queued' => true, 'action' => $actionName];
            $logsTable->save($log);

            $instance->status = WorkflowInstance::STATUS_WAITING;
            $this->updateInstance($instance, []);

            $queuedJobsTable = TableRegistry::getTableLocator()->get('Queue.QueuedJobs');
            $queuedJobsTable->createJob('WorkflowResume', [
                'instanceId' => $instance->id,
                'nodeId' => $nodeId,
                'outputPort' => 'default',
                'additionalData' => [
                    'asyncAction' => $actionName,
                    'nodeConfig' => $node['config'] ?? [],
                ],
            ]);

            Log::info("WorkflowEngine: Async action '{$actionName}' queued for node '{$nodeId}' instance #{$instance->id}.");

            return;
        }

        $serviceClass = $actionConfig['serviceClass'];
        $serviceMethod = $actionConfig['serviceMethod'];

        $service = $this->container->has($serviceClass)
            ? $this->container->get($serviceClass)
            : new $serviceClass();
        $context = $instance->context ?? [];

        // Resolve and merge config.params into top-level config so actions can read params directly
        $nodeConfig = $node['config'] ?? [];
        if (!empty($nodeConfig['params']) && is_array($nodeConfig['params'])) {
            $resolvedParams = [];
            foreach ($nodeConfig['params'] as $key => $paramValue) {
                $resolvedParams[$key] = $this->resolveParamValue($paramValue, $context);
            }
            $nodeConfig = array_merge($nodeConfig, $resolvedParams);
        }

        $result = $service->{$serviceMethod}($context, $nodeConfig);

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
        $conditionName = $node['config']['condition'] ?? $node['config']['evaluator'] ?? null;
        $context = $instance->context ?? [];
        $result = false;

        if ($conditionName) {
            $conditionConfig = WorkflowConditionRegistry::getCondition($conditionName);
            if ($conditionConfig) {
                $evaluatorClass = $conditionConfig['evaluatorClass'];
                $evaluatorMethod = $conditionConfig['evaluatorMethod'];
                $evaluator = $this->container->has($evaluatorClass)
                    ? $this->container->get($evaluatorClass)
                    : new $evaluatorClass();
                // Resolve and merge config.params into top-level config so evaluators can read params directly
                $nodeConfig = $node['config'] ?? [];
                if (isset($nodeConfig['expectedValue'])) {
                    $nodeConfig['expectedValue'] = $this->resolveParamValue($nodeConfig['expectedValue'], $context);
                }
                if (!empty($nodeConfig['params']) && is_array($nodeConfig['params'])) {
                    $resolvedParams = [];
                    foreach ($nodeConfig['params'] as $key => $paramValue) {
                        $resolvedParams[$key] = $this->resolveParamValue($paramValue, $context);
                    }
                    $nodeConfig = array_merge($nodeConfig, $resolvedParams);
                }
                $result = (bool)$evaluator->{$evaluatorMethod}($context, $nodeConfig);
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

        // Build approver_config from top-level config keys
        $approverConfig = $config['approverConfig'] ?? [];
        if (empty($approverConfig)) {
            if (!empty($config['permission'])) {
                $approverConfig['permission'] = $config['permission'];
            }
            if (!empty($config['role'])) {
                $approverConfig['role'] = $config['role'];
            }
            if (!empty($config['member_id'])) {
                $approverConfig['member_id'] = $config['member_id'];
            }
            // Policy approver type fields
            if (!empty($config['policyClass'])) {
                $approverConfig['policyClass'] = $config['policyClass'];
            }
            if (!empty($config['policyAction'])) {
                $approverConfig['policyAction'] = $config['policyAction'];
            }
            if (!empty($config['entityTable'])) {
                $approverConfig['entityTable'] = $config['entityTable'];
            }
            if (!empty($config['entityIdKey'])) {
                $approverConfig['entityIdKey'] = $config['entityIdKey'];
            }
        }

        // Resolve requiredCount (may be int, or {type: "app_setting", key: "..."})
        $requiredCount = $this->resolveRequiredCount($config['requiredCount'] ?? 1, $instance->context ?? []);

        // Parse deadline duration (e.g., "14d", "24h", "7d") into a future DateTime
        $deadline = null;
        if (!empty($config['deadline'])) {
            $deadline = $this->parseDeadline($config['deadline']);
        }

        $approval = $approvalsTable->newEntity([
            'workflow_instance_id' => $instance->id,
            'node_id' => $nodeId,
            'execution_log_id' => $log->id,
            'approver_type' => $config['approverType'] ?? WorkflowApproval::APPROVER_TYPE_PERMISSION,
            'approver_config' => $approverConfig,
            'required_count' => $requiredCount,
            'approved_count' => 0,
            'rejected_count' => 0,
            'status' => WorkflowApproval::STATUS_PENDING,
            'allow_parallel' => !empty($config['parallel'] ?? $config['allowParallel'] ?? false),
            'deadline' => $deadline,
            'escalation_config' => $config['escalationConfig'] ?? null,
        ]);
        if ($approval->getErrors()) {
            Log::error('Approval entity validation errors: ' . json_encode($approval->getErrors()));
        }
        if (!$approvalsTable->save($approval)) {
            Log::error('Failed to save workflow approval for node ' . $nodeId . ': ' . json_encode($approval->getErrors()));
        }

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
     * If this instance is a child workflow, resumes the parent instance.
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

            // Subworkflow completion callback: resume parent if this is a child instance
            $context = $instance->context ?? [];
            $parentInstanceId = $context['_internal']['parentInstanceId'] ?? null;
            $parentNodeId = $context['_internal']['parentNodeId'] ?? null;

            if ($parentInstanceId !== null && $parentNodeId !== null) {
                Log::info(
                    "WorkflowEngine: Child instance #{$instance->id} completed, "
                    . "resuming parent instance #{$parentInstanceId} at node '{$parentNodeId}'."
                );

                $this->resumeWorkflow(
                    (int)$parentInstanceId,
                    $parentNodeId,
                    'default',
                    ['childResult' => $context['nodes'] ?? [], 'childInstanceId' => $instance->id],
                );
            }
        }
    }

    /**
     * Execute a subworkflow node — starts a child workflow, sets instance to WAITING.
     * Passes parent instance/node info so the child can resume the parent on completion.
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

        // Store parent info in the child instance's context so it can call back
        $childInstanceId = $childResult->data['instanceId'] ?? null;
        if ($childInstanceId !== null) {
            $instancesTable = TableRegistry::getTableLocator()->get('WorkflowInstances');
            $childInstance = $instancesTable->get($childInstanceId);
            $childContext = $childInstance->context ?? [];
            $childContext['_internal']['parentInstanceId'] = $instance->id;
            $childContext['_internal']['parentNodeId'] = $nodeId;
            $childInstance->context = $childContext;
            $instancesTable->save($childInstance);
        }

        $context = $instance->context ?? [];
        $context['nodes'][$nodeId] = [
            'result' => $childResult->data,
            'childInstanceId' => $childInstanceId,
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
     * Resolve a parameter value that may be a plain scalar, a $.path string,
     * or a value descriptor object {type: '...', ...}.
     *
     * @param mixed $value The raw parameter value from workflow config
     * @param array $context The workflow instance context
     * @param mixed $default Fallback if resolution fails
     * @return mixed The resolved value
     */
    protected function resolveParamValue(mixed $value, array $context, mixed $default = null): mixed
    {
        if ($value === null || $value === '') {
            return $default;
        }

        // Plain scalar (not a string starting with $.)
        if (is_scalar($value) && !(is_string($value) && str_starts_with($value, '$.'))) {
            return $value;
        }

        // Shorthand: string starting with $. is a context path
        if (is_string($value) && str_starts_with($value, '$.')) {
            $resolved = $this->resolveContextValue($context, $value);

            return $resolved ?? $default;
        }

        // Value descriptor object
        if (is_array($value)) {
            $type = $value['type'] ?? 'fixed';
            switch ($type) {
                case 'fixed':
                    return $value['value'] ?? $default;

                case 'context':
                    $path = $value['path'] ?? '';
                    if ($path) {
                        $resolved = $this->resolveContextValue($context, $path);

                        return $resolved ?? ($value['default'] ?? $default);
                    }

                    return $value['default'] ?? $default;

                case 'app_setting':
                    $key = $value['key'] ?? '';
                    if ($key) {
                        $settingsTable = TableRegistry::getTableLocator()->get('AppSettings');
                        $setting = $settingsTable->find()->where(['name' => $key])->first();
                        if ($setting) {
                            return $setting->value;
                        }
                    }

                    return $value['default'] ?? $default;

                default:
                    Log::warning("WorkflowEngine: Unknown value resolution type '{$type}'");

                    return $default;
            }
        }

        return $default;
    }

    /**
     * Resolve a required count value that may be an integer or a config object.
     *
     * Delegates to resolveParamValue() for universal resolution, then ensures
     * the result is an integer >= 1.
     */
    protected function resolveRequiredCount(mixed $value, array $context): int
    {
        $resolved = $this->resolveParamValue($value, $context, 1);

        return max(1, (int)$resolved);
    }

    /**
     * Parse a deadline duration string (e.g., "14d", "24h", "7d") into a future DateTime.
     */
    protected function parseDeadline(string $deadline): ?DateTime
    {
        if (preg_match('/^(\d+)([dhm])$/i', $deadline, $matches)) {
            $amount = (int)$matches[1];
            $unit = strtolower($matches[2]);
            $now = DateTime::now();

            switch ($unit) {
                case 'd':
                    return $now->modify("+{$amount} days");
                case 'h':
                    return $now->modify("+{$amount} hours");
                case 'm':
                    return $now->modify("+{$amount} minutes");
            }
        }

        // Try parsing as a standard datetime string
        try {
            return new DateTime($deadline);
        } catch (\Exception $e) {
            Log::warning("Could not parse deadline: {$deadline}");
            return null;
        }
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

        // Check top-level edges array (if present)
        $edges = $definition['edges'] ?? [];
        foreach ($edges as $edge) {
            $edgeSource = $edge['source'] ?? $edge['from'] ?? null;
            $edgePort = $edge['sourcePort'] ?? $edge['port'] ?? 'default';
            $edgeTarget = $edge['target'] ?? $edge['to'] ?? null;

            if ($edgeSource === $nodeId && $this->portsMatch($edgePort, $port) && $edgeTarget !== null) {
                $targets[] = $edgeTarget;
            }
        }

        // Check per-node outputs (primary format used by designer/seed data)
        $node = $definition['nodes'][$nodeId] ?? null;
        if ($node && !empty($node['outputs'])) {
            foreach ($node['outputs'] as $output) {
                $outputPort = $output['port'] ?? 'default';
                $outputTarget = $output['target'] ?? null;

                if ($this->portsMatch($outputPort, $port) && $outputTarget !== null) {
                    $targets[] = $outputTarget;
                }
            }
        }

        return array_unique($targets);
    }

    /**
     * Check if two port names are equivalent.
     * Treats "next" and "default" as equivalent for regular action/trigger outputs.
     */
    protected function portsMatch(string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }
        // Normalize output-N ports (legacy designer naming) to 'default'
        if (preg_match('/^output-\d+$/', $a)) {
            $a = 'default';
        }
        if (preg_match('/^output-\d+$/', $b)) {
            $b = 'default';
        }
        $defaultAliases = ['default', 'next'];
        return in_array($a, $defaultAliases) && in_array($b, $defaultAliases);
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

        // Check top-level edges
        $edges = $definition['edges'] ?? [];
        foreach ($edges as $edge) {
            $edgeSource = $edge['source'] ?? $edge['from'] ?? null;
            $edgeTarget = $edge['target'] ?? $edge['to'] ?? null;

            if ($edgeSource === $nodeId && $edgeTarget !== null) {
                $targets[] = $edgeTarget;
            }
        }

        // Check per-node outputs
        $node = $definition['nodes'][$nodeId] ?? null;
        if ($node && !empty($node['outputs'])) {
            foreach ($node['outputs'] as $output) {
                $outputTarget = $output['target'] ?? null;
                if ($outputTarget !== null) {
                    $targets[] = $outputTarget;
                }
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
