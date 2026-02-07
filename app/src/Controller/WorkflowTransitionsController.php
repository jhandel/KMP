<?php

declare(strict_types=1);

namespace App\Controller;

/**
 * Manages workflow transitions within a workflow definition.
 *
 * @property \App\Model\Table\WorkflowTransitionsTable $WorkflowTransitions
 */
class WorkflowTransitionsController extends AppController
{
    public function initialize(): void
    {
        parent::initialize();
        $this->Authorization->authorizeModel('add');
    }

    /**
     * Add a new transition to a workflow definition.
     *
     * @param string|null $workflowDefinitionId Parent definition id.
     * @return \Cake\Http\Response|null|void
     */
    public function add($workflowDefinitionId = null)
    {
        $transition = $this->WorkflowTransitions->newEmptyEntity();
        $transition->workflow_definition_id = (int)$workflowDefinitionId;

        if ($this->request->is('post')) {
            $transition = $this->WorkflowTransitions->patchEntity($transition, $this->request->getData());
            $transition->workflow_definition_id = (int)$workflowDefinitionId;

            if ($this->WorkflowTransitions->save($transition)) {
                $this->Flash->success(__('The transition has been saved.'));

                return $this->redirect(['controller' => 'WorkflowDefinitions', 'action' => 'view', $workflowDefinitionId]);
            }
            $this->Flash->error(__('The transition could not be saved. Please, try again.'));
        }

        $statesTable = $this->fetchTable('WorkflowStates');
        $states = $statesTable->find('list', keyField: 'id', valueField: 'label')
            ->where(['workflow_definition_id' => $workflowDefinitionId])
            ->orderBy(['label' => 'ASC'])
            ->toArray();

        $triggerTypes = ['manual' => 'Manual', 'automatic' => 'Automatic', 'scheduled' => 'Scheduled', 'event' => 'Event'];
        $this->set(compact('transition', 'workflowDefinitionId', 'states', 'triggerTypes'));
    }

    /**
     * Edit an existing workflow transition.
     *
     * @param string|null $id Transition id.
     * @return \Cake\Http\Response|null|void
     */
    public function edit($id = null)
    {
        $transition = $this->WorkflowTransitions->get($id);
        $this->Authorization->authorize($transition);

        if ($this->request->is(['patch', 'post', 'put'])) {
            $transition = $this->WorkflowTransitions->patchEntity($transition, $this->request->getData());
            if ($this->WorkflowTransitions->save($transition)) {
                $this->Flash->success(__('The transition has been updated.'));

                return $this->redirect(['controller' => 'WorkflowDefinitions', 'action' => 'view', $transition->workflow_definition_id]);
            }
            $this->Flash->error(__('The transition could not be saved. Please, try again.'));
        }

        $workflowDefinitionId = $transition->workflow_definition_id;

        $statesTable = $this->fetchTable('WorkflowStates');
        $states = $statesTable->find('list', keyField: 'id', valueField: 'label')
            ->where(['workflow_definition_id' => $workflowDefinitionId])
            ->orderBy(['label' => 'ASC'])
            ->toArray();

        $triggerTypes = ['manual' => 'Manual', 'automatic' => 'Automatic', 'scheduled' => 'Scheduled', 'event' => 'Event'];
        $this->set(compact('transition', 'workflowDefinitionId', 'states', 'triggerTypes'));
    }

    /**
     * Delete a workflow transition.
     *
     * @param string|null $id Transition id.
     * @return \Cake\Http\Response|null
     */
    public function delete($id = null)
    {
        $this->request->allowMethod(['post', 'delete']);
        $transition = $this->WorkflowTransitions->get($id);
        $this->Authorization->authorize($transition);

        $defId = $transition->workflow_definition_id;

        if ($this->WorkflowTransitions->delete($transition)) {
            $this->Flash->success(__('The transition has been deleted.'));
        } else {
            $this->Flash->error(__('The transition could not be deleted. Please, try again.'));
        }

        return $this->redirect(['controller' => 'WorkflowDefinitions', 'action' => 'view', $defId]);
    }
}
