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

    protected function setUp(): void
    {
        parent::setUp();

        $this->defTable = TableRegistry::getTableLocator()->get('WorkflowDefinitions');
        $this->versionsTable = TableRegistry::getTableLocator()->get('WorkflowVersions');

        // Build a concrete object that uses the trait, with a mock request
        $identity = $this->createMock(\Authentication\IdentityInterface::class);
        $identity->method('getIdentifier')->willReturn(42);

        $request = new ServerRequest(['url' => '/test']);
        $request = $request->withAttribute('identity', $identity);

        $trait = $this;
        $this->subject = new class ($request) {
            use \Cake\ORM\Locator\LocatorAwareTrait;
            use WorkflowDispatchTrait {
                dispatchOrLegacy as public;
                dispatchWorkflowEvent as public;
            }

            public \Cake\Http\ServerRequest $request;

            public function __construct(ServerRequest $request)
            {
                $this->request = $request;
            }
        };
    }

    /**
     * Helper: create an active workflow definition with a published version.
     */
    private function createActiveWorkflow(string $slug): int
    {
        $def = $this->defTable->newEntity([
            'name' => 'Test: ' . $slug,
            'slug' => $slug,
            'trigger_type' => 'manual',
            'is_active' => true,
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
    private function createInactiveWorkflow(string $slug): int
    {
        $def = $this->defTable->newEntity([
            'name' => 'Inactive: ' . $slug,
            'slug' => $slug,
            'trigger_type' => 'manual',
            'is_active' => false,
        ]);
        $this->defTable->saveOrFail($def);

        return $def->id;
    }

    /**
     * Helper: create an active workflow definition WITHOUT a current version.
     */
    private function createWorkflowWithoutVersion(string $slug): int
    {
        $def = $this->defTable->newEntity([
            'name' => 'No version: ' . $slug,
            'slug' => $slug,
            'trigger_type' => 'manual',
            'is_active' => true,
            'current_version_id' => null,
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
            ->with('Test.Event', ['foo' => 'bar'], 42)
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

        // Subject with no identity
        $request = new ServerRequest(['url' => '/test']);
        $subject = new class ($request) {
            use \Cake\ORM\Locator\LocatorAwareTrait;
            use WorkflowDispatchTrait {
                dispatchOrLegacy as public;
                dispatchWorkflowEvent as public;
            }

            public \Cake\Http\ServerRequest $request;

            public function __construct(ServerRequest $request)
            {
                $this->request = $request;
            }
        };

        $dispatcher = $this->createMock(TriggerDispatcher::class);
        $dispatcher->expects($this->once())
            ->method('dispatch')
            ->with('Test.NullId', [], null)
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
            ->with('Activities.AuthorizationRequested', $expectedCtx, $this->anything())
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
            ->with('Activities.AuthorizationRevoked', ['id' => 99], 42);

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
        $request = new ServerRequest(['url' => '/test']);
        $subject = new class ($request) {
            use \Cake\ORM\Locator\LocatorAwareTrait;
            use WorkflowDispatchTrait {
                dispatchOrLegacy as public;
                dispatchWorkflowEvent as public;
            }

            public \Cake\Http\ServerRequest $request;

            public function __construct(ServerRequest $request)
            {
                $this->request = $request;
            }
        };

        $dispatcher = $this->createMock(TriggerDispatcher::class);
        $dispatcher->expects($this->once())
            ->method('dispatch')
            ->with('Test.NullIdentity', [], null);

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
}
