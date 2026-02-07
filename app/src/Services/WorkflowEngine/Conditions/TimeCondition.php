<?php

declare(strict_types=1);

namespace App\Services\WorkflowEngine\Conditions;

use Cake\I18n\DateTime;

/**
 * Evaluates time-based conditions (state duration and date field comparisons).
 */
class TimeCondition implements ConditionInterface
{
    public function evaluate(array $params, array $context): bool
    {
        $timeType = $params['time'] ?? null;
        if ($timeType === null) {
            return false;
        }

        return match ($timeType) {
            'state_duration' => $this->evaluateStateDuration($params, $context),
            'field_date' => $this->evaluateFieldDate($params, $context),
            default => false,
        };
    }

    public function getName(): string
    {
        return 'time';
    }

    public function getDescription(): string
    {
        return 'Evaluates time-based conditions including state duration and date field comparisons';
    }

    public function getParameterSchema(): array
    {
        return [
            'time' => [
                'type' => 'string',
                'required' => true,
                'enum' => ['state_duration', 'field_date'],
            ],
            'operator' => [
                'type' => 'string',
                'required' => true,
                'enum' => ['eq', 'neq', 'gt', 'gte', 'lt', 'lte'],
            ],
            'value' => [
                'type' => 'mixed',
                'required' => true,
                'description' => 'Numeric duration or date string ("now" or ISO date)',
            ],
            'unit' => [
                'type' => 'string',
                'required' => false,
                'enum' => ['seconds', 'minutes', 'hours', 'days'],
                'description' => 'Time unit for state_duration',
            ],
            'field' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Entity date field path for field_date type',
            ],
        ];
    }

    protected function evaluateStateDuration(array $params, array $context): bool
    {
        $enteredAt = $context['state_entered_at'] ?? null;
        if ($enteredAt === null) {
            return false;
        }

        $enteredTime = $enteredAt instanceof \DateTimeInterface
            ? $enteredAt
            : new DateTime($enteredAt);

        $now = new DateTime();
        $diffSeconds = $now->getTimestamp() - $enteredTime->getTimestamp();

        $unit = $params['unit'] ?? 'hours';
        $duration = match ($unit) {
            'seconds' => $diffSeconds,
            'minutes' => $diffSeconds / 60,
            'hours' => $diffSeconds / 3600,
            'days' => $diffSeconds / 86400,
            default => $diffSeconds / 3600,
        };

        $operator = $params['operator'] ?? 'gt';
        $value = (float)($params['value'] ?? 0);

        return $this->compare($duration, $operator, $value);
    }

    protected function evaluateFieldDate(array $params, array $context): bool
    {
        $fieldPath = $params['field'] ?? null;
        if ($fieldPath === null) {
            return false;
        }

        $fieldValue = $this->resolveField($fieldPath, $context);
        if ($fieldValue === null) {
            return false;
        }

        $fieldDate = $fieldValue instanceof \DateTimeInterface
            ? $fieldValue
            : new DateTime($fieldValue);

        $compareValue = $params['value'] ?? 'now';
        $compareDate = ($compareValue === 'now')
            ? new DateTime()
            : new DateTime($compareValue);

        $operator = $params['operator'] ?? 'lt';

        return $this->compare(
            $fieldDate->getTimestamp(),
            $operator,
            $compareDate->getTimestamp(),
        );
    }

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

    protected function compare(float $a, string $operator, float $b): bool
    {
        return match ($operator) {
            'eq' => $a == $b,
            'neq' => $a != $b,
            'gt' => $a > $b,
            'gte' => $a >= $b,
            'lt' => $a < $b,
            'lte' => $a <= $b,
            default => false,
        };
    }
}
