<?php

declare(strict_types=1);

namespace App\Controller;

use App\Services\ServiceResult;
use App\Services\WorkflowEngine\WorkflowApprovalManagerInterface;
use App\Services\WorkflowEngine\WorkflowEngineInterface;
use App\Services\WorkflowEngine\WorkflowVersionManagerInterface;
use App\Services\WorkflowRegistry\WorkflowActionRegistry;
use App\Services\WorkflowRegistry\WorkflowConditionRegistry;
use App\Services\WorkflowRegistry\WorkflowEntityRegistry;
use App\Services\WorkflowRegistry\WorkflowTriggerRegistry;
use App\Model\Entity\WorkflowInstance;
use Cake\Controller\ComponentRegistry;
use Cake\Http\ServerRequest;

/**
 * Workflows Controller
 *
 * Manages workflow definitions, visual designer, versioning,
 * instance monitoring, approvals, and execution logs.
 *
 * @property \App\Model\Table\WorkflowDefinitionsTable $WorkflowDefinitions
 */
class WorkflowsController extends AppController
{
    use DataverseGridTrait;
    protected ?string $defaultTable = 'WorkflowDefinitions';

    private WorkflowEngineInterface $engine;
    private WorkflowVersionManagerInterface $versionManager;
    private WorkflowApprovalManagerInterface $approvalManager;

    public function __construct(
        ServerRequest $request,
        WorkflowEngineInterface $engine,
        WorkflowVersionManagerInterface $versionManager,
        WorkflowApprovalManagerInterface $approvalManager,
        ?ComponentRegistry $components = null,
    ) {
        parent::__construct($request, null, null, $components);
        $this->engine = $engine;
        $this->versionManager = $versionManager;
        $this->approvalManager = $approvalManager;
    }

    public function initialize(): void
    {
        parent::initialize();
        $this->Authorization->authorizeModel(
            'index',
            'designer',
            'loadVersion',
            'registry',
            'save',
            'publish',
            'add',
            'instances',
            'viewInstance',
            'versions',
            'compareVersions',
            'toggleActive',
            'createDraft',
            'migrateInstances',
            'approvals',
            'recordApproval'
        );
    }

    /**
     * List all workflow definitions.
     *
     * @return \Cake\Http\Response|null|void
     */
    public function index()
    {
        $workflows = $this->fetchTable('WorkflowDefinitions')->find()
            ->contain(['CurrentVersion'])
            ->where(['WorkflowDefinitions.deleted IS' => null])
            ->orderBy(['WorkflowDefinitions.name' => 'ASC'])
            ->all();
        $this->set(compact('workflows'));
    }

    /**
     * Visual workflow designer page.
     *
     * @param int|null $id Workflow definition ID
     * @return \Cake\Http\Response|null|void
     */
    public function designer(?int $id = null)
    {
        $definitionsTable = $this->fetchTable('WorkflowDefinitions');
        $versionsTable = $this->fetchTable('WorkflowVersions');

        if ($id) {
            $workflow = $definitionsTable->get($id, contain: ['CurrentVersion']);
            $draftVersion = $versionsTable->find()
                ->where(['workflow_definition_id' => $id, 'status' => 'draft'])
                ->first();

            if (!$draftVersion && $workflow->current_version) {
                $versionManager = $this->getVersionManager();
                $result = $versionManager->createDraft(
                    $id,
                    $workflow->current_version->definition ?? ['nodes' => []],
                    $workflow->current_version->canvas_layout,
                    'Cloned from v' . $workflow->current_version->version_number
                );
                if ($result->isSuccess()) {
                    $draftVersion = $versionsTable->get($result->data['versionId']);
                }
            }
        } else {
            $workflow = null;
            $draftVersion = null;
        }

        $this->set(compact('workflow', 'draftVersion'));
    }

    /**
     * API: Return a workflow version's definition as JSON.
     *
     * @param int $versionId Version ID
     * @return \Cake\Http\Response|null|void
     */
    public function loadVersion(int $versionId)
    {
        $this->request->allowMethod(['get']);
        $versionsTable = $this->fetchTable('WorkflowVersions');
        $version = $versionsTable->get($versionId);

        $data = [
            'definition' => $version->definition ?? ['nodes' => []],
            'canvasLayout' => $version->canvas_layout,
        ];
        $this->set('data', $data);
        $this->viewBuilder()->setOption('serialize', 'data');
        $this->response = $this->response->withType('application/json');
        $this->viewBuilder()->setClassName('Json');
    }

    /**
     * API: Return registry data for the designer palette.
     *
     * @return \Cake\Http\Response|null|void
     */
    public function registry()
    {
        $this->request->allowMethod(['get']);
        $data = [
            'triggers' => WorkflowTriggerRegistry::getForDesigner(),
            'actions' => WorkflowActionRegistry::getForDesigner(),
            'conditions' => WorkflowConditionRegistry::getForDesigner(),
            'entities' => WorkflowEntityRegistry::getForDesigner(),
            'approvalOutputSchema' => WorkflowActionRegistry::APPROVAL_OUTPUT_SCHEMA,
            'builtinContext' => [
                ['path' => '$.instance.id', 'label' => 'Instance ID', 'type' => 'integer'],
                ['path' => '$.instance.created', 'label' => 'Instance Created', 'type' => 'datetime'],
                ['path' => '$.triggeredBy', 'label' => 'Triggered By (member ID)', 'type' => 'integer'],
            ],
        ];
        $this->set('data', $data);
        $this->viewBuilder()->setOption('serialize', 'data');
        $this->response = $this->response->withType('application/json');
        $this->viewBuilder()->setClassName('Json');
    }

    /**
     * API: Save a workflow definition and draft version.
     *
     * @return \Cake\Http\Response|null|void
     */
    public function save()
    {
        $this->request->allowMethod(['post', 'put']);
        $data = $this->request->getData();
        $definitionsTable = $this->fetchTable('WorkflowDefinitions');

        $versionManager = $this->getVersionManager();

        if (!empty($data['workflowId'])) {
            $workflowId = (int)$data['workflowId'];
            if (!empty($data['versionId'])) {
                $result = $versionManager->updateDraft(
                    (int)$data['versionId'],
                    $data['definition'] ?? [],
                    $data['canvasLayout'] ?? null,
                    $data['changeNotes'] ?? null
                );
            } else {
                $result = $versionManager->createDraft(
                    $workflowId,
                    $data['definition'] ?? [],
                    $data['canvasLayout'] ?? null,
                    $data['changeNotes'] ?? null
                );
            }
        } else {
            $workflow = $definitionsTable->newEntity([
                'name' => $data['name'] ?? 'New Workflow',
                'slug' => $data['slug'] ?? 'workflow-' . time(),
                'description' => $data['description'] ?? '',
                'trigger_type' => $data['triggerType'] ?? 'event',
                'trigger_config' => $data['triggerConfig'] ?? null,
                'entity_type' => $data['entityType'] ?? null,
            ]);
            if ($definitionsTable->save($workflow)) {
                $result = $versionManager->createDraft(
                    $workflow->id,
                    $data['definition'] ?? ['nodes' => []],
                    $data['canvasLayout'] ?? null,
                    'Initial draft'
                );
                $result->data['workflowId'] = $workflow->id;
            } else {
                $result = new ServiceResult(false, 'Failed to create workflow definition');
            }
        }

        return $this->buildServiceResultResponse($result);
    }

    /**
     * API: Publish a draft workflow version.
     *
     * @return \Cake\Http\Response|null|void
     */
    public function publish()
    {
        $this->request->allowMethod(['post']);
        $versionId = (int)$this->request->getData('versionId');
        $currentUser = $this->request->getAttribute('identity');

        $versionManager = $this->getVersionManager();
        $result = $versionManager->publish($versionId, $currentUser->id);

        return $this->buildServiceResultResponse($result);
    }

    /**
     * Form to create a new workflow definition.
     *
     * @return \Cake\Http\Response|null|void
     */
    public function add()
    {
        $definitionsTable = $this->fetchTable('WorkflowDefinitions');
        $workflow = $definitionsTable->newEmptyEntity();

        if ($this->request->is('post')) {
            $workflow = $definitionsTable->patchEntity($workflow, $this->request->getData());
            if ($definitionsTable->save($workflow)) {
                $this->Flash->success(__('Workflow definition created.'));

                return $this->redirect(['action' => 'designer', $workflow->id]);
            }
            $this->Flash->error(__('Could not save workflow definition.'));
        }
        $this->set(compact('workflow'));
    }

    /**
     * List workflow instances, optionally filtered by definition.
     *
     * @param int|null $definitionId Filter by workflow definition
     * @return \Cake\Http\Response|null|void
     */
    public function instances(?int $definitionId = null)
    {
        $query = $this->fetchTable('WorkflowInstances')->find()
            ->contain(['WorkflowDefinitions', 'WorkflowVersions'])
            ->orderBy(['WorkflowInstances.created' => 'DESC']);

        if ($definitionId) {
            $query->where(['WorkflowInstances.workflow_definition_id' => $definitionId]);
        }

        $instances = $this->paginate($query, ['limit' => 25]);
        $this->set(compact('instances', 'definitionId'));
    }

    /**
     * View a single workflow instance with execution log and approvals.
     *
     * @param int $id Instance ID
     * @return \Cake\Http\Response|null|void
     */
    public function viewInstance(int $id)
    {
        $instance = $this->fetchTable('WorkflowInstances')->get($id, contain: [
            'WorkflowDefinitions',
            'WorkflowVersions',
            'WorkflowExecutionLogs' => ['sort' => ['WorkflowExecutionLogs.created' => 'ASC']],
            'WorkflowApprovals' => ['WorkflowApprovalResponses' => ['Members']],
        ]);
        $this->set(compact('instance'));
    }

    /**
     * Approval dashboard entry point.
     *
     * Renders the approvals page shell. The DataverseGrid lazy-loads
     * actual data via approvalsGridData().
     *
     * @return \Cake\Http\Response|null|void
     */
    public function approvals()
    {
        // Page shell only — grid lazy-loads via approvalsGridData
    }

    /**
     * Grid data endpoint for the My Approvals DataverseGrid.
     *
     * Two system views:
     *  - Pending: eligible approvals via approval manager eligibility logic
     *  - Decisions: resolved approvals the current user responded to
     *
     * @return \Cake\Http\Response|null|void
     */
    public function approvalsGridData()
    {
        $this->Authorization->skipAuthorization();

        $currentUser = $this->request->getAttribute('identity');
        $approvalManager = $this->getApprovalManager();
        $approvalsTable = $this->fetchTable('WorkflowApprovals');

        // Base query with workflow context for the workflow_name column
        $baseQuery = $approvalsTable->find()
            ->contain(['WorkflowInstances' => ['WorkflowDefinitions']]);

        // System views
        $systemViews = \App\KMP\GridColumns\ApprovalsGridColumns::getSystemViews();

        // Query callback scopes data to current user based on selected system view
        $queryCallback = function ($query, $systemView) use ($currentUser, $approvalManager) {
            if ($systemView === null) {
                // No view selected — return empty result set
                $query->where(['WorkflowApprovals.id' => -1]);
                return $query;
            }

            if ($systemView['id'] === 'sys-approvals-pending') {
                // Get eligible approval IDs via complex eligibility logic,
                // then let the trait handle filtering/sorting/pagination
                $eligible = $approvalManager->getPendingApprovalsForMember($currentUser->id);
                $eligibleIds = array_map(fn($a) => $a->id, $eligible);

                if (empty($eligibleIds)) {
                    $query->where(['WorkflowApprovals.id' => -1]);
                } else {
                    $query->where(['WorkflowApprovals.id IN' => $eligibleIds]);
                }
            } elseif ($systemView['id'] === 'sys-approvals-decisions') {
                // Approvals this user has responded to (unique constraint prevents duplicates)
                $query->innerJoinWith('WorkflowApprovalResponses', function ($q) use ($currentUser) {
                    return $q->where(['WorkflowApprovalResponses.member_id' => $currentUser->id]);
                });
            }

            return $query;
        };

        $result = $this->processDataverseGrid([
            'gridKey' => 'Workflows.approvals.main',
            'gridColumnsClass' => \App\KMP\GridColumns\ApprovalsGridColumns::class,
            'baseQuery' => $baseQuery,
            'tableName' => 'WorkflowApprovals',
            'defaultSort' => ['WorkflowApprovals.modified' => 'desc'],
            'defaultPageSize' => 25,
            'systemViews' => $systemViews,
            'defaultSystemView' => 'sys-approvals-pending',
            'queryCallback' => $queryCallback,
            'showAllTab' => false,
            'canAddViews' => false,
            'canFilter' => true,
            'canExportCsv' => false,
            'lockedFilters' => ['status_label'],
            'showFilterPills' => true,
            'showSearchBox' => true,
        ]);

        // Enrich paginated rows with virtual fields
        foreach ($result['data'] as $approval) {
            // Workflow name (from contained relation)
            $approval->workflow_name = $approval->workflow_instance?->workflow_definition?->name ?? __('Unknown');

            // Status label: "Pending (X/Y)" for pending, ucfirst for resolved
            if ($approval->status === \App\Model\Entity\WorkflowApproval::STATUS_PENDING) {
                $approval->status_label = __('Pending ({0}/{1})', $approval->approved_count, $approval->required_count);
            } else {
                $approval->status_label = ucfirst($approval->status);
            }

            // Request: entity name from workflow instance context
            $instance = $approval->workflow_instance;
            if ($instance) {
                $entityContext = $this->resolveEntityContext($instance);
                $approval->request = $entityContext['entityName'] ?? "#{$instance->entity_id}";
            } else {
                $approval->request = '—';
            }
        }

        // Set view variables (following WarrantRostersController pattern)
        $this->set([
            'data' => $result['data'],
            'gridState' => $result['gridState'],
            'columns' => $result['columnsMetadata'],
            'visibleColumns' => $result['visibleColumns'],
            'searchableColumns' => \App\KMP\GridColumns\ApprovalsGridColumns::getSearchableColumns(),
            'dropdownFilterColumns' => $result['dropdownFilterColumns'],
            'filterOptions' => $result['filterOptions'],
            'currentFilters' => $result['currentFilters'],
            'currentSearch' => $result['currentSearch'],
            'currentView' => $result['currentView'],
            'availableViews' => $result['availableViews'],
            'gridKey' => $result['gridKey'],
            'currentSort' => $result['currentSort'],
            'currentMember' => $result['currentMember'],
        ]);

        // Determine template based on Turbo-Frame header
        $turboFrame = $this->request->getHeaderLine('Turbo-Frame');

        if ($turboFrame === 'approvals-grid-table') {
            $this->set('tableFrameId', 'approvals-grid-table');
            $this->viewBuilder()->disableAutoLayout();
            $this->viewBuilder()->setTemplate('../element/dv_grid_table');
        } else {
            $this->set('frameId', 'approvals-grid');
            $this->viewBuilder()->disableAutoLayout();
            $this->viewBuilder()->setTemplate('../element/dv_grid_content');
        }
    }

    /**
     * Resolve entity details from workflow instance for display.
     */
    private function resolveEntityContext(WorkflowInstance $instance): array
    {
        $details = [
            'entityType' => $instance->entity_type,
            'entityId' => $instance->entity_id,
            'startedBy' => null,
            'entityName' => null,
        ];

        // Resolve who started the workflow
        if ($instance->started_by) {
            $member = $this->fetchTable('Members')->find()
                ->where(['id' => $instance->started_by])
                ->first();
            if ($member) {
                $details['startedBy'] = $member->sca_name ?? $member->email_address;
            }
        }

        // Resolve entity name based on type
        if ($instance->entity_type && $instance->entity_id) {
            try {
                $table = \Cake\ORM\TableRegistry::getTableLocator()->get($instance->entity_type);
                $entity = $table->find()->where(['id' => $instance->entity_id])->first();
                if ($entity) {
                    $details['entityName'] = $entity->name ?? $entity->sca_name ?? "#{$instance->entity_id}";
                }
            } catch (\Exception $e) {
                // Table doesn't exist or other error — skip
            }
        }

        return $details;
    }

    /**
     * API: Record an approval response and optionally resume workflow.
     *
     * @return \Cake\Http\Response|null|void
     */
    public function recordApproval()
    {
        $this->request->allowMethod(['post']);
        $approvalId = (int)$this->request->getData('approvalId');
        $decision = $this->request->getData('decision');
        $comment = $this->request->getData('comment');
        $currentUser = $this->request->getAttribute('identity');

        // Eligibility (including policy checks) is enforced inside recordResponse()
        $approvalManager = $this->getApprovalManager();
        $result = $approvalManager->recordResponse($approvalId, $currentUser->id, $decision, $comment);

        if ($result->isSuccess() && $result->getData()) {
            $data = $result->getData();
            if (in_array($data['approvalStatus'] ?? '', ['approved', 'rejected'])) {
                $engine = $this->getWorkflowEngine();
                $outputPort = $data['approvalStatus'] === 'approved' ? 'approved' : 'rejected';
                $engine->resumeWorkflow(
                    $data['instanceId'],
                    $data['nodeId'],
                    $outputPort,
                    [
                        'approval' => $data,
                        'approverId' => $currentUser->id,
                        'decision' => $decision,
                        'comment' => $comment,
                    ]
                );
            }
        }

        if ($this->request->is('ajax')) {
            $this->set('result', $result);
            $this->viewBuilder()->setOption('serialize', 'result');
            $this->response = $this->response->withType('application/json');
            $this->viewBuilder()->setClassName('Json');
        } else {
            if ($result->isSuccess()) {
                $this->Flash->success(__('Approval response recorded.'));
            } else {
                $this->Flash->error($result->getError());
            }

            return $this->redirect(['action' => 'approvals']);
        }
    }

    /**
     * Show version history for a workflow definition.
     *
     * @param int $definitionId Workflow definition ID
     * @return \Cake\Http\Response|null|void
     */
    public function versions(int $definitionId)
    {
        $workflow = $this->fetchTable('WorkflowDefinitions')->get($definitionId);
        $versionManager = $this->getVersionManager();
        $versions = $versionManager->getVersionHistory($definitionId);
        $this->set(compact('workflow', 'versions'));
    }

    /**
     * API: Compare two workflow versions and return their diff.
     *
     * @return \Cake\Http\Response|null|void
     */
    public function compareVersions()
    {
        $this->request->allowMethod(['get']);
        $v1 = (int)$this->request->getQuery('v1');
        $v2 = (int)$this->request->getQuery('v2');
        $versionManager = $this->getVersionManager();
        $diff = $versionManager->compareVersions($v1, $v2);
        $this->set('diff', $diff);
        $this->viewBuilder()->setOption('serialize', 'diff');
        $this->response = $this->response->withType('application/json');
        $this->viewBuilder()->setClassName('Json');
    }

    /**
     * API: Toggle a workflow definition's is_active flag.
     *
     * @param int $id Workflow definition ID
     * @return \Cake\Http\Response|null|void
     */
    public function toggleActive(int $id)
    {
        $this->request->allowMethod(['post']);
        $definitionsTable = $this->fetchTable('WorkflowDefinitions');
        $workflow = $definitionsTable->get($id);
        $workflow->is_active = !$workflow->is_active;
        $definitionsTable->save($workflow);

        if ($this->request->is('ajax')) {
            $result = ['success' => true, 'is_active' => $workflow->is_active];
            $this->set('result', $result);
            $this->viewBuilder()->setOption('serialize', 'result');
            $this->response = $this->response->withType('application/json');
            $this->viewBuilder()->setClassName('Json');
        } else {
            $this->Flash->success(__('Workflow status updated.'));

            return $this->redirect(['action' => 'index']);
        }
    }

    /**
     * API: Create a new draft version from the current published version.
     *
     * @return \Cake\Http\Response|null|void
     */
    public function createDraft()
    {
        $this->request->allowMethod(['post']);
        $workflowId = (int)$this->request->getData('workflowId');
        $definitionsTable = $this->fetchTable('WorkflowDefinitions');
        $workflow = $definitionsTable->get($workflowId, contain: ['CurrentVersion']);

        $versionManager = $this->getVersionManager();
        $definition = $workflow->current_version->definition ?? ['nodes' => []];
        $canvasLayout = $workflow->current_version->canvas_layout ?? null;
        $result = $versionManager->createDraft(
            $workflowId,
            $definition,
            $canvasLayout,
            'Cloned from v' . ($workflow->current_version->version_number ?? '0')
        );

        return $this->buildServiceResultResponse($result);
    }

    /**
     * API: Migrate running instances to a specified version via VersionManager
     * for proper node remapping and audit trail.
     *
     * @return \Cake\Http\Response|null|void
     */
    public function migrateInstances()
    {
        $this->request->allowMethod(['post']);
        $versionId = (int)$this->request->getData('versionId');
        $instancesTable = $this->fetchTable('WorkflowInstances');
        $version = $this->fetchTable('WorkflowVersions')->get($versionId);
        $currentUser = $this->request->getAttribute('identity');

        $instances = $instancesTable->find()
            ->where([
                'workflow_definition_id' => $version->workflow_definition_id,
                'status IN' => ['active', 'waiting'],
            ])
            ->all();

        $versionManager = $this->getVersionManager();
        $migrated = 0;
        $errors = [];

        foreach ($instances as $instance) {
            $migrationResult = $versionManager->migrateInstance(
                $instance->id,
                $versionId,
                $currentUser->id,
            );
            if ($migrationResult->isSuccess()) {
                $migrated++;
            } else {
                $errors[] = "Instance {$instance->id}: " . $migrationResult->getError();
            }
        }

        $result = [
            'success' => empty($errors),
            'message' => __('Migrated {0} running instance(s) to version {1}.', $migrated, $version->version_number),
            'errors' => $errors,
        ];
        $this->set('result', $result);
        $this->viewBuilder()->setOption('serialize', 'result');
        $this->response = $this->response->withType('application/json');
        $this->viewBuilder()->setClassName('Json');
    }

    /**
     * Build a flat JSON response from a ServiceResult.
     *
     * Returns flat keys (success, reason, plus any data fields) instead of
     * nested {result: {data: {}}} so the frontend can read them directly.
     */
    private function buildServiceResultResponse(ServiceResult $result): \Cake\Http\Response
    {
        $responseData = ['success' => $result->success];
        if ($result->reason) {
            $responseData['reason'] = $result->reason;
        }
        if ($result->data) {
            $responseData = array_merge($responseData, (array)$result->data);
        }
        $response = $this->response->withType('application/json')
            ->withStringBody(json_encode($responseData));
        if (!$result->success) {
            $response = $response->withStatus(422);
        }

        return $response;
    }

    private function getWorkflowEngine(): WorkflowEngineInterface
    {
        return $this->engine;
    }

    private function getVersionManager(): WorkflowVersionManagerInterface
    {
        return $this->versionManager;
    }

    private function getApprovalManager(): WorkflowApprovalManagerInterface
    {
        return $this->approvalManager;
    }

    /**
     * Return JSON list of entity policy classes in the system.
     *
     * @return void
     */
    public function policyClasses()
    {
        $this->Authorization->skipAuthorization();
        $this->request->allowMethod(['get']);

        $results = [];

        // Scan app/src/Policy/
        $appPolicyDir = APP . 'Policy' . DS;
        if (is_dir($appPolicyDir)) {
            foreach (glob($appPolicyDir . '*Policy.php') as $file) {
                $className = basename($file, '.php');
                if ($this->isEntityPolicy($className)) {
                    $fqcn = 'App\\Policy\\' . $className;
                    $results[] = [
                        'class' => $fqcn,
                        'label' => $this->policyLabel($className),
                    ];
                }
            }
        }

        // Scan plugins/*/src/Policy/
        $pluginsDir = ROOT . DS . 'plugins' . DS;
        if (is_dir($pluginsDir)) {
            foreach (glob($pluginsDir . '*/src/Policy/*Policy.php') as $file) {
                $className = basename($file, '.php');
                if ($this->isEntityPolicy($className)) {
                    // Derive plugin name from path
                    $relative = str_replace($pluginsDir, '', $file);
                    $pluginName = explode(DS, $relative)[0];
                    $fqcn = $pluginName . '\\Policy\\' . $className;
                    $results[] = [
                        'class' => $fqcn,
                        'label' => $this->policyLabel($className),
                    ];
                }
            }
        }

        sort($results);
        $this->set('policyClasses', $results);
        $this->viewBuilder()->setOption('serialize', ['policyClasses']);
        $this->response = $this->response->withType('application/json');
        $this->viewBuilder()->setClassName('Json');
    }

    /**
     * Return JSON list of public 'can*' methods for a given policy class.
     *
     * @return void
     */
    public function policyActions()
    {
        $this->Authorization->skipAuthorization();
        $this->request->allowMethod(['get']);

        $className = $this->request->getQuery('class');
        $results = [];

        if (
            $className
            && class_exists($className)
            && str_ends_with($className, 'Policy')
            && str_contains($className, '\\Policy\\')
        ) {
            $reflection = new \ReflectionClass($className);
            foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if (
                    str_starts_with($method->getName(), 'can')
                    && $method->getDeclaringClass()->getName() === $className
                ) {
                    $action = $method->getName();
                    $results[] = [
                        'action' => $action,
                        'label' => $this->actionLabel($action),
                    ];
                }
            }
        }

        $this->set('policyActions', $results);
        $this->viewBuilder()->setOption('serialize', ['policyActions']);
        $this->response = $this->response->withType('application/json');
        $this->viewBuilder()->setClassName('Json');
    }

    /**
     * Check if a policy class name is an entity policy (not base, table, or controller).
     */
    private function isEntityPolicy(string $className): bool
    {
        if ($className === 'BasePolicy') {
            return false;
        }
        if (str_ends_with($className, 'TablePolicy')) {
            return false;
        }
        if (str_ends_with($className, 'ControllerPolicy')) {
            return false;
        }

        return true;
    }

    /**
     * Convert PascalCase policy class name to human-readable label.
     */
    private function policyLabel(string $className): string
    {
        // Split PascalCase into words
        return trim(preg_replace('/([a-z])([A-Z])/', '$1 $2', $className));
    }

    /**
     * Return JSON list of app settings for the workflow designer.
     *
     * Used by the approval node to populate the app_setting dropdown
     * for dynamic requiredCount configuration.
     *
     * @return void
     */
    public function appSettings(): void
    {
        $this->Authorization->skipAuthorization();
        $this->request->allowMethod(['get']);

        $settingsTable = $this->fetchTable('AppSettings');
        $settings = $settingsTable->find()
            ->select(['name', 'value', 'type'])
            ->orderBy(['name' => 'ASC'])
            ->all();

        $results = [];
        foreach ($settings as $setting) {
            $results[] = [
                'name' => $setting->name,
                'value' => $setting->value,
                'type' => $setting->type,
            ];
        }

        $this->set('appSettings', $results);
        $this->viewBuilder()->setOption('serialize', ['appSettings']);
        $this->response = $this->response->withType('application/json');
        $this->viewBuilder()->setClassName('Json');
    }

    /**
     * Convert camelCase action name to human-readable label.
     */
    private function actionLabel(string $action): string
    {
        // "canApprove" → "Can Approve"
        return trim(ucfirst(preg_replace('/([a-z])([A-Z])/', '$1 $2', $action)));
    }
}
