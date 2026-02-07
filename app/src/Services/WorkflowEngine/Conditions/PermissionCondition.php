<?php

declare(strict_types=1);

namespace App\Services\WorkflowEngine\Conditions;

/**
 * Checks if the current user has a specified permission.
 */
class PermissionCondition implements ConditionInterface
{
    public function evaluate(array $params, array $context): bool
    {
        $requiredPermission = $params['permission'] ?? null;
        if ($requiredPermission === null) {
            return false;
        }

        $userPermissions = $context['user']['permissions'] ?? [];

        return in_array($requiredPermission, $userPermissions, true);
    }

    public function getName(): string
    {
        return 'permission';
    }

    public function getDescription(): string
    {
        return 'Checks if the current user has a specified permission';
    }

    public function getParameterSchema(): array
    {
        return [
            'permission' => ['type' => 'string', 'required' => true, 'description' => 'Required permission name'],
        ];
    }
}
