<?php
declare(strict_types=1);

namespace Activities\Services\WorkflowEngine\Actions;

use App\Services\ServiceResult;
use App\Services\WorkflowEngine\Actions\ActionInterface;
use Cake\Log\Log;

/**
 * Grants an activity authorization role when an authorization is approved.
 */
class GrantActivityRoleAction implements ActionInterface
{
    public function execute(array $params, array $context): ServiceResult
    {
        $entityId = $context['entity_id'] ?? null;
        $memberId = $context['triggered_by'] ?? null;
        $roleName = $params['role_name'] ?? 'unknown';

        // Stub: full integration in Phase 6
        Log::info("Activities: Granting role '{$roleName}' for authorization #{$entityId} to member #{$memberId}");

        return new ServiceResult(true, null, ['granted' => true, 'role_name' => $roleName]);
    }

    public function getName(): string
    {
        return 'grant_activity_role';
    }

    public function getDescription(): string
    {
        return 'Grants an activity authorization role when approved';
    }

    public function getParameterSchema(): array
    {
        return [
            'role_name' => [
                'type' => 'string',
                'required' => true,
                'description' => 'Name of the activity role to grant',
            ],
        ];
    }
}
