<?php

declare(strict_types=1);

namespace App\Services\WorkflowEngine\Conditions;

/**
 * Checks the current user's ownership relationship to the entity.
 */
class OwnershipCondition implements ConditionInterface
{
    public function evaluate(array $params, array $context): bool
    {
        $type = $params['ownership'] ?? null;
        if ($type === null) {
            return false;
        }

        $userId = $context['user_id'] ?? null;
        if ($userId === null) {
            return false;
        }

        $entity = $context['entity'] ?? [];

        return match ($type) {
            'requester' => $this->isRequester($userId, $entity),
            'recipient' => $this->isRecipient($userId, $entity),
            'parent_of_minor' => $this->isParentOfMinor($context, $entity),
            'any' => $this->isRequester($userId, $entity)
                || $this->isRecipient($userId, $entity)
                || $this->isParentOfMinor($context, $entity),
            default => false,
        };
    }

    public function getName(): string
    {
        return 'ownership';
    }

    public function getDescription(): string
    {
        return 'Checks the user\'s relationship to the entity (requester, recipient, parent_of_minor, any)';
    }

    public function getParameterSchema(): array
    {
        return [
            'ownership' => [
                'type' => 'string',
                'required' => true,
                'enum' => ['requester', 'recipient', 'parent_of_minor', 'any'],
                'description' => 'The ownership relationship to check',
            ],
        ];
    }

    protected function isRequester(mixed $userId, array $entity): bool
    {
        return ($entity['requester_id'] ?? null) == $userId
            || ($entity['created_by'] ?? null) == $userId;
    }

    protected function isRecipient(mixed $userId, array $entity): bool
    {
        return ($entity['member_id'] ?? null) == $userId;
    }

    protected function isParentOfMinor(array $context, array $entity): bool
    {
        $managedIds = $context['user_managed_member_ids'] ?? [];
        $memberId = $entity['member_id'] ?? null;

        if ($memberId === null || empty($managedIds)) {
            return false;
        }

        return in_array($memberId, $managedIds);
    }
}
