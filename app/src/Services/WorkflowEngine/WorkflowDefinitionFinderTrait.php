<?php

declare(strict_types=1);

namespace App\Services\WorkflowEngine;

use App\Model\Entity\WorkflowDefinition;
use Cake\ORM\TableRegistry;

/**
 * Kingdom-scoped lookup for workflow definitions.
 *
 * Returns kingdom-specific definitions when available, falling back
 * to global (kingdom_id IS NULL) definitions otherwise.
 */
trait WorkflowDefinitionFinderTrait
{
    /**
     * Find a workflow definition by slug for a specific kingdom.
     *
     * Returns the kingdom-specific definition if it exists, otherwise
     * falls back to the global definition (kingdom_id IS NULL).
     *
     * @param int $kingdomId Kingdom branch ID
     * @param string $slug Workflow definition slug
     * @return \App\Model\Entity\WorkflowDefinition|null
     */
    public function findForKingdom(int $kingdomId, string $slug): ?WorkflowDefinition
    {
        $table = $this->getWorkflowDefinitionsTable();

        // Try kingdom-specific first
        $definition = $table->find()
            ->where([
                'slug' => $slug,
                'kingdom_id' => $kingdomId,
            ])
            ->first();

        if ($definition !== null) {
            return $definition;
        }

        // Fall back to global
        return $table->find()
            ->where([
                'slug' => $slug,
                'kingdom_id IS' => null,
            ])
            ->first();
    }

    /**
     * Find all workflow definitions applicable to a kingdom.
     *
     * Returns kingdom-specific definitions plus global definitions that
     * don't have a kingdom-specific override for the same slug.
     *
     * @param int $kingdomId Kingdom branch ID
     * @return array<\App\Model\Entity\WorkflowDefinition>
     */
    public function findAllForKingdom(int $kingdomId): array
    {
        $table = $this->getWorkflowDefinitionsTable();

        // Get all kingdom-specific definitions
        $kingdomDefs = $table->find()
            ->where(['kingdom_id' => $kingdomId])
            ->all()
            ->toArray();

        // Collect slugs that have kingdom-specific versions
        $overriddenSlugs = array_map(
            fn(WorkflowDefinition $def) => $def->slug,
            $kingdomDefs,
        );

        // Get global definitions that are NOT overridden
        $globalQuery = $table->find()
            ->where(['kingdom_id IS' => null]);

        if (!empty($overriddenSlugs)) {
            $globalQuery = $globalQuery->where([
                'slug NOT IN' => $overriddenSlugs,
            ]);
        }

        $globalDefs = $globalQuery->all()->toArray();

        return array_merge($kingdomDefs, $globalDefs);
    }

    /**
     * Get the WorkflowDefinitions table instance.
     *
     * @return \App\Model\Table\WorkflowDefinitionsTable
     */
    private function getWorkflowDefinitionsTable()
    {
        return TableRegistry::getTableLocator()->get('WorkflowDefinitions');
    }
}
