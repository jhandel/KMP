<?php

declare(strict_types=1);

namespace App\Services\WorkflowEngine\Actions;

use App\Services\ServiceResult;
use App\Services\WorkflowEngine\ContextResolverTrait;
use Cake\I18n\DateTime;
use Cake\Log\Log;

/**
 * Activates a warrant by setting status to Current and adjusting dates.
 *
 * Mirrors the activation logic in DefaultWarrantManager::approve().
 */
class ActivateWarrantAction implements ActionInterface
{
    use ContextResolverTrait;

    /**
     * @inheritDoc
     */
    public function execute(array $params, array $context): ServiceResult
    {
        $entityTable = $context['entity_table'] ?? null;
        $entity = $context['entity_object'] ?? null;

        if ($entityTable === null || $entity === null) {
            return new ServiceResult(false, 'Entity table and object are required for activate_warrant action');
        }

        $now = new DateTime();

        $entity->set('status', 'Current');
        $entity->set('approved_date', $now);

        // If start_on is null or in the past, snap to now
        $startOn = $entity->get('start_on');
        if ($startOn === null || $startOn < $now) {
            $entity->set('start_on', $now);
        }

        if (!$entityTable->save($entity)) {
            Log::error("ActivateWarrantAction: Failed to save warrant #{$entity->get('id')}");

            return new ServiceResult(false, 'Failed to activate warrant');
        }

        Log::info("ActivateWarrantAction: Warrant #{$entity->get('id')} activated");

        return new ServiceResult(true, null, [
            'warrant_id' => $entity->get('id'),
            'status' => 'Current',
            'start_on' => $entity->get('start_on'),
        ]);
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'activate_warrant';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Activates a warrant by setting status to Current and adjusting start date';
    }

    /**
     * @inheritDoc
     */
    public function getParameterSchema(): array
    {
        return [];
    }
}
