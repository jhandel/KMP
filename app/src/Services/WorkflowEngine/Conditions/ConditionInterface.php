<?php

declare(strict_types=1);

namespace App\Services\WorkflowEngine\Conditions;

/**
 * Interface for custom workflow condition types.
 *
 * Plugins register implementations to extend the rule evaluator with domain-specific checks.
 */
interface ConditionInterface
{
    /**
     * Evaluate the condition against the given context.
     *
     * @param array $params Condition parameters from the workflow definition
     * @param array $context Runtime context (entity, user, etc.)
     * @return bool Whether the condition is satisfied
     */
    public function evaluate(array $params, array $context): bool;

    /**
     * @return string Machine-readable condition name
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
