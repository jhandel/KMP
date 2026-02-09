<?php

declare(strict_types=1);

namespace Officers\Services;

use App\KMP\TimezoneHelper;
use App\Services\WorkflowEngine\Actions\CoreActions;
use Cake\I18n\DateTime;
use Cake\Log\Log;
use Cake\ORM\TableRegistry;
use Officers\Model\Entity\Officer;

/**
 * Workflow action implementations for officer lifecycle operations.
 */
class OfficerWorkflowActions
{
    /**
     * Resolve a config value from context if it starts with '$.'.
     *
     * @param mixed $value Raw value or context path
     * @param array $context Current workflow context
     * @return mixed
     */
    private function resolveValue(mixed $value, array $context): mixed
    {
        if (is_string($value) && str_starts_with($value, '$.')) {
            return \App\Services\WorkflowEngine\Conditions\CoreConditions::resolveFieldPath($context, $value);
        }

        return $value;
    }

    /**
     * Create an officer record for a member in an office.
     *
     * @param array $context Current workflow context
     * @param array $config Action config with memberId, officeId, branchId, startOn, expiresOn
     * @return array Output with officerId
     */
    public function createOfficerRecord(array $context, array $config): array
    {
        try {
            $memberId = (int)$this->resolveValue($config['memberId'], $context);
            $officeId = (int)$this->resolveValue($config['officeId'], $context);
            $branchId = (int)$this->resolveValue($config['branchId'], $context);

            $startOnRaw = $this->resolveValue($config['startOn'] ?? null, $context);
            $startOn = $startOnRaw instanceof DateTime ? $startOnRaw : new DateTime($startOnRaw ?? 'now');

            $expiresOnRaw = $this->resolveValue($config['expiresOn'] ?? null, $context);
            $expiresOn = null;
            if ($expiresOnRaw !== null) {
                $expiresOn = $expiresOnRaw instanceof DateTime ? $expiresOnRaw : new DateTime($expiresOnRaw);
            }

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

            $officer = $officerTable->newEmptyEntity();
            $officer->member_id = $memberId;
            $officer->office_id = $officeId;
            $officer->branch_id = $branchId;
            $officer->status = $status;
            $officer->start_on = $startOn;
            $officer->expires_on = $expiresOn;
            $officer->approver_id = $context['triggeredBy'] ?? null;
            $officer->approval_date = DateTime::now();

            if (!$officerTable->save($officer)) {
                Log::error('Workflow CreateOfficerRecord: failed to save officer');

                return ['officerId' => null];
            }

            return ['officerId' => $officer->id];
        } catch (\Throwable $e) {
            Log::error('Workflow CreateOfficerRecord failed: ' . $e->getMessage());

            return ['officerId' => null];
        }
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

            $vars = [
                'memberScaName' => $member->sca_name,
                'officeName' => $officer->office->name,
                'branchName' => $branch->name,
                'hireDate' => TimezoneHelper::formatDate($officer->start_on),
                'endDate' => TimezoneHelper::formatDate($officer->expires_on),
                'requiresWarrant' => $officer->office->requires_warrant,
            ];

            $queuedJobsTable = TableRegistry::getTableLocator()->get('Queue.QueuedJobs');
            $data = [
                'class' => 'Officers.Officers',
                'action' => 'notifyOfHire',
                'vars' => array_merge($vars, ['to' => $member->email_address]),
            ];
            $queuedJobsTable->createJob('Queue.Mailer', $data);

            return ['sent' => true];
        } catch (\Throwable $e) {
            Log::error('Workflow SendHireNotification failed: ' . $e->getMessage());

            return ['sent' => false];
        }
    }
}
