<?php

declare(strict_types=1);

namespace Awards\Controller;

use App\Controller\DataverseGridTrait;
use Awards\Model\Entity\Recommendation;
use Awards\Model\Entity\RecommendationStateFieldRule;
use Cake\ORM\TableRegistry;

/**
 * CRUD operations for recommendation state management.
 *
 * States are specific workflow positions within a status category.
 * Deleting a state with recommendations requires transferring them first.
 * Renaming cascades to awards_recommendations and audit logs.
 * Manages inline field rules and transition configuration.
 *
 * @property \Awards\Model\Table\RecommendationStatesTable $RecommendationStates
 */
class RecommendationStatesController extends AppController
{
    use DataverseGridTrait;

    /**
     * @return void
     */
    public function initialize(): void
    {
        parent::initialize();
        $this->Authorization->authorizeModel("index", "add", "gridData");
    }

    /**
     * List all recommendation states.
     *
     * @return void
     */
    public function index(): void
    {
        $this->set('user', $this->request->getAttribute('identity'));
    }

    /**
     * Grid data endpoint for states listing.
     *
     * @param \App\Services\CsvExportService $csvExportService Injected CSV export service
     * @return \Cake\Http\Response|null|void
     */
    public function gridData(\App\Services\CsvExportService $csvExportService)
    {
        $baseQuery = $this->RecommendationStates->find()
            ->contain(['RecommendationStatuses']);

        $result = $this->processDataverseGrid([
            'gridKey' => 'Awards.RecommendationStates.index.main',
            'gridColumnsClass' => \Awards\KMP\GridColumns\RecommendationStatesGridColumns::class,
            'baseQuery' => $baseQuery,
            'tableName' => 'RecommendationStates',
            'defaultSort' => ['RecommendationStates.sort_order' => 'asc'],
            'defaultPageSize' => 25,
            'showAllTab' => false,
            'canAddViews' => false,
            'canFilter' => true,
            'canExportCsv' => false,
        ]);

        if (!empty($result['isCsvExport'])) {
            return $this->handleCsvExport($result, $csvExportService, 'recommendation-states');
        }

        $this->set([
            'states' => $result['data'],
            'gridState' => $result['gridState'],
            'columns' => $result['columnsMetadata'],
            'visibleColumns' => $result['visibleColumns'],
            'searchableColumns' => \Awards\KMP\GridColumns\RecommendationStatesGridColumns::getSearchableColumns(),
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

        $turboFrame = $this->request->getHeaderLine('Turbo-Frame');
        $this->viewBuilder()->setPlugin(null);

        if ($turboFrame === 'recommendation-states-grid-table') {
            $this->set('data', $result['data']);
            $this->set('tableFrameId', 'recommendation-states-grid-table');
            $this->viewBuilder()->disableAutoLayout();
            $this->viewBuilder()->setTemplatePath('element');
            $this->viewBuilder()->setTemplate('dv_grid_table');
        } else {
            $this->set('data', $result['data']);
            $this->set('frameId', 'recommendation-states-grid');
            $this->viewBuilder()->disableAutoLayout();
            $this->viewBuilder()->setTemplatePath('element');
            $this->viewBuilder()->setTemplate('dv_grid_content');
        }
    }

    /**
     * View state details with field rules and transitions.
     *
     * @param string|null $id State ID
     * @return void
     * @throws \Cake\Datasource\Exception\RecordNotFoundException
     */
    public function view($id = null): void
    {
        $state = $this->RecommendationStates->get($id, contain: [
            'RecommendationStatuses',
            'RecommendationStateFieldRules',
            'OutgoingTransitions' => ['ToStates'],
        ]);
        if (!$state) {
            throw new \Cake\Http\Exception\NotFoundException();
        }
        $this->Authorization->authorize($state);

        // Count recommendations in this state
        $recommendationsTable = $this->fetchTable('Awards.Recommendations');
        $recommendationCount = $recommendationsTable->find()
            ->where(['state' => $state->name])
            ->count();

        // Get all states for transition matrix
        $allStates = $this->RecommendationStates->find()
            ->contain(['RecommendationStatuses'])
            ->orderBy(['RecommendationStatuses.sort_order' => 'ASC', 'RecommendationStates.sort_order' => 'ASC'])
            ->all()
            ->toArray();

        // Build transition target IDs set for quick lookup
        $transitionTargetIds = [];
        foreach ($state->outgoing_transitions as $transition) {
            $transitionTargetIds[$transition->to_state_id] = true;
        }

        $this->set(compact('state', 'recommendationCount', 'allStates', 'transitionTargetIds'));
        $this->set('fieldTargetOptions', RecommendationStateFieldRule::FIELD_TARGET_OPTIONS);
        $this->set('ruleTypeOptions', RecommendationStateFieldRule::RULE_TYPE_OPTIONS);
    }

    /**
     * Create a new state.
     *
     * @return \Cake\Http\Response|null|void
     */
    public function add()
    {
        $state = $this->RecommendationStates->newEmptyEntity();
        if ($this->request->is('post')) {
            $state = $this->RecommendationStates->patchEntity(
                $state,
                $this->request->getData(),
            );
            if ($this->RecommendationStates->save($state)) {
                Recommendation::clearCache();
                $this->Flash->success(__('The Recommendation State has been saved.'));
                return $this->redirect(['action' => 'view', $state->id]);
            }
            $this->Flash->error(__('The Recommendation State could not be saved. Please, try again.'));
        }
        $statuses = $this->RecommendationStates->RecommendationStatuses->find('list', limit: 200)
            ->orderBy(['sort_order' => 'ASC'])
            ->toArray();
        $this->set(compact('state', 'statuses'));
    }

    /**
     * Edit an existing state. Cascades name changes to recommendations and audit logs.
     *
     * @param string|null $id State ID
     * @return \Cake\Http\Response|null|void
     * @throws \Cake\Datasource\Exception\RecordNotFoundException
     */
    public function edit($id = null)
    {
        $state = $this->RecommendationStates->get($id, contain: ['RecommendationStatuses']);
        if (!$state) {
            throw new \Cake\Http\Exception\NotFoundException();
        }
        $this->Authorization->authorize($state);

        if ($this->request->is(['patch', 'post', 'put'])) {
            $oldName = $state->name;
            $oldStatusId = $state->status_id;
            $state = $this->RecommendationStates->patchEntity(
                $state,
                $this->request->getData(),
            );

            if ($this->RecommendationStates->save($state)) {
                if ($oldName !== $state->name) {
                    $this->cascadeStateRename($oldName, $state->name);
                }
                if ($oldStatusId !== $state->status_id) {
                    $this->cascadeStateStatusChange($state->name, $state->status_id);
                }
                Recommendation::clearCache();
                $this->Flash->success(__('The Recommendation State has been saved.'));
                return $this->redirect(['action' => 'view', $state->id]);
            }
            $this->Flash->error(__('The Recommendation State could not be saved. Please, try again.'));
        }
        $statuses = $this->RecommendationStates->RecommendationStatuses->find('list', limit: 200)
            ->orderBy(['sort_order' => 'ASC'])
            ->toArray();
        $this->set(compact('state', 'statuses'));
    }

    /**
     * Delete a state. Requires transfer if recommendations exist in this state.
     *
     * @param string|null $id State ID
     * @return \Cake\Http\Response|null
     * @throws \Cake\Datasource\Exception\RecordNotFoundException
     */
    public function delete($id = null)
    {
        $this->request->allowMethod(['post', 'delete']);
        $state = $this->RecommendationStates->get($id);
        if (!$state) {
            throw new \Cake\Http\Exception\NotFoundException();
        }
        $this->Authorization->authorize($state);

        $recommendationsTable = $this->fetchTable('Awards.Recommendations');
        $recCount = $recommendationsTable->find()
            ->where(['state' => $state->name])
            ->count();

        if ($recCount > 0) {
            $targetStateId = $this->request->getData('target_state_id');
            if (empty($targetStateId)) {
                $this->Flash->error(__(
                    'Cannot delete this state because {0} recommendation(s) are in it. Please select a target state to transfer them.',
                    $recCount
                ));
                return $this->redirect(['action' => 'view', $state->id]);
            }

            $targetState = $this->RecommendationStates->get((int)$targetStateId, contain: ['RecommendationStatuses']);
            $this->transferRecommendations($state->name, $targetState->name, $targetState->recommendation_status->name);
        }

        if ($this->RecommendationStates->delete($state)) {
            Recommendation::clearCache();
            $this->Flash->success(__('The Recommendation State has been deleted.'));
        } else {
            $this->Flash->error(__('The Recommendation State could not be deleted. Please, try again.'));
            return $this->redirect(['action' => 'view', $state->id]);
        }

        return $this->redirect(['action' => 'index']);
    }

    /**
     * Add a field rule to a state.
     *
     * @param string $stateId State ID
     * @return \Cake\Http\Response|null|void
     */
    public function addFieldRule(string $stateId)
    {
        $this->request->allowMethod(['post']);
        $state = $this->RecommendationStates->get($stateId);
        $this->Authorization->authorize($state, 'edit');

        $rulesTable = $this->fetchTable('Awards.RecommendationStateFieldRules');
        $rule = $rulesTable->newEntity($this->request->getData());
        $rule->state_id = (int)$stateId;

        if ($rulesTable->save($rule)) {
            Recommendation::clearCache();
            $this->Flash->success(__('Field rule added.'));
        } else {
            $this->Flash->error(__('Could not add field rule. Please, try again.'));
        }
        return $this->redirect(['action' => 'view', $stateId]);
    }

    /**
     * Edit an existing field rule.
     *
     * @param string $ruleId Rule ID
     * @return \Cake\Http\Response|null|void
     */
    public function editFieldRule(string $ruleId)
    {
        $this->request->allowMethod(['post', 'put', 'patch']);
        $rulesTable = $this->fetchTable('Awards.RecommendationStateFieldRules');
        $rule = $rulesTable->get($ruleId, contain: ['RecommendationStates']);
        $this->Authorization->authorize($rule->recommendation_state, 'edit');

        $stateId = $rule->state_id;
        $rule = $rulesTable->patchEntity($rule, $this->request->getData());

        if ($rulesTable->save($rule)) {
            Recommendation::clearCache();
            $this->Flash->success(__('Field rule updated.'));
        } else {
            $this->Flash->error(__('Could not update field rule. Please, try again.'));
        }
        return $this->redirect(['action' => 'view', $stateId]);
    }

    /**
     * Delete a field rule from a state.
     *
     * @param string $ruleId Rule ID
     * @return \Cake\Http\Response|null|void
     */
    public function deleteFieldRule(string $ruleId)
    {
        $this->request->allowMethod(['post', 'delete']);
        $rulesTable = $this->fetchTable('Awards.RecommendationStateFieldRules');
        $rule = $rulesTable->get($ruleId, contain: ['RecommendationStates']);
        $this->Authorization->authorize($rule->recommendation_state, 'edit');

        $stateId = $rule->state_id;
        if ($rulesTable->delete($rule)) {
            Recommendation::clearCache();
            $this->Flash->success(__('Field rule removed.'));
        } else {
            $this->Flash->error(__('Could not remove field rule.'));
        }
        return $this->redirect(['action' => 'view', $stateId]);
    }

    /**
     * Save the transition matrix for a state (AJAX endpoint).
     *
     * @param string $stateId State ID
     * @return \Cake\Http\Response|null|void
     */
    public function saveTransitions(string $stateId)
    {
        $this->request->allowMethod(['post']);
        $state = $this->RecommendationStates->get($stateId);
        $this->Authorization->authorize($state, 'edit');

        $transitionsTable = $this->fetchTable('Awards.RecommendationStateTransitions');
        $targetIds = $this->request->getData('transition_targets') ?? [];

        // Remove all existing outgoing transitions for this state
        $transitionsTable->deleteAll(['from_state_id' => (int)$stateId]);

        // Add new transitions
        foreach ($targetIds as $toStateId) {
            $transition = $transitionsTable->newEntity([
                'from_state_id' => (int)$stateId,
                'to_state_id' => (int)$toStateId,
            ]);
            $transitionsTable->save($transition);
        }

        Recommendation::clearCache();
        $this->Flash->success(__('Transitions updated.'));
        return $this->redirect(['action' => 'view', $stateId]);
    }

    /**
     * Cascade a state name change to recommendations and state transition logs.
     *
     * @param string $oldName Previous state name
     * @param string $newName New state name
     * @return void
     */
    private function cascadeStateRename(string $oldName, string $newName): void
    {
        $recommendationsTable = $this->fetchTable('Awards.Recommendations');
        $recommendationsTable->updateAll(
            ['state' => $newName],
            ['state' => $oldName]
        );

        $logsTable = $this->fetchTable('Awards.RecommendationsStatesLogs');
        $logsTable->updateAll(
            ['from_state' => $newName],
            ['from_state' => $oldName]
        );
        $logsTable->updateAll(
            ['to_state' => $newName],
            ['to_state' => $oldName]
        );
    }

    /**
     * Update the status column on recommendations when a state moves to a different status.
     *
     * @param string $stateName The state name whose recommendations need updating
     * @param int $newStatusId The new status ID to look up the name from
     * @return void
     */
    private function cascadeStateStatusChange(string $stateName, int $newStatusId): void
    {
        $newStatus = $this->RecommendationStates->RecommendationStatuses->get($newStatusId);
        $recommendationsTable = $this->fetchTable('Awards.Recommendations');
        $recommendationsTable->updateAll(
            ['status' => $newStatus->name],
            ['state' => $stateName]
        );
    }

    /**
     * Transfer recommendations from one state to another.
     *
     * @param string $fromState Source state name
     * @param string $toState Target state name
     * @param string $toStatus Target status name
     * @return void
     */
    private function transferRecommendations(string $fromState, string $toState, string $toStatus): void
    {
        $recommendationsTable = $this->fetchTable('Awards.Recommendations');
        $recommendationsTable->updateAll(
            ['state' => $toState, 'status' => $toStatus],
            ['state' => $fromState]
        );
    }
}
