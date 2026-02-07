<?php
declare(strict_types=1);

namespace Awards\Services\WorkflowEngine\Actions;

use App\Services\ServiceResult;
use App\Services\WorkflowEngine\Actions\ActionInterface;
use Cake\Log\Log;

/**
 * Logs recommendation state changes to the Awards audit trail.
 */
class LogRecommendationStateAction implements ActionInterface
{
    public function execute(array $params, array $context): ServiceResult
    {
        $entityId = $context['entity_id'] ?? null;
        $newState = $context['to_state'] ?? 'unknown';
        $triggeredBy = $context['triggered_by'] ?? null;

        Log::info("Awards: Recommendation #{$entityId} state changed to '{$newState}' by member #{$triggeredBy}");

        return new ServiceResult(true, null, ['logged' => true]);
    }

    public function getName(): string
    {
        return 'log_recommendation_state';
    }

    public function getDescription(): string
    {
        return 'Logs recommendation state changes to Awards audit trail';
    }

    public function getParameterSchema(): array
    {
        return [];
    }
}
