<?php

declare(strict_types=1);

namespace Awards\Services\WorkflowEngine\Actions;

use App\Services\WorkflowEngine\Actions\ActionInterface;
use App\Services\ServiceResult;
use Cake\ORM\TableRegistry;

/**
 * Logs recommendation state changes to the awards_recommendations_states_logs audit table.
 */
class LogRecommendationStateAction implements ActionInterface
{
    /**
     * Write an audit row for the state transition.
     *
     * @param array $params Action parameters (unused)
     * @param array $context Runtime context with entity_id, from_state, to_state, user_id
     * @return ServiceResult
     */
    public function execute(array $params, array $context): ServiceResult
    {
        $logsTable = TableRegistry::getTableLocator()->get('Awards.RecommendationsStatesLogs');
        $log = $logsTable->newEntity([
            'recommendation_id' => $context['entity_id'] ?? null,
            'from_state' => $context['from_state']['name'] ?? null,
            'to_state' => $context['to_state']['name'] ?? null,
            'from_status' => $context['from_state']['status_category'] ?? null,
            'to_status' => $context['to_state']['status_category'] ?? null,
            'created_by' => $context['user_id'] ?? null,
        ]);

        if ($logsTable->save($log)) {
            return new ServiceResult(true);
        }

        return new ServiceResult(false, 'Failed to save recommendation state log');
    }

    public function getName(): string
    {
        return 'log_recommendation_state';
    }

    public function getDescription(): string
    {
        return 'Log recommendation state change to audit table';
    }

    public function getParameterSchema(): array
    {
        return [];
    }
}
