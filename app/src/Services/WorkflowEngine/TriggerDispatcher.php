<?php

declare(strict_types=1);

namespace App\Services\WorkflowEngine;

use Cake\Log\Log;

/**
 * Dispatches trigger events to find and start matching workflows.
 */
class TriggerDispatcher
{
    private WorkflowEngineInterface $engine;

    public function __construct(WorkflowEngineInterface $engine)
    {
        $this->engine = $engine;
    }

    /**
     * Dispatch a trigger event to find and start matching workflows.
     *
     * @param string $eventName Event identifier (e.g., 'Officers.HireRequested')
     * @param array $eventData Data associated with the event
     * @param int|null $triggeredBy Member who triggered
     * @return array Array of ServiceResult from started workflows
     */
    public function dispatch(string $eventName, array $eventData = [], ?int $triggeredBy = null): array
    {
        try {
            return $this->engine->dispatchTrigger($eventName, $eventData, $triggeredBy);
        } catch (\Throwable $e) {
            Log::error("TriggerDispatcher failed for {$eventName}: " . $e->getMessage());

            return [];
        }
    }
}
