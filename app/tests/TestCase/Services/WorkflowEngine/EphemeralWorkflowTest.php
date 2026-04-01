<?php

declare(strict_types=1);

namespace App\Test\TestCase\Services\WorkflowEngine;

use App\Services\WorkflowEngine\DefaultWorkflowEngine;
use App\Services\WorkflowRegistry\WorkflowActionRegistry;
use App\Test\TestCase\BaseTestCase;
use Cake\Core\ContainerInterface;
use Cake\ORM\TableRegistry;

/**
 * Dummy action service for ephemeral tests.
 */
class EphemeralTestAction
{
    public static array $calls = [];

    public function execute(array $context, array $config): array
    {
        self::$calls[] = ['context' => $context, 'config' => $config];

        return ['executed' => true];
    }

    public static function reset(): void
    {
        self::$calls = [];
    }
}

/**
 * Tests for ephemeral (in-memory, no persistence) workflow execution.
 */
class EphemeralWorkflowTest extends BaseTestCase
{
    private DefaultWorkflowEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        $tracker = new EphemeralTestAction();
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturnCallback(function (string $id) {
            return $id === EphemeralTestAction::class;
        });
        $container->method('get')->willReturnCallback(function (string $id) use ($tracker) {
            if ($id === EphemeralTestAction::class) {
                return $tracker;
            }
            throw new \RuntimeException("Service '{$id}' not registered.");
        });

        $this->engine = new DefaultWorkflowEngine($container);

        EphemeralTestAction::reset();

        WorkflowActionRegistry::register('EphemeralTest', [
            [
                'action' => 'EphemeralTest.Run',
                'label' => 'Test Action',
                'description' => 'Test action for ephemeral workflows',
                'inputSchema' => [],
                'outputSchema' => [],
                'serviceClass' => EphemeralTestAction::class,
                'serviceMethod' => 'execute',
            ],
        ]);
    }

    private function createEphemeralWorkflow(array $definition): string
    {
        $defTable = TableRegistry::getTableLocator()->get('WorkflowDefinitions');
        $verTable = TableRegistry::getTableLocator()->get('WorkflowVersions');

        $slug = 'ephemeral-test-' . uniqid();

        $def = $defTable->newEntity([
            'name' => 'Ephemeral Test',
            'slug' => $slug,
            'description' => 'Test ephemeral workflow',
            'trigger_type' => 'event',
            'is_active' => true,
            'execution_mode' => 'ephemeral',
        ]);
        $defTable->saveOrFail($def);

        $ver = $verTable->newEntity([
            'workflow_definition_id' => $def->id,
            'version_number' => 1,
            'status' => 'published',
            'definition' => $definition,
        ]);
        $verTable->saveOrFail($ver);

        $def->current_version_id = $ver->id;
        $defTable->saveOrFail($def);

        return $slug;
    }

    public function testEphemeralWorkflowCreatesNoInstances(): void
    {
        $slug = $this->createEphemeralWorkflow([
            'nodes' => [
                'trigger1' => ['type' => 'trigger', 'config' => [], 'outputs' => [['target' => 'action1', 'port' => 'default']]],
                'action1' => ['type' => 'action', 'config' => ['action' => 'EphemeralTest.Run'], 'outputs' => [['target' => 'end1', 'port' => 'default']]],
                'end1' => ['type' => 'end', 'config' => []],
            ],
        ]);

        $instancesTable = TableRegistry::getTableLocator()->get('WorkflowInstances');
        $countBefore = $instancesTable->find()->count();

        $result = $this->engine->startWorkflow($slug, ['test' => true]);

        $this->assertTrue($result->isSuccess());
        $this->assertNull($result->data['instanceId']);
        $this->assertTrue($result->data['ephemeral']);

        // No new instances created
        $countAfter = $instancesTable->find()->count();
        $this->assertEquals($countBefore, $countAfter, 'Ephemeral workflow should not create instances');
    }

    public function testEphemeralWorkflowCreatesNoLogs(): void
    {
        $slug = $this->createEphemeralWorkflow([
            'nodes' => [
                'trigger1' => ['type' => 'trigger', 'config' => [], 'outputs' => [['target' => 'action1', 'port' => 'default']]],
                'action1' => ['type' => 'action', 'config' => ['action' => 'EphemeralTest.Run'], 'outputs' => [['target' => 'end1', 'port' => 'default']]],
                'end1' => ['type' => 'end', 'config' => []],
            ],
        ]);

        $logsTable = TableRegistry::getTableLocator()->get('WorkflowExecutionLogs');
        $countBefore = $logsTable->find()->count();

        $result = $this->engine->startWorkflow($slug, ['test' => true]);
        $this->assertTrue($result->isSuccess());

        $countAfter = $logsTable->find()->count();
        $this->assertEquals($countBefore, $countAfter, 'Ephemeral workflow should not create execution logs');
    }

    public function testEphemeralWorkflowStillExecutesActions(): void
    {
        $slug = $this->createEphemeralWorkflow([
            'nodes' => [
                'trigger1' => ['type' => 'trigger', 'config' => [], 'outputs' => [['target' => 'action1', 'port' => 'default']]],
                'action1' => ['type' => 'action', 'config' => ['action' => 'EphemeralTest.Run'], 'outputs' => [['target' => 'end1', 'port' => 'default']]],
                'end1' => ['type' => 'end', 'config' => []],
            ],
        ]);

        $result = $this->engine->startWorkflow($slug, ['test' => true]);
        $this->assertTrue($result->isSuccess());

        // Action was executed
        $this->assertCount(1, EphemeralTestAction::$calls, 'Ephemeral workflow should still execute actions');
    }

    public function testEphemeralWorkflowRejectsApprovalNode(): void
    {
        $slug = $this->createEphemeralWorkflow([
            'nodes' => [
                'trigger1' => ['type' => 'trigger', 'config' => [], 'outputs' => [['target' => 'approval1', 'port' => 'default']]],
                'approval1' => ['type' => 'approval', 'config' => ['approverType' => 'permission', 'requiredCount' => 1]],
            ],
        ]);

        $result = $this->engine->startWorkflow($slug, ['test' => true]);
        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('ephemeral', strtolower($result->reason ?? ''));
    }

    public function testEphemeralWorkflowRejectsHumanTaskNode(): void
    {
        $slug = $this->createEphemeralWorkflow([
            'nodes' => [
                'trigger1' => ['type' => 'trigger', 'config' => [], 'outputs' => [['target' => 'task1', 'port' => 'default']]],
                'task1' => ['type' => 'humanTask', 'config' => ['taskTitle' => 'Test', 'formFields' => []]],
            ],
        ]);

        $result = $this->engine->startWorkflow($slug, ['test' => true]);
        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('ephemeral', strtolower($result->reason ?? ''));
    }

    public function testEphemeralWorkflowWithConditions(): void
    {
        $slug = $this->createEphemeralWorkflow([
            'nodes' => [
                'trigger1' => ['type' => 'trigger', 'config' => [], 'outputs' => [['target' => 'cond1', 'port' => 'default']]],
                'cond1' => [
                    'type' => 'condition',
                    'config' => [
                        'conditionType' => 'expression',
                        'expression' => '$.trigger.route == "yes"',
                    ],
                    'outputs' => [
                        ['target' => 'action1', 'port' => 'true'],
                        ['target' => 'end1', 'port' => 'false'],
                    ],
                ],
                'action1' => ['type' => 'action', 'config' => ['action' => 'EphemeralTest.Run'], 'outputs' => [['target' => 'end1', 'port' => 'default']]],
                'end1' => ['type' => 'end', 'config' => []],
            ],
        ]);

        // Route = "yes" → action executes
        $result = $this->engine->startWorkflow($slug, ['route' => 'yes']);
        $this->assertTrue($result->isSuccess());
        $this->assertCount(1, EphemeralTestAction::$calls);
    }

    public function testDurableWorkflowStillCreatesInstances(): void
    {
        $defTable = TableRegistry::getTableLocator()->get('WorkflowDefinitions');
        $verTable = TableRegistry::getTableLocator()->get('WorkflowVersions');

        $slug = 'durable-test-' . uniqid();
        $def = $defTable->newEntity([
            'name' => 'Durable Test',
            'slug' => $slug,
            'description' => 'Test durable workflow',
            'trigger_type' => 'event',
            'is_active' => true,
            'execution_mode' => 'durable',
        ]);
        $defTable->saveOrFail($def);

        $ver = $verTable->newEntity([
            'workflow_definition_id' => $def->id,
            'version_number' => 1,
            'status' => 'published',
            'definition' => [
                'nodes' => [
                    'trigger1' => ['type' => 'trigger', 'config' => [], 'outputs' => [['target' => 'end1', 'port' => 'default']]],
                    'end1' => ['type' => 'end', 'config' => []],
                ],
            ],
        ]);
        $verTable->saveOrFail($ver);
        $def->current_version_id = $ver->id;
        $defTable->saveOrFail($def);

        $instancesTable = TableRegistry::getTableLocator()->get('WorkflowInstances');
        $countBefore = $instancesTable->find()->count();

        $result = $this->engine->startWorkflow($slug, ['test' => true]);
        $this->assertTrue($result->isSuccess());
        $this->assertNotNull($result->data['instanceId']);

        $countAfter = $instancesTable->find()->count();
        $this->assertEquals($countBefore + 1, $countAfter, 'Durable workflow should create instances');
    }
}
