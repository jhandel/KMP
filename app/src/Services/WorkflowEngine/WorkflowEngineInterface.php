<?php

declare(strict_types=1);

namespace App\Services\WorkflowEngine;

use App\Services\ServiceResult;
use App\Services\WorkflowEngine\Actions\ActionInterface;
use App\Services\WorkflowEngine\Conditions\ConditionInterface;

/**
 * Workflow Engine Interface
 *
 * Core orchestration service for the KMP workflow system.
 * Manages workflow instances, state transitions, and plugin extensibility.
 *
 * @see \App\Services\WorkflowEngine\DefaultWorkflowEngine Default implementation
 */
interface WorkflowEngineInterface
{
    /**
     * Initiate a workflow for an entity.
     *
     * @param string $workflowSlug Workflow definition slug
     * @param string $entityType Entity type (e.g., 'Members', 'Awards')
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
     * Get available transitions for current user on a workflow instance.
     *
     * @param int $instanceId Workflow instance ID
     * @param int|null $userId User to check (null = current user)
     * @return ServiceResult Contains array of available transition definitions
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
     * @param ConditionInterface $condition Condition implementation
     * @return void
     */
    public function registerConditionType(string $name, ConditionInterface $condition): void;

    /**
     * Register a custom action type (for plugins).
     *
     * @param string $name Unique action type name
     * @param ActionInterface $action Action implementation
     * @return void
     */
    public function registerActionType(string $name, ActionInterface $action): void;

    /**
     * Process scheduled/automatic transitions (called by cron).
     *
     * @return ServiceResult Contains count of transitions processed
     */
    public function processScheduledTransitions(): ServiceResult;
}
