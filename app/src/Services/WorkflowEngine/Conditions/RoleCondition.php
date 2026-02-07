<?php

declare(strict_types=1);

namespace App\Services\WorkflowEngine\Conditions;

/**
 * Checks if the current user has a specified role.
 */
class RoleCondition implements ConditionInterface
{
    public function evaluate(array $params, array $context): bool
    {
        $requiredRole = $params['role'] ?? null;
        if ($requiredRole === null) {
            return false;
        }

        $userRoles = $context['user']['roles'] ?? [];

        return in_array($requiredRole, $userRoles, true);
    }

    public function getName(): string
    {
        return 'role';
    }

    public function getDescription(): string
    {
        return 'Checks if the current user has a specified role';
    }

    public function getParameterSchema(): array
    {
        return [
            'role' => ['type' => 'string', 'required' => true, 'description' => 'Required role name'],
        ];
    }
}
