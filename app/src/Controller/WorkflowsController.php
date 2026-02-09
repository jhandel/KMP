<?php

declare(strict_types=1);

namespace App\Controller;

use App\Services\ServiceResult;
use App\Services\WorkflowEngine\DefaultWorkflowApprovalManager;
use App\Services\WorkflowEngine\DefaultWorkflowEngine;
use App\Services\WorkflowEngine\DefaultWorkflowVersionManager;
use App\Services\WorkflowEngine\WorkflowApprovalManagerInterface;
use App\Services\WorkflowEngine\WorkflowEngineInterface;
use App\Services\WorkflowEngine\WorkflowVersionManagerInterface;
use App\Services\WorkflowRegistry\WorkflowActionRegistry;
use App\Services\WorkflowRegistry\WorkflowConditionRegistry;
use App\Services\WorkflowRegistry\WorkflowEntityRegistry;
use App\Services\WorkflowRegistry\WorkflowTriggerRegistry;

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
    protected ?string $defaultTable = 'WorkflowDefinitions';

    public function initialize(): void
    {
        parent::initialize();
        $this->Authorization->authorizeModel(
            'index',
            'designer',
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

        $this->set('result', $result);
        $this->viewBuilder()->setOption('serialize', 'result');
        $this->response = $this->response->withType('application/json');
        $this->viewBuilder()->setClassName('Json');
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

        $this->set('result', $result);
        $this->viewBuilder()->setOption('serialize', 'result');
        $this->response = $this->response->withType('application/json');
        $this->viewBuilder()->setClassName('Json');
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

        $instances = $query->limit(100)->all();
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
            'WorkflowApprovals' => ['WorkflowApprovalResponses'],
        ]);
        $this->set(compact('instance'));
    }

    /**
     * Approval dashboard: pending approvals and recent decisions.
     *
     * @return \Cake\Http\Response|null|void
     */
    public function approvals()
    {
        $currentUser = $this->request->getAttribute('identity');
        $approvalManager = $this->getApprovalManager();
        $pendingApprovals = $approvalManager->getPendingApprovalsForMember($currentUser->id);

        $recentApprovals = $this->fetchTable('WorkflowApprovals')->find()
            ->contain(['WorkflowInstances' => ['WorkflowDefinitions'], 'WorkflowApprovalResponses'])
            ->where(['WorkflowApprovals.status !=' => 'pending'])
            ->orderBy(['WorkflowApprovals.modified' => 'DESC'])
            ->limit(50)
            ->all();

        $this->set(compact('pendingApprovals', 'recentApprovals'));
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
                    ['approval' => $data]
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

        $this->set('result', $result);
        $this->viewBuilder()->setOption('serialize', 'result');
        $this->response = $this->response->withType('application/json');
        $this->viewBuilder()->setClassName('Json');
    }

    /**
     * API: Migrate running instances to a specified version.
     *
     * @return \Cake\Http\Response|null|void
     */
    public function migrateInstances()
    {
        $this->request->allowMethod(['post']);
        $versionId = (int)$this->request->getData('versionId');
        $instancesTable = $this->fetchTable('WorkflowInstances');
        $version = $this->fetchTable('WorkflowVersions')->get($versionId);

        $updated = $instancesTable->updateAll(
            ['workflow_version_id' => $versionId],
            [
                'workflow_definition_id' => $version->workflow_definition_id,
                'status IN' => ['active', 'waiting'],
            ]
        );

        $result = [
            'success' => true,
            'message' => __('Migrated {0} running instance(s) to version {1}.', $updated, $version->version_number),
        ];
        $this->set('result', $result);
        $this->viewBuilder()->setOption('serialize', 'result');
        $this->response = $this->response->withType('application/json');
        $this->viewBuilder()->setClassName('Json');
    }

    private function getWorkflowEngine(): WorkflowEngineInterface
    {
        return new DefaultWorkflowEngine();
    }

    private function getVersionManager(): WorkflowVersionManagerInterface
    {
        return new DefaultWorkflowVersionManager();
    }

    private function getApprovalManager(): WorkflowApprovalManagerInterface
    {
        return new DefaultWorkflowApprovalManager();
    }
}
