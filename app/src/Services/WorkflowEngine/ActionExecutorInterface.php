<?php

declare(strict_types=1);

namespace App\Services\WorkflowEngine;

use App\Services\ServiceResult;
use App\Services\WorkflowEngine\Actions\ActionInterface;

/**
 * Action Executor Interface
 *
 * Executes arrays of action definitions as part of workflow transitions.
 * Plugins register custom action types via registerActionType().
 */
interface ActionExecutorInterface
{
    /**
     * Execute an array of action definitions.
     *
     * @param array $actions Action definitions from workflow configuration
     * @param array $context Runtime context (entity, user, etc.)
     * @return ServiceResult Success/failure with details
     */
    public function execute(array $actions, array $context): ServiceResult;

    /**
     * Register a custom action type.
     *
     * @param string $name Unique action type name
     * @param ActionInterface $action Action implementation
     * @return void
     */
    public function registerActionType(string $name, ActionInterface $action): void;

    /**
     * Get list of registered action type names.
     *
     * @return array<string>
     */
    public function getRegisteredActionTypes(): array;
}
