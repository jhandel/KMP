<?php

declare(strict_types=1);

namespace Officers\Services;

use App\KMP\TimezoneHelper;
use App\Services\ActiveWindowManager\ActiveWindowManagerInterface;
use App\Services\WarrantManager\WarrantManagerInterface;
use App\Services\WarrantManager\WarrantRequest;
use App\Services\WorkflowEngine\WorkflowContextAwareTrait;
use Cake\I18n\DateTime;
use Cake\Log\Log;
use Cake\ORM\TableRegistry;
use Officers\Model\Entity\Officer;

/**
 * Workflow action implementations for officer lifecycle operations.
 *
 * Uses injected ActiveWindowManagerInterface and WarrantManagerInterface
 * rather than direct instantiation.
 */
class OfficerWorkflowActions
{
    use WorkflowContextAwareTrait;

    private ActiveWindowManagerInterface $activeWindowManager;
    private WarrantManagerInterface $warrantManager;
    private ?OfficerManagerInterface $officerManager;

    public function __construct(
        ActiveWindowManagerInterface $activeWindowManager,
        WarrantManagerInterface $warrantManager,
        ?OfficerManagerInterface $officerManager = null,
    ) {
        $this->activeWindowManager = $activeWindowManager;
        $this->warrantManager = $warrantManager;
        $this->officerManager = $officerManager;
    }

    /**
     * Create an officer record with full lifecycle: reporting fields,
     * one-per-branch conflict release, ActiveWindow start, and role assignment.
     *
     * @param array $context Current workflow context
     * @param array $config Action config
     * @return array Output with officerId
     */
    public function createOfficerRecord(array $context, array $config): array
    {
        try {
            $memberId = (int)$this->resolveValue($config['memberId'], $context);
            $officeId = (int)$this->resolveValue($config['officeId'], $context);
            $branchId = (int)$this->resolveValue($config['branchId'], $context);
            $approverId = (int)($context['triggeredBy'] ?? 0);

            $startOnRaw = $this->resolveValue($config['startOn'] ?? null, $context);
            $startOn = $startOnRaw instanceof DateTime ? $startOnRaw : new DateTime($startOnRaw ?? 'now');

            $expiresOnRaw = $this->resolveValue($config['expiresOn'] ?? null, $context);
            $expiresOn = null;
            if ($expiresOnRaw !== null && $expiresOnRaw !== '') {
                $expiresOn = $expiresOnRaw instanceof DateTime ? $expiresOnRaw : new DateTime($expiresOnRaw);
            }

            $emailAddress = $this->resolveValue($config['emailAddress'] ?? '', $context) ?? '';
            $deputyDescription = $this->resolveValue($config['deputyDescription'] ?? null, $context);

            $officerTable = TableRegistry::getTableLocator()->get('Officers.Officers');
            $officeTable = TableRegistry::getTableLocator()->get('Officers.Offices');
            $office = $officeTable->get($officeId);

            // Derive expiry from term length if not provided
            if ($expiresOn === null && $office->term_length > 0) {
                $expiresOn = $startOn->modify("+{$office->term_length} months");
            }

            // Determine status based on dates
            $status = Officer::UPCOMING_STATUS;
            if ($startOn->isToday() || $startOn->isPast()) {
                $status = Officer::CURRENT_STATUS;
            }
            if ($expiresOn !== null && $expiresOn->isPast()) {
                $status = Officer::EXPIRED_STATUS;
            }

            // Release current officers if one-per-branch
            if ($office->only_one_per_branch) {
                $currentOfficers = $officerTable->find()
                    ->where([
                        'office_id' => $officeId,
                        'branch_id' => $branchId,
                        'status' => Officer::CURRENT_STATUS,
                    ])
                    ->all();
                foreach ($currentOfficers as $existing) {
                    $existing->status = Officer::REPLACED_STATUS;
                    $existing->expires_on = $startOn;
                    $existing->revoked_reason = 'Replaced by new officer';
                    $officerTable->save($existing);
                }
            }

            $officer = $officerTable->newEmptyEntity();
            $officer->member_id = $memberId;
            $officer->office_id = $officeId;
            $officer->branch_id = $branchId;
            $officer->status = $status;
            $officer->start_on = $startOn;
            $officer->expires_on = $expiresOn;
            $officer->approver_id = $approverId;
            $officer->approval_date = DateTime::now();
            $officer->email_address = $emailAddress;
            $officer->deputy_description = $deputyDescription;

            // Calculate reporting relationships
            $reporting = $this->calculateReportingFields($office, $officer);
            $officer->reports_to_office_id = $reporting['reports_to_office_id'];
            $officer->reports_to_branch_id = $reporting['reports_to_branch_id'];
            $officer->deputy_to_office_id = $reporting['deputy_to_office_id'];
            $officer->deputy_to_branch_id = $reporting['deputy_to_branch_id'];

            if (!$officerTable->save($officer)) {
                Log::error('Workflow CreateOfficerRecord: failed to save officer');

                return ['officerId' => null];
            }

            // Start ActiveWindow (manages role assignment and temporal lifecycle)
            try {
                $awResult = $this->activeWindowManager->start(
                    'Officers.Officers',
                    $officer->id,
                    $approverId > 0 ? $approverId : $memberId,
                    $startOn,
                    $expiresOn,
                    $office->term_length,
                    $office->grants_role_id,
                    $office->only_one_per_branch,
                    $branchId,
                );
                if (!$awResult->success) {
                    Log::error('Workflow CreateOfficerRecord: ActiveWindow failed: ' . $awResult->reason);
                }
            } catch (\Throwable $e) {
                Log::error('Workflow CreateOfficerRecord: ActiveWindow exception: ' . $e->getMessage());
            }

            return ['officerId' => $officer->id];
        } catch (\Throwable $e) {
            Log::error('Workflow CreateOfficerRecord failed: ' . $e->getMessage());

            return ['officerId' => null];
        }
    }

    /**
     * Calculate reporting relationships for an officer based on office config.
     */
    private function calculateReportingFields(object $office, object $officer): array
    {
        $result = [
            'reports_to_office_id' => null,
            'reports_to_branch_id' => null,
            'deputy_to_office_id' => null,
            'deputy_to_branch_id' => null,
        ];

        if ($office->deputy_to_id != null) {
            $result['deputy_to_branch_id'] = $officer->branch_id;
            $result['deputy_to_office_id'] = $office->deputy_to_id;
            $result['reports_to_branch_id'] = $officer->branch_id;
            $result['reports_to_office_id'] = $office->deputy_to_id;
        } else {
            $result['reports_to_office_id'] = $office->reports_to_id;
            $branchTable = TableRegistry::getTableLocator()->get('Branches');
            $branch = $branchTable->get($officer->branch_id);

            if ($branch->parent_id != null) {
                $officesTable = TableRegistry::getTableLocator()->get('Officers.Offices');

                if (!$office->can_skip_report) {
                    $result['reports_to_branch_id'] = $officesTable->findCompatibleBranchForOffice(
                        $branch->parent_id,
                        $office->reports_to_id,
                    );
                } else {
                    $officerTable = TableRegistry::getTableLocator()->get('Officers.Officers');
                    $currentBranchId = $branch->parent_id;
                    $previousBranchId = $officer->branch_id;
                    $found = false;

                    while ($currentBranchId != null) {
                        $count = $officerTable->find('Current')
                            ->where(['branch_id' => $currentBranchId, 'office_id' => $office->reports_to_id])
                            ->count();
                        if ($count > 0) {
                            $result['reports_to_branch_id'] = $currentBranchId;
                            $found = true;
                            break;
                        }
                        $previousBranchId = $currentBranchId;
                        $currentBranch = $branchTable->get($currentBranchId);
                        $currentBranchId = $currentBranch->parent_id;
                    }

                    if (!$found) {
                        $result['reports_to_branch_id'] = $officesTable->findCompatibleBranchForOffice(
                            $previousBranchId,
                            $office->reports_to_id,
                        );
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Release an officer using the same lifecycle steps as the legacy manager path.
     *
     * This mirrors DefaultOfficerManager::release() without re-dispatching
     * Officers.Released from inside the workflow.
     *
     * @param array $context Current workflow context
     * @param array $config Action config with officerId, releasedById, reason, expiresOn
     * @return array Output with released boolean
     */
    public function releaseOfficer(array $context, array $config): array
    {
        try {
            $officerId = (int)$this->resolveValue($config['officerId'], $context);
            $releasedById = (int)($this->resolveValue(
                $config['releasedById'] ?? ($context['triggeredBy'] ?? null),
                $context,
            ) ?? 0);
            $reason = (string)$this->resolveValue($config['reason'] ?? 'Released via workflow', $context);

            $expiresOnRaw = $this->resolveValue($config['expiresOn'] ?? null, $context);
            $expiresOn = $expiresOnRaw instanceof DateTime
                ? $expiresOnRaw
                : new DateTime($expiresOnRaw ?? 'now');

            $officerTable = TableRegistry::getTableLocator()->get('Officers.Officers');
            $officer = $officerTable->get($officerId, contain: ['Offices']);

            $awResult = $this->activeWindowManager->stop(
                'Officers.Officers',
                $officerId,
                $releasedById,
                Officer::RELEASED_STATUS,
                $reason,
                $expiresOn,
            );
            if (!$awResult->success) {
                Log::error('Workflow ReleaseOfficer: ActiveWindow stop failed: ' . $awResult->reason);

                return ['released' => false, 'error' => $awResult->reason];
            }

            if ($officer->office->requires_warrant) {
                $wmResult = $this->warrantManager->cancelByEntity(
                    'Officers.Officers',
                    $officerId,
                    $reason,
                    $releasedById,
                    $expiresOn,
                );
                if (!$wmResult->success) {
                    Log::error('Workflow ReleaseOfficer: warrant cancellation failed: ' . $wmResult->reason);

                    return ['released' => false, 'error' => $wmResult->reason];
                }
            }

            return ['released' => true, 'officerId' => $officerId];
        } catch (\Throwable $e) {
            Log::error('Workflow ReleaseOfficer failed: ' . $e->getMessage());

            return ['released' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Request a warrant roster for a newly hired officer.
     * Creates the roster and dispatches Warrants.RosterCreated trigger,
     * which kicks off the warrant-roster approval workflow.
     *
     * @param array $context Current workflow context
     * @param array $config Action config with officerId
     * @return array Output with rosterId
     */
    public function requestWarrantRoster(array $context, array $config): array
    {
        try {
            $officerId = (int)$this->resolveValue($config['officerId'], $context);

            $officerTable = TableRegistry::getTableLocator()->get('Officers.Officers');
            $officer = $officerTable->get($officerId, contain: ['Offices']);

            $member = TableRegistry::getTableLocator()->get('Members')->get($officer->member_id);
            $branch = TableRegistry::getTableLocator()->get('Branches')->get($officer->branch_id);
            $office = $officer->office;

            $officeName = $office->name;
            if (!empty($officer->deputy_description)) {
                $officeName .= ' (' . $officer->deputy_description . ')';
            }

            $requestedBy = $context['triggeredBy'] ?? null;

            $warrantRequest = new WarrantRequest(
                "Hiring Warrant: {$branch->name} - {$officeName}",
                'Officers.Officers',
                $officer->id,
                $requestedBy ?? 0,
                $officer->member_id,
                $officer->start_on,
                $officer->expires_on,
                $officer->granted_member_role_id,
            );

            $result = $this->warrantManager->request(
                "{$office->name} : {$member->sca_name}",
                '',
                [$warrantRequest],
                $requestedBy,
            );

            if (!$result->success) {
                Log::error('Workflow RequestWarrantRoster: ' . $result->reason);

                return ['rosterId' => null];
            }

            return ['rosterId' => $result->data];
        } catch (\Throwable $e) {
            Log::error('Workflow RequestWarrantRoster failed: ' . $e->getMessage());

            return ['rosterId' => null];
        }
    }

    /**
     * Calculate reporting hierarchy fields for an officer.
     *
     * Standalone action wrapping the complex hierarchy traversal logic
     * so it can be invoked independently in a workflow step.
     *
     * @param array $context Current workflow context
     * @param array $config Action config with officeId, branchId, officerId
     * @return array Reporting field values
     */
    public function calculateReportingFieldsAction(array $context, array $config): array
    {
        try {
            $officeId = (int)$this->resolveValue($config['officeId'], $context);
            $branchId = (int)$this->resolveValue($config['branchId'], $context);

            $officeTable = TableRegistry::getTableLocator()->get('Officers.Offices');
            $office = $officeTable->get($officeId);

            // Build a minimal officer-like object for the calculation
            $officer = new \stdClass();
            $officer->branch_id = $branchId;
            $officer->deputy_description = $this->resolveValue($config['deputyDescription'] ?? null, $context);

            $result = $this->calculateReportingFields($office, $officer);

            return $result;
        } catch (\Throwable $e) {
            Log::error('Workflow CalculateReportingFields failed: ' . $e->getMessage());

            return [
                'reports_to_office_id' => null,
                'reports_to_branch_id' => null,
                'deputy_to_office_id' => null,
                'deputy_to_branch_id' => null,
            ];
        }
    }

    /**
     * Release existing officers when a one-per-branch office gets a new assignment.
     *
     * @param array $context Current workflow context
     * @param array $config Action config with officeId, branchId, newOfficerStartDate
     * @return array List of released officer IDs
     */
    public function releaseConflictingOfficers(array $context, array $config): array
    {
        try {
            $officeId = (int)$this->resolveValue($config['officeId'], $context);
            $branchId = (int)$this->resolveValue($config['branchId'], $context);

            $startDateRaw = $this->resolveValue($config['newOfficerStartDate'] ?? null, $context);
            $startDate = $startDateRaw instanceof DateTime
                ? $startDateRaw
                : new DateTime($startDateRaw ?? 'now');

            $officerTable = TableRegistry::getTableLocator()->get('Officers.Officers');

            $currentOfficers = $officerTable->find()
                ->where([
                    'office_id' => $officeId,
                    'branch_id' => $branchId,
                    'status' => Officer::CURRENT_STATUS,
                ])
                ->all();

            $releasedIds = [];
            foreach ($currentOfficers as $existing) {
                $existing->status = Officer::REPLACED_STATUS;
                $existing->expires_on = $startDate;
                $existing->revoked_reason = 'Replaced by new officer';
                if ($officerTable->save($existing)) {
                    $releasedIds[] = $existing->id;
                }
            }

            return ['releasedOfficerIds' => $releasedIds];
        } catch (\Throwable $e) {
            Log::error('Workflow ReleaseConflictingOfficers failed: ' . $e->getMessage());

            return ['releasedOfficerIds' => []];
        }
    }

    /**
     * Batch recalculate all officers when office configuration changes.
     *
     * Delegates to OfficerManagerInterface::recalculateOfficersForOffice().
     *
     * @param array $context Current workflow context
     * @param array $config Action config with officeId, updaterId
     * @return array Updated count
     */
    public function recalculateOfficersForOffice(array $context, array $config): array
    {
        try {
            $officeId = (int)$this->resolveValue($config['officeId'], $context);
            $updaterId = (int)$this->resolveValue(
                $config['updaterId'] ?? $context['triggeredBy'] ?? 0,
                $context,
            );

            if ($this->officerManager === null) {
                Log::error('Workflow RecalculateOfficersForOffice: OfficerManager not available');

                return ['updatedCount' => 0];
            }

            $result = $this->officerManager->recalculateOfficersForOffice($officeId, $updaterId);

            if (!$result->success) {
                Log::error('Workflow RecalculateOfficersForOffice: ' . $result->reason);

                return ['updatedCount' => 0];
            }

            return ['updatedCount' => $result->data['updated_count'] ?? 0];
        } catch (\Throwable $e) {
            Log::error('Workflow RecalculateOfficersForOffice failed: ' . $e->getMessage());

            return ['updatedCount' => 0];
        }
    }

    /**
     * Prepare all hire-notification email variables for use by Core.SendEmail.
     *
     * Loads officer, member, and branch records; formats dates; and resolves
     * the warrant-required notice text. Outputs vars for the
     * officer-hire-notification DB template into workflow context.
     *
     * @param array $context Current workflow context
     * @param array $config Action config with officerId
     * @return array Output with hire notification vars
     */
    public function prepareHireNotificationVars(array $context, array $config): array
    {
        try {
            $officerId = (int)$this->resolveValue($config['officerId'], $context);

            $officerTable = TableRegistry::getTableLocator()->get('Officers.Officers');
            $officer = $officerTable->get($officerId, contain: ['Offices']);

            $member = TableRegistry::getTableLocator()->get('Members')->get($officer->member_id);
            $branch = TableRegistry::getTableLocator()->get('Branches')->get($officer->branch_id);

            $requiresWarrantNotice = $officer->office->requires_warrant
                ? 'Please note that this office requires a warrant. '
                  . 'A request for that warrant has been forwarded to the Crown for approval.'
                : '';

            return [
                'success' => true,
                'data' => [
                    'to' => $member->email_address,
                    'memberScaName' => $member->sca_name,
                    'officeName' => $officer->office->name,
                    'branchName' => $branch->name,
                    'hireDate' => TimezoneHelper::formatDate($officer->start_on),
                    'endDate' => TimezoneHelper::formatDate($officer->expires_on),
                    'requiresWarrantNotice' => $requiresWarrantNotice,
                    'siteAdminSignature' => \App\KMP\StaticHelpers::getAppSetting(
                        'Email.SiteAdminSignature',
                        '',
                        null,
                        true,
                    ),
                ],
            ];
        } catch (\Throwable $e) {
            Log::error('Workflow PrepareHireNotificationVars failed: ' . $e->getMessage());

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Prepare all release-notification email variables for use by Core.SendEmail.
     *
     * Loads officer, member, and branch records; formats the release date;
     * and resolves the reason. Outputs vars for the officer-release-notification
     * DB template into workflow context.
     *
     * @param array $context Current workflow context
     * @param array $config Action config with officerId, reason
     * @return array Output with release notification vars
     */
    public function prepareReleaseNotificationVars(array $context, array $config): array
    {
        try {
            $officerId = (int)$this->resolveValue($config['officerId'], $context);

            $officerTable = TableRegistry::getTableLocator()->get('Officers.Officers');
            $officer = $officerTable->get($officerId, contain: ['Offices']);

            $member = TableRegistry::getTableLocator()->get('Members')->get($officer->member_id);
            $branch = TableRegistry::getTableLocator()->get('Branches')->get($officer->branch_id);

            $reason = (string)$this->resolveValue($config['reason'] ?? 'Released via workflow', $context);
            $releaseDate = $officer->expires_on ?? DateTime::now();

            return [
                'success' => true,
                'data' => [
                    'to' => $member->email_address,
                    'memberScaName' => $member->sca_name,
                    'officeName' => $officer->office->name,
                    'branchName' => $branch->name,
                    'reason' => $reason,
                    'releaseDate' => TimezoneHelper::formatDate($releaseDate),
                    'siteAdminSignature' => \App\KMP\StaticHelpers::getAppSetting(
                        'Email.SiteAdminSignature',
                        '',
                        null,
                        true,
                    ),
                ],
            ];
        } catch (\Throwable $e) {
            Log::error('Workflow PrepareReleaseNotificationVars failed: ' . $e->getMessage());

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
