<?php

declare(strict_types=1);

namespace App\Services\WorkflowEngine\Conditions;

/**
 * Compares entity field values using dot notation and comparison operators.
 *
 * Supports: eq, neq, gt, gte, lt, lte, in, not_in, is_set, is_empty,
 * contains, starts_with, ends_with.
 */
class FieldCondition implements ConditionInterface
{
    public function evaluate(array $params, array $context): bool
    {
        $fieldPath = $params['field'] ?? null;
        if ($fieldPath === null) {
            return false;
        }

        $operator = $params['operator'] ?? 'eq';
        $expected = $params['value'] ?? null;
        $resolved = $this->resolveField($fieldPath, $context);

        return $this->compare($resolved, $operator, $expected);
    }

    public function getName(): string
    {
        return 'field';
    }

    public function getDescription(): string
    {
        return 'Compares entity field values using dot notation and comparison operators';
    }

    public function getParameterSchema(): array
    {
        return [
            'field' => ['type' => 'string', 'required' => true, 'description' => 'Dot-notation field path'],
            'operator' => [
                'type' => 'string',
                'required' => false,
                'default' => 'eq',
                'enum' => ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'in', 'not_in', 'is_set', 'is_empty', 'contains', 'starts_with', 'ends_with'],
            ],
            'value' => ['type' => 'mixed', 'required' => false, 'description' => 'Value to compare against'],
        ];
    }

    /**
     * Resolve a dot-notation path from the context array.
     *
     * @param string $path Dot-notation path (e.g., "entity.status")
     * @param array $context Runtime context
     * @return mixed Resolved value or null
     */
    private function resolveField(string $path, array $context): mixed
    {
        // Try direct resolution first
        $value = $this->resolvePath($path, $context);
        if ($value !== null) {
            return $value;
        }

        // Try under 'entity' key if path doesn't start with 'entity.'
        if (!str_starts_with($path, 'entity.') && isset($context['entity'])) {
            return $this->resolvePath($path, $context['entity']);
        }

        return null;
    }

    private function resolvePath(string $path, array $data): mixed
    {
        $keys = explode('.', $path);
        $current = $data;

        foreach ($keys as $key) {
            if (is_array($current) && array_key_exists($key, $current)) {
                $current = $current[$key];
            } else {
                return null;
            }
        }

        return $current;
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
            'contains' => is_string($actual) && is_string($expected) && str_contains($actual, $expected),
            'starts_with' => is_string($actual) && is_string($expected) && str_starts_with($actual, $expected),
            'ends_with' => is_string($actual) && is_string($expected) && str_ends_with($actual, $expected),
            default => false,
        };
    }
}
