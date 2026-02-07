<?php

declare(strict_types=1);

namespace App\Services\WorkflowEngine;

/**
 * Visibility Evaluator Interface
 *
 * Determines field-level and entity-level visibility/editability
 * based on the current workflow state and user context.
 */
interface VisibilityEvaluatorInterface
{
    /**
     * Check if user can view entity in current workflow state.
     *
     * @param int $instanceId Workflow instance ID
     * @param int|null $userId User to check (null = current user)
     * @return bool
     */
    public function canViewEntity(int $instanceId, ?int $userId = null): bool;

    /**
     * Check if user can edit entity in current workflow state.
     *
     * @param int $instanceId Workflow instance ID
     * @param int|null $userId User to check (null = current user)
     * @return bool
     */
    public function canEditEntity(int $instanceId, ?int $userId = null): bool;

    /**
     * Get visible fields for user in current workflow state.
     *
     * @param int $instanceId Workflow instance ID
     * @param int|null $userId User to check (null = current user)
     * @return array<string> List of visible field names
     */
    public function getVisibleFields(int $instanceId, ?int $userId = null): array;

    /**
     * Get editable fields for user in current workflow state.
     *
     * @param int $instanceId Workflow instance ID
     * @param int|null $userId User to check (null = current user)
     * @return array<string> List of editable field names
     */
    public function getEditableFields(int $instanceId, ?int $userId = null): array;

    /**
     * Evaluate a specific visibility rule.
     *
     * @param string $ruleType Type of visibility rule to evaluate
     * @param int $stateId Workflow state ID
     * @param int|null $userId User to check (null = current user)
     * @param array $context Additional context for evaluation
     * @return bool
     */
    public function evaluateRule(string $ruleType, int $stateId, ?int $userId = null, array $context = []): bool;
}
