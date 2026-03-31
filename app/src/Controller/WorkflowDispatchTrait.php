<?php

declare(strict_types=1);

namespace App\Controller;

use App\Model\Entity\WorkflowDefinition;
use App\Services\WorkflowEngine\TriggerDispatcher;
use App\Services\WorkflowEngine\WorkflowDefinitionFinderTrait;
use Cake\Log\Log;
use Cake\ORM\TableRegistry;

/**
 * Dual-path workflow dispatch for controllers.
 *
 * Enables gradual migration: kingdoms with active workflows use the engine;
 * others keep existing legacy behavior via a callable fallback.
 * Resolves the current kingdom from the authenticated member's branch
 * hierarchy and uses kingdom-scoped lookup for workflow definitions.
 */
trait WorkflowDispatchTrait
{
    use WorkflowDefinitionFinderTrait;

    /**
     * Dispatch to workflow engine if an active definition exists, otherwise run legacy callable.
     *
     * Resolves the current kingdom from the authenticated user's branch hierarchy,
     * then uses kingdom-scoped lookup to find the appropriate workflow definition.
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
        $kingdomId = $this->resolveKingdomId();
        $def = $this->findActiveDefinition($slug, $kingdomId);

        if ($def && $def->current_version) {
            $triggeredBy = $this->request->getAttribute('identity')?->getIdentifier();
            $context['kingdom_id'] = $kingdomId;

            return $dispatcher->dispatch($triggerEvent, $context, $triggeredBy);
        }

        return $legacy();
    }

    /**
     * Fire a workflow event without fallback. Silently logs on failure.
     *
     * Includes kingdom context from the authenticated user.
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
            $kingdomId = $this->resolveKingdomId();
            $context['kingdom_id'] = $kingdomId;

            $dispatcher->dispatch($triggerEvent, $context, $triggeredBy);
        } catch (\Throwable $e) {
            Log::warning("Workflow dispatch failed for {$triggerEvent}: " . $e->getMessage());
        }
    }

    /**
     * Resolve the kingdom ID from the authenticated member's branch hierarchy.
     *
     * @return int|null Kingdom branch ID, or null if unavailable
     */
    protected function resolveKingdomId(): ?int
    {
        $identity = $this->request->getAttribute('identity');
        if (!$identity) {
            return null;
        }

        $branchId = isset($identity['branch_id']) ? (int)$identity['branch_id'] : null;
        if (!$branchId) {
            return null;
        }

        return $this->resolveKingdomIdFromBranch($branchId);
    }

    /**
     * Walk the branch parent chain to find the kingdom-type ancestor.
     *
     * @param int $branchId Starting branch ID
     * @return int|null Kingdom branch ID, or null if no kingdom found
     */
    protected function resolveKingdomIdFromBranch(int $branchId): ?int
    {
        $branchesTable = TableRegistry::getTableLocator()->get('Branches');
        $parents = $branchesTable->getAllParents($branchId);
        $candidateIds = array_merge([$branchId], $parents);

        $kingdom = $branchesTable->find()
            ->select(['id'])
            ->where([
                'id IN' => $candidateIds,
                'type' => 'Kingdom',
            ])
            ->first();

        return $kingdom ? (int)$kingdom->id : null;
    }

    /**
     * Find an active workflow definition with kingdom scoping.
     *
     * Uses kingdom-scoped lookup when a kingdom is available, falling back
     * to global-only lookup otherwise.
     *
     * @param string $slug Workflow definition slug
     * @param int|null $kingdomId Kingdom branch ID, or null for global-only
     * @return \App\Model\Entity\WorkflowDefinition|null
     */
    private function findActiveDefinition(string $slug, ?int $kingdomId): ?WorkflowDefinition
    {
        $table = TableRegistry::getTableLocator()->get('WorkflowDefinitions');

        if ($kingdomId !== null) {
            $def = $this->findForKingdom($kingdomId, $slug);
        } else {
            $def = $table->find()
                ->where(['slug' => $slug, 'kingdom_id IS' => null])
                ->first();
        }

        if (!$def || !$def->is_active || !$def->current_version_id) {
            return null;
        }

        return $table->find()
            ->where(['WorkflowDefinitions.id' => $def->id])
            ->contain(['CurrentVersion'])
            ->first();
    }
}
