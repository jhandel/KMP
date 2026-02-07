<?php
declare(strict_types=1);

namespace Officers\Services\WorkflowEngine\Actions;

use App\Services\ServiceResult;
use App\Services\WorkflowEngine\Actions\ActionInterface;
use Cake\Log\Log;

/**
 * Initiates a warrant request when an officer is assigned to a warrantable office.
 */
class RequestWarrantAction implements ActionInterface
{
    public function execute(array $params, array $context): ServiceResult
    {
        $entityId = $context['entity_id'] ?? null;
        $memberId = $context['triggered_by'] ?? null;
        $officeId = $params['office_id'] ?? null;

        // Stub: full integration in Phase 8
        Log::info("Officers: Requesting warrant for office #{$officeId}, member #{$memberId} (entity #{$entityId})");

        return new ServiceResult(true, null, [
            'warrant_requested' => true,
            'office_id' => $officeId,
        ]);
    }

    public function getName(): string
    {
        return 'request_warrant';
    }

    public function getDescription(): string
    {
        return 'Initiates a warrant request for a warrantable office assignment';
    }

    public function getParameterSchema(): array
    {
        return [
            'office_id' => [
                'type' => 'integer',
                'required' => true,
                'description' => 'ID of the office requiring a warrant',
            ],
        ];
    }
}
