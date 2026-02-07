<?php

declare(strict_types=1);

namespace Activities\Controller;

use App\Controller\DataverseGridTrait;
use App\Services\CsvExportService;
use Cake\Log\Log;
use Cake\ORM\TableRegistry;
use Cake\I18n\DateTime;

/**
 * Activities Plugin Reports Controller
 *
 * Generates authorization reports with branch-scoped analytics, temporal filtering,
 * and activity-specific reporting for administrative oversight and compliance monitoring.
 *
 * @property \Activities\Model\Table\AuthorizationsTable $Authorizations
 * @package Activities\Controller
 */

class ReportsController extends AppController
{
    use DataverseGridTrait;
    /**
     * Initialize controller with authorization settings.
     *
     * @return void
     */
    public function initialize(): void
    {
        parent::initialize();
    }

    /**
     * Generate authorization report with member counts, activity rollups, and detailed listings.
     *
     * @return void
     */
    public function authorizations()
    {
        // Authorization validation - ensure user has permission to access reports
        $this->authorizeCurrentUrl();

        // Initialize variables for report generation
        $distincMemberCount = 0;

        // Load Activities table for activity selection and filtering
        $ActivitiesTbl = TableRegistry::getTableLocator()->get('Activities.Activities');
        $activitiesList = $ActivitiesTbl->find('list')->orderBy(['name' => 'ASC'])->toArray();

        // Default to all activities if none specified
        $default_activities = [];
        foreach ($activitiesList as $activityId => $activityName) {
            $default_activities[] = $activityId;
        }

        // Load Branches table for organizational hierarchy filtering
        $branchesTbl = TableRegistry::getTableLocator()->get('Branches');
        $branchesList = $branchesTbl->find('treeList', spacer: '-')->toArray();

        // Default validity date (tomorrow) for authorization checking
        $validOn = DateTime::now()->addDays(1);

        // Initialize result containers
        $memberRollup  = [];
        $memberListQuery = [];
        $activities = [];

        // Process query parameters if provided
        if ($this->request->getQuery('validOn')) {
            // Extract filter parameters
            $activities = $this->request->getQuery('activities');
            $filter_branch = $this->request->getQuery('branches');

            // Calculate valid branches including children in hierarchy
            $valid_branches = $branchesTbl->find('children', for: $filter_branch)->all()->extract('id')->toArray();
            $valid_branches[] = $filter_branch; // Include parent branch

            // Parse validity date
            $validOn = (new DateTime($this->request->getQuery('validOn')))->addDays(1);

            // Load Authorizations table for data queries
            $authTbl = TableRegistry::getTableLocator()->get('Activities.Authorizations');

            // Calculate distinct member count with authorization filters
            $distincMemberCount = $authTbl->find()
                ->select('member_id')
                ->contain(['Members' => function ($q) use ($valid_branches) {
                    return $q->select(['id'])->where(['branch_id IN' => $valid_branches]);
                }])
                ->where([
                    "or" => [
                        "start_on <=" => $validOn,
                        "start_on IS" => null
                    ],
                    "expires_on >" => $validOn,
                    "activity_id IN" => $activities
                ])
                ->distinct('member_id')
                ->count();

            // Generate detailed member listing with authorization details
            $memberListQuery = $authTbl->find('all')
                ->contain(['Activities' => function ($q) {
                    return $q->select(['name']);
                }, 'Members' => function ($q) use ($valid_branches) {
                    return $q->select(['membership_number', 'sca_name', 'id'])->where(['branch_id IN' => $valid_branches]);
                }, "Members.Branches" => function ($q) {
                    return $q->select(['name']);
                }])
                ->where([
                    "or" => [
                        "start_on <=" => $validOn,
                        "start_on IS" => null
                    ],
                    "expires_on >" => $validOn,
                    "activity_id IN" => $activities
                ])
                ->orderBy(['Activities.name' => 'ASC', 'Members.sca_name' => 'ASC'])
                ->all();

            // Generate statistical rollup by activity type
            $authTypes = $authTbl->find('all')->contain('Activities');
            $memberRollup = $authTypes
                ->select(["auth" => 'Activities.name', "count" => $authTypes->func()->count('member_id')])
                ->contain(['Members' => function ($q) use ($valid_branches) {
                    return $q->select(['id'])->where(['branch_id IN' => $valid_branches]);
                }])
                ->where([
                    "or" => [
                        "start_on <=" => $validOn,
                        "start_on IS" => null
                    ],
                    "expires_on >" => $validOn,
                    "activity_id IN" => $activities
                ])
                ->groupBy(['Activities.name'])
                ->all();
        }

        // Adjust validity date for display (subtract the added day)
        $validOn = $validOn->subDays(1);

        // Use default activities if none selected
        if (!$activities) {
            $activities = $default_activities;
        }

        // Set template variables for view rendering
        $this->set(compact(
            'activitiesList',      // Available activities for filter selection
            'branchesList',        // Branch hierarchy for organizational filtering
            'distincMemberCount',  // Total unique authorized members
            'validOn',             // Target date for report validity
            'memberRollup',        // Statistical summary by activity type
            'memberListQuery',     // Detailed member authorization listings
            'activities',          // Selected activity IDs for filtering
        ));
    }

    /**
     * Display the all authorizations admin report page.
     *
     * @return void
     */
    public function allAuthorizations()
    {
        $this->authorizeCurrentUrl();
    }

    /**
     * Dataverse grid data endpoint for all authorizations report.
     *
     * @param \App\Services\CsvExportService $csvExportService Injected CSV export service
     * @return \Cake\Http\Response|null|void
     */
    public function allAuthorizationsGridData(CsvExportService $csvExportService)
    {
        $this->authorizeCurrentUrl();

        $authTbl = $this->fetchTable('Activities.Authorizations');
        $baseQuery = $authTbl->find()
            ->contain([
                'Activities' => function ($q) {
                    return $q->select(['id', 'name']);
                },
                'Members' => function ($q) {
                    return $q->select(['id', 'sca_name', 'branch_id']);
                },
                'Members.Branches' => function ($q) {
                    return $q->select(['id', 'name']);
                },
            ]);

        $systemViews = \Activities\KMP\GridColumns\AllAuthorizationsGridColumns::getSystemViews();

        $result = $this->processDataverseGrid([
            'gridKey' => 'Activities.Reports.allAuthorizations',
            'gridColumnsClass' => \Activities\KMP\GridColumns\AllAuthorizationsGridColumns::class,
            'baseQuery' => $baseQuery,
            'tableName' => 'Authorizations',
            'defaultSort' => ['Authorizations.created' => 'desc'],
            'defaultPageSize' => 25,
            'systemViews' => $systemViews,
            'defaultSystemView' => 'all',
            'showAllTab' => false,
            'canAddViews' => true,
            'canFilter' => true,
            'canExportCsv' => true,
            'showViewTabs' => true,
            'enableColumnPicker' => true,
        ]);

        if (!empty($result['isCsvExport'])) {
            return $this->handleCsvExport($result, $csvExportService, 'all-authorizations', 'Activities.Authorizations');
        }

        $this->set($result);
        $this->set('customElement', 'Activities.all_authorizations_table');

        // Determine which template to render based on Turbo-Frame header
        $turboFrame = $this->request->getHeaderLine('Turbo-Frame');

        // Use main app's element templates (not plugin templates)
        $this->viewBuilder()->setPlugin(null);

        if ($turboFrame === 'all-authorizations-grid-table') {
            $this->set('tableFrameId', 'all-authorizations-grid-table');
            $this->viewBuilder()->disableAutoLayout();
            $this->viewBuilder()->setTemplatePath('element');
            $this->viewBuilder()->setTemplate('dv_grid_table');
        } else {
            $this->set('frameId', 'all-authorizations-grid');
            $this->viewBuilder()->disableAutoLayout();
            $this->viewBuilder()->setTemplatePath('element');
            $this->viewBuilder()->setTemplate('dv_grid_content');
        }
    }

    /**
     * Apply temporal validity filter to authorization query.
     *
     * @param \Cake\ORM\Query $q The base query object to filter
     * @param \Cake\I18n\DateTime $validOn The target date for validity checking
     * @return \Cake\ORM\Query The modified query with temporal filters applied
     */
    protected function setValidFilter($q, $validOn)
    {
        return $q->where([
            "OR" => [
                "start_on <=" => $validOn,
                "start_on IS" => null
            ]
        ])->where([
            "OR" => [
                "expires_on >=" => $validOn,
                "expires_on IS" => null
            ]
        ]);
    }
}
