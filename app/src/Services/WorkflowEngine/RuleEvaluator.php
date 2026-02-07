<?php

declare(strict_types=1);

namespace App\Services\WorkflowEngine;

use App\Services\WorkflowEngine\Conditions\ConditionInterface;

/**
 * Parses and evaluates JSON condition DSL with boolean combinators.
 *
 * Supports: all (AND), any (OR), not (invert). Fails closed on unknown types.
 */
class RuleEvaluator
{
    /** @var array<string, ConditionInterface> */
    private array $conditionTypes = [];

    public function __construct()
    {
        $this->registerBuiltIns();
    }

    /**
     * Evaluate a condition tree against runtime context.
     *
     * @param array $condition Condition tree (may contain all/any/not combinators)
     * @param array $context Runtime context
     * @return bool True if condition is met
     */
    public function evaluate(array $condition, array $context): bool
    {
        if (empty($condition)) {
            return true;
        }

        if (isset($condition['all'])) {
            foreach ($condition['all'] as $sub) {
                if (!$this->evaluate($sub, $context)) {
                    return false;
                }
            }

            return true;
        }

        if (isset($condition['any'])) {
            foreach ($condition['any'] as $sub) {
                if ($this->evaluate($sub, $context)) {
                    return true;
                }
            }

            return false;
        }

        if (isset($condition['not'])) {
            return !$this->evaluate($condition['not'], $context);
        }

        return $this->evaluateSingle($condition, $context);
    }

    /**
     * Register a custom condition type.
     *
     * @param string $name Condition type name
     * @param ConditionInterface $condition Condition implementation
     */
    public function registerConditionType(string $name, ConditionInterface $condition): void
    {
        $this->conditionTypes[$name] = $condition;
    }

    /**
     * @return string[] Registered condition type names
     */
    public function getRegisteredTypes(): array
    {
        return array_keys($this->conditionTypes);
    }

    private function evaluateSingle(array $condition, array $context): bool
    {
        $type = $condition['type'] ?? $this->detectType($condition);
        if ($type === null || !isset($this->conditionTypes[$type])) {
            return false; // Fail closed
        }

        return $this->conditionTypes[$type]->evaluate($condition, $context);
    }

    /**
     * Auto-detect condition type from known keys.
     */
    private function detectType(array $condition): ?string
    {
        foreach (['field', 'role', 'permission', 'ownership', 'time', 'workflow_context', 'approval_gate'] as $key) {
            if (isset($condition[$key])) {
                return $key;
            }
        }

        return null;
    }

    private function registerBuiltIns(): void
    {
        $this->registerConditionType('field', new Conditions\FieldCondition());
        $this->registerConditionType('role', new Conditions\RoleCondition());
        $this->registerConditionType('permission', new Conditions\PermissionCondition());
        $this->registerConditionType('ownership', new Conditions\OwnershipCondition());
        $this->registerConditionType('time', new Conditions\TimeCondition());
        $this->registerConditionType('workflow_context', new Conditions\WorkflowContextCondition());
        $this->registerConditionType('approval_gate', new Conditions\ApprovalGateCondition());
    }
}
