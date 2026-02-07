<?php

declare(strict_types=1);

namespace App\Services\WorkflowEngine\Conditions;

/**
 * Checks if the current user has a specific permission string.
 */
class PermissionCondition implements ConditionInterface
{
    public function evaluate(array $params, array $context): bool
    {
        $permission = $params['permission'] ?? null;
        if ($permission === null) {
            return false;
        }

        $userPermissions = $context['user_permissions'] ?? [];

        return in_array($permission, $userPermissions, true);
    }

    public function getName(): string
    {
        return 'permission';
    }

    public function getDescription(): string
    {
        return 'Checks if the user has a specific permission';
    }

    public function getParameterSchema(): array
    {
        return [
            'permission' => [
                'type' => 'string',
                'required' => true,
                'description' => 'The permission string to check (e.g. Awards.Recommendations.canApproveLevel.AoA)',
            ],
        ];
    }
}
