<?php

declare(strict_types=1);

namespace App\Controller;

use App\Services\WorkflowEngine\WorkflowEngineInterface;
use Cake\Controller\ComponentRegistry;
use Cake\Http\ServerRequest;

/**
 * WorkflowInstances Controller
 *
 * Manages workflow instance monitoring and viewing.
 *
 * @property \App\Model\Table\WorkflowInstancesTable $WorkflowInstances
 */
class WorkflowInstancesController extends AppController
{
    protected ?string $defaultTable = 'WorkflowInstances';

    private WorkflowEngineInterface $engine;

    public function __construct(
        ServerRequest $request,
        WorkflowEngineInterface $engine,
        ?ComponentRegistry $components = null,
    ) {
        parent::__construct($request, null, null, $components);
        $this->engine = $engine;
    }

    public function initialize(): void
    {
        parent::initialize();
        $this->Authorization->authorizeModel(
            'instances',
            'viewInstance',
        );
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
}
