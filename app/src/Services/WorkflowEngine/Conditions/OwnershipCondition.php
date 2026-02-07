<?php

declare(strict_types=1);

namespace App\Services\WorkflowEngine\Conditions;

/**
 * Checks if the current user owns or is related to the entity.
 */
class OwnershipCondition implements ConditionInterface
{
    public function evaluate(array $params, array $context): bool
    {
        $ownershipField = $params['ownership'] ?? null;
        if ($ownershipField === null) {
            return false;
        }

        $userId = $context['user']['id'] ?? null;
        if ($userId === null) {
            return false;
        }

        // Map common ownership types to field names
        $fieldMap = [
            'creator' => 'created_by',
            'owner' => 'owner_id',
            'assigned_to' => 'assigned_to',
            'member' => 'member_id',
        ];

        $entityField = $fieldMap[$ownershipField] ?? $ownershipField;
        $entityValue = $context['entity'][$entityField] ?? null;

        return $entityValue !== null && $userId == $entityValue;
    }

    public function getName(): string
    {
        return 'ownership';
    }

    public function getDescription(): string
    {
        return 'Checks if the current user owns or is related to the entity';
    }

    public function getParameterSchema(): array
    {
        return [
            'ownership' => [
                'type' => 'string',
                'required' => true,
                'description' => 'Ownership type (creator, owner, assigned_to, member, or custom field)',
            ],
        ];
    }
}
