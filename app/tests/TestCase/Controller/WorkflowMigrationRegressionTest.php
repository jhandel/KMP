<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Controller\WorkflowDispatchTrait;
use App\Services\WorkflowEngine\TriggerDispatcher;
use App\Test\TestCase\BaseTestCase;
use ArrayAccess;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\ORM\TableRegistry;
use RuntimeException;

/**
 * Workflow migration regression tests.
 *
 * Verifies that the dual-path dispatch in WorkflowDispatchTrait doesn't
 * break existing legacy behavior. When NO active workflow definitions
 * exist the legacy callable must always execute.
 *
 * Pattern follows RecommendationsWorkflowDispatchTest: lightweight trait
 * stub avoids full HTTP stack while exercising real dispatch logic.
 */
class WorkflowMigrationRegressionTest extends BaseTestCase
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
        $this->deactivateAllWorkflows();
    }

    // ── Helpers ──────────────────────────────────────────────────────

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

    private function buildThrowingDispatcher(): TriggerDispatcher
    {
        $mock = $this->createMock(TriggerDispatcher::class);
        $mock->method('dispatch')
            ->willThrowException(new RuntimeException('Workflow engine offline'));

        return $mock;
    }

    private function buildTraitStub(?int $identityId = null): object
    {
        return new class ($identityId) {
            use WorkflowDispatchTrait;
            use LocatorAwareTrait;

            public object $request;

            public function __construct(?int $identityId)
            {
                $identity = $identityId !== null
                    ? new class ($identityId) implements ArrayAccess {
                        private int $id;
                        private array $data;

                        public function __construct(int $id)
                        {
                            $this->id = $id;
                            $this->data = ['id' => $id];
                        }

                        public function getIdentifier(): int
                        {
                            return $this->id;
                        }

                        public function offsetExists(mixed $offset): bool
                        {
                            return isset($this->data[$offset]);
                        }

                        public function offsetGet(mixed $offset): mixed
                        {
                            return $this->data[$offset] ?? null;
                        }

                        public function offsetSet(mixed $offset, mixed $value): void
                        {
                            $this->data[$offset] = $value;
                        }

                        public function offsetUnset(mixed $offset): void
                        {
                            unset($this->data[$offset]);
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
                TriggerDispatcher $dispatcher,
                string $slug,
                string $trigger,
                array $ctx,
                callable $legacy,
            ): mixed {
                return $this->dispatchOrLegacy($dispatcher, $slug, $trigger, $ctx, $legacy);
            }

            public function callDispatchWorkflowEvent(
                TriggerDispatcher $dispatcher,
                string $trigger,
                array $ctx,
            ): void {
                $this->dispatchWorkflowEvent($dispatcher, $trigger, $ctx);
            }
        };
    }

    /**
     * Deactivate all workflow definitions to guarantee the legacy path.
     */
    private function deactivateAllWorkflows(): void
    {
        $definitions = TableRegistry::getTableLocator()->get('WorkflowDefinitions');
        $definitions->updateAll(['is_active' => false], ['1 = 1']);
    }

    /**
     * Create an active workflow definition with a published current version.
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
     * Create a workflow definition that is active but has no current_version.
     */
    private function createDefinitionWithoutVersion(string $slug): int
    {
        $definitions = TableRegistry::getTableLocator()->get('WorkflowDefinitions');

        $def = $definitions->newEntity([
            'name' => 'No-version Workflow - ' . $slug,
            'slug' => $slug,
            'description' => 'Active but no published version',
            'trigger_type' => 'event',
            'is_active' => true,
            'current_version_id' => null,
        ]);
        $definitions->saveOrFail($def);

        return (int)$def->id;
    }

    /**
     * Create a workflow definition that is explicitly inactive.
     */
    private function createInactiveDefinition(string $slug): int
    {
        $definitions = TableRegistry::getTableLocator()->get('WorkflowDefinitions');
        $versions = TableRegistry::getTableLocator()->get('WorkflowVersions');

        $def = $definitions->newEntity([
            'name' => 'Inactive Workflow - ' . $slug,
            'slug' => $slug,
            'description' => 'Deactivated definition',
            'trigger_type' => 'event',
            'is_active' => false,
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

    // ================================================================
    // 1–10: Legacy Path — No Active Workflow
    // ================================================================

    /**
     * Activities add(): authorization request falls back to legacy AuthorizationManager.
     */
    public function testActivitiesAddLegacyPath(): void
    {
        $stub = $this->buildTraitStub(self::ADMIN_MEMBER_ID);
        $dispatcher = $this->buildMockDispatcher();

        $legacyCalled = false;
        $result = $stub->callDispatchOrLegacy(
            $dispatcher,
            'activities-authorization-request',
            'Activities.AuthorizationRequested',
            [
                'member_id' => self::ADMIN_MEMBER_ID,
                'activity_id' => 1,
                'approver_id' => self::ADMIN_MEMBER_ID,
                'renewal' => false,
            ],
            function () use (&$legacyCalled) {
                $legacyCalled = true;

                return (object)['success' => true, 'reason' => ''];
            },
        );

        $this->assertTrue($legacyCalled, 'Legacy AuthorizationManager path must run when no workflow is active');
        $this->assertEmpty($this->dispatched, 'Workflow engine must NOT be called');
        $this->assertTrue($result->success);
    }

    /**
     * Activities renew(): renewal falls back to legacy path.
     */
    public function testActivitiesRenewLegacyPath(): void
    {
        $stub = $this->buildTraitStub(self::ADMIN_MEMBER_ID);
        $dispatcher = $this->buildMockDispatcher();

        $legacyCalled = false;
        $result = $stub->callDispatchOrLegacy(
            $dispatcher,
            'activities-authorization-renewal',
            'Activities.AuthorizationRequested',
            [
                'member_id' => self::ADMIN_MEMBER_ID,
                'activity_id' => 1,
                'approver_id' => self::ADMIN_MEMBER_ID,
                'renewal' => true,
            ],
            function () use (&$legacyCalled) {
                $legacyCalled = true;

                return (object)['success' => true, 'reason' => ''];
            },
        );

        $this->assertTrue($legacyCalled, 'Legacy renewal path must run when no workflow is active');
        $this->assertEmpty($this->dispatched);
        $this->assertTrue($result->success);
    }

    /**
     * Activities revoke(): always uses legacy path + event hook (no dispatchOrLegacy).
     *
     * Revoke is always legacy; only a fire-and-forget event is sent.
     * With no workflow active, the event dispatch still works without error.
     */
    public function testActivitiesRevokeLegacyWithEventHook(): void
    {
        $stub = $this->buildTraitStub(self::ADMIN_MEMBER_ID);
        $dispatcher = $this->buildMockDispatcher();

        // Revoke always runs legacy then fires event
        $stub->callDispatchWorkflowEvent(
            $dispatcher,
            'Activities.AuthorizationRevoked',
            [
                'authorization_id' => 1,
                'member_id' => self::ADMIN_MEMBER_ID,
                'activity_id' => 1,
                'revoked_by' => self::ADMIN_MEMBER_ID,
                'revoked_reason' => 'Testing revocation',
            ],
        );

        // Event should still dispatch (fire-and-forget, no definition check)
        $this->assertCount(1, $this->dispatched);
        $this->assertEquals('Activities.AuthorizationRevoked', $this->dispatched[0]['event']);
    }

    /**
     * Officers release(): release falls back to legacy path.
     */
    public function testOfficersReleaseLegacyPath(): void
    {
        $stub = $this->buildTraitStub(self::ADMIN_MEMBER_ID);
        $dispatcher = $this->buildMockDispatcher();

        $legacyCalled = false;
        $stub->callDispatchOrLegacy(
            $dispatcher,
            'officers-release',
            'Officers.Released',
            [
                'officer_id' => 1,
                'released_by' => self::ADMIN_MEMBER_ID,
                'reason' => 'End of term',
                'revoked_on' => '2025-06-01 00:00:00',
            ],
            function () use (&$legacyCalled) {
                $legacyCalled = true;

                return (object)['success' => true, 'reason' => ''];
            },
        );

        $this->assertTrue($legacyCalled, 'Legacy OfficerManager release path must run when no workflow is active');
        $this->assertEmpty($this->dispatched);
    }

    /**
     * Members forgotPassword(): password reset falls back to legacy MemberAuthenticationService.
     */
    public function testMembersForgotPasswordLegacyPath(): void
    {
        $stub = $this->buildTraitStub(null); // Not authenticated for forgot password
        $dispatcher = $this->buildMockDispatcher();

        $legacyCalled = false;
        $result = $stub->callDispatchOrLegacy(
            $dispatcher,
            'member-password-reset',
            'Members.PasswordResetRequested',
            ['email_address' => 'admin@amp.ansteorra.org'],
            function () use (&$legacyCalled) {
                $legacyCalled = true;

                return 'legacy-reset-redirect';
            },
        );

        $this->assertTrue($legacyCalled, 'Legacy password reset must run when no workflow is active');
        $this->assertEquals('legacy-reset-redirect', $result);
        $this->assertEmpty($this->dispatched);
    }

    /**
     * Awards add(): recommendation creation falls back to legacy save.
     */
    public function testAwardsAddLegacyPath(): void
    {
        $stub = $this->buildTraitStub(self::ADMIN_MEMBER_ID);
        $dispatcher = $this->buildMockDispatcher();

        $legacyCalled = false;
        $result = $stub->callDispatchOrLegacy(
            $dispatcher,
            'awards-recommendation-submitted',
            'Awards.RecommendationCreateRequested',
            [
                'data' => [
                    'award_id' => 1,
                    'reason' => 'Legacy fallback test',
                ],
                'submissionMode' => 'authenticated',
            ],
            function () use (&$legacyCalled) {
                $legacyCalled = true;

                return 'legacy-save-result';
            },
        );

        $this->assertTrue($legacyCalled, 'Legacy award recommendation save must run when no workflow is active');
        $this->assertEquals('legacy-save-result', $result);
        $this->assertEmpty($this->dispatched);
    }

    /**
     * Waivers close(): waiver closure falls back to legacy WaiverStateService.
     */
    public function testWaiversCloseLegacyPath(): void
    {
        $stub = $this->buildTraitStub(self::ADMIN_MEMBER_ID);
        $dispatcher = $this->buildMockDispatcher();

        $legacyCalled = false;
        $result = $stub->callDispatchOrLegacy(
            $dispatcher,
            'waiver-closure',
            'Waivers.CollectionClosed',
            ['gathering_id' => 1, 'closed_by' => self::ADMIN_MEMBER_ID],
            function () use (&$legacyCalled) {
                $legacyCalled = true;

                return (object)['success' => true, 'reason' => 'Closed'];
            },
        );

        $this->assertTrue($legacyCalled, 'Legacy WaiverStateService close must run when no workflow is active');
        $this->assertEmpty($this->dispatched);
        $this->assertTrue($result->success);
    }

    /**
     * Warrants roster creation: falls back to legacy WarrantManager.
     */
    public function testWarrantsRosterCreationLegacyPath(): void
    {
        $stub = $this->buildTraitStub(self::ADMIN_MEMBER_ID);
        $dispatcher = $this->buildMockDispatcher();

        $legacyCalled = false;
        $result = $stub->callDispatchOrLegacy(
            $dispatcher,
            'warrants-roster-approval',
            'Warrants.RosterCreated',
            [
                'roster_id' => null,
                'warrant_requests' => [],
                'created_by' => self::ADMIN_MEMBER_ID,
            ],
            function () use (&$legacyCalled) {
                $legacyCalled = true;

                return 999; // Simulated roster ID
            },
        );

        $this->assertTrue($legacyCalled, 'Legacy WarrantManager roster creation must run when no workflow is active');
        $this->assertEquals(999, $result);
        $this->assertEmpty($this->dispatched);
    }

    // ================================================================
    // 11–13: WorkflowDispatchTrait Resilience
    // ================================================================

    /**
     * When an active workflow definition exists and the dispatcher's engine
     * fails, TriggerDispatcher catches the exception internally and returns [].
     *
     * dispatchOrLegacy does NOT fall back to legacy when a definition is found;
     * the workflow path is always taken. We simulate the real behaviour by
     * using a dispatcher that returns [] (as TriggerDispatcher::dispatch does
     * when the engine throws).
     */
    public function testDispatchOrLegacyWithActiveWorkflowAndFailingEngine(): void
    {
        $this->activateWorkflow('test-resilience-slug');
        $stub = $this->buildTraitStub(self::ADMIN_MEMBER_ID);

        // Simulate TriggerDispatcher returning [] after catching an internal exception
        $dispatcher = $this->createMock(TriggerDispatcher::class);
        $dispatcher->method('dispatch')->willReturn([]);

        $legacyCalled = false;
        $result = $stub->callDispatchOrLegacy(
            $dispatcher,
            'test-resilience-slug',
            'Test.ResilienceEvent',
            ['key' => 'value'],
            function () use (&$legacyCalled) {
                $legacyCalled = true;

                return 'legacy-fallback';
            },
        );

        $this->assertFalse($legacyCalled, 'Legacy must NOT run when an active definition exists');
        $this->assertIsArray($result, 'Result should be the empty array from the dispatcher');
        $this->assertEmpty($result);
    }

    /**
     * Workflow definition exists but has no current_version → legacy path runs.
     */
    public function testDefinitionWithoutCurrentVersionFallsToLegacy(): void
    {
        $this->createDefinitionWithoutVersion('no-version-test');
        $dispatcher = $this->buildMockDispatcher();
        $stub = $this->buildTraitStub(self::ADMIN_MEMBER_ID);

        $legacyCalled = false;
        $result = $stub->callDispatchOrLegacy(
            $dispatcher,
            'no-version-test',
            'Test.NoVersionEvent',
            ['key' => 'value'],
            function () use (&$legacyCalled) {
                $legacyCalled = true;

                return 'legacy-no-version';
            },
        );

        $this->assertTrue($legacyCalled, 'Legacy must run when definition has no current_version');
        $this->assertEquals('legacy-no-version', $result);
        $this->assertEmpty($this->dispatched);
    }

    /**
     * Workflow definition is_active=false → legacy path runs.
     */
    public function testInactiveDefinitionFallsToLegacy(): void
    {
        $this->createInactiveDefinition('inactive-test');
        $dispatcher = $this->buildMockDispatcher();
        $stub = $this->buildTraitStub(self::ADMIN_MEMBER_ID);

        $legacyCalled = false;
        $result = $stub->callDispatchOrLegacy(
            $dispatcher,
            'inactive-test',
            'Test.InactiveEvent',
            ['key' => 'value'],
            function () use (&$legacyCalled) {
                $legacyCalled = true;

                return 'legacy-inactive';
            },
        );

        $this->assertTrue($legacyCalled, 'Legacy must run when definition is inactive');
        $this->assertEquals('legacy-inactive', $result);
        $this->assertEmpty($this->dispatched);
    }

    // ================================================================
    // 14–15: Event Hook Safety
    // ================================================================

    /**
     * dispatchWorkflowEvent failure does not affect the main action.
     *
     * The event dispatch error is logged but the action succeeds.
     */
    public function testDispatchWorkflowEventFailureDoesNotAffectMainAction(): void
    {
        $dispatcher = $this->buildThrowingDispatcher();
        $stub = $this->buildTraitStub(self::ADMIN_MEMBER_ID);

        // Simulate: legacy action succeeds, then fire-and-forget event fails
        $actionResult = 'action-succeeded';

        // This must not throw even though the dispatcher raises an exception
        $stub->callDispatchWorkflowEvent(
            $dispatcher,
            'Activities.AuthorizationRevoked',
            [
                'authorization_id' => 1,
                'member_id' => self::ADMIN_MEMBER_ID,
                'revoked_by' => self::ADMIN_MEMBER_ID,
                'revoked_reason' => 'Test',
            ],
        );

        // Main action result is unaffected
        $this->assertEquals('action-succeeded', $actionResult);
    }

    /**
     * dispatchWorkflowEvent works without an authenticated user (null identity).
     */
    public function testDispatchWorkflowEventWithNullIdentity(): void
    {
        $dispatcher = $this->buildMockDispatcher();
        $stub = $this->buildTraitStub(null); // No authenticated user

        $stub->callDispatchWorkflowEvent(
            $dispatcher,
            'Members.PasswordResetRequested',
            ['email_address' => 'anon@example.com'],
        );

        $this->assertCount(1, $this->dispatched);
        $this->assertNull($this->dispatched[0]['triggeredBy'], 'triggeredBy must be null for unauthenticated user');
        $this->assertEquals('Members.PasswordResetRequested', $this->dispatched[0]['event']);
    }

    // ================================================================
    // 16–18: Additional Regression Coverage
    // ================================================================

    /**
     * Legacy callable return value is passed through unchanged.
     */
    public function testLegacyReturnValuePassthrough(): void
    {
        $stub = $this->buildTraitStub(self::ADMIN_MEMBER_ID);
        $dispatcher = $this->buildMockDispatcher();

        // Test with null return
        $result = $stub->callDispatchOrLegacy(
            $dispatcher,
            'nonexistent-slug',
            'Test.NullReturn',
            [],
            fn() => null,
        );
        $this->assertNull($result, 'Null return from legacy must pass through');

        // Test with false return
        $result = $stub->callDispatchOrLegacy(
            $dispatcher,
            'nonexistent-slug',
            'Test.FalseReturn',
            [],
            fn() => false,
        );
        $this->assertFalse($result, 'False return from legacy must pass through');

        // Test with complex object return
        $obj = (object)['id' => 42, 'success' => true, 'nested' => ['a' => 1]];
        $result = $stub->callDispatchOrLegacy(
            $dispatcher,
            'nonexistent-slug',
            'Test.ObjectReturn',
            [],
            fn() => $obj,
        );
        $this->assertSame($obj, $result, 'Object return from legacy must be the same reference');
    }

    /**
     * Active workflow dispatch sends correct triggeredBy from identity.
     */
    public function testActiveWorkflowPassesCorrectTriggeredBy(): void
    {
        $this->activateWorkflow('identity-test');
        $dispatcher = $this->buildMockDispatcher();
        $actorId = self::TEST_MEMBER_AGATHA_ID;
        $stub = $this->buildTraitStub($actorId);

        $legacyCalled = false;
        $stub->callDispatchOrLegacy(
            $dispatcher,
            'identity-test',
            'Test.IdentityCheck',
            ['key' => 'value'],
            function () use (&$legacyCalled) {
                $legacyCalled = true;
            },
        );

        $this->assertFalse($legacyCalled, 'Legacy must NOT run when workflow is active');
        $this->assertCount(1, $this->dispatched);
        $this->assertEquals($actorId, $this->dispatched[0]['triggeredBy']);
    }

    /**
     * Context data is forwarded verbatim to the workflow engine.
     */
    public function testContextDataForwardedVerbatim(): void
    {
        $this->activateWorkflow('context-test');
        $dispatcher = $this->buildMockDispatcher();
        $stub = $this->buildTraitStub(self::ADMIN_MEMBER_ID);

        $context = [
            'member_id' => 123,
            'nested' => ['a' => 1, 'b' => [2, 3]],
            'empty_string' => '',
            'zero' => 0,
            'null_value' => null,
        ];

        $stub->callDispatchOrLegacy(
            $dispatcher,
            'context-test',
            'Test.ContextCheck',
            $context,
            fn() => 'should-not-run',
        );

        $this->assertCount(1, $this->dispatched);
        // Kingdom-aware trait adds kingdom_id to context
        $expected = $context + ['kingdom_id' => null];
        $this->assertEquals($expected, $this->dispatched[0]['data'], 'Context must be forwarded with kingdom_id');
    }
}
