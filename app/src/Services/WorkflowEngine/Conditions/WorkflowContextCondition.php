<?php

declare(strict_types=1);

namespace App\Services\WorkflowEngine\Conditions;

/**
 * Checks accumulated workflow context data stored on the workflow instance.
 */
class WorkflowContextCondition implements ConditionInterface
{
    public function evaluate(array $params, array $context): bool
    {
        $key = $params['workflow_context'] ?? null;
        $operator = $params['operator'] ?? 'eq';

        if ($key === null) {
            return false;
        }

        $instanceContext = $context['instance']['context'] ?? [];
        $actualValue = $instanceContext[$key] ?? null;

        $compareValue = $params['value'] ?? null;

        return $this->compare($actualValue, $operator, $compareValue);
    }

    public function getName(): string
    {
        return 'workflow_context';
    }

    public function getDescription(): string
    {
        return 'Checks accumulated workflow context data on the instance';
    }

    public function getParameterSchema(): array
    {
        return [
            'workflow_context' => [
                'type' => 'string',
                'required' => true,
                'description' => 'The context key to check (e.g. approval_count)',
            ],
            'operator' => [
                'type' => 'string',
                'required' => true,
                'enum' => ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'in', 'not_in'],
            ],
            'value' => [
                'type' => 'mixed',
                'required' => true,
                'description' => 'Value to compare against',
            ],
        ];
    }

    protected function compare(mixed $actual, string $operator, mixed $expected): bool
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
            default => false,
        };
    }
}
