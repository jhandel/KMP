<?php

declare(strict_types=1);

namespace Officers\Services;

use App\KMP\TimezoneHelper;
use App\Services\ActiveWindowManager\ActiveWindowManagerInterface;
use App\Services\WarrantManager\WarrantManagerInterface;
use App\Services\WarrantManager\WarrantRequest;
use App\Services\WorkflowEngine\WorkflowContextAwareTrait;
use Cake\Core\App;
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

    public function __construct(
        ActiveWindowManagerInterface $activeWindowManager,
        WarrantManagerInterface $warrantManager,
    ) {
        $this->activeWindowManager = $activeWindowManager;
        $this->warrantManager = $warrantManager;
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
     * Release an officer from their position by setting expires_on.
     *
     * @param array $context Current workflow context
     * @param array $config Action config with officerId, reason, expiresOn
     * @return array Output with released boolean
     */
    public function releaseOfficer(array $context, array $config): array
    {
        try {
            $officerId = (int)$this->resolveValue($config['officerId'], $context);

            $expiresOnRaw = $this->resolveValue($config['expiresOn'] ?? null, $context);
            $expiresOn = $expiresOnRaw instanceof DateTime
                ? $expiresOnRaw
                : new DateTime($expiresOnRaw ?? 'now');

            $officerTable = TableRegistry::getTableLocator()->get('Officers.Officers');
            $officer = $officerTable->get($officerId);

            $officer->status = Officer::RELEASED_STATUS;
            $officer->expires_on = $expiresOn;
            $officer->revoked_reason = $this->resolveValue($config['reason'] ?? 'Released via workflow', $context);

            if (!$officerTable->save($officer)) {
                Log::error('Workflow ReleaseOfficer: failed to save officer');

                return ['released' => false];
            }

            return ['released' => true];
        } catch (\Throwable $e) {
            Log::error('Workflow ReleaseOfficer failed: ' . $e->getMessage());

            return ['released' => false];
        }
    }

    /**
     * Queue a hire notification email for a newly assigned officer.
     *
     * @param array $context Current workflow context
     * @param array $config Action config with officerId
     * @return array Output with sent boolean
     */
    public function sendHireNotification(array $context, array $config): array
    {
        try {
            $officerId = (int)$this->resolveValue($config['officerId'], $context);

            $officerTable = TableRegistry::getTableLocator()->get('Officers.Officers');
            $officer = $officerTable->get($officerId, contain: ['Offices']);

            $member = TableRegistry::getTableLocator()->get('Members')->get($officer->member_id);
            $branch = TableRegistry::getTableLocator()->get('Branches')->get($officer->branch_id);

            $mailerClass = App::className('Officers.Officers', 'Mailer', 'Mailer');
            $vars = [
                'to' => $member->email_address,
                'memberScaName' => $member->sca_name,
                'officeName' => $officer->office->name,
                'branchName' => $branch->name,
                'hireDate' => TimezoneHelper::formatDate($officer->start_on),
                'endDate' => TimezoneHelper::formatDate($officer->expires_on),
                'requiresWarrant' => $officer->office->requires_warrant,
            ];

            $queuedJobsTable = TableRegistry::getTableLocator()->get('Queue.QueuedJobs');
            $data = [
                'class' => $mailerClass,
                'action' => 'notifyOfHire',
                'vars' => $vars,
            ];
            $queuedJobsTable->createJob('Queue.Mailer', $data);

            return ['sent' => true];
        } catch (\Throwable $e) {
            Log::error('Workflow SendHireNotification failed: ' . $e->getMessage());

            return ['sent' => false];
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

            $warrantRequest = new WarrantRequest(
                "Hiring Warrant: {$branch->name} - {$officeName}",
                'Officers.Officers',
                $officer->id,
                $context['triggeredBy'] ?? 0,
                $officer->member_id,
                $officer->start_on,
                $officer->expires_on,
                $officer->granted_member_role_id,
            );

            $result = $this->warrantManager->request(
                "{$office->name} : {$member->sca_name}",
                '',
                [$warrantRequest],
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
}
