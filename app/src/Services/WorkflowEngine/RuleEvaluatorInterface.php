<?php

declare(strict_types=1);

namespace App\Services\WorkflowEngine;

use App\Services\WorkflowEngine\Conditions\ConditionInterface;

/**
 * Rule Evaluator Interface
 *
 * Evaluates condition DSL expressions against runtime context.
 * Plugins register custom condition types via registerConditionType().
 */
interface RuleEvaluatorInterface
{
    /**
     * Evaluate a condition DSL (JSON-decoded array) against context.
     *
     * @param array $condition Condition definition from workflow configuration
     * @param array $context Runtime context (entity, user, etc.)
     * @return bool Whether the condition is satisfied
     */
    public function evaluate(array $condition, array $context): bool;

    /**
     * Register a custom condition type.
     *
     * @param string $name Unique condition type name
     * @param ConditionInterface $condition Condition implementation
     * @return void
     */
    public function registerConditionType(string $name, ConditionInterface $condition): void;

    /**
     * Get list of registered condition type names.
     *
     * @return array<string>
     */
    public function getRegisteredConditionTypes(): array;
}
