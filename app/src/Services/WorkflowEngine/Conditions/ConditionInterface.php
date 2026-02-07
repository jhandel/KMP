<?php

declare(strict_types=1);

namespace App\Services\WorkflowEngine\Conditions;

/**
 * Interface for pluggable condition types.
 *
 * Plugins register implementations to extend the rule evaluator.
 */
interface ConditionInterface
{
    /**
     * Evaluate the condition against runtime context.
     *
     * @param array $params Condition parameters from the workflow definition
     * @param array $context Runtime context (entity, user, subject, etc.)
     * @return bool True if condition is met
     */
    public function evaluate(array $params, array $context): bool;

    /** @return string Machine-readable condition name */
    public function getName(): string;

    /** @return string Human-readable description */
    public function getDescription(): string;

    /** @return array JSON-schema-style parameter definition */
    public function getParameterSchema(): array;
}
