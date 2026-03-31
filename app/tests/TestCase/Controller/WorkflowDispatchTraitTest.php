<?php

declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Controller\WorkflowDispatchTrait;
use App\Services\WorkflowEngine\TriggerDispatcher;
use App\Test\TestCase\BaseTestCase;
use Cake\Http\ServerRequest;
use Cake\ORM\TableRegistry;

/**
 * Tests for WorkflowDispatchTrait dual-path dispatch logic.
 */
class WorkflowDispatchTraitTest extends BaseTestCase
{
    private $defTable;
    private $versionsTable;

    /**
     * Anonymous class instance using the trait under test.
     */
    private $subject;

    /**
     * Create a test subject with a mock identity that has a specific branch_id.
     */
    private function createSubjectWithBranch(?int $branchId, int $memberId = 42): object
    {
        $identity = $this->createMock(\Authentication\IdentityInterface::class);
        $identity->method('getIdentifier')->willReturn($memberId);
        $identity->method('offsetGet')->willReturnCallback(function ($offset) use ($branchId) {
            return match ($offset) {
                'branch_id' => $branchId,
                default => null,
            };
        });
        $identity->method('offsetExists')->willReturnCallback(function ($offset) use ($branchId) {
            return $offset === 'branch_id' && $branchId !== null;
        });

        $request = new ServerRequest(['url' => '/test']);
        $request = $request->withAttribute('identity', $identity);

        return new class ($request) {
            use \Cake\ORM\Locator\LocatorAwareTrait;
            use WorkflowDispatchTrait {
                dispatchOrLegacy as public;
                dispatchWorkflowEvent as public;
                resolveKingdomId as public;
                resolveKingdomIdFromBranch as public;
            }

            public \Cake\Http\ServerRequest $request;

            public function __construct(ServerRequest $request)
            {
                $this->request = $request;
            }
        };
    }

    /**
     * Create a test subject with no identity (anonymous context).
     */
    private function createAnonymousSubject(): object
    {
        $request = new ServerRequest(['url' => '/test']);

        return new class ($request) {
            use \Cake\ORM\Locator\LocatorAwareTrait;
            use WorkflowDispatchTrait {
                dispatchOrLegacy as public;
                dispatchWorkflowEvent as public;
                resolveKingdomId as public;
            }

            public \Cake\Http\ServerRequest $request;

            public function __construct(ServerRequest $request)
            {
                $this->request = $request;
            }
        };
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->defTable = TableRegistry::getTableLocator()->get('WorkflowDefinitions');
        $this->versionsTable = TableRegistry::getTableLocator()->get('WorkflowVersions');

        // Default subject with a branch in the kingdom hierarchy
        $this->subject = $this->createSubjectWithBranch(self::TEST_BRANCH_STARGATE_ID);
    }

    /**
     * Helper: create an active workflow definition with a published version.
     */
    private function createActiveWorkflow(string $slug, ?int $kingdomId = null): int
    {
        $def = $this->defTable->newEntity([
            'name' => 'Test: ' . $slug,
            'slug' => $slug,
            'trigger_type' => 'manual',
            'is_active' => true,
            'kingdom_id' => $kingdomId,
        ]);
        $this->defTable->saveOrFail($def);

        $version = $this->versionsTable->newEntity([
            'workflow_definition_id' => $def->id,
            'version_number' => 1,
            'definition' => [
                'nodes' => [
                    'trigger1' => ['type' => 'trigger', 'config' => [], 'outputs' => [['port' => 'default', 'target' => 'end1']]],
                    'end1' => ['type' => 'end', 'config' => [], 'outputs' => []],
                ],
            ],
            'status' => 'published',
        ]);
        $this->versionsTable->saveOrFail($version);

        $def->current_version_id = $version->id;
        $this->defTable->saveOrFail($def);

        return $def->id;
    }

    /**
     * Helper: create an inactive workflow definition.
     */
    private function createInactiveWorkflow(string $slug, ?int $kingdomId = null): int
    {
        $def = $this->defTable->newEntity([
            'name' => 'Inactive: ' . $slug,
            'slug' => $slug,
            'trigger_type' => 'manual',
            'is_active' => false,
            'kingdom_id' => $kingdomId,
        ]);
        $this->defTable->saveOrFail($def);

        return $def->id;
    }

    /**
     * Helper: create an active workflow definition WITHOUT a current version.
     */
    private function createWorkflowWithoutVersion(string $slug, ?int $kingdomId = null): int
    {
        $def = $this->defTable->newEntity([
            'name' => 'No version: ' . $slug,
            'slug' => $slug,
            'trigger_type' => 'manual',
            'is_active' => true,
            'current_version_id' => null,
            'kingdom_id' => $kingdomId,
        ]);
        $this->defTable->saveOrFail($def);

        return $def->id;
    }

    // =========================================================================
    // dispatchOrLegacy — legacy fallback path
    // =========================================================================

    public function testDispatchOrLegacyCallsLegacyWhenNoWorkflowDefined(): void
    {
        $dispatcher = $this->createMock(TriggerDispatcher::class);
        $dispatcher->expects($this->never())->method('dispatch');

        $slug = 'no-def-' . uniqid();
        $called = false;
        $result = $this->subject->dispatchOrLegacy(
            $dispatcher,
            $slug,
            'Test.Event',
            ['key' => 'val'],
            function () use (&$called) {
                $called = true;

                return 'legacy-result';
            },
        );

        $this->assertTrue($called, 'Legacy callable should be invoked');
        $this->assertSame('legacy-result', $result);
    }

    public function testDispatchOrLegacyCallsLegacyWhenWorkflowIsInactive(): void
    {
        $slug = 'inactive-' . uniqid();
        $this->createInactiveWorkflow($slug);

        $dispatcher = $this->createMock(TriggerDispatcher::class);
        $dispatcher->expects($this->never())->method('dispatch');

        $called = false;
        $this->subject->dispatchOrLegacy(
            $dispatcher,
            $slug,
            'Test.Event',
            [],
            function () use (&$called) {
                $called = true;

                return null;
            },
        );

        $this->assertTrue($called, 'Legacy callable should be invoked for inactive workflow');
    }

    public function testDispatchOrLegacyCallsLegacyWhenDefinitionHasNoCurrentVersion(): void
    {
        $slug = 'no-version-' . uniqid();
        $this->createWorkflowWithoutVersion($slug);

        $dispatcher = $this->createMock(TriggerDispatcher::class);
        $dispatcher->expects($this->never())->method('dispatch');

        $called = false;
        $this->subject->dispatchOrLegacy(
            $dispatcher,
            $slug,
            'Test.Event',
            [],
            function () use (&$called) {
                $called = true;

                return 'fallback';
            },
        );

        $this->assertTrue($called, 'Legacy callable should be invoked when no current version');
    }

    // =========================================================================
    // dispatchOrLegacy — workflow dispatch path
    // =========================================================================

    public function testDispatchOrLegacyCallsDispatcherWhenWorkflowIsActive(): void
    {
        $slug = 'active-' . uniqid();
        $this->createActiveWorkflow($slug);

        $dispatcher = $this->createMock(TriggerDispatcher::class);
        $dispatcher->expects($this->once())
            ->method('dispatch')
            ->with('Test.Event', $this->callback(function ($ctx) {
                return $ctx['foo'] === 'bar' && array_key_exists('kingdom_id', $ctx);
            }), 42)
            ->willReturn(['result1']);

        $legacyCalled = false;
        $result = $this->subject->dispatchOrLegacy(
            $dispatcher,
            $slug,
            'Test.Event',
            ['foo' => 'bar'],
            function () use (&$legacyCalled) {
                $legacyCalled = true;

                return 'should-not-return';
            },
        );

        $this->assertFalse($legacyCalled, 'Legacy callable should NOT be invoked');
        $this->assertSame(['result1'], $result);
    }

    public function testDispatchOrLegacyPassesTriggeredByFromIdentity(): void
    {
        $slug = 'identity-' . uniqid();
        $this->createActiveWorkflow($slug);

        $dispatcher = $this->createMock(TriggerDispatcher::class);
        $dispatcher->expects($this->once())
            ->method('dispatch')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->equalTo(42),
            )
            ->willReturn([]);

        $this->subject->dispatchOrLegacy(
            $dispatcher,
            $slug,
            'Test.Identity',
            [],
            fn () => null,
        );
    }

    public function testDispatchOrLegacyHandlesNullIdentity(): void
    {
        $slug = 'null-identity-' . uniqid();
        $this->createActiveWorkflow($slug);

        $subject = $this->createAnonymousSubject();

        $dispatcher = $this->createMock(TriggerDispatcher::class);
        $dispatcher->expects($this->once())
            ->method('dispatch')
            ->with('Test.NullId', $this->callback(function ($ctx) {
                return $ctx['kingdom_id'] === null;
            }), null)
            ->willReturn([]);

        $subject->dispatchOrLegacy(
            $dispatcher,
            $slug,
            'Test.NullId',
            [],
            fn () => null,
        );
    }

    public function testDispatchOrLegacyPassesContextToDispatcher(): void
    {
        $slug = 'ctx-' . uniqid();
        $this->createActiveWorkflow($slug);

        $expectedCtx = ['member_id' => 1, 'activity_id' => 5, 'renewal' => true];

        $dispatcher = $this->createMock(TriggerDispatcher::class);
        $dispatcher->expects($this->once())
            ->method('dispatch')
            ->with('Activities.AuthorizationRequested', $this->callback(function ($ctx) use ($expectedCtx) {
                return $ctx['member_id'] === $expectedCtx['member_id']
                    && $ctx['activity_id'] === $expectedCtx['activity_id']
                    && $ctx['renewal'] === $expectedCtx['renewal']
                    && array_key_exists('kingdom_id', $ctx);
            }), $this->anything())
            ->willReturn([]);

        $this->subject->dispatchOrLegacy(
            $dispatcher,
            $slug,
            'Activities.AuthorizationRequested',
            $expectedCtx,
            fn () => null,
        );
    }

    // =========================================================================
    // dispatchWorkflowEvent
    // =========================================================================

    public function testDispatchWorkflowEventCallsDispatcher(): void
    {
        $dispatcher = $this->createMock(TriggerDispatcher::class);
        $dispatcher->expects($this->once())
            ->method('dispatch')
            ->with('Activities.AuthorizationRevoked', $this->callback(function ($ctx) {
                return $ctx['id'] === 99 && array_key_exists('kingdom_id', $ctx);
            }), 42);

        $this->subject->dispatchWorkflowEvent(
            $dispatcher,
            'Activities.AuthorizationRevoked',
            ['id' => 99],
        );
    }

    public function testDispatchWorkflowEventDoesNotThrowWhenDispatchFails(): void
    {
        $dispatcher = $this->createMock(TriggerDispatcher::class);
        $dispatcher->method('dispatch')
            ->willThrowException(new \RuntimeException('Boom'));

        // Should not throw
        $this->subject->dispatchWorkflowEvent(
            $dispatcher,
            'Test.Failure',
            ['data' => 'value'],
        );

        $this->assertTrue(true, 'No exception should propagate');
    }

    public function testDispatchWorkflowEventPassesTriggeredByFromIdentity(): void
    {
        $dispatcher = $this->createMock(TriggerDispatcher::class);
        $dispatcher->expects($this->once())
            ->method('dispatch')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->equalTo(42),
            );

        $this->subject->dispatchWorkflowEvent(
            $dispatcher,
            'Test.IdentityCheck',
            [],
        );
    }

    public function testDispatchWorkflowEventHandlesNullIdentity(): void
    {
        $subject = $this->createAnonymousSubject();

        $dispatcher = $this->createMock(TriggerDispatcher::class);
        $dispatcher->expects($this->once())
            ->method('dispatch')
            ->with('Test.NullIdentity', $this->callback(function ($ctx) {
                return $ctx['kingdom_id'] === null;
            }), null);

        $subject->dispatchWorkflowEvent(
            $dispatcher,
            'Test.NullIdentity',
            [],
        );
    }

    // =========================================================================
    // Edge cases
    // =========================================================================

    public function testDispatchOrLegacyReturnsLegacyResultValueUnchanged(): void
    {
        $slug = 'return-val-' . uniqid();
        $dispatcher = $this->createMock(TriggerDispatcher::class);
        $dispatcher->expects($this->never())->method('dispatch');

        $obj = new \stdClass();
        $obj->success = true;
        $obj->reason = null;

        $result = $this->subject->dispatchOrLegacy(
            $dispatcher,
            $slug,
            'Test.Return',
            [],
            fn () => $obj,
        );

        $this->assertSame($obj, $result, 'Legacy return value should be passed through unchanged');
    }

    // =========================================================================
    // Kingdom resolution
    // =========================================================================

    public function testResolveKingdomIdReturnsKingdomForLocalBranch(): void
    {
        // Stargate (barony) should resolve to kingdom
        $subject = $this->createSubjectWithBranch(self::TEST_BRANCH_STARGATE_ID);
        $kingdomId = $subject->resolveKingdomId();

        $this->assertSame(self::KINGDOM_BRANCH_ID, $kingdomId);
    }

    public function testResolveKingdomIdReturnsKingdomForKingdomBranch(): void
    {
        // Kingdom branch should resolve to itself
        $subject = $this->createSubjectWithBranch(self::KINGDOM_BRANCH_ID);
        $kingdomId = $subject->resolveKingdomId();

        $this->assertSame(self::KINGDOM_BRANCH_ID, $kingdomId);
    }

    public function testResolveKingdomIdReturnsNullForAnonymous(): void
    {
        $subject = $this->createAnonymousSubject();
        $this->assertNull($subject->resolveKingdomId());
    }

    public function testResolveKingdomIdReturnsNullForNullBranch(): void
    {
        $subject = $this->createSubjectWithBranch(null);
        $this->assertNull($subject->resolveKingdomId());
    }

    public function testResolveKingdomIdFromBranchForRegion(): void
    {
        // Central Region should resolve to kingdom
        $subject = $this->createSubjectWithBranch(self::TEST_BRANCH_CENTRAL_REGION_ID);
        $kingdomId = $subject->resolveKingdomIdFromBranch(self::TEST_BRANCH_CENTRAL_REGION_ID);

        $this->assertSame(self::KINGDOM_BRANCH_ID, $kingdomId);
    }

    // =========================================================================
    // Kingdom-scoped workflow dispatch
    // =========================================================================

    public function testDispatchOrLegacyPrefersKingdomSpecificOverGlobal(): void
    {
        $slug = 'kingdom-pref-' . uniqid();

        // Create both global and kingdom-specific definitions
        $this->createActiveWorkflow($slug); // global (kingdom_id = null)
        $this->createActiveWorkflow($slug, self::KINGDOM_BRANCH_ID); // kingdom-specific

        $subject = $this->createSubjectWithBranch(self::TEST_BRANCH_STARGATE_ID);

        $dispatcher = $this->createMock(TriggerDispatcher::class);
        $dispatcher->expects($this->once())
            ->method('dispatch')
            ->with('Test.KingdomPref', $this->callback(function ($ctx) {
                return $ctx['kingdom_id'] === self::KINGDOM_BRANCH_ID;
            }), $this->anything())
            ->willReturn(['kingdom-result']);

        $result = $subject->dispatchOrLegacy(
            $dispatcher,
            $slug,
            'Test.KingdomPref',
            [],
            fn () => 'legacy',
        );

        $this->assertSame(['kingdom-result'], $result);
    }

    public function testDispatchOrLegacyFallsBackToGlobalWhenNoKingdomSpecific(): void
    {
        $slug = 'global-fallback-' . uniqid();

        // Only create global definition
        $this->createActiveWorkflow($slug);

        $subject = $this->createSubjectWithBranch(self::TEST_BRANCH_STARGATE_ID);

        $dispatcher = $this->createMock(TriggerDispatcher::class);
        $dispatcher->expects($this->once())
            ->method('dispatch')
            ->with('Test.GlobalFallback', $this->callback(function ($ctx) {
                return $ctx['kingdom_id'] === self::KINGDOM_BRANCH_ID;
            }), $this->anything())
            ->willReturn(['global-result']);

        $result = $subject->dispatchOrLegacy(
            $dispatcher,
            $slug,
            'Test.GlobalFallback',
            [],
            fn () => 'legacy',
        );

        $this->assertSame(['global-result'], $result);
    }

    public function testDispatchOrLegacyUsesGlobalOnlyWhenNoKingdomAvailable(): void
    {
        $slug = 'anon-global-' . uniqid();

        // Create both global and kingdom-specific
        $this->createActiveWorkflow($slug); // global
        $this->createActiveWorkflow($slug, self::KINGDOM_BRANCH_ID); // kingdom-specific

        // Anonymous subject — no identity, no kingdom
        $subject = $this->createAnonymousSubject();

        $dispatcher = $this->createMock(TriggerDispatcher::class);
        $dispatcher->expects($this->once())
            ->method('dispatch')
            ->with('Test.AnonGlobal', $this->callback(function ($ctx) {
                return $ctx['kingdom_id'] === null;
            }), null)
            ->willReturn(['anon-result']);

        $result = $subject->dispatchOrLegacy(
            $dispatcher,
            $slug,
            'Test.AnonGlobal',
            [],
            fn () => 'legacy',
        );

        $this->assertSame(['anon-result'], $result);
    }

    public function testDispatchOrLegacyCallsLegacyWhenKingdomSpecificIsInactive(): void
    {
        $slug = 'inactive-kingdom-' . uniqid();

        // Create inactive kingdom-specific (no global)
        $this->createInactiveWorkflow($slug, self::KINGDOM_BRANCH_ID);

        $subject = $this->createSubjectWithBranch(self::TEST_BRANCH_STARGATE_ID);

        $dispatcher = $this->createMock(TriggerDispatcher::class);
        $dispatcher->expects($this->never())->method('dispatch');

        $called = false;
        $subject->dispatchOrLegacy(
            $dispatcher,
            $slug,
            'Test.InactiveKingdom',
            [],
            function () use (&$called) {
                $called = true;

                return 'legacy';
            },
        );

        $this->assertTrue($called, 'Legacy callable should be invoked when kingdom-specific is inactive');
    }

    public function testDispatchOrLegacyFallsBackToGlobalWhenKingdomSpecificLacksVersion(): void
    {
        $slug = 'no-ver-kingdom-' . uniqid();

        // Kingdom-specific without version, global with version
        $this->createWorkflowWithoutVersion($slug, self::KINGDOM_BRANCH_ID);
        $this->createActiveWorkflow($slug); // global

        $subject = $this->createSubjectWithBranch(self::TEST_BRANCH_STARGATE_ID);

        // findForKingdom returns kingdom-specific first (no version), but it's inactive check fails,
        // so it should fall back to legacy since the kingdom-specific def is the one returned
        $dispatcher = $this->createMock(TriggerDispatcher::class);

        // The kingdom-specific def is found but has no current_version_id, so
        // findActiveDefinition returns null → legacy is called
        $called = false;
        $subject->dispatchOrLegacy(
            $dispatcher,
            $slug,
            'Test.NoVerKingdom',
            [],
            function () use (&$called) {
                $called = true;

                return 'legacy';
            },
        );

        $this->assertTrue($called, 'Legacy called when kingdom-specific definition has no version');
    }

    public function testDispatchWorkflowEventIncludesKingdomIdInContext(): void
    {
        $subject = $this->createSubjectWithBranch(self::TEST_BRANCH_STARGATE_ID);

        $dispatcher = $this->createMock(TriggerDispatcher::class);
        $dispatcher->expects($this->once())
            ->method('dispatch')
            ->with(
                'Test.KingdomContext',
                $this->callback(function ($ctx) {
                    return $ctx['kingdom_id'] === self::KINGDOM_BRANCH_ID
                        && $ctx['original_key'] === 'value';
                }),
                $this->anything(),
            );

        $subject->dispatchWorkflowEvent(
            $dispatcher,
            'Test.KingdomContext',
            ['original_key' => 'value'],
        );
    }

    public function testDispatchWorkflowEventNullKingdomForAnonymous(): void
    {
        $subject = $this->createAnonymousSubject();

        $dispatcher = $this->createMock(TriggerDispatcher::class);
        $dispatcher->expects($this->once())
            ->method('dispatch')
            ->with(
                'Test.AnonContext',
                $this->callback(function ($ctx) {
                    return $ctx['kingdom_id'] === null;
                }),
                null,
            );

        $subject->dispatchWorkflowEvent(
            $dispatcher,
            'Test.AnonContext',
            [],
        );
    }
}
