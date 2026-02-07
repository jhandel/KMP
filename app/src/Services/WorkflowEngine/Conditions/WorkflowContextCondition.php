<?php

declare(strict_types=1);

namespace App\Services\WorkflowEngine\Conditions;

/**
 * Checks values in the workflow instance's context bag.
 */
class WorkflowContextCondition implements ConditionInterface
{
    public function evaluate(array $params, array $context): bool
    {
        $key = $params['workflow_context'] ?? null;
        if ($key === null) {
            return false;
        }

        $workflowContext = $context['workflow_context'] ?? [];
        $actual = $workflowContext[$key] ?? null;

        $operator = $params['operator'] ?? 'eq';
        $expected = $params['value'] ?? null;

        return $this->compare($actual, $operator, $expected);
    }

    public function getName(): string
    {
        return 'workflow_context';
    }

    public function getDescription(): string
    {
        return 'Checks values in the workflow instance context bag';
    }

    public function getParameterSchema(): array
    {
        return [
            'workflow_context' => ['type' => 'string', 'required' => true, 'description' => 'Context key to check'],
            'operator' => ['type' => 'string', 'required' => false, 'default' => 'eq'],
            'value' => ['type' => 'mixed', 'required' => false, 'description' => 'Value to compare against'],
        ];
    }

    private function compare(mixed $actual, string $operator, mixed $expected): bool
    {
        return match ($operator) {
            'eq' => $actual == $expected,
            'neq' => $actual != $expected,
            'gt' => $actual > $expected,
            'gte' => $actual >= $expected,
            'lt' => $actual < $expected,
            'lte' => $actual <= $expected,
            'in' => is_array($expected) && in_array($actual, $expected),
            'not_in' => is_array($expected) && !in_array($actual, $expected),
            'is_set' => $actual !== null,
            'is_empty' => $actual === null || $actual === '' || $actual === [],
            default => false,
        };
    }
}
