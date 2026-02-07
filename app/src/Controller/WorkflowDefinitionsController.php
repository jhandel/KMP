<?php

declare(strict_types=1);

namespace App\Controller;

/**
 * WorkflowDefinitions Controller
 *
 * Admin CRUD for workflow definitions and visual editor page.
 */
class WorkflowDefinitionsController extends AppController
{
    public function initialize(): void
    {
        parent::initialize();
    }

    /**
     * List all workflow definitions.
     *
     * @return void
     */
    public function index()
    {
        $definitions = $this->paginate(
            $this->fetchTable('WorkflowDefinitions')->find()
                ->orderBy(['name' => 'ASC'])
        );
        $this->set(compact('definitions'));
    }

    /**
     * Create a new workflow definition.
     *
     * @return \Cake\Http\Response|null|void
     */
    public function add()
    {
        $table = $this->fetchTable('WorkflowDefinitions');
        $definition = $table->newEmptyEntity();

        if ($this->request->is('post')) {
            $definition = $table->patchEntity($definition, $this->request->getData());
            if ($table->save($definition)) {
                $this->Flash->success(__('Workflow definition created.'));

                return $this->redirect(['action' => 'editor', $definition->id]);
            }
            $this->Flash->error(__('Could not save workflow definition.'));
        }
        $this->set(compact('definition'));
    }

    /**
     * Visual editor for a workflow definition.
     *
     * @param string|null $id Workflow definition ID.
     * @return void
     */
    public function editor($id = null)
    {
        $table = $this->fetchTable('WorkflowDefinitions');
        $definition = $table->get($id, contain: [
            'WorkflowStates' => ['WorkflowVisibilityRules', 'WorkflowApprovalGates'],
            'WorkflowTransitions' => ['FromState', 'ToState'],
        ]);
        $this->set(compact('definition'));
    }

    /**
     * Display workflow analytics with instance and transition counts.
     *
     * @return void
     */
    public function analytics()
    {
        $definitionsTable = $this->fetchTable('WorkflowDefinitions');
        $instancesTable = $this->fetchTable('WorkflowInstances');
        $logsTable = $this->fetchTable('WorkflowTransitionLogs');

        $definitions = $definitionsTable->find()->all();

        $stats = [];
        foreach ($definitions as $def) {
            $activeCount = $instancesTable->find()
                ->where(['workflow_definition_id' => $def->id, 'completed_at IS' => null])
                ->count();
            $completedCount = $instancesTable->find()
                ->where(['workflow_definition_id' => $def->id, 'completed_at IS NOT' => null])
                ->count();
            $transitionCount = $logsTable->find()
                ->innerJoinWith('WorkflowInstances')
                ->where(['WorkflowInstances.workflow_definition_id' => $def->id])
                ->count();

            $stats[] = [
                'definition' => $def,
                'active_instances' => $activeCount,
                'completed_instances' => $completedCount,
                'total_transitions' => $transitionCount,
            ];
        }

        $this->set(compact('stats'));
    }

    /**
     * Delete a workflow definition if no active instances exist.
     *
     * @param string|null $id Workflow definition ID.
     * @return \Cake\Http\Response|null
     */
    public function delete($id = null)
    {
        $this->request->allowMethod(['post', 'delete']);
        $table = $this->fetchTable('WorkflowDefinitions');
        $definition = $table->get($id);

        $instanceCount = $this->fetchTable('WorkflowInstances')->find()
            ->where(['workflow_definition_id' => $id, 'completed_at IS' => null])
            ->count();

        if ($instanceCount > 0) {
            $this->Flash->error(__('Cannot delete: {0} active workflow instances use this definition.', $instanceCount));

            return $this->redirect(['action' => 'index']);
        }

        if ($table->delete($definition)) {
            $this->Flash->success(__('Workflow definition deleted.'));
        } else {
            $this->Flash->error(__('Could not delete workflow definition.'));
        }

        return $this->redirect(['action' => 'index']);
    }
}
