<?php
declare(strict_types=1);

namespace App\Services\WorkflowEngine\Actions;

use App\Services\ServiceResult;

/**
 * Interface for pluggable workflow action types.
 *
 * Plugins register implementations to extend the action executor.
 */
interface ActionInterface
{
    /**
     * Execute the action.
     *
     * @param array $params Action parameters from workflow definition
     * @param array $context Runtime context
     * @return ServiceResult
     */
    public function execute(array $params, array $context): ServiceResult;

    /** @return string Machine-readable action name */
    public function getName(): string;

    /** @return string Human-readable description */
    public function getDescription(): string;

    /** @return array JSON-schema-style parameter definition */
    public function getParameterSchema(): array;
}
