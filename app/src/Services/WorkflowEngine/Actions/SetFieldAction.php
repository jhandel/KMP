<?php

declare(strict_types=1);

namespace App\Services\WorkflowEngine\Actions;

use App\Services\ServiceResult;
use App\Services\WorkflowEngine\ContextResolverTrait;

/**
 * Sets a field value on the entity being transitioned.
 *
 * Resolves {{template}} variables from context before saving.
 */
class SetFieldAction implements ActionInterface
{
    use ContextResolverTrait;

    /**
     * @inheritDoc
     */
    public function execute(array $params, array $context): ServiceResult
    {
        $field = $params['field'] ?? null;
        $value = $params['value'] ?? null;

        if ($field === null) {
            return new ServiceResult(false, 'No field specified for set_field action');
        }

        $value = $this->resolveValue($value, $context);

        $entityTable = $context['entity_table'] ?? null;
        $entity = $context['entity_object'] ?? null;

        if ($entityTable && $entity) {
            $entity->set($field, $value);
            if ($entityTable->save($entity)) {
                return new ServiceResult(true, null, ['field' => $field, 'value' => $value]);
            }

            return new ServiceResult(false, "Failed to save field {$field}");
        }

        // No table/entity available — record the intent for deferred application
        return new ServiceResult(true, null, ['field' => $field, 'value' => $value, 'deferred' => true]);
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'set_field';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Sets a field value on the entity being transitioned';
    }

    /**
     * @inheritDoc
     */
    public function getParameterSchema(): array
    {
        return [
            'field' => [
                'type' => 'string',
                'required' => true,
                'description' => 'Entity field name to set',
            ],
            'value' => [
                'type' => 'string',
                'required' => true,
                'description' => 'Value to set (supports {{template}} variables)',
            ],
        ];
    }
}
