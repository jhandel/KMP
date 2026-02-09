<?php

declare(strict_types=1);

namespace Officers\Services;

use App\Services\WorkflowEngine\Conditions\CoreConditions;
use Cake\ORM\TableRegistry;

/**
 * Workflow condition evaluators for Officers plugin.
 */
class OfficerWorkflowConditions
{
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
            $officeId = $config['officeId'] ?? null;
            if (is_string($officeId) && str_starts_with($officeId, '$.')) {
                $officeId = CoreConditions::resolveFieldPath($context, $officeId);
            }

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
            $officeId = $config['officeId'] ?? null;
            if (is_string($officeId) && str_starts_with($officeId, '$.')) {
                $officeId = CoreConditions::resolveFieldPath($context, $officeId);
            }

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
}
