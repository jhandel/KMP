<?php

declare(strict_types=1);

namespace App\Services\WorkflowEngine\Conditions;

/**
 * Checks if the current user has a specific role.
 */
class RoleCondition implements ConditionInterface
{
    public function evaluate(array $params, array $context): bool
    {
        $role = $params['role'] ?? null;
        if ($role === null) {
            return false;
        }

        $userRoles = $context['user_roles'] ?? [];

        return in_array($role, $userRoles, true);
    }

    public function getName(): string
    {
        return 'role';
    }

    public function getDescription(): string
    {
        return 'Checks if the user has a specific role';
    }

    public function getParameterSchema(): array
    {
        return [
            'role' => [
                'type' => 'string',
                'required' => true,
                'description' => 'The role name to check (e.g. Crown, Steward)',
            ],
        ];
    }
}
