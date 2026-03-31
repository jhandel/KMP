<?php

declare(strict_types=1);

namespace App\Test\TestCase\Services\WorkflowEngine;

use App\Services\WorkflowEngine\WorkflowDefinitionFinderTrait;
use App\Test\TestCase\BaseTestCase;
use Cake\ORM\TableRegistry;

/**
 * Tests for kingdom-scoped workflow definition lookup.
 */
class WorkflowDefinitionScopingTest extends BaseTestCase
{
    use WorkflowDefinitionFinderTrait;

    private $defTable;

    protected function setUp(): void
    {
        parent::setUp();
        $this->defTable = TableRegistry::getTableLocator()->get('WorkflowDefinitions');
    }

    /**
     * Helper: create a workflow definition with optional kingdom_id.
     */
    private function createDefinition(string $slug, ?int $kingdomId = null, bool $isActive = true): \App\Model\Entity\WorkflowDefinition
    {
        $entity = $this->defTable->newEntity([
            'name' => 'Test: ' . $slug . ($kingdomId ? " (K$kingdomId)" : ' (global)'),
            'slug' => $slug,
            'trigger_type' => 'manual',
            'is_active' => $isActive,
            'kingdom_id' => $kingdomId,
        ]);

        return $this->defTable->saveOrFail($entity);
    }

    // =========================================================
    // findForKingdom() tests
    // =========================================================

    /**
     * findForKingdom returns the kingdom-specific definition when it exists.
     */
    public function testFindForKingdomReturnsKingdomSpecific(): void
    {
        $slug = 'test-wf-' . uniqid();
        $kingdomId = self::KINGDOM_BRANCH_ID;

        $this->createDefinition($slug); // global
        $kingdomDef = $this->createDefinition($slug, $kingdomId);

        $result = $this->findForKingdom($kingdomId, $slug);

        $this->assertNotNull($result);
        $this->assertEquals($kingdomDef->id, $result->id);
        $this->assertEquals($kingdomId, $result->kingdom_id);
    }

    /**
     * findForKingdom falls back to global when no kingdom-specific exists.
     */
    public function testFindForKingdomFallsBackToGlobal(): void
    {
        $slug = 'test-wf-' . uniqid();
        $kingdomId = self::KINGDOM_BRANCH_ID;

        $globalDef = $this->createDefinition($slug); // global only

        $result = $this->findForKingdom($kingdomId, $slug);

        $this->assertNotNull($result);
        $this->assertEquals($globalDef->id, $result->id);
        $this->assertNull($result->kingdom_id);
    }

    /**
     * findForKingdom returns null when no definition exists at all.
     */
    public function testFindForKingdomReturnsNullWhenNotFound(): void
    {
        $result = $this->findForKingdom(self::KINGDOM_BRANCH_ID, 'nonexistent-slug-' . uniqid());

        $this->assertNull($result);
    }

    /**
     * findForKingdom does not return definitions for a different kingdom.
     */
    public function testFindForKingdomIgnoresOtherKingdoms(): void
    {
        $slug = 'test-wf-' . uniqid();
        $otherKingdomId = self::TEST_BRANCH_LOCAL_ID; // a different branch

        $this->createDefinition($slug, $otherKingdomId);

        $result = $this->findForKingdom(self::KINGDOM_BRANCH_ID, $slug);

        $this->assertNull($result);
    }

    /**
     * findForKingdom prefers kingdom-specific over global for same slug.
     */
    public function testFindForKingdomPrefersKingdomOverGlobal(): void
    {
        $slug = 'test-wf-' . uniqid();
        $kingdomId = self::KINGDOM_BRANCH_ID;

        $globalDef = $this->createDefinition($slug);
        $kingdomDef = $this->createDefinition($slug, $kingdomId);

        $result = $this->findForKingdom($kingdomId, $slug);

        $this->assertNotNull($result);
        $this->assertEquals($kingdomDef->id, $result->id);
        $this->assertNotEquals($globalDef->id, $result->id);
    }

    // =========================================================
    // findAllForKingdom() tests
    // =========================================================

    /**
     * findAllForKingdom includes both kingdom-specific and global definitions.
     */
    public function testFindAllForKingdomIncludesBothKingdomAndGlobal(): void
    {
        $slugGlobal = 'test-global-' . uniqid();
        $slugKingdom = 'test-kingdom-' . uniqid();
        $kingdomId = self::KINGDOM_BRANCH_ID;

        $globalDef = $this->createDefinition($slugGlobal);
        $kingdomDef = $this->createDefinition($slugKingdom, $kingdomId);

        $results = $this->findAllForKingdom($kingdomId);
        $slugs = array_map(fn($d) => $d->slug, $results);

        $this->assertContains($slugGlobal, $slugs);
        $this->assertContains($slugKingdom, $slugs);
    }

    /**
     * findAllForKingdom excludes global when kingdom-specific override exists.
     */
    public function testFindAllForKingdomOverridesGlobalWithKingdomSpecific(): void
    {
        $slug = 'test-override-' . uniqid();
        $kingdomId = self::KINGDOM_BRANCH_ID;

        $globalDef = $this->createDefinition($slug);
        $kingdomDef = $this->createDefinition($slug, $kingdomId);

        $results = $this->findAllForKingdom($kingdomId);
        $matchingResults = array_filter($results, fn($d) => $d->slug === $slug);

        $this->assertCount(1, $matchingResults);
        $found = array_values($matchingResults)[0];
        $this->assertEquals($kingdomDef->id, $found->id);
        $this->assertEquals($kingdomId, $found->kingdom_id);
    }

    /**
     * findAllForKingdom does not include definitions for other kingdoms.
     */
    public function testFindAllForKingdomExcludesOtherKingdoms(): void
    {
        $slug = 'test-other-' . uniqid();
        $kingdomId = self::KINGDOM_BRANCH_ID;
        $otherKingdomId = self::TEST_BRANCH_LOCAL_ID;

        $this->createDefinition($slug, $otherKingdomId);

        $results = $this->findAllForKingdom($kingdomId);
        $slugs = array_map(fn($d) => $d->slug, $results);

        $this->assertNotContains($slug, $slugs);
    }

    /**
     * findAllForKingdom returns only globals when kingdom has no overrides.
     */
    public function testFindAllForKingdomReturnsGlobalsWhenNoOverrides(): void
    {
        $slug = 'test-global-only-' . uniqid();
        $kingdomId = self::KINGDOM_BRANCH_ID;

        $globalDef = $this->createDefinition($slug);

        $results = $this->findAllForKingdom($kingdomId);
        $slugs = array_map(fn($d) => $d->slug, $results);

        $this->assertContains($slug, $slugs);
    }

    // =========================================================
    // Entity / schema tests
    // =========================================================

    /**
     * Null kingdom_id means global definition.
     */
    public function testNullKingdomIdMeansGlobal(): void
    {
        $slug = 'test-null-' . uniqid();
        $def = $this->createDefinition($slug);

        $this->assertNull($def->kingdom_id);

        // Verify it persisted correctly
        $reloaded = $this->defTable->get($def->id);
        $this->assertNull($reloaded->kingdom_id);
    }

    /**
     * Can create two definitions with same slug but different kingdom_ids.
     */
    public function testSameSlugDifferentKingdomsAllowed(): void
    {
        $slug = 'test-dup-' . uniqid();
        $kingdomId = self::KINGDOM_BRANCH_ID;

        $global = $this->createDefinition($slug);
        $kingdom = $this->createDefinition($slug, $kingdomId);

        $this->assertNotEquals($global->id, $kingdom->id);
        $this->assertEquals($slug, $global->slug);
        $this->assertEquals($slug, $kingdom->slug);
        $this->assertNull($global->kingdom_id);
        $this->assertEquals($kingdomId, $kingdom->kingdom_id);
    }
}
