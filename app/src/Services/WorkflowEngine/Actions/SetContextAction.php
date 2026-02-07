<?php
declare(strict_types=1);

namespace App\Services\WorkflowEngine\Actions;

use App\Services\ServiceResult;
use Cake\Log\Log;

/**
 * Updates the workflow instance's context bag.
 *
 * Values prefixed with "context." are resolved from the runtime context.
 */
class SetContextAction implements ActionInterface
{
    public function execute(array $params, array $context): ServiceResult
    {
        $key = $params['key'] ?? null;

        if ($key === null) {
            return new ServiceResult(false, "set_context: 'key' parameter is required");
        }

        $value = $params['value'] ?? null;
        $resolvedValue = $this->resolveValue($value, $context);

        // The engine reads workflow_context from the context array
        if (!isset($context['workflow_context'])) {
            $context['workflow_context'] = [];
        }
        $context['workflow_context'][$key] = $resolvedValue;

        Log::info("WorkflowEngine: set_context '{$key}' = " . json_encode($resolvedValue));

        return new ServiceResult(true, "Context '{$key}' set", [
            'key' => $key,
            'value' => $resolvedValue,
            'workflow_context' => $context['workflow_context'],
        ]);
    }

    /**
     * Resolve "context." prefixed values from the runtime context.
     *
     * @param mixed $value Raw value from params
     * @param array $context Runtime context
     * @return mixed Resolved value
     */
    private function resolveValue(mixed $value, array $context): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        if (str_starts_with($value, 'context.')) {
            $path = substr($value, 8); // Remove "context." prefix
            return $context[$path] ?? null;
        }

        return $value;
    }

    public function getName(): string
    {
        return 'set_context';
    }

    public function getDescription(): string
    {
        return "Updates the workflow instance's context bag";
    }

    public function getParameterSchema(): array
    {
        return [
            'key' => ['type' => 'string', 'required' => true, 'description' => 'Context key to set'],
            'value' => ['type' => 'mixed', 'required' => false, 'description' => 'Value or context. reference'],
        ];
    }
}
