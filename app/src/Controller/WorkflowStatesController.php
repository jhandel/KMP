<?php

declare(strict_types=1);

namespace App\Controller;

/**
 * Manages workflow states within a workflow definition.
 *
 * @property \App\Model\Table\WorkflowStatesTable $WorkflowStates
 */
class WorkflowStatesController extends AppController
{
    public function initialize(): void
    {
        parent::initialize();
        $this->Authorization->authorizeModel('add');
    }

    /**
     * Add a new state to a workflow definition.
     *
     * @param string|null $workflowDefinitionId Parent definition id.
     * @return \Cake\Http\Response|null|void
     */
    public function add($workflowDefinitionId = null)
    {
        $state = $this->WorkflowStates->newEmptyEntity();
        $state->workflow_definition_id = (int)$workflowDefinitionId;

        if ($this->request->is('post')) {
            $state = $this->WorkflowStates->patchEntity($state, $this->request->getData());
            $state->workflow_definition_id = (int)$workflowDefinitionId;

            if ($this->WorkflowStates->save($state)) {
                $this->Flash->success(__('The state has been saved.'));

                return $this->redirect(['controller' => 'WorkflowDefinitions', 'action' => 'view', $workflowDefinitionId]);
            }
            $this->Flash->error(__('The state could not be saved. Please, try again.'));
        }

        $stateTypes = ['initial' => 'Initial', 'intermediate' => 'Intermediate', 'approval' => 'Approval', 'final' => 'Final'];
        $this->set(compact('state', 'workflowDefinitionId', 'stateTypes'));
    }

    /**
     * Edit an existing workflow state.
     *
     * @param string|null $id State id.
     * @return \Cake\Http\Response|null|void
     */
    public function edit($id = null)
    {
        $state = $this->WorkflowStates->get($id);
        $this->Authorization->authorize($state);

        if ($this->request->is(['patch', 'post', 'put'])) {
            $state = $this->WorkflowStates->patchEntity($state, $this->request->getData());
            if ($this->WorkflowStates->save($state)) {
                $this->Flash->success(__('The state has been updated.'));

                return $this->redirect(['controller' => 'WorkflowDefinitions', 'action' => 'view', $state->workflow_definition_id]);
            }
            $this->Flash->error(__('The state could not be saved. Please, try again.'));
        }

        $workflowDefinitionId = $state->workflow_definition_id;
        $stateTypes = ['initial' => 'Initial', 'intermediate' => 'Intermediate', 'approval' => 'Approval', 'final' => 'Final'];
        $this->set(compact('state', 'workflowDefinitionId', 'stateTypes'));
    }

    /**
     * Delete a workflow state.
     *
     * @param string|null $id State id.
     * @return \Cake\Http\Response|null
     */
    public function delete($id = null)
    {
        $this->request->allowMethod(['post', 'delete']);
        $state = $this->WorkflowStates->get($id);
        $this->Authorization->authorize($state);

        $defId = $state->workflow_definition_id;

        if ($this->WorkflowStates->delete($state)) {
            $this->Flash->success(__('The state has been deleted.'));
        } else {
            $this->Flash->error(__('The state could not be deleted. Please, try again.'));
        }

        return $this->redirect(['controller' => 'WorkflowDefinitions', 'action' => 'view', $defId]);
    }
}
