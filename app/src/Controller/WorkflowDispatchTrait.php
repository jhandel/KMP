<?php

declare(strict_types=1);

namespace App\Controller;

use App\Services\WorkflowEngine\TriggerDispatcher;
use Cake\Log\Log;

/**
 * Dual-path workflow dispatch for controllers.
 *
 * Enables gradual migration: kingdoms with active workflows use the engine;
 * others keep existing legacy behavior via a callable fallback.
 * TriggerDispatcher is passed explicitly via CakePHP method injection.
 */
trait WorkflowDispatchTrait
{
    /**
     * Dispatch to workflow engine if an active definition exists, otherwise run legacy callable.
     *
     * @param \App\Services\WorkflowEngine\TriggerDispatcher $dispatcher Workflow trigger dispatcher
     * @param string $slug Workflow definition slug
     * @param string $triggerEvent Event name for the workflow engine
     * @param array $context Event data / context for the workflow
     * @param callable $legacy Fallback callable when no active workflow exists
     * @return mixed Workflow dispatch results (array) or legacy return value
     */
    protected function dispatchOrLegacy(
        TriggerDispatcher $dispatcher,
        string $slug,
        string $triggerEvent,
        array $context,
        callable $legacy,
    ): mixed {
        $definitions = $this->fetchTable('WorkflowDefinitions');
        $def = $definitions->find()
            ->where(['slug' => $slug, 'is_active' => true])
            ->contain(['CurrentVersion'])
            ->first();

        if ($def && $def->current_version) {
            $triggeredBy = $this->request->getAttribute('identity')?->getIdentifier();

            return $dispatcher->dispatch($triggerEvent, $context, $triggeredBy);
        }

        return $legacy();
    }

    /**
     * Fire a workflow event without fallback. Silently logs on failure.
     *
     * @param \App\Services\WorkflowEngine\TriggerDispatcher $dispatcher Workflow trigger dispatcher
     * @param string $triggerEvent Event name for the workflow engine
     * @param array $context Event data / context for the workflow
     * @return void
     */
    protected function dispatchWorkflowEvent(
        TriggerDispatcher $dispatcher,
        string $triggerEvent,
        array $context,
    ): void {
        try {
            $triggeredBy = $this->request->getAttribute('identity')?->getIdentifier();
            $dispatcher->dispatch($triggerEvent, $context, $triggeredBy);
        } catch (\Throwable $e) {
            Log::warning("Workflow dispatch failed for {$triggerEvent}: " . $e->getMessage());
        }
    }
}
