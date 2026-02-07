<?php
declare(strict_types=1);

namespace App\Services\WorkflowEngine\Actions;

use App\Services\ServiceResult;
use Cake\Log\Log;

/**
 * Sets a field value on the workflow subject entity.
 *
 * Also stores the update in $context['field_updates'] for the engine to persist.
 */
class SetFieldAction implements ActionInterface
{
    public function execute(array $params, array $context): ServiceResult
    {
        $field = $params['field'] ?? null;
        $value = $params['value'] ?? null;

        if ($field === null) {
            return new ServiceResult(false, "set_field: 'field' parameter is required");
        }

        if (isset($context['subject'])) {
            $context['subject']->{$field} = $value;
        }

        // Store field update for engine persistence
        if (!isset($context['field_updates'])) {
            $context['field_updates'] = [];
        }
        $context['field_updates'][$field] = $value;

        Log::info("WorkflowEngine: set_field '{$field}' = " . json_encode($value));

        return new ServiceResult(true, "Field '{$field}' set", ['field' => $field, 'value' => $value]);
    }

    public function getName(): string
    {
        return 'set_field';
    }

    public function getDescription(): string
    {
        return 'Sets a field value on the workflow subject entity';
    }

    public function getParameterSchema(): array
    {
        return [
            'field' => ['type' => 'string', 'required' => true, 'description' => 'Field name to set'],
            'value' => ['type' => 'mixed', 'required' => false, 'description' => 'Value to assign'],
        ];
    }
}
