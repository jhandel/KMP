<?php

declare(strict_types=1);

namespace Officers\Services;

use App\Services\WorkflowEngine\WorkflowContextAwareTrait;
use Cake\ORM\TableRegistry;

/**
 * Workflow condition evaluators for Officers plugin.
 */
class OfficerWorkflowConditions
{
    use WorkflowContextAwareTrait;

    /**
     * Check if an office requires a warrant.
     *
     * @param array $context Current workflow context
     * @param array $config Config with 'officeId'
     * @return bool
     */
    public function officeRequiresWarrant(array $context, array $config): bool
    {
        try {
            $officeId = $this->resolveValue($config['officeId'] ?? null, $context);

            if (empty($officeId)) {
                return false;
            }

            $officeTable = TableRegistry::getTableLocator()->get('Officers.Offices');
            $office = $officeTable->get((int)$officeId);

            return (bool)$office->requires_warrant;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Check if an office allows only one officer per branch.
     *
     * @param array $context Current workflow context
     * @param array $config Config with 'officeId'
     * @return bool
     */
    public function isOnlyOnePerBranch(array $context, array $config): bool
    {
        try {
            $officeId = $this->resolveValue($config['officeId'] ?? null, $context);

            if (empty($officeId)) {
                return false;
            }

            $officeTable = TableRegistry::getTableLocator()->get('Officers.Offices');
            $office = $officeTable->get((int)$officeId);

            return (bool)$office->only_one_per_branch;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Check if a member is warrantable (meets all warrant eligibility requirements).
     *
     * @param array $context Current workflow context
     * @param array $config Config with 'memberId'
     * @return bool
     */
    public function isMemberWarrantable(array $context, array $config): bool
    {
        try {
            $memberId = $this->resolveValue($config['memberId'] ?? null, $context);

            if (empty($memberId)) {
                return false;
            }

            $memberTable = TableRegistry::getTableLocator()->get('Members');
            $member = $memberTable->get((int)$memberId);

            return (bool)$member->warrantable;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
