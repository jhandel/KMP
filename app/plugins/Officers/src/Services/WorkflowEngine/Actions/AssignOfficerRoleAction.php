<?php
declare(strict_types=1);

namespace Officers\Services\WorkflowEngine\Actions;

use App\Services\ServiceResult;
use App\Services\WorkflowEngine\Actions\ActionInterface;
use Cake\Log\Log;

/**
 * Assigns the officer role when an officer is hired.
 */
class AssignOfficerRoleAction implements ActionInterface
{
    public function execute(array $params, array $context): ServiceResult
    {
        $entityId = $context['entity_id'] ?? null;
        $memberId = $context['triggered_by'] ?? null;
        $officeId = $params['office_id'] ?? null;

        // Stub: full integration in Phase 8
        Log::info("Officers: Assigning officer role for office #{$officeId} to member #{$memberId} (entity #{$entityId})");

        return new ServiceResult(true, null, [
            'assigned' => true,
            'office_id' => $officeId,
        ]);
    }

    public function getName(): string
    {
        return 'assign_officer_role';
    }

    public function getDescription(): string
    {
        return 'Assigns the officer role when an officer is hired';
    }

    public function getParameterSchema(): array
    {
        return [
            'office_id' => [
                'type' => 'integer',
                'required' => true,
                'description' => 'ID of the office to assign',
            ],
        ];
    }
}
