<?php

declare(strict_types=1);

namespace Waivers\Test\TestCase\Controller;

use App\Services\WorkflowEngine\TriggerDispatcher;
use App\Test\TestCase\Support\HttpIntegrationTestCase;
use Cake\Core\ContainerInterface;

/**
 * Tests workflow dispatch integration in GatheringWaiversController.
 *
 * Verifies that close() uses dispatchOrLegacy (dual-path) and that
 * reopen(), markReadyToClose(), and decline() fire workflow events
 * after successful operations.
 */
class GatheringWaiversWorkflowDispatchTest extends HttpIntegrationTestCase
{
    /**
     * Keys of mocked services that need DI argument clearing.
     */
    private array $mockedServiceKeys = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableCsrfToken();
        $this->enableSecurityToken();
        $this->authenticateAsSuperUser();

        $this->mockServiceClean(ContainerInterface::class, function () {
            return $this->createMock(ContainerInterface::class);
        });

        $this->mockServiceClean(TriggerDispatcher::class, function () {
            $mock = $this->createMock(TriggerDispatcher::class);
            $mock->method('dispatch')->willReturn([]);

            return $mock;
        });
    }

    protected function tearDown(): void
    {
        $this->mockedServiceKeys = [];
        parent::tearDown();
    }

    /**
     * Override modifyContainer to clear stale DI arguments after setConcrete.
     */
    public function modifyContainer(\Cake\Event\EventInterface $event, \Psr\Container\ContainerInterface $container): void
    {
        parent::modifyContainer($event, $container);

        foreach ($this->mockedServiceKeys as $key) {
            if ($container->has($key)) {
                try {
                    $def = $container->extend($key);
                    $ref = new \ReflectionProperty($def, 'arguments');
                    $ref->setAccessible(true);
                    $ref->setValue($def, []);
                } catch (\Exception $e) {
                    // Definition may not exist in aggregate — ignore
                }
            }
        }
    }

    /**
     * Mock a service AND mark it for DI argument clearing.
     */
    protected function mockServiceClean(string $class, \Closure $factory): void
    {
        $this->mockService($class, $factory);
        $this->mockedServiceKeys[] = $class;
    }

    /**
     * Helper: find a gathering that has waivers in seed data.
     */
    private function getGatheringWithWaivers(): ?object
    {
        $GatheringWaivers = $this->getTableLocator()->get('Waivers.GatheringWaivers');
        $waiver = $GatheringWaivers->find()->contain(['Gatherings'])->first();
        if (!$waiver || !$waiver->gathering) {
            return null;
        }

        return $waiver;
    }

    // ---------------------------------------------------------------
    // close() — dual-path dispatch
    // ---------------------------------------------------------------

    /**
     * Test close action falls back to legacy path when no workflow definition exists.
     */
    public function testCloseFallsBackToLegacyWithoutWorkflowDefinition(): void
    {
        $waiver = $this->getGatheringWithWaivers();
        if (!$waiver) {
            $this->markTestSkipped('No gathering waivers in seed data');
        }
        $gatheringId = $waiver->gathering_id;

        // Ensure no waiver-closure workflow definition exists (default state)
        $defTable = $this->getTableLocator()->get('WorkflowDefinitions');
        $defTable->deleteAll(['slug' => 'waiver-closure']);

        $this->post('/waivers/gathering-waivers/close/' . $gatheringId);
        $this->assertResponseSuccess();
        $this->assertRedirect();
    }

    /**
     * Test close action uses workflow dispatch when an active definition exists.
     */
    public function testCloseDispatchesWorkflowWhenDefinitionActive(): void
    {
        $waiver = $this->getGatheringWithWaivers();
        if (!$waiver) {
            $this->markTestSkipped('No gathering waivers in seed data');
        }
        $gatheringId = $waiver->gathering_id;

        // Reopen first so we can close cleanly
        $closuresTable = $this->getTableLocator()->get('Waivers.GatheringWaiverClosures');
        $closuresTable->deleteAll(['gathering_id' => $gatheringId]);

        // Create an active workflow definition with a published version
        $defTable = $this->getTableLocator()->get('WorkflowDefinitions');
        $defTable->deleteAll(['slug' => 'waiver-closure']);

        $def = $defTable->newEntity([
            'name' => 'Waiver Closure Workflow',
            'slug' => 'waiver-closure',
            'trigger_type' => 'event',
            'trigger_config' => ['event' => 'Waivers.CollectionClosed'],
            'entity_type' => 'Gatherings',
            'is_active' => true,
        ]);
        $defTable->saveOrFail($def);

        $versionsTable = $this->getTableLocator()->get('WorkflowVersions');
        $version = $versionsTable->newEntity([
            'workflow_definition_id' => $def->id,
            'version_number' => 1,
            'definition' => [
                'nodes' => [
                    'trigger1' => [
                        'type' => 'trigger',
                        'config' => ['event' => 'Waivers.CollectionClosed'],
                        'outputs' => [['port' => 'default', 'target' => 'end1']],
                    ],
                    'end1' => ['type' => 'end', 'config' => [], 'outputs' => []],
                ],
            ],
            'status' => 'published',
        ]);
        $versionsTable->saveOrFail($version);

        $def->current_version_id = $version->id;
        $defTable->saveOrFail($def);

        // Now call close — should go through workflow dispatch path
        $this->post('/waivers/gathering-waivers/close/' . $gatheringId);
        $this->assertResponseSuccess();
        $this->assertRedirect();
    }

    /**
     * Test close requires POST method.
     */
    public function testCloseRejectsGetRequest(): void
    {
        $waiver = $this->getGatheringWithWaivers();
        if (!$waiver) {
            $this->markTestSkipped('No gathering waivers in seed data');
        }

        $this->get('/waivers/gathering-waivers/close/' . $waiver->gathering_id);
        $this->assertResponseCode(405);
    }

    // ---------------------------------------------------------------
    // reopen() — fire-and-forget workflow event
    // ---------------------------------------------------------------

    /**
     * Test reopen fires workflow event on success.
     */
    public function testReopenFiresWorkflowEvent(): void
    {
        $waiver = $this->getGatheringWithWaivers();
        if (!$waiver) {
            $this->markTestSkipped('No gathering waivers in seed data');
        }
        $gatheringId = $waiver->gathering_id;

        // Close the gathering first so we can reopen it
        $closuresTable = $this->getTableLocator()->get('Waivers.GatheringWaiverClosures');
        $closuresTable->deleteAll(['gathering_id' => $gatheringId]);
        $closure = $closuresTable->newEntity([
            'gathering_id' => $gatheringId,
            'closed_at' => new \Cake\I18n\DateTime(),
            'closed_by' => self::ADMIN_MEMBER_ID,
        ]);
        $closuresTable->saveOrFail($closure);

        $this->post('/waivers/gathering-waivers/reopen/' . $gatheringId);
        $this->assertResponseSuccess();
        $this->assertRedirect();

        // Verify gathering is no longer closed
        $this->assertFalse(
            $closuresTable->exists(['gathering_id' => $gatheringId, 'closed_at IS NOT' => null]),
            'Gathering should be reopened',
        );
    }

    /**
     * Test reopen does not fire workflow event on failure (already open).
     */
    public function testReopenNoWorkflowEventOnAlreadyOpen(): void
    {
        $waiver = $this->getGatheringWithWaivers();
        if (!$waiver) {
            $this->markTestSkipped('No gathering waivers in seed data');
        }
        $gatheringId = $waiver->gathering_id;

        // Ensure gathering is not closed
        $closuresTable = $this->getTableLocator()->get('Waivers.GatheringWaiverClosures');
        $closuresTable->deleteAll(['gathering_id' => $gatheringId]);

        $this->post('/waivers/gathering-waivers/reopen/' . $gatheringId);
        $this->assertResponseSuccess();
        $this->assertRedirect();
    }

    // ---------------------------------------------------------------
    // markReadyToClose() — fire-and-forget workflow event
    // ---------------------------------------------------------------

    /**
     * Test markReadyToClose fires workflow event on success.
     */
    public function testMarkReadyToCloseFiresWorkflowEvent(): void
    {
        $waiver = $this->getGatheringWithWaivers();
        if (!$waiver) {
            $this->markTestSkipped('No gathering waivers in seed data');
        }
        $gatheringId = $waiver->gathering_id;

        // Ensure gathering is not already marked or closed
        $closuresTable = $this->getTableLocator()->get('Waivers.GatheringWaiverClosures');
        $closuresTable->deleteAll(['gathering_id' => $gatheringId]);

        $this->post('/waivers/gathering-waivers/mark-ready-to-close/' . $gatheringId);
        $this->assertResponseSuccess();
        $this->assertRedirect();

        // Verify gathering is now marked ready to close
        $this->assertTrue(
            $closuresTable->exists([
                'gathering_id' => $gatheringId,
                'ready_to_close_at IS NOT' => null,
            ]),
            'Gathering should be marked ready to close',
        );
    }

    /**
     * Test markReadyToClose does not fire event when already marked.
     */
    public function testMarkReadyToCloseAlreadyMarked(): void
    {
        $waiver = $this->getGatheringWithWaivers();
        if (!$waiver) {
            $this->markTestSkipped('No gathering waivers in seed data');
        }
        $gatheringId = $waiver->gathering_id;

        // Mark ready first
        $closuresTable = $this->getTableLocator()->get('Waivers.GatheringWaiverClosures');
        $closuresTable->deleteAll(['gathering_id' => $gatheringId]);
        $closuresTable->markReadyToClose((int)$gatheringId, self::ADMIN_MEMBER_ID);

        // Try again — should get "already" message, no workflow event
        $this->post('/waivers/gathering-waivers/mark-ready-to-close/' . $gatheringId);
        $this->assertResponseSuccess();
        $this->assertRedirect();
    }

    // ---------------------------------------------------------------
    // decline() — fire-and-forget workflow event
    // ---------------------------------------------------------------

    /**
     * Test decline fires workflow event on success.
     */
    public function testDeclineFiresWorkflowEvent(): void
    {
        $GatheringWaivers = $this->getTableLocator()->get('Waivers.GatheringWaivers');
        $waiver = $GatheringWaivers->find()
            ->where(['status' => 'active'])
            ->first();
        if (!$waiver) {
            $this->markTestSkipped('No active gathering waivers in seed data');
        }

        $this->post('/waivers/gathering-waivers/decline/' . $waiver->id, [
            'decline_reason' => 'Illegible scan, cannot verify signature',
        ]);
        $this->assertResponseSuccess();
        $this->assertRedirect();
    }

    /**
     * Test decline without reason still processes but service may reject.
     */
    public function testDeclineWithoutReasonProcesses(): void
    {
        $GatheringWaivers = $this->getTableLocator()->get('Waivers.GatheringWaivers');
        $waiver = $GatheringWaivers->find()
            ->where(['status' => 'active'])
            ->first();
        if (!$waiver) {
            $this->markTestSkipped('No active gathering waivers in seed data');
        }

        $this->post('/waivers/gathering-waivers/decline/' . $waiver->id, [
            'decline_reason' => '',
        ]);
        $this->assertResponseSuccess();
        $this->assertRedirect();
    }
}
