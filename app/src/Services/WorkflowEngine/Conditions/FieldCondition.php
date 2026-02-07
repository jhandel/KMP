<?php

declare(strict_types=1);

namespace App\Services\WorkflowEngine\Conditions;

/**
 * Compares entity field values using dot notation and comparison operators.
 */
class FieldCondition implements ConditionInterface
{
    public function evaluate(array $params, array $context): bool
    {
        $fieldPath = $params['field'] ?? null;
        $operator = $params['operator'] ?? 'eq';

        if ($fieldPath === null) {
            return false;
        }

        $fieldValue = $this->resolveField($fieldPath, $context);

        // Operators that don't need a comparison value
        if ($operator === 'is_set') {
            return $fieldValue !== null;
        }
        if ($operator === 'is_empty') {
            return $fieldValue === null || $fieldValue === '' || $fieldValue === [];
        }

        $compareValue = $params['value'] ?? null;

        return $this->compare($fieldValue, $operator, $compareValue);
    }

    public function getName(): string
    {
        return 'field';
    }

    public function getDescription(): string
    {
        return 'Compares entity field values with support for dot notation and multiple operators';
    }

    public function getParameterSchema(): array
    {
        return [
            'field' => [
                'type' => 'string',
                'required' => true,
                'description' => 'Dot-notation field path (e.g. award.level)',
            ],
            'operator' => [
                'type' => 'string',
                'required' => true,
                'enum' => ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'in', 'not_in', 'is_set', 'is_empty', 'contains', 'starts_with', 'ends_with'],
            ],
            'value' => [
                'type' => 'mixed',
                'required' => false,
                'description' => 'Value to compare against (not required for is_set/is_empty)',
            ],
        ];
    }

    /**
     * Resolve a dot-notation path from context, falling back to entity sub-path.
     */
    protected function resolveField(string $path, array $context): mixed
    {
        // Try direct path from context root
        $value = $this->getNestedValue($context, $path);
        if ($value !== null) {
            return $value;
        }

        // Fallback: try under entity
        return $this->getNestedValue($context, 'entity.' . $path);
    }

    protected function getNestedValue(array $data, string $path): mixed
    {
        $keys = explode('.', $path);
        $current = $data;

        foreach ($keys as $key) {
            if (!is_array($current) || !array_key_exists($key, $current)) {
                return null;
            }
            $current = $current[$key];
        }

        return $current;
    }

    protected function compare(mixed $fieldValue, string $operator, mixed $compareValue): bool
    {
        return match ($operator) {
            'eq' => $fieldValue == $compareValue,
            'neq' => $fieldValue != $compareValue,
            'gt' => $fieldValue > $compareValue,
            'gte' => $fieldValue >= $compareValue,
            'lt' => $fieldValue < $compareValue,
            'lte' => $fieldValue <= $compareValue,
            'in' => is_array($compareValue) && in_array($fieldValue, $compareValue),
            'not_in' => is_array($compareValue) && !in_array($fieldValue, $compareValue),
            'contains' => is_string($fieldValue) && is_string($compareValue) && str_contains($fieldValue, $compareValue),
            'starts_with' => is_string($fieldValue) && is_string($compareValue) && str_starts_with($fieldValue, $compareValue),
            'ends_with' => is_string($fieldValue) && is_string($compareValue) && str_ends_with($fieldValue, $compareValue),
            default => false,
        };
    }
}
