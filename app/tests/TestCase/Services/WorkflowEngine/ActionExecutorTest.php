<?php
declare(strict_types=1);

namespace App\Test\TestCase\Services\WorkflowEngine;

use App\Services\WorkflowEngine\ActionExecutor;
use App\Services\WorkflowEngine\Actions\ActionInterface;
use App\Services\WorkflowEngine\Actions\RequestApprovalAction;
use App\Services\WorkflowEngine\Actions\SendEmailAction;
use App\Services\WorkflowEngine\Actions\SetContextAction;
use App\Services\WorkflowEngine\Actions\SetFieldAction;
use App\Services\WorkflowEngine\Actions\WebhookAction;
use App\Services\ServiceResult;
use Cake\TestSuite\TestCase;

class ActionExecutorTest extends TestCase
{
    private ActionExecutor $executor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->executor = new ActionExecutor();
    }

    // ── SetFieldAction ──────────────────────────────────────────

    public function testSetFieldSetsFieldOnSubject(): void
    {
        $subject = new \stdClass();
        $subject->status = 'pending';

        $action = new SetFieldAction();
        $result = $action->execute(
            ['field' => 'status', 'value' => 'active'],
            ['subject' => $subject]
        );

        $this->assertTrue($result->isSuccess());
        $this->assertSame('active', $subject->status);
        $this->assertSame('status', $result->getData()['field']);
        $this->assertSame('active', $result->getData()['value']);
    }

    public function testSetFieldMissingFieldParamReturnsError(): void
    {
        $action = new SetFieldAction();
        $result = $action->execute(['value' => 'something'], []);

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('field', $result->getError());
    }

    // ── SetContextAction ────────────────────────────────────────

    public function testSetContextSetsKeyInWorkflowContext(): void
    {
        $action = new SetContextAction();
        $result = $action->execute(
            ['key' => 'approved_by', 'value' => 'admin'],
            ['workflow_context' => []]
        );

        $this->assertTrue($result->isSuccess());
        $this->assertSame('admin', $result->getData()['value']);
        $this->assertSame('admin', $result->getData()['workflow_context']['approved_by']);
    }

    public function testSetContextResolvesContextReferences(): void
    {
        $action = new SetContextAction();
        $result = $action->execute(
            ['key' => 'approved_by', 'value' => 'context.triggered_by'],
            ['triggered_by' => 'user_42', 'workflow_context' => []]
        );

        $this->assertTrue($result->isSuccess());
        $this->assertSame('user_42', $result->getData()['value']);
        $this->assertSame('user_42', $result->getData()['workflow_context']['approved_by']);
    }

    public function testSetContextMissingKeyParamReturnsError(): void
    {
        $action = new SetContextAction();
        $result = $action->execute(['value' => 'something'], []);

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('key', $result->getError());
    }

    // ── SendEmailAction ─────────────────────────────────────────

    public function testSendEmailLogsIntentWithoutThrowing(): void
    {
        $action = new SendEmailAction();
        $result = $action->execute(
            ['to' => 'test@example.com', 'template' => 'warrant_issued', 'plugin' => 'Officers'],
            []
        );

        $this->assertTrue($result->isSuccess());
        $this->assertSame('warrant_issued', $result->getData()['template']);
        $this->assertSame('Officers', $result->getData()['plugin']);
    }

    public function testSendEmailMissingTemplateReturnsError(): void
    {
        $action = new SendEmailAction();
        $result = $action->execute(['to' => 'test@example.com'], []);

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('template', $result->getError());
    }

    // ── WebhookAction ───────────────────────────────────────────

    public function testWebhookLogsIntentWithoutThrowing(): void
    {
        $action = new WebhookAction();
        $result = $action->execute(
            ['url' => 'https://example.com/callback', 'method' => 'POST'],
            []
        );

        $this->assertTrue($result->isSuccess());
        $this->assertSame('https://example.com/callback', $result->getData()['url']);
        $this->assertSame('POST', $result->getData()['method']);
    }

    public function testWebhookMissingUrlReturnsError(): void
    {
        $action = new WebhookAction();
        $result = $action->execute(['method' => 'POST'], []);

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('url', $result->getError());
    }

    // ── RequestApprovalAction ───────────────────────────────────

    public function testRequestApprovalLogsStubRequest(): void
    {
        $action = new RequestApprovalAction();
        $result = $action->execute(
            ['gate_id' => 5, 'notify' => true],
            []
        );

        $this->assertTrue($result->isSuccess());
        $this->assertSame(5, $result->getData()['gate_id']);
        $this->assertTrue($result->getData()['notify']);
    }

    public function testRequestApprovalMissingGateIdReturnsError(): void
    {
        $action = new RequestApprovalAction();
        $result = $action->execute(['notify' => true], []);

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('gate_id', $result->getError());
    }

    // ── ActionExecutor ──────────────────────────────────────────

    public function testExecuteAllRunsAllActionsInOrder(): void
    {
        $subject = new \stdClass();
        $subject->status = 'pending';
        $subject->close_reason = null;

        $actions = [
            ['action' => 'set_field', 'params' => ['field' => 'status', 'value' => 'active']],
            ['action' => 'set_field', 'params' => ['field' => 'close_reason', 'value' => 'Given']],
        ];

        $results = $this->executor->executeAll($actions, ['subject' => $subject]);

        $this->assertCount(2, $results);
        $this->assertTrue($results[0]->isSuccess());
        $this->assertTrue($results[1]->isSuccess());
        $this->assertSame('active', $subject->status);
        $this->assertSame('Given', $subject->close_reason);
    }

    public function testExecuteAllUnknownActionTypeLoggedAndSkipped(): void
    {
        $results = $this->executor->executeAll(
            [['action' => 'nonexistent_action', 'params' => []]],
            []
        );

        $this->assertCount(1, $results);
        $this->assertFalse($results[0]->isSuccess());
        $this->assertStringContainsString('Unknown action type', $results[0]->getError());
    }

    public function testExecuteAllExceptionInActionCaughtAndReported(): void
    {
        // Register a deliberately broken action
        $brokenAction = new class implements ActionInterface {
            public function execute(array $params, array $context): ServiceResult
            {
                throw new \RuntimeException('Deliberate test failure');
            }

            public function getName(): string
            {
                return 'broken';
            }

            public function getDescription(): string
            {
                return 'Broken test action';
            }

            public function getParameterSchema(): array
            {
                return [];
            }
        };

        $this->executor->registerActionType('broken', $brokenAction);

        $results = $this->executor->executeAll(
            [['action' => 'broken', 'params' => []]],
            []
        );

        $this->assertCount(1, $results);
        $this->assertFalse($results[0]->isSuccess());
        $this->assertStringContainsString('failed', $results[0]->getError());
    }

    public function testExecuteAllEmptyActionsReturnsEmptyResults(): void
    {
        $results = $this->executor->executeAll([], []);

        $this->assertIsArray($results);
        $this->assertCount(0, $results);
    }

    public function testPluginRegistrationOfCustomAction(): void
    {
        $customAction = new class implements ActionInterface {
            public function execute(array $params, array $context): ServiceResult
            {
                return new ServiceResult(true, 'Custom action executed', $params);
            }

            public function getName(): string
            {
                return 'custom_notify';
            }

            public function getDescription(): string
            {
                return 'Custom notification action';
            }

            public function getParameterSchema(): array
            {
                return ['message' => ['type' => 'string', 'required' => true]];
            }
        };

        $this->executor->registerActionType('custom_notify', $customAction);

        $this->assertContains('custom_notify', $this->executor->getRegisteredTypes());

        $results = $this->executor->executeAll(
            [['action' => 'custom_notify', 'params' => ['message' => 'Hello']]],
            []
        );

        $this->assertCount(1, $results);
        $this->assertTrue($results[0]->isSuccess());
        $this->assertSame('Hello', $results[0]->getData()['message']);
    }

    public function testMultipleActionsInSequenceAllExecuted(): void
    {
        $subject = new \stdClass();
        $subject->status = 'pending';

        $actions = [
            ['action' => 'set_field', 'params' => ['field' => 'status', 'value' => 'approved']],
            ['action' => 'set_context', 'params' => ['key' => 'reviewer', 'value' => 'admin']],
            ['action' => 'send_email', 'params' => ['template' => 'approved', 'to' => 'user@example.com']],
            ['action' => 'webhook', 'params' => ['url' => 'https://example.com/hook']],
            ['action' => 'request_approval', 'params' => ['gate_id' => 1]],
        ];

        $results = $this->executor->executeAll($actions, ['subject' => $subject]);

        $this->assertCount(5, $results);
        foreach ($results as $result) {
            $this->assertTrue($result->isSuccess(), "Failed: " . ($result->getError() ?? 'unknown'));
        }
        $this->assertSame('approved', $subject->status);
    }

    public function testBuiltInActionTypesRegistered(): void
    {
        $types = $this->executor->getRegisteredTypes();

        $this->assertContains('set_field', $types);
        $this->assertContains('set_context', $types);
        $this->assertContains('send_email', $types);
        $this->assertContains('webhook', $types);
        $this->assertContains('request_approval', $types);
    }

    public function testExecuteAllWithNullActionType(): void
    {
        $results = $this->executor->executeAll(
            [['params' => ['field' => 'status']]],
            []
        );

        $this->assertCount(1, $results);
        $this->assertFalse($results[0]->isSuccess());
        $this->assertStringContainsString('Unknown action type', $results[0]->getError());
    }
}
