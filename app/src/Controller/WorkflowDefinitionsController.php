<?php

declare(strict_types=1);

namespace App\Controller;

/**
 * Manages workflow definition CRUD and duplication for admin users.
 *
 * @property \App\Model\Table\WorkflowDefinitionsTable $WorkflowDefinitions
 */
class WorkflowDefinitionsController extends AppController
{
    public function initialize(): void
    {
        parent::initialize();
        $this->Authorization->authorizeModel('index', 'add', 'createFromTemplate');
    }

    /**
     * List all workflow definitions.
     *
     * @return \Cake\Http\Response|null|void
     */
    public function index()
    {
        $query = $this->WorkflowDefinitions->find()
            ->contain(['WorkflowStates'])
            ->orderBy(['WorkflowDefinitions.name' => 'ASC']);

        $definitions = $this->paginate($query);

        // Scan available templates
        $templates = [];
        $templateDir = CONFIG . 'WorkflowTemplates';
        if (is_dir($templateDir)) {
            foreach (glob($templateDir . DS . '*.json') as $file) {
                $data = json_decode(file_get_contents($file), true);
                if ($data) {
                    $templates[] = [
                        'file' => pathinfo($file, PATHINFO_FILENAME),
                        'name' => $data['name'] ?? pathinfo($file, PATHINFO_FILENAME),
                    ];
                }
            }
            usort($templates, fn($a, $b) => strcmp($a['name'], $b['name']));
        }

        $this->set(compact('definitions', 'templates'));
    }

    /**
     * View a single workflow definition with states and transitions.
     *
     * @param string|null $id Definition id.
     * @return \Cake\Http\Response|null|void
     */
    public function view($id = null)
    {
        $definition = $this->WorkflowDefinitions->get($id, contain: [
            'WorkflowStates' => ['sort' => ['WorkflowStates.slug' => 'ASC']],
            'WorkflowTransitions' => [
                'FromState',
                'ToState',
            ],
        ]);
        $this->Authorization->authorize($definition);

        $this->set(compact('definition'));
    }

    /**
     * Create a new workflow definition.
     *
     * @return \Cake\Http\Response|null|void
     */
    public function add()
    {
        $definition = $this->WorkflowDefinitions->newEmptyEntity();

        if ($this->request->is('post')) {
            $definition = $this->WorkflowDefinitions->patchEntity($definition, $this->request->getData());
            if ($this->WorkflowDefinitions->save($definition)) {
                $this->Flash->success(__('The workflow definition has been saved.'));

                return $this->redirect(['action' => 'view', $definition->id]);
            }
            $this->Flash->error(__('The workflow definition could not be saved. Please, try again.'));
        }

        $this->set(compact('definition'));
    }

    /**
     * Edit an existing workflow definition.
     *
     * @param string|null $id Definition id.
     * @return \Cake\Http\Response|null|void
     */
    public function edit($id = null)
    {
        $definition = $this->WorkflowDefinitions->get($id);
        $this->Authorization->authorize($definition);

        if ($this->request->is(['patch', 'post', 'put'])) {
            $definition = $this->WorkflowDefinitions->patchEntity($definition, $this->request->getData());
            if ($this->WorkflowDefinitions->save($definition)) {
                $this->Flash->success(__('The workflow definition has been updated.'));

                return $this->redirect(['action' => 'view', $id]);
            }
            $this->Flash->error(__('The workflow definition could not be saved. Please, try again.'));
        }

        $this->set(compact('definition'));
    }

    /**
     * Delete a workflow definition.
     *
     * @param string|null $id Definition id.
     * @return \Cake\Http\Response|null
     */
    public function delete($id = null)
    {
        $this->request->allowMethod(['post', 'delete']);
        $definition = $this->WorkflowDefinitions->get($id);
        $this->Authorization->authorize($definition);

        // Check if definition has active instances
        $instanceCount = $this->WorkflowDefinitions->WorkflowInstances->find()
            ->where(['workflow_definition_id' => $id])
            ->count();

        if ($instanceCount > 0) {
            $this->Flash->error(__(
                'Cannot delete workflow definition "{0}" because it has {1} active instance(s).',
                $definition->name,
                $instanceCount
            ));

            return $this->redirect(['action' => 'index']);
        }

        if ($this->WorkflowDefinitions->delete($definition)) {
            $this->Flash->success(__('The workflow definition has been deleted.'));
        } else {
            $this->Flash->error(__('The workflow definition could not be deleted. Please, try again.'));
        }

        return $this->redirect(['action' => 'index']);
    }

    /**
     * Display analytics/monitoring dashboard for a workflow definition.
     *
     * @param string|null $id Definition id.
     * @return \Cake\Http\Response|null|void
     */
    public function analytics($id = null): void
    {
        $definition = $this->WorkflowDefinitions->get($id, contain: [
            'WorkflowStates',
            'WorkflowInstances' => function ($q) {
                return $q->contain(['CurrentState'])
                    ->orderBy(['WorkflowInstances.created' => 'DESC']);
            },
        ]);
        $this->Authorization->authorize($definition, 'view');

        // Count instances by state
        $instancesByState = [];
        foreach ($definition->workflow_instances ?? [] as $instance) {
            $stateLabel = $instance->current_state->label ?? __('Unknown');
            $instancesByState[$stateLabel] = ($instancesByState[$stateLabel] ?? 0) + 1;
        }

        // Count active vs completed
        $activeCount = 0;
        $completedCount = 0;
        foreach ($definition->workflow_instances ?? [] as $instance) {
            if ($instance->completed_at === null) {
                $activeCount++;
            } else {
                $completedCount++;
            }
        }

        $this->set(compact('definition', 'instancesByState', 'activeCount', 'completedCount'));
    }

    /**
     * Create a workflow definition from a JSON template file.
     *
     * @param string|null $templateName Template filename (without .json).
     * @return \Cake\Http\Response|null
     */
    public function createFromTemplate($templateName = null)
    {
        $this->request->allowMethod(['post']);

        $templatePath = CONFIG . 'WorkflowTemplates' . DS . $templateName . '.json';
        if (!file_exists($templatePath)) {
            $this->Flash->error(__('Template not found.'));
            return $this->redirect(['action' => 'index']);
        }

        $template = json_decode(file_get_contents($templatePath), true);

        // Create definition
        $definition = $this->WorkflowDefinitions->newEntity([
            'name' => $template['name'],
            'slug' => $template['slug'] . '-' . time(),
            'description' => $template['description'],
            'entity_type' => $template['entity_type'],
            'plugin_name' => $template['plugin_name'] ?? null,
            'version' => 1,
            'is_active' => false,
            'is_default' => false,
        ]);

        if (!$this->WorkflowDefinitions->save($definition)) {
            $this->Flash->error(__('Could not create from template.'));
            return $this->redirect(['action' => 'index']);
        }

        // Create states
        $stateIdMap = [];
        foreach ($template['states'] ?? [] as $s) {
            $state = $this->WorkflowDefinitions->WorkflowStates->newEntity([
                'workflow_definition_id' => $definition->id,
                'name' => $s['slug'],
                'slug' => $s['slug'],
                'label' => $s['label'],
                'state_type' => $s['state_type'],
                'status_category' => $s['status_category'] ?? null,
                'metadata' => isset($s['metadata']) ? json_encode($s['metadata']) : null,
            ]);
            $this->WorkflowDefinitions->WorkflowStates->save($state);
            $stateIdMap[$s['slug']] = $state->id;
        }

        // Create transitions
        foreach ($template['transitions'] ?? [] as $t) {
            $fromSlugs = (array)$t['from'];
            $toSlug = $t['to'];

            foreach ($fromSlugs as $fromSlug) {
                if (!isset($stateIdMap[$fromSlug]) || !isset($stateIdMap[$toSlug])) {
                    continue;
                }
                $transition = $this->WorkflowDefinitions->WorkflowTransitions->newEntity([
                    'workflow_definition_id' => $definition->id,
                    'from_state_id' => $stateIdMap[$fromSlug],
                    'to_state_id' => $stateIdMap[$toSlug],
                    'name' => $t['slug'],
                    'slug' => $t['slug'],
                    'label' => $t['label'],
                    'trigger_type' => 'manual',
                ]);
                $this->WorkflowDefinitions->WorkflowTransitions->save($transition);
            }
        }

        $this->Flash->success(__('Workflow created from template.'));
        return $this->redirect(['action' => 'view', $definition->id]);
    }

    /**
     * Duplicate a workflow definition including states and transitions.
     *
     * @param string|null $id Source definition id.
     * @return \Cake\Http\Response|null
     */
    public function duplicate($id = null)
    {
        $this->request->allowMethod(['post']);
        $source = $this->WorkflowDefinitions->get($id, contain: [
            'WorkflowStates',
            'WorkflowTransitions',
        ]);
        $this->Authorization->authorize($source, 'edit');

        $newDef = $this->WorkflowDefinitions->newEntity([
            'name' => $source->name . ' (Copy)',
            'slug' => $source->slug . '-copy-' . time(),
            'description' => $source->description,
            'entity_type' => $source->entity_type,
            'plugin_name' => $source->plugin_name,
            'version' => $source->version + 1,
            'is_active' => false,
            'is_default' => false,
        ]);

        if ($this->WorkflowDefinitions->save($newDef)) {
            // Clone states and build old-to-new ID map
            $stateIdMap = [];
            foreach ($source->workflow_states ?? [] as $state) {
                $newState = $this->WorkflowDefinitions->WorkflowStates->newEntity([
                    'workflow_definition_id' => $newDef->id,
                    'name' => $state->name,
                    'slug' => $state->slug,
                    'label' => $state->label,
                    'description' => $state->description,
                    'state_type' => $state->state_type,
                    'status_category' => $state->status_category,
                    'metadata' => $state->metadata,
                    'position_x' => $state->position_x,
                    'position_y' => $state->position_y,
                    'on_enter_actions' => $state->on_enter_actions,
                    'on_exit_actions' => $state->on_exit_actions,
                ]);
                $this->WorkflowDefinitions->WorkflowStates->save($newState);
                $stateIdMap[$state->id] = $newState->id;
            }

            // Clone transitions with mapped state IDs
            foreach ($source->workflow_transitions ?? [] as $t) {
                $newT = $this->WorkflowDefinitions->WorkflowTransitions->newEntity([
                    'workflow_definition_id' => $newDef->id,
                    'from_state_id' => $stateIdMap[$t->from_state_id] ?? null,
                    'to_state_id' => $stateIdMap[$t->to_state_id] ?? null,
                    'name' => $t->name,
                    'slug' => $t->slug,
                    'label' => $t->label,
                    'description' => $t->description,
                    'priority' => $t->priority,
                    'conditions' => $t->conditions,
                    'actions' => $t->actions,
                    'is_automatic' => $t->is_automatic,
                    'trigger_type' => $t->trigger_type,
                    'trigger_config' => $t->trigger_config,
                ]);
                $this->WorkflowDefinitions->WorkflowTransitions->save($newT);
            }

            $this->Flash->success(__('Workflow definition duplicated.'));

            return $this->redirect(['action' => 'view', $newDef->id]);
        }

        $this->Flash->error(__('Could not duplicate workflow definition.'));

        return $this->redirect(['action' => 'view', $id]);
    }
}
