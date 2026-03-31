<?php

declare(strict_types=1);

namespace App\Controller;

use Cake\Log\Log;

/**
 * Dual-path workflow dispatch for controllers.
 *
 * Enables gradual migration: kingdoms with active workflows use the engine;
 * others keep existing legacy behavior via a callable fallback.
 */
trait WorkflowDispatchTrait
{
    use \Cake\ORM\Locator\LocatorAwareTrait;

    /**
     * Dispatch to workflow engine if an active definition exists, otherwise run legacy callable.
     *
     * @param string $slug Workflow definition slug.
     * @param string $triggerEvent Event name for the workflow trigger.
     * @param array $context Data passed to the workflow or legacy callable.
     * @param callable $legacy Fallback callable containing existing logic.
     * @return mixed Result from workflow dispatch or legacy callable.
     */
    protected function dispatchOrLegacy(string $slug, string $triggerEvent, array $context, callable $legacy): mixed
    {
        try {
            $definitions = $this->fetchTable('WorkflowDefinitions');
            $def = $definitions->find()
                ->where(['slug' => $slug, 'is_active' => true])
                ->contain(['CurrentVersion'])
                ->first();

            if ($def && $def->current_version) {
                $dispatcher = \Cake\Core\ContainerSingleton::getContainer()
                    ->get(\App\Services\WorkflowEngine\TriggerDispatcher::class);

                return $dispatcher->dispatch(
                    $triggerEvent,
                    $context,
                    $this->request->getAttribute('identity')?->get('id'),
                );
            }
        } catch (\Throwable $e) {
            Log::warning('Workflow lookup failed, using legacy path: ' . $e->getMessage());
        }

        return $legacy();
    }

    /**
     * Fire a workflow event without affecting the current action flow.
     *
     * @param string $triggerEvent Event name for the workflow trigger.
     * @param array $context Data passed to the workflow.
     */
    protected function dispatchWorkflowEvent(string $triggerEvent, array $context): void
    {
        try {
            $dispatcher = \Cake\Core\ContainerSingleton::getContainer()
                ->get(\App\Services\WorkflowEngine\TriggerDispatcher::class);
            $dispatcher->dispatch(
                $triggerEvent,
                $context,
                $this->request->getAttribute('identity')?->get('id'),
            );
        } catch (\Throwable $e) {
            Log::warning("Workflow dispatch failed for {$triggerEvent}: " . $e->getMessage());
        }
    }
}
