<?php
declare(strict_types=1);

namespace App\Services\WorkflowEngine;

use App\Services\ServiceResult;

/**
 * Core orchestration service for the KMP workflow system.
 *
 * Manages workflow instances, state transitions, and plugin extensibility.
 */
interface WorkflowEngineInterface
{
    /**
     * Start a workflow for an entity.
     *
     * @param string $workflowSlug Workflow definition slug
     * @param string $entityType Entity type (e.g., 'AwardsRecommendations')
     * @param int $entityId Entity primary key ID
     * @param int|null $initiatedBy Member who initiated (null = system)
     * @return ServiceResult Contains the new workflow instance on success
     */
    public function startWorkflow(string $workflowSlug, string $entityType, int $entityId, ?int $initiatedBy = null): ServiceResult;

    /**
     * Attempt a named transition on a workflow instance.
     *
     * @param int $instanceId Workflow instance ID
     * @param string $transitionSlug Transition slug to attempt
     * @param int|null $triggeredBy Member who triggered (null = system)
     * @param array $context Additional context for condition evaluation and actions
     * @return ServiceResult Success/failure with transition details
     */
    public function transition(int $instanceId, string $transitionSlug, ?int $triggeredBy = null, array $context = []): ServiceResult;

    /**
     * Get available transitions for a workflow instance.
     *
     * @param int $instanceId Workflow instance ID
     * @param int|null $userId User to check permissions for (null = current user)
     * @return ServiceResult Contains array of available transition info
     */
    public function getAvailableTransitions(int $instanceId, ?int $userId = null): ServiceResult;

    /**
     * Get workflow instance for an entity.
     *
     * @param string $entityType Entity type
     * @param int $entityId Entity primary key ID
     * @return ServiceResult Contains the workflow instance or null
     */
    public function getInstanceForEntity(string $entityType, int $entityId): ServiceResult;

    /**
     * Get current state of a workflow instance.
     *
     * @param int $instanceId Workflow instance ID
     * @return ServiceResult Contains the current state definition
     */
    public function getCurrentState(int $instanceId): ServiceResult;

    /**
     * Register a custom condition type (for plugins).
     *
     * @param string $name Unique condition type name
     * @param callable $evaluator Function(array $params, array $context): bool
     * @return void
     */
    public function registerConditionType(string $name, callable $evaluator): void;

    /**
     * Register a custom action type (for plugins).
     *
     * @param string $name Unique action type name
     * @param callable $executor Function(array $params, array $context): void
     * @return void
     */
    public function registerActionType(string $name, callable $executor): void;

    /**
     * Process scheduled/automatic transitions (called by cron).
     *
     * @return ServiceResult Contains count of transitions processed
     */
    public function processScheduledTransitions(): ServiceResult;
}
