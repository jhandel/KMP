<?php

declare(strict_types=1);

namespace Awards\Test\TestCase\Controller;

use App\Services\WorkflowEngine\TriggerDispatcher;
use App\Test\TestCase\BaseTestCase;
use Awards\Services\RecommendationStateService;
use Cake\I18n\DateTime;
use Cake\ORM\TableRegistry;

/**
 * Tests for workflow dispatch integration in RecommendationsController.
 *
 * Uses a lightweight stub controller that mirrors the real controller's
 * workflow dispatch wiring. This avoids the full HTTP stack while testing
 * the trait integration, event payloads, and dual-path dispatch logic.
 */
class RecommendationsWorkflowDispatchTest extends BaseTestCase
{
    /**
     * Captured dispatch calls from the mocked TriggerDispatcher.
     *
     * @var array<int, array{event: string, data: array, triggeredBy: int|null}>
     */
    private array $dispatched = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->skipIfPostgres();
        $this->dispatched = [];
    }

    // ── Helpers ──────────────────────────────────────────────────────

    /**
     * Create a mock TriggerDispatcher that records calls.
     */
    private function buildMockDispatcher(): TriggerDispatcher
    {
        $mock = $this->createMock(TriggerDispatcher::class);
        $mock->method('dispatch')
            ->willReturnCallback(function (string $event, array $data, ?int $triggeredBy) {
                $this->dispatched[] = compact('event', 'data', 'triggeredBy');

                return [];
            });

        return $mock;
    }

    /**
     * Create a mock TriggerDispatcher that throws on dispatch.
     */
    private function buildThrowingDispatcher(): TriggerDispatcher
    {
        $mock = $this->createMock(TriggerDispatcher::class);
        $mock->method('dispatch')
            ->willThrowException(new \RuntimeException('Workflow engine offline'));

        return $mock;
    }

    /**
     * Create an active workflow definition so dispatchOrLegacy chooses the workflow path.
     */
    private function activateWorkflow(string $slug): int
    {
        $definitions = TableRegistry::getTableLocator()->get('WorkflowDefinitions');
        $versions = TableRegistry::getTableLocator()->get('WorkflowVersions');

        $def = $definitions->newEntity([
            'name' => 'Test Workflow - ' . $slug,
            'slug' => $slug,
            'description' => 'Activated for test',
            'trigger_type' => 'event',
            'is_active' => true,
        ]);
        $definitions->saveOrFail($def);

        $version = $versions->newEntity([
            'workflow_definition_id' => $def->id,
            'version_number' => 1,
            'status' => 'published',
            'definition' => json_encode(['nodes' => [], 'edges' => []]),
        ]);
        $versions->saveOrFail($version);

        $def->current_version_id = $version->id;
        $definitions->saveOrFail($def);

        return (int)$def->id;
    }

    /**
     * Deactivate all workflow definitions matching a slug.
     */
    private function deactivateWorkflow(string $slug): void
    {
        $definitions = TableRegistry::getTableLocator()->get('WorkflowDefinitions');
        $definitions->updateAll(['is_active' => false], ['slug' => $slug]);
    }

    /**
     * Get the first available award ID from the database.
     */
    private function getFirstAwardId(): int
    {
        $awards = TableRegistry::getTableLocator()->get('Awards.Awards');
        $award = $awards->find()->select(['id'])->first();

        return $award ? (int)$award->id : 1;
    }

    /**
     * Create a recommendation in the database for state transition tests.
     */
    private function createTestRecommendation(string $state = 'Submitted'): int
    {
        $table = TableRegistry::getTableLocator()->get('Awards.Recommendations');
        $rec = $table->newEntity([
            'member_id' => self::ADMIN_MEMBER_ID,
            'requester_id' => self::ADMIN_MEMBER_ID,
            'award_id' => $this->getFirstAwardId(),
            'reason' => 'Test recommendation for workflow',
            'requester_sca_name' => 'Admin von Admin',
            'member_sca_name' => 'Admin von Admin',
            'contact_email' => 'admin@test.com',
            'status' => 'In Progress',
            'state' => $state,
            'state_date' => DateTime::now(),
            'not_found' => false,
            'call_into_court' => 'Not Set',
            'court_availability' => 'Not Set',
            'person_to_notify' => '',
            'branch_id' => self::KINGDOM_BRANCH_ID,
        ]);
        $table->saveOrFail($rec);

        return (int)$rec->id;
    }

    /**
     * Build a stub object that uses WorkflowDispatchTrait with a mock request.
     */
    private function buildTraitStub(?int $identityId = null): object
    {
        return new class ($identityId) {
            use \App\Controller\WorkflowDispatchTrait;
            use \Cake\ORM\Locator\LocatorAwareTrait;

            public object $request;

            public function __construct(?int $identityId)
            {
                $identity = $identityId !== null
                    ? new class ($identityId) {
                        private int $id;
                        public function __construct(int $id)
                        {
                            $this->id = $id;
                        }
                        public function getIdentifier(): int
                        {
                            return $this->id;
                        }
                    }
                    : null;

                $this->request = new class ($identity) {
                    private $identity;
                    public function __construct($identity)
                    {
                        $this->identity = $identity;
                    }
                    public function getAttribute(string $name): mixed
                    {
                        return $name === 'identity' ? $this->identity : null;
                    }
                };
            }

            public function callDispatchOrLegacy(
                \App\Services\WorkflowEngine\TriggerDispatcher $dispatcher,
                string $slug,
                string $trigger,
                array $ctx,
                callable $legacy,
            ): mixed {
                return $this->dispatchOrLegacy($dispatcher, $slug, $trigger, $ctx, $legacy);
            }

            public function callDispatchWorkflowEvent(
                \App\Services\WorkflowEngine\TriggerDispatcher $dispatcher,
                string $trigger,
                array $ctx,
            ): void {
                $this->dispatchWorkflowEvent($dispatcher, $trigger, $ctx);
            }
        };
    }

    // ── 1. Legacy path runs when no workflow is active ───────────────

    public function testDispatchOrLegacyRunsLegacyWhenNoWorkflow(): void
    {
        $this->deactivateWorkflow('awards-recommendation-lifecycle');
        $stub = $this->buildTraitStub(self::ADMIN_MEMBER_ID);
        $dispatcher = $this->buildMockDispatcher();

        $legacyCalled = false;
        $result = $stub->callDispatchOrLegacy(
            $dispatcher,
            'awards-recommendation-lifecycle',
            'Awards.RecommendationSubmitted',
            ['recommendationId' => 1],
            function () use (&$legacyCalled) {
                $legacyCalled = true;

                return 'legacy-result';
            },
        );

        $this->assertTrue($legacyCalled, 'Legacy callable should run when no workflow is active');
        $this->assertEquals('legacy-result', $result);
        $this->assertEmpty($this->dispatched, 'No workflow dispatch when no active definition');
    }

    // ── 2. Workflow path dispatches when workflow is active ──────────

    public function testDispatchOrLegacyDispatchesWhenWorkflowActive(): void
    {
        $this->activateWorkflow('awards-recommendation-lifecycle');
        $dispatcher = $this->buildMockDispatcher();
        $stub = $this->buildTraitStub(self::ADMIN_MEMBER_ID);

        $legacyCalled = false;
        $stub->callDispatchOrLegacy(
            $dispatcher,
            'awards-recommendation-lifecycle',
            'Awards.RecommendationSubmitted',
            [
                'recommendationId' => 42,
                'awardId' => 1,
                'state' => 'Submitted',
            ],
            function () use (&$legacyCalled) {
                $legacyCalled = true;
            },
        );

        $this->assertFalse($legacyCalled, 'Legacy callable should NOT run when workflow is active');
        $this->assertCount(1, $this->dispatched);
        $this->assertEquals('Awards.RecommendationSubmitted', $this->dispatched[0]['event']);
        $this->assertEquals(42, $this->dispatched[0]['data']['recommendationId']);
        $this->assertEquals(self::ADMIN_MEMBER_ID, $this->dispatched[0]['triggeredBy']);
    }

    // ── 3. State change event fires via dispatchWorkflowEvent ───────

    public function testDispatchWorkflowEventFiresStateChanged(): void
    {
        $dispatcher = $this->buildMockDispatcher();
        $recId = $this->createTestRecommendation();
        $stub = $this->buildTraitStub(self::ADMIN_MEMBER_ID);

        $stub->callDispatchWorkflowEvent(
            $dispatcher,
            'Awards.RecommendationStateChanged',
            [
                'recommendationId' => $recId,
                'previousState' => 'Submitted',
                'newState' => 'In Consideration',
                'previousStatus' => 'In Progress',
                'newStatus' => 'In Progress',
                'actorId' => self::ADMIN_MEMBER_ID,
            ],
        );

        $this->assertCount(1, $this->dispatched);
        $call = $this->dispatched[0];
        $this->assertEquals('Awards.RecommendationStateChanged', $call['event']);
        $this->assertEquals($recId, $call['data']['recommendationId']);
        $this->assertEquals('Submitted', $call['data']['previousState']);
        $this->assertEquals('In Consideration', $call['data']['newState']);
    }

    // ── 4. Bulk transition event fires with correct payload ─────────

    public function testDispatchWorkflowEventFiresBulkTransition(): void
    {
        $dispatcher = $this->buildMockDispatcher();
        $recId1 = $this->createTestRecommendation();
        $recId2 = $this->createTestRecommendation();
        $stub = $this->buildTraitStub(self::ADMIN_MEMBER_ID);

        $stub->callDispatchWorkflowEvent(
            $dispatcher,
            'Awards.BulkStateTransition',
            [
                'recommendationIds' => [(string)$recId1, (string)$recId2],
                'targetState' => 'In Consideration',
                'actorId' => self::ADMIN_MEMBER_ID,
            ],
        );

        $this->assertCount(1, $this->dispatched);
        $call = $this->dispatched[0];
        $this->assertEquals('Awards.BulkStateTransition', $call['event']);
        $this->assertContains((string)$recId1, $call['data']['recommendationIds']);
        $this->assertContains((string)$recId2, $call['data']['recommendationIds']);
        $this->assertEquals('In Consideration', $call['data']['targetState']);
    }

    // ── 5. dispatchWorkflowEvent swallows exceptions gracefully ─────

    public function testDispatchWorkflowEventSwallowsExceptions(): void
    {
        $dispatcher = $this->buildThrowingDispatcher();
        $stub = $this->buildTraitStub(self::ADMIN_MEMBER_ID);

        // Should not throw even though dispatcher raises an exception
        $stub->callDispatchWorkflowEvent(
            $dispatcher,
            'Awards.RecommendationStateChanged',
            ['recommendationId' => 1, 'previousState' => 'Submitted', 'newState' => 'Given'],
        );

        $this->assertTrue(true, 'dispatchWorkflowEvent should swallow exceptions');
    }

    // ── 6. dispatchOrLegacy passes identity ID to trigger ───────────

    public function testDispatchOrLegacyPassesActorId(): void
    {
        $this->activateWorkflow('awards-recommendation-lifecycle');
        $dispatcher = $this->buildMockDispatcher();
        $actorId = self::TEST_MEMBER_AGATHA_ID;
        $stub = $this->buildTraitStub($actorId);

        $stub->callDispatchOrLegacy(
            $dispatcher,
            'awards-recommendation-lifecycle',
            'Awards.RecommendationSubmitted',
            ['recommendationId' => 99],
            function () {},
        );

        $this->assertEquals($actorId, $this->dispatched[0]['triggeredBy']);
    }

    // ── 7. Bulk state service integration produces correct state ─────

    public function testBulkUpdateStatesSucceeds(): void
    {
        $recId = $this->createTestRecommendation('Submitted');
        $table = TableRegistry::getTableLocator()->get('Awards.Recommendations');
        $stateService = new RecommendationStateService();

        $success = $stateService->bulkUpdateStates(
            $table,
            [
                'ids' => [(string)$recId],
                'newState' => 'In Consideration',
                'gathering_id' => null,
                'given' => null,
                'note' => null,
                'close_reason' => null,
            ],
            self::ADMIN_MEMBER_ID,
        );

        $this->assertTrue($success, 'Bulk state update should succeed');

        $updated = $table->get($recId);
        $this->assertEquals('In Consideration', $updated->state);
        $this->assertEquals('In Progress', $updated->status);
    }

    // ── 8. Kanban move service integration preserves old state ───────

    public function testKanbanMoveUpdatesState(): void
    {
        $recId = $this->createTestRecommendation('Submitted');
        $table = TableRegistry::getTableLocator()->get('Awards.Recommendations');
        $recommendation = $table->get($recId);
        $stateService = new RecommendationStateService();

        $oldState = $recommendation->state;
        $result = $stateService->kanbanMove(
            $table,
            $recommendation,
            'Awaiting Feedback',
            null,
            null,
        );

        $this->assertEquals('success', $result);
        $this->assertEquals('Submitted', $oldState, 'Old state should have been Submitted');

        $updated = $table->get($recId);
        $this->assertEquals('Awaiting Feedback', $updated->state);
    }

    // ── 9. Controller uses WorkflowDispatchTrait ────────────────────

    public function testRecommendationsControllerUsesWorkflowDispatchTrait(): void
    {
        $uses = class_uses(\Awards\Controller\RecommendationsController::class);
        $this->assertArrayHasKey(
            'App\Controller\WorkflowDispatchTrait',
            $uses,
            'RecommendationsController must use WorkflowDispatchTrait',
        );
    }

    // ── 10. Workflow trigger names match provider registration ───────

    public function testWorkflowTriggerNamesMatchProvider(): void
    {
        $expectedTriggers = [
            'Awards.RecommendationSubmitted',
            'Awards.RecommendationStateChanged',
            'Awards.BulkStateTransition',
        ];

        // Verify the controller source references each expected trigger
        $controllerSource = file_get_contents(
            dirname(__DIR__, 3) . '/src/Controller/RecommendationsController.php',
        );

        foreach ($expectedTriggers as $trigger) {
            $this->assertStringContainsString(
                $trigger,
                $controllerSource,
                "Controller must reference trigger '{$trigger}'",
            );
        }
    }
}
