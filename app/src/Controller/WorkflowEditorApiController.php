<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\AppController;
use Cake\Event\EventInterface;

/**
 * WorkflowEditorApi Controller
 *
 * Session-authenticated JSON API endpoints for the visual workflow editor.
 * Handles CRUD for states, transitions, visibility rules, and approval gates.
 * Only accessible to super users.
 */
class WorkflowEditorApiController extends AppController
{
    public function initialize(): void
    {
        parent::initialize();
        $this->viewBuilder()->setClassName('Json');
    }

    /**
     * Restrict all actions to super users and skip model authorization.
     */
    public function beforeFilter(EventInterface $event)
    {
        parent::beforeFilter($event);
        $this->Authorization->skipAuthorization();
        $identity = $this->request->getAttribute('identity');
        if (!$identity || !$identity->getOriginalData()->isSuperUser()) {
            $this->set(['error' => 'Forbidden']);
            $this->viewBuilder()->setOption('serialize', ['error']);
            $this->response = $this->response->withStatus(403);

            return $this->response;
        }
    }

    /**
     * GET /api/workflow-editor/definition/{id}
     *
     * @param string|null $id Definition ID.
     * @return void
     */
    public function getDefinition($id)
    {
        $table = $this->fetchTable('WorkflowDefinitions');
        $definition = $table->get($id, contain: [
            'WorkflowStates' => [
                'WorkflowVisibilityRules',
                'WorkflowApprovalGates',
            ],
            'WorkflowTransitions',
        ]);
        $this->set('definition', $definition);
        $this->viewBuilder()->setOption('serialize', ['definition']);
    }

    /**
     * PUT /api/workflow-editor/definition/{id}
     *
     * @param string|null $id Definition ID.
     * @return void
     */
    public function saveDefinition($id)
    {
        $this->request->allowMethod(['put']);
        $table = $this->fetchTable('WorkflowDefinitions');
        $definition = $table->get($id);
        $definition = $table->patchEntity($definition, $this->request->getData());

        $success = (bool)$table->save($definition);
        $this->set(compact('success', 'definition'));
        $this->viewBuilder()->setOption('serialize', ['success', 'definition']);
    }

    /**
     * POST /api/workflow-editor/states
     *
     * @return void
     */
    public function createState()
    {
        $this->request->allowMethod(['post']);
        $table = $this->fetchTable('WorkflowStates');
        $state = $table->newEntity($this->request->getData());
        $success = (bool)$table->save($state);
        $errors = $state->getErrors();
        $this->set(compact('success', 'state', 'errors'));
        $this->viewBuilder()->setOption('serialize', ['success', 'state', 'errors']);
    }

    /**
     * PUT /api/workflow-editor/states/{id}
     *
     * @param string|null $id State ID.
     * @return void
     */
    public function updateState($id)
    {
        $this->request->allowMethod(['put']);
        $table = $this->fetchTable('WorkflowStates');
        $state = $table->get($id);
        $state = $table->patchEntity($state, $this->request->getData());
        $success = (bool)$table->save($state);
        $errors = $state->getErrors();
        $this->set(compact('success', 'state', 'errors'));
        $this->viewBuilder()->setOption('serialize', ['success', 'state', 'errors']);
    }

    /**
     * DELETE /api/workflow-editor/states/{id}
     *
     * @param string|null $id State ID.
     * @return void
     */
    public function deleteState($id)
    {
        $this->request->allowMethod(['delete']);
        $table = $this->fetchTable('WorkflowStates');
        $state = $table->get($id);

        $transitionCount = $this->fetchTable('WorkflowTransitions')->find()
            ->where(['OR' => ['from_state_id' => $id, 'to_state_id' => $id]])
            ->count();

        if ($transitionCount > 0) {
            $this->set(['success' => false, 'error' => 'State has transitions. Remove them first.']);
            $this->viewBuilder()->setOption('serialize', ['success', 'error']);

            return;
        }

        $success = (bool)$table->delete($state);
        $this->set(compact('success'));
        $this->viewBuilder()->setOption('serialize', ['success']);
    }

    /**
     * POST /api/workflow-editor/transitions
     *
     * @return void
     */
    public function createTransition()
    {
        $this->request->allowMethod(['post']);
        $table = $this->fetchTable('WorkflowTransitions');
        $data = $this->request->getData();
        foreach (['conditions', 'actions', 'trigger_config'] as $jsonField) {
            if (isset($data[$jsonField]) && is_array($data[$jsonField])) {
                $data[$jsonField] = json_encode($data[$jsonField]);
            }
        }
        $transition = $table->newEntity($data);
        $success = (bool)$table->save($transition);
        $errors = $transition->getErrors();
        $this->set(compact('success', 'transition', 'errors'));
        $this->viewBuilder()->setOption('serialize', ['success', 'transition', 'errors']);
    }

    /**
     * PUT /api/workflow-editor/transitions/{id}
     *
     * @param string|null $id Transition ID.
     * @return void
     */
    public function updateTransition($id)
    {
        $this->request->allowMethod(['put']);
        $table = $this->fetchTable('WorkflowTransitions');
        $transition = $table->get($id);
        $data = $this->request->getData();
        foreach (['conditions', 'actions', 'trigger_config'] as $jsonField) {
            if (isset($data[$jsonField]) && is_array($data[$jsonField])) {
                $data[$jsonField] = json_encode($data[$jsonField]);
            }
        }
        $transition = $table->patchEntity($transition, $data);
        $success = (bool)$table->save($transition);
        $errors = $transition->getErrors();
        $this->set(compact('success', 'transition', 'errors'));
        $this->viewBuilder()->setOption('serialize', ['success', 'transition', 'errors']);
    }

    /**
     * DELETE /api/workflow-editor/transitions/{id}
     *
     * @param string|null $id Transition ID.
     * @return void
     */
    public function deleteTransition($id)
    {
        $this->request->allowMethod(['delete']);
        $table = $this->fetchTable('WorkflowTransitions');
        $transition = $table->get($id);
        $success = (bool)$table->delete($transition);
        $this->set(compact('success'));
        $this->viewBuilder()->setOption('serialize', ['success']);
    }

    /**
     * POST /api/workflow-editor/visibility-rules
     *
     * @return void
     */
    public function saveVisibilityRules()
    {
        $this->request->allowMethod(['post']);
        $table = $this->fetchTable('WorkflowVisibilityRules');
        $data = $this->request->getData();

        if (isset($data['condition']) && is_array($data['condition'])) {
            $data['condition'] = json_encode($data['condition']);
        }

        $rule = isset($data['id']) ? $table->get($data['id']) : $table->newEmptyEntity();
        $rule = $table->patchEntity($rule, $data);
        $success = (bool)$table->save($rule);
        $this->set(compact('success', 'rule'));
        $this->viewBuilder()->setOption('serialize', ['success', 'rule']);
    }

    /**
     * POST /api/workflow-editor/approval-gates
     *
     * @return void
     */
    public function saveApprovalGate()
    {
        $this->request->allowMethod(['post', 'put']);
        $table = $this->fetchTable('WorkflowApprovalGates');
        $data = $this->request->getData();

        if (isset($data['approver_rule']) && is_string($data['approver_rule'])) {
            // Already JSON string, keep as-is
        } elseif (isset($data['approver_rule']) && is_array($data['approver_rule'])) {
            $data['approver_rule'] = json_encode($data['approver_rule']);
        }

        if (isset($data['threshold_config']) && is_array($data['threshold_config'])) {
            $data['threshold_config'] = json_encode($data['threshold_config']);
        }

        $gate = isset($data['id']) ? $table->get($data['id']) : $table->newEmptyEntity();
        $gate = $table->patchEntity($gate, $data);
        $success = (bool)$table->save($gate);
        $this->set(compact('success', 'gate'));
        $this->viewBuilder()->setOption('serialize', ['success', 'gate']);
    }

    /**
     * DELETE /api/workflow-editor/approval-gates/{id}
     *
     * @param string|null $id Gate ID.
     * @return void
     */
    public function deleteApprovalGate($id)
    {
        $this->request->allowMethod(['delete']);
        $table = $this->fetchTable('WorkflowApprovalGates');
        $gate = $table->get($id);
        $success = (bool)$table->delete($gate);
        $this->set(compact('success'));
        $this->viewBuilder()->setOption('serialize', ['success']);
    }

    /**
     * PUT /workflow-engine/api/gate/{id}
     */
    public function updateApprovalGate($id)
    {
        $this->request->allowMethod(['put']);
        $table = $this->fetchTable('WorkflowApprovalGates');
        $data = $this->request->getData();

        if (isset($data['threshold_config']) && is_array($data['threshold_config'])) {
            $data['threshold_config'] = json_encode($data['threshold_config']);
        }
        if (isset($data['approver_rule']) && is_string($data['approver_rule'])) {
            // keep as-is
        } elseif (isset($data['approver_rule']) && is_array($data['approver_rule'])) {
            $data['approver_rule'] = json_encode($data['approver_rule']);
        }

        $gate = $table->get($id);
        $gate = $table->patchEntity($gate, $data);
        $success = (bool)$table->save($gate);
        $this->set(compact('success', 'gate'));
        $this->viewBuilder()->setOption('serialize', ['success', 'gate']);
    }

    /**
     * POST /api/workflow-editor/definition/{id}/publish
     *
     * @param string|null $id Definition ID.
     * @return void
     */
    public function publishDefinition($id)
    {
        $this->request->allowMethod(['post']);
        $table = $this->fetchTable('WorkflowDefinitions');
        $definition = $table->get($id);
        $definition->is_active = true;
        $definition->version = $definition->version + 1;
        $success = (bool)$table->save($definition);
        $this->set(compact('success', 'definition'));
        $this->viewBuilder()->setOption('serialize', ['success', 'definition']);
    }

    /**
     * GET /api/workflow-editor/definition/{id}/export
     *
     * @param string|null $id Definition ID.
     * @return void
     */
    public function exportDefinition($id)
    {
        $table = $this->fetchTable('WorkflowDefinitions');
        $definition = $table->get($id, contain: [
            'WorkflowStates' => ['WorkflowVisibilityRules', 'WorkflowApprovalGates'],
            'WorkflowTransitions',
        ]);

        $export = $definition->toArray();
        unset($export['id'], $export['created'], $export['modified'], $export['created_by'], $export['modified_by']);

        $this->set(compact('export'));
        $this->viewBuilder()->setOption('serialize', ['export']);
    }

    /**
     * POST /api/workflow-editor/import
     *
     * @return void
     */
    public function importDefinition()
    {
        $this->request->allowMethod(['post']);
        $data = $this->request->getData();

        $table = $this->fetchTable('WorkflowDefinitions');
        $definition = $table->newEntity([
            'name' => $data['name'] ?? 'Imported Workflow',
            'slug' => $data['slug'] ?? 'imported-' . time(),
            'description' => $data['description'] ?? '',
            'entity_type' => $data['entity_type'] ?? '',
            'plugin_name' => $data['plugin_name'] ?? null,
            'version' => 1,
            'is_active' => false,
            'is_default' => false,
        ]);

        $success = (bool)$table->save($definition);

        if ($success && isset($data['workflow_states'])) {
            $statesTable = $this->fetchTable('WorkflowStates');
            $stateIdMap = [];

            foreach ($data['workflow_states'] as $stateData) {
                $oldId = $stateData['id'] ?? null;
                unset($stateData['id']);
                $stateData['workflow_definition_id'] = $definition->id;
                $state = $statesTable->newEntity($stateData);
                if ($statesTable->save($state) && $oldId) {
                    $stateIdMap[$oldId] = $state->id;
                }
            }

            if (isset($data['workflow_transitions'])) {
                $transitionsTable = $this->fetchTable('WorkflowTransitions');
                foreach ($data['workflow_transitions'] as $transitionData) {
                    unset($transitionData['id']);
                    $transitionData['workflow_definition_id'] = $definition->id;
                    $transitionData['from_state_id'] = $stateIdMap[$transitionData['from_state_id']] ?? null;
                    $transitionData['to_state_id'] = $stateIdMap[$transitionData['to_state_id']] ?? null;
                    if ($transitionData['from_state_id'] && $transitionData['to_state_id']) {
                        $transition = $transitionsTable->newEntity($transitionData);
                        $transitionsTable->save($transition);
                    }
                }
            }
        }

        $this->set(compact('success', 'definition'));
        $this->viewBuilder()->setOption('serialize', ['success', 'definition']);
    }

    /**
     * POST /api/workflow-editor/definition/{id}/validate
     *
     * @param string|null $id Definition ID.
     * @return void
     */
    public function validateDefinition($id)
    {
        $this->request->allowMethod(['post']);
        $table = $this->fetchTable('WorkflowDefinitions');
        $definition = $table->get($id, contain: [
            'WorkflowStates',
            'WorkflowTransitions',
        ]);

        $errors = [];
        $warnings = [];
        $states = $definition->workflow_states ?? [];
        $transitions = $definition->workflow_transitions ?? [];

        // Check for initial state
        $initialStates = array_filter($states, fn($s) => $s->state_type === 'initial');
        if (count($initialStates) === 0) {
            $errors[] = 'No initial state defined. Workflow needs at least one initial state.';
        } elseif (count($initialStates) > 1) {
            $warnings[] = 'Multiple initial states defined. Only one initial state is typical.';
        }

        // Check for terminal states
        $terminalStates = array_filter($states, fn($s) => $s->state_type === 'terminal');
        if (count($terminalStates) === 0) {
            $errors[] = 'No terminal states defined. Workflow needs at least one terminal state.';
        }

        // Check reachability from initial states
        $stateIds = array_map(fn($s) => $s->id, $states);
        $reachable = [];
        $initialIds = array_map(fn($s) => $s->id, $initialStates);
        $queue = $initialIds;

        while (!empty($queue)) {
            $currentId = array_shift($queue);
            if (in_array($currentId, $reachable)) {
                continue;
            }
            $reachable[] = $currentId;
            foreach ($transitions as $t) {
                if ($t->from_state_id === $currentId && !in_array($t->to_state_id, $reachable)) {
                    $queue[] = $t->to_state_id;
                }
            }
        }

        $unreachable = array_diff($stateIds, $reachable);
        if (!empty($unreachable)) {
            $unreachableNames = array_map(function ($id) use ($states) {
                foreach ($states as $s) {
                    if ($s->id === $id) {
                        return $s->name;
                    }
                }

                return "ID:{$id}";
            }, $unreachable);
            $errors[] = 'Unreachable states: ' . implode(', ', $unreachableNames);
        }

        // Check for orphan states with no incoming transitions
        foreach ($states as $state) {
            if ($state->state_type === 'initial') {
                continue;
            }
            $hasIncoming = false;
            foreach ($transitions as $t) {
                if ($t->to_state_id === $state->id) {
                    $hasIncoming = true;
                    break;
                }
            }
            if (!$hasIncoming) {
                $warnings[] = "State '{$state->name}' has no incoming transitions.";
            }
        }

        // Check for dead-end states with no outgoing transitions
        foreach ($states as $state) {
            if ($state->state_type === 'terminal') {
                continue;
            }
            $hasOutgoing = false;
            foreach ($transitions as $t) {
                if ($t->from_state_id === $state->id) {
                    $hasOutgoing = true;
                    break;
                }
            }
            if (!$hasOutgoing) {
                $warnings[] = "State '{$state->name}' has no outgoing transitions (dead end).";
            }
        }

        $isValid = empty($errors);
        $this->set(compact('isValid', 'errors', 'warnings'));
        $this->viewBuilder()->setOption('serialize', ['isValid', 'errors', 'warnings']);
    }
}
