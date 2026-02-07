<?php
declare(strict_types=1);

namespace Officers\Services\WorkflowEngine\Actions;

use App\Services\ServiceResult;
use App\Services\WorkflowEngine\Actions\ActionInterface;
use Cake\Log\Log;

/**
 * Activates a warrant (sets start date, etc.) when approved.
 */
class ActivateWarrantAction implements ActionInterface
{
    public function execute(array $params, array $context): ServiceResult
    {
        $entityId = $context['entity_id'] ?? null;
        $memberId = $context['triggered_by'] ?? null;
        $warrantId = $params['warrant_id'] ?? $entityId;

        // Stub: full integration in Phase 8
        Log::info("Officers: Activating warrant #{$warrantId} for member #{$memberId}");

        return new ServiceResult(true, null, [
            'warrant_activated' => true,
            'warrant_id' => $warrantId,
        ]);
    }

    public function getName(): string
    {
        return 'activate_warrant';
    }

    public function getDescription(): string
    {
        return 'Activates a warrant when approved';
    }

    public function getParameterSchema(): array
    {
        return [
            'warrant_id' => [
                'type' => 'integer',
                'required' => false,
                'description' => 'ID of the warrant to activate (defaults to entity_id from context)',
            ],
        ];
    }
}
