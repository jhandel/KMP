<?php

declare(strict_types=1);

namespace Awards\Controller;

use App\Controller\DataverseGridTrait;
use Awards\Model\Entity\Recommendation;

/**
 * CRUD operations for recommendation status management.
 *
 * Statuses are the top-level workflow categories (e.g., "In Progress", "Closed").
 * Cannot delete a status that still has states belonging to it.
 * Renaming cascades to awards_recommendations and audit logs.
 *
 * @property \Awards\Model\Table\RecommendationStatusesTable $RecommendationStatuses
 */
class RecommendationStatusesController extends AppController
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
     * List all recommendation statuses.
     *
     * @return void
     */
    public function index(): void
    {
        $this->set('user', $this->request->getAttribute('identity'));
    }

    /**
     * Grid data endpoint for statuses listing.
     *
     * @param \App\Services\CsvExportService $csvExportService Injected CSV export service
     * @return \Cake\Http\Response|null|void
     */
    public function gridData(\App\Services\CsvExportService $csvExportService)
    {
        $baseQuery = $this->RecommendationStatuses->find()
            ->contain(['RecommendationStates']);

        $result = $this->processDataverseGrid([
            'gridKey' => 'Awards.RecommendationStatuses.index.main',
            'gridColumnsClass' => \Awards\KMP\GridColumns\RecommendationStatusesGridColumns::class,
            'baseQuery' => $baseQuery,
            'tableName' => 'RecommendationStatuses',
            'defaultSort' => ['RecommendationStatuses.sort_order' => 'asc'],
            'defaultPageSize' => 25,
            'showAllTab' => false,
            'canAddViews' => false,
            'canFilter' => false,
            'canExportCsv' => false,
        ]);

        if (!empty($result['isCsvExport'])) {
            return $this->handleCsvExport($result, $csvExportService, 'recommendation-statuses');
        }

        // Add computed state_count to each result
        foreach ($result['data'] as $status) {
            $status->state_count = count($status->recommendation_states ?? []);
        }

        $this->set([
            'statuses' => $result['data'],
            'gridState' => $result['gridState'],
            'columns' => $result['columnsMetadata'],
            'visibleColumns' => $result['visibleColumns'],
            'searchableColumns' => \Awards\KMP\GridColumns\RecommendationStatusesGridColumns::getSearchableColumns(),
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

        if ($turboFrame === 'recommendation-statuses-grid-table') {
            $this->set('data', $result['data']);
            $this->set('tableFrameId', 'recommendation-statuses-grid-table');
            $this->viewBuilder()->disableAutoLayout();
            $this->viewBuilder()->setTemplatePath('element');
            $this->viewBuilder()->setTemplate('dv_grid_table');
        } else {
            $this->set('data', $result['data']);
            $this->set('frameId', 'recommendation-statuses-grid');
            $this->viewBuilder()->disableAutoLayout();
            $this->viewBuilder()->setTemplatePath('element');
            $this->viewBuilder()->setTemplate('dv_grid_content');
        }
    }

    /**
     * View status details with associated states.
     *
     * @param string|null $id Status ID
     * @return void
     * @throws \Cake\Datasource\Exception\RecordNotFoundException
     */
    public function view($id = null): void
    {
        $status = $this->RecommendationStatuses->get($id, contain: [
            'RecommendationStates' => function ($q) {
                return $q->orderBy(['RecommendationStates.sort_order' => 'ASC']);
            },
        ]);
        if (!$status) {
            throw new \Cake\Http\Exception\NotFoundException();
        }
        $this->Authorization->authorize($status);
        $this->set(compact('status'));
    }

    /**
     * Create a new status.
     *
     * @return \Cake\Http\Response|null|void
     */
    public function add()
    {
        $status = $this->RecommendationStatuses->newEmptyEntity();
        if ($this->request->is('post')) {
            $status = $this->RecommendationStatuses->patchEntity(
                $status,
                $this->request->getData(),
            );
            if ($this->RecommendationStatuses->save($status)) {
                Recommendation::clearCache();
                $this->Flash->success(__('The Recommendation Status has been saved.'));
                return $this->redirect(['action' => 'view', $status->id]);
            }
            $this->Flash->error(__('The Recommendation Status could not be saved. Please, try again.'));
        }
        $this->set(compact('status'));
    }

    /**
     * Edit an existing status. Cascades name changes to recommendations and audit logs.
     *
     * @param string|null $id Status ID
     * @return \Cake\Http\Response|null|void
     * @throws \Cake\Datasource\Exception\RecordNotFoundException
     */
    public function edit($id = null)
    {
        $status = $this->RecommendationStatuses->get($id);
        if (!$status) {
            throw new \Cake\Http\Exception\NotFoundException();
        }
        $this->Authorization->authorize($status);

        if ($this->request->is(['patch', 'post', 'put'])) {
            $oldName = $status->name;
            $status = $this->RecommendationStatuses->patchEntity(
                $status,
                $this->request->getData(),
            );

            if ($this->RecommendationStatuses->save($status)) {
                // Cascade name change to recommendations and audit logs
                if ($oldName !== $status->name) {
                    $this->cascadeStatusRename($oldName, $status->name);
                }
                Recommendation::clearCache();
                $this->Flash->success(__('The Recommendation Status has been saved.'));
                return $this->redirect(['action' => 'view', $status->id]);
            }
            $this->Flash->error(__('The Recommendation Status could not be saved. Please, try again.'));
        }
        $this->set(compact('status'));
    }

    /**
     * Delete a status. Blocked if status has associated states.
     *
     * @param string|null $id Status ID
     * @return \Cake\Http\Response|null
     * @throws \Cake\Datasource\Exception\RecordNotFoundException
     */
    public function delete($id = null)
    {
        $this->request->allowMethod(['post', 'delete']);
        $status = $this->RecommendationStatuses->get($id, contain: ['RecommendationStates']);
        if (!$status) {
            throw new \Cake\Http\Exception\NotFoundException();
        }
        $this->Authorization->authorize($status);

        if (!empty($status->recommendation_states)) {
            $this->Flash->error(__(
                'Cannot delete this status because it still has {0} state(s). Remove or reassign all states first.',
                count($status->recommendation_states)
            ));
            return $this->redirect(['action' => 'view', $status->id]);
        }

        if ($this->RecommendationStatuses->delete($status)) {
            Recommendation::clearCache();
            $this->Flash->success(__('The Recommendation Status has been deleted.'));
        } else {
            $this->Flash->error(__('The Recommendation Status could not be deleted. Please, try again.'));
            return $this->redirect(['action' => 'view', $status->id]);
        }

        return $this->redirect(['action' => 'index']);
    }

    /**
     * Cascade a status name change to recommendations and state transition logs.
     *
     * @param string $oldName Previous status name
     * @param string $newName New status name
     * @return void
     */
    private function cascadeStatusRename(string $oldName, string $newName): void
    {
        $recommendationsTable = $this->fetchTable('Awards.Recommendations');
        $recommendationsTable->updateAll(
            ['status' => $newName],
            ['status' => $oldName]
        );

        $logsTable = $this->fetchTable('Awards.RecommendationsStatesLogs');
        $logsTable->updateAll(
            ['from_status' => $newName],
            ['from_status' => $oldName]
        );
        $logsTable->updateAll(
            ['to_status' => $newName],
            ['to_status' => $oldName]
        );
    }
}
