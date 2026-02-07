<?php

declare(strict_types=1);

namespace App\Services\WorkflowEngine\Actions;

use App\Services\ServiceResult;
use App\Services\WorkflowEngine\ContextResolverTrait;
use Cake\I18n\DateTime;
use Cake\Log\Log;

/**
 * Cancels or deactivates a warrant with a reason.
 *
 * Sets revoked_reason and revoker_id from context. If expires_on is in
 * the past the status is set to Deactivated; otherwise the target state
 * from the transition is used.
 */
class CancelWarrantAction implements ActionInterface
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
            return new ServiceResult(false, 'Entity table and object are required for cancel_warrant action');
        }

        $reason = isset($params['reason']) ? $this->resolveValue($params['reason'], $context) : 'Cancelled via workflow';
        $revokerId = $context['triggered_by'] ?? null;

        $entity->set('revoked_reason', $reason);
        if ($revokerId !== null) {
            $entity->set('revoker_id', $revokerId);
        }

        // Snap expires_on to now when deactivating an active warrant
        $now = new DateTime();
        $expiresOn = $entity->get('expires_on');
        if ($expiresOn !== null && $expiresOn > $now) {
            $entity->set('expires_on', $now);
        }

        if (!$entityTable->save($entity)) {
            Log::error("CancelWarrantAction: Failed to save warrant #{$entity->get('id')}");

            return new ServiceResult(false, 'Failed to cancel warrant');
        }

        Log::info("CancelWarrantAction: Warrant #{$entity->get('id')} cancelled — {$reason}");

        return new ServiceResult(true, null, [
            'warrant_id' => $entity->get('id'),
            'reason' => $reason,
            'revoker_id' => $revokerId,
        ]);
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'cancel_warrant';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Cancels or deactivates a warrant with a recorded reason';
    }

    /**
     * @inheritDoc
     */
    public function getParameterSchema(): array
    {
        return [
            'reason' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Reason for cancellation (supports {{template}} variables)',
            ],
        ];
    }
}
