<?php

declare(strict_types=1);

namespace App\Services\WorkflowEngine\Actions;

use App\Services\ServiceResult;

/**
 * Interface for custom workflow action types.
 *
 * Plugins register implementations to extend the action executor with domain-specific side-effects.
 */
interface ActionInterface
{
    /**
     * Execute the action with the given parameters and context.
     *
     * @param array $params Action parameters from the workflow definition
     * @param array $context Runtime context (entity, user, etc.)
     * @return ServiceResult Success/failure with details
     */
    public function execute(array $params, array $context): ServiceResult;

    /**
     * @return string Machine-readable action name
     */
    public function getName(): string;

    /**
     * @return string Human-readable description
     */
    public function getDescription(): string;

    /**
     * Return JSON-schema-style parameter definition for the visual editor.
     *
     * @return array Parameter schema
     */
    public function getParameterSchema(): array;
}
