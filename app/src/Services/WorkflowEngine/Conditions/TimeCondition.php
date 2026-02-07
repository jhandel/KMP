<?php

declare(strict_types=1);

namespace App\Services\WorkflowEngine\Conditions;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Temporal conditions for scheduled transitions.
 *
 * Modes: after, before, between, elapsed_days, business_hours.
 */
class TimeCondition implements ConditionInterface
{
    public function evaluate(array $params, array $context): bool
    {
        $mode = $params['time'] ?? null;
        if ($mode === null) {
            return false;
        }

        $now = $this->getCurrentTime($context);

        return match ($mode) {
            'after' => $this->evaluateAfter($params, $context, $now),
            'before' => $this->evaluateBefore($params, $context, $now),
            'between' => $this->evaluateBetween($params, $context, $now),
            'elapsed_days' => $this->evaluateElapsedDays($params, $context, $now),
            'business_hours' => $this->evaluateBusinessHours($now),
            default => false,
        };
    }

    public function getName(): string
    {
        return 'time';
    }

    public function getDescription(): string
    {
        return 'Temporal conditions for scheduled transitions';
    }

    public function getParameterSchema(): array
    {
        return [
            'time' => [
                'type' => 'string',
                'required' => true,
                'enum' => ['after', 'before', 'between', 'elapsed_days', 'business_hours'],
            ],
            'date_field' => ['type' => 'string', 'required' => false, 'description' => 'Dot-notation path to date field'],
            'value' => ['type' => 'mixed', 'required' => false, 'description' => 'Date string or numeric value'],
            'operator' => ['type' => 'string', 'required' => false, 'description' => 'Comparison operator for elapsed_days'],
        ];
    }

    private function getCurrentTime(array $context): DateTimeImmutable
    {
        // Allow test injection of current time
        if (isset($context['_now'])) {
            $now = $context['_now'];
            if ($now instanceof DateTimeInterface) {
                return DateTimeImmutable::createFromInterface($now);
            }

            return new DateTimeImmutable($now);
        }

        return new DateTimeImmutable();
    }

    private function resolveDate(array $params, array $context, string $key = 'date_field'): ?DateTimeImmutable
    {
        $fieldPath = $params[$key] ?? null;

        if ($fieldPath !== null) {
            $value = $this->resolvePath($fieldPath, $context);
            if ($value === null) {
                return null;
            }
            if ($value instanceof DateTimeInterface) {
                return DateTimeImmutable::createFromInterface($value);
            }

            return new DateTimeImmutable((string)$value);
        }

        // Fall back to 'value' param as literal date
        $literal = $params['value'] ?? null;
        if ($literal === null) {
            return null;
        }

        return new DateTimeImmutable((string)$literal);
    }

    private function resolvePath(string $path, array $data): mixed
    {
        $keys = explode('.', $path);
        $current = $data;

        foreach ($keys as $k) {
            if (is_array($current) && array_key_exists($k, $current)) {
                $current = $current[$k];
            } else {
                return null;
            }
        }

        return $current;
    }

    private function evaluateAfter(array $params, array $context, DateTimeImmutable $now): bool
    {
        $target = $this->resolveDate($params, $context);

        return $target !== null && $now > $target;
    }

    private function evaluateBefore(array $params, array $context, DateTimeImmutable $now): bool
    {
        $target = $this->resolveDate($params, $context);

        return $target !== null && $now < $target;
    }

    private function evaluateBetween(array $params, array $context, DateTimeImmutable $now): bool
    {
        $start = isset($params['start']) ? new DateTimeImmutable($params['start']) : null;
        $end = isset($params['end']) ? new DateTimeImmutable($params['end']) : null;

        if ($start === null || $end === null) {
            return false;
        }

        return $now >= $start && $now <= $end;
    }

    private function evaluateElapsedDays(array $params, array $context, DateTimeImmutable $now): bool
    {
        $fieldPath = $params['date_field'] ?? null;
        if ($fieldPath === null) {
            return false;
        }

        $fieldValue = $this->resolvePath($fieldPath, $context);
        if ($fieldValue === null) {
            return false;
        }

        $fieldDate = $fieldValue instanceof DateTimeInterface
            ? DateTimeImmutable::createFromInterface($fieldValue)
            : new DateTimeImmutable((string)$fieldValue);

        $diff = $now->diff($fieldDate);
        $days = (int)$diff->days;

        $operator = $params['operator'] ?? 'gte';
        $expected = (int)($params['value'] ?? 0);

        return match ($operator) {
            'eq' => $days === $expected,
            'neq' => $days !== $expected,
            'gt' => $days > $expected,
            'gte' => $days >= $expected,
            'lt' => $days < $expected,
            'lte' => $days <= $expected,
            default => false,
        };
    }

    private function evaluateBusinessHours(DateTimeImmutable $now): bool
    {
        $dayOfWeek = (int)$now->format('N'); // 1=Mon, 7=Sun
        $hour = (int)$now->format('G');

        // Mon-Fri, 9am-5pm
        return $dayOfWeek >= 1 && $dayOfWeek <= 5 && $hour >= 9 && $hour < 17;
    }
}
