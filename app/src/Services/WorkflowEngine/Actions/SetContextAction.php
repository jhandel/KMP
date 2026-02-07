<?php

declare(strict_types=1);

namespace App\Services\WorkflowEngine\Actions;

use App\Services\ServiceResult;
use App\Services\WorkflowEngine\ContextResolverTrait;

/**
 * Sets a value in the workflow instance's context JSON.
 *
 * Supports {{now}} for current datetime and {{increment}} for numeric counters.
 */
class SetContextAction implements ActionInterface
{
    use ContextResolverTrait;

    /**
     * @inheritDoc
     */
    public function execute(array $params, array $context): ServiceResult
    {
        $key = $params['key'] ?? null;
        $value = $params['value'] ?? null;

        if ($key === null) {
            return new ServiceResult(false, 'No key specified for set_context action');
        }

        $resolvedValue = $this->resolveValue($value, $context);

        // Handle increment: read current value from instance context and add 1
        if ($resolvedValue === 'increment') {
            $instanceContext = $context['instance_context'] ?? [];
            $current = $instanceContext[$key] ?? 0;
            $resolvedValue = (int)$current + 1;
        }

        // Convert DateTime to ISO-8601 string for JSON storage
        if ($resolvedValue instanceof \DateTime || $resolvedValue instanceof \DateTimeImmutable) {
            $resolvedValue = $resolvedValue->format('Y-m-d\TH:i:sP');
        }

        return new ServiceResult(true, null, [
            'context_updates' => [$key => $resolvedValue],
        ]);
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'set_context';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Sets a value in the workflow instance context JSON';
    }

    /**
     * @inheritDoc
     */
    public function getParameterSchema(): array
    {
        return [
            'key' => [
                'type' => 'string',
                'required' => true,
                'description' => 'Context key to set',
            ],
            'value' => [
                'type' => 'string',
                'required' => true,
                'description' => 'Value to set (supports {{now}}, {{increment}}, {{template}})',
            ],
        ];
    }
}
