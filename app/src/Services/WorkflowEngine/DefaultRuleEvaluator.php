<?php

declare(strict_types=1);

namespace App\Services\WorkflowEngine;

use App\Services\WorkflowEngine\Conditions\ConditionInterface;

/**
 * Parses and evaluates JSON condition DSL against runtime context.
 *
 * Supports boolean combinators (all, any, not) and pluggable condition types.
 * Fails closed: unknown condition types return false.
 */
class DefaultRuleEvaluator implements RuleEvaluatorInterface
{
    /** @var array<string, ConditionInterface> */
    protected array $conditionTypes = [];

    public function __construct()
    {
        $this->registerBuiltInConditions();
    }

    /**
     * @inheritDoc
     */
    public function evaluate(array $condition, array $context): bool
    {
        if (isset($condition['all'])) {
            return $this->evaluateAll($condition['all'], $context);
        }
        if (isset($condition['any'])) {
            return $this->evaluateAny($condition['any'], $context);
        }
        if (isset($condition['not'])) {
            return !$this->evaluate($condition['not'], $context);
        }

        return $this->evaluateCondition($condition, $context);
    }

    /**
     * @inheritDoc
     */
    public function registerConditionType(string $name, ConditionInterface $condition): void
    {
        $this->conditionTypes[$name] = $condition;
    }

    /**
     * @inheritDoc
     */
    public function getRegisteredConditionTypes(): array
    {
        return array_keys($this->conditionTypes);
    }

    protected function evaluateAll(array $conditions, array $context): bool
    {
        foreach ($conditions as $condition) {
            if (!$this->evaluate($condition, $context)) {
                return false;
            }
        }

        return true;
    }

    protected function evaluateAny(array $conditions, array $context): bool
    {
        foreach ($conditions as $condition) {
            if ($this->evaluate($condition, $context)) {
                return true;
            }
        }

        return false;
    }

    protected function evaluateCondition(array $condition, array $context): bool
    {
        $type = $this->detectConditionType($condition);

        if ($type === null || !isset($this->conditionTypes[$type])) {
            return false;
        }

        return $this->conditionTypes[$type]->evaluate($condition, $context);
    }

    protected function detectConditionType(array $condition): ?string
    {
        if (isset($condition['type'])) {
            return $condition['type'];
        }

        if (isset($condition['permission'])) return 'permission';
        if (isset($condition['role'])) return 'role';
        if (isset($condition['field'])) return 'field';
        if (isset($condition['ownership'])) return 'ownership';
        if (isset($condition['approval_gate'])) return 'approval_gate';
        if (isset($condition['time'])) return 'time';
        if (isset($condition['workflow_context'])) return 'workflow_context';

        return null;
    }

    protected function registerBuiltInConditions(): void
    {
        $this->registerConditionType('permission', new Conditions\PermissionCondition());
        $this->registerConditionType('role', new Conditions\RoleCondition());
        $this->registerConditionType('field', new Conditions\FieldCondition());
        $this->registerConditionType('ownership', new Conditions\OwnershipCondition());
        $this->registerConditionType('approval_gate', new Conditions\ApprovalGateCondition());
        $this->registerConditionType('time', new Conditions\TimeCondition());
        $this->registerConditionType('workflow_context', new Conditions\WorkflowContextCondition());
    }
}
