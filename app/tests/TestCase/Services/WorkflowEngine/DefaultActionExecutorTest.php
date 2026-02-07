<?php

declare(strict_types=1);

namespace App\Test\TestCase\Services\WorkflowEngine;

use App\Services\ServiceResult;
use App\Services\WorkflowEngine\Actions\ActionInterface;
use App\Services\WorkflowEngine\DefaultActionExecutor;
use Cake\TestSuite\TestCase;

/**
 * Tests for DefaultActionExecutor action execution.
 *
 * Covers all built-in action types, chain behavior, and custom registration.
 */
class DefaultActionExecutorTest extends TestCase
{
    protected DefaultActionExecutor $executor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->executor = new DefaultActionExecutor();
    }

    // ==================================================
    // SET FIELD ACTION
    // ==================================================

    public function testSetFieldDeferredWithoutEntity(): void
    {
        $actions = [
            ['type' => 'set_field', 'field' => 'status', 'value' => 'approved'],
        ];
        $result = $this->executor->execute($actions, []);
        $this->assertTrue($result->success);
        $this->assertCount(1, $result->data);
        $this->assertTrue($result->data[0]['success']);
        $this->assertEquals('status', $result->data[0]['data']['field']);
        $this->assertEquals('approved', $result->data[0]['data']['value']);
        $this->assertTrue($result->data[0]['data']['deferred']);
    }

    public function testSetFieldMissingFieldFails(): void
    {
        $actions = [
            ['type' => 'set_field', 'value' => 'test'],
        ];
        $result = $this->executor->execute($actions, []);
        $this->assertFalse($result->success);
    }

    public function testSetFieldResolvesTemplateVariable(): void
    {
        $actions = [
            ['type' => 'set_field', 'field' => 'approved_by', 'value' => '{{user_id}}'],
        ];
        $context = ['user_id' => 42];
        $result = $this->executor->execute($actions, $context);
        $this->assertTrue($result->success);
        $this->assertEquals(42, $result->data[0]['data']['value']);
    }

    // ==================================================
    // SET CONTEXT ACTION
    // ==================================================

    public function testSetContextBasic(): void
    {
        $actions = [
            ['type' => 'set_context', 'key' => 'reviewed', 'value' => 'yes'],
        ];
        $result = $this->executor->execute($actions, []);
        $this->assertTrue($result->success);
        $this->assertEquals(['reviewed' => 'yes'], $result->data[0]['data']['context_updates']);
    }

    public function testSetContextIncrement(): void
    {
        $actions = [
            ['type' => 'set_context', 'key' => 'approval_count', 'value' => '{{increment}}'],
        ];
        $context = ['instance_context' => ['approval_count' => 2]];
        $result = $this->executor->execute($actions, $context);
        $this->assertTrue($result->success);
        $this->assertEquals(['approval_count' => 3], $result->data[0]['data']['context_updates']);
    }

    public function testSetContextIncrementFromZero(): void
    {
        $actions = [
            ['type' => 'set_context', 'key' => 'approval_count', 'value' => '{{increment}}'],
        ];
        $result = $this->executor->execute($actions, []);
        $this->assertTrue($result->success);
        $this->assertEquals(['approval_count' => 1], $result->data[0]['data']['context_updates']);
    }

    public function testSetContextNowResolvesToDateTime(): void
    {
        $actions = [
            ['type' => 'set_context', 'key' => 'reviewed_at', 'value' => '{{now}}'],
        ];
        $result = $this->executor->execute($actions, []);
        $this->assertTrue($result->success);
        // DateTime gets converted to ISO-8601 string
        $contextUpdates = $result->data[0]['data']['context_updates'];
        $this->assertArrayHasKey('reviewed_at', $contextUpdates);
        $this->assertIsString($contextUpdates['reviewed_at']);
        // Verify it's a valid date
        $parsed = \DateTime::createFromFormat(\DateTime::ATOM, $contextUpdates['reviewed_at']);
        $this->assertInstanceOf(\DateTime::class, $parsed);
    }

    public function testSetContextMissingKeyFails(): void
    {
        $actions = [
            ['type' => 'set_context', 'value' => 'test'],
        ];
        $result = $this->executor->execute($actions, []);
        $this->assertFalse($result->success);
    }

    // ==================================================
    // SEND EMAIL ACTION
    // ==================================================

    public function testSendEmailReturnsSuccess(): void
    {
        $actions = [
            [
                'type' => 'send_email',
                'mailer' => 'Awards.Awards',
                'method' => 'sendApproval',
                'to' => 'test@example.com',
            ],
        ];
        $result = $this->executor->execute($actions, []);
        $this->assertTrue($result->success);
        $this->assertEquals('Awards.Awards', $result->data[0]['data']['mailer']);
        $this->assertEquals('sendApproval', $result->data[0]['data']['method']);
        $this->assertEquals('test@example.com', $result->data[0]['data']['to']);
    }

    public function testSendEmailResolvesRecipientFromContext(): void
    {
        $actions = [
            [
                'type' => 'send_email',
                'mailer' => 'KMP',
                'method' => 'notify',
                'to' => '{{entity.email}}',
            ],
        ];
        $context = ['entity' => ['email' => 'member@test.com']];
        $result = $this->executor->execute($actions, $context);
        $this->assertTrue($result->success);
        $this->assertEquals('member@test.com', $result->data[0]['data']['to']);
    }

    public function testSendEmailResolvesVars(): void
    {
        $actions = [
            [
                'type' => 'send_email',
                'mailer' => 'KMP',
                'method' => 'notify',
                'to' => 'test@test.com',
                'vars' => [
                    'memberName' => '{{entity.name}}',
                    'status' => 'approved',
                ],
            ],
        ];
        $context = ['entity' => ['name' => 'Sir Test']];
        $result = $this->executor->execute($actions, $context);
        $this->assertTrue($result->success);
        $this->assertEquals('Sir Test', $result->data[0]['data']['vars']['memberName']);
        $this->assertEquals('approved', $result->data[0]['data']['vars']['status']);
    }

    public function testSendEmailMissingMailerFails(): void
    {
        $actions = [
            ['type' => 'send_email', 'method' => 'sendApproval'],
        ];
        $result = $this->executor->execute($actions, []);
        $this->assertFalse($result->success);
    }

    public function testSendEmailMissingMethodFails(): void
    {
        $actions = [
            ['type' => 'send_email', 'mailer' => 'Awards.Awards'],
        ];
        $result = $this->executor->execute($actions, []);
        $this->assertFalse($result->success);
    }

    // ==================================================
    // WEBHOOK ACTION
    // ==================================================

    public function testWebhookMissingUrlFails(): void
    {
        $actions = [
            ['type' => 'webhook', 'method' => 'POST'],
        ];
        $result = $this->executor->execute($actions, []);
        $this->assertFalse($result->success);
    }

    // ==================================================
    // ACTION CHAIN BEHAVIOR
    // ==================================================

    public function testMultipleActionsAllSucceed(): void
    {
        $actions = [
            ['type' => 'set_field', 'field' => 'status', 'value' => 'approved'],
            ['type' => 'set_context', 'key' => 'approved', 'value' => 'true'],
        ];
        $result = $this->executor->execute($actions, []);
        $this->assertTrue($result->success);
        $this->assertCount(2, $result->data);
        $this->assertTrue($result->data[0]['success']);
        $this->assertTrue($result->data[1]['success']);
    }

    public function testNonOptionalFailureStopsChain(): void
    {
        $actions = [
            ['type' => 'set_field'], // Missing field — will fail
            ['type' => 'set_context', 'key' => 'never_reached', 'value' => 'yes'],
        ];
        $result = $this->executor->execute($actions, []);
        $this->assertFalse($result->success);
        // Only the failed action is in results (chain stopped)
        $this->assertCount(1, $result->data);
    }

    public function testOptionalFailureContinuesChain(): void
    {
        $actions = [
            ['type' => 'set_field', 'optional' => true], // Will fail but is optional
            ['type' => 'set_context', 'key' => 'still_reached', 'value' => 'yes'],
        ];
        $result = $this->executor->execute($actions, []);
        $this->assertTrue($result->success);
        $this->assertCount(2, $result->data);
        $this->assertFalse($result->data[0]['success']);
        $this->assertTrue($result->data[1]['success']);
    }

    public function testEmptyActionsReturnsSuccess(): void
    {
        $result = $this->executor->execute([], []);
        $this->assertTrue($result->success);
        $this->assertEmpty($result->data);
    }

    // ==================================================
    // UNKNOWN / MISSING ACTION TYPE
    // ==================================================

    public function testMissingTypeSkipsAction(): void
    {
        // Missing type continues (doesn't stop chain)
        $actions = [
            ['field' => 'status', 'value' => 'test'],
            ['type' => 'set_context', 'key' => 'ok', 'value' => 'yes'],
        ];
        $result = $this->executor->execute($actions, []);
        $this->assertTrue($result->success);
        $this->assertCount(2, $result->data);
        $this->assertFalse($result->data[0]['success']);
        $this->assertEquals('No action type specified', $result->data[0]['reason']);
    }

    public function testUnknownTypeSkipsAction(): void
    {
        $actions = [
            ['type' => 'nonexistent_action'],
            ['type' => 'set_context', 'key' => 'ok', 'value' => 'yes'],
        ];
        $result = $this->executor->execute($actions, []);
        $this->assertTrue($result->success);
        $this->assertCount(2, $result->data);
        $this->assertFalse($result->data[0]['success']);
        $this->assertStringContainsString('Unknown action type', $result->data[0]['reason']);
    }

    // ==================================================
    // CUSTOM ACTION REGISTRATION
    // ==================================================

    public function testRegisterCustomAction(): void
    {
        $customAction = new class implements ActionInterface {
            public function execute(array $params, array $context): ServiceResult
            {
                return new ServiceResult(true, null, ['custom' => true]);
            }

            public function getName(): string
            {
                return 'custom_action';
            }

            public function getDescription(): string
            {
                return 'Custom test action';
            }

            public function getParameterSchema(): array
            {
                return [];
            }
        };

        $this->executor->registerActionType('custom_action', $customAction);
        $this->assertContains('custom_action', $this->executor->getRegisteredActionTypes());

        $result = $this->executor->execute([['type' => 'custom_action']], []);
        $this->assertTrue($result->success);
        $this->assertTrue($result->data[0]['data']['custom']);
    }

    public function testGetRegisteredActionTypesIncludesBuiltIns(): void
    {
        $types = $this->executor->getRegisteredActionTypes();
        $this->assertContains('set_field', $types);
        $this->assertContains('send_email', $types);
        $this->assertContains('set_context', $types);
        $this->assertContains('webhook', $types);
    }

    // ==================================================
    // TEMPLATE VARIABLE RESOLUTION
    // ==================================================

    public function testSetFieldWithDotNotationTemplate(): void
    {
        $actions = [
            ['type' => 'set_field', 'field' => 'assigned_to', 'value' => '{{entity.owner.name}}'],
        ];
        $context = ['entity' => ['owner' => ['name' => 'Lady Test']]];
        $result = $this->executor->execute($actions, $context);
        $this->assertTrue($result->success);
        $this->assertEquals('Lady Test', $result->data[0]['data']['value']);
    }

    public function testSetFieldWithNonStringValuePassesThrough(): void
    {
        $actions = [
            ['type' => 'set_field', 'field' => 'count', 'value' => 42],
        ];
        $result = $this->executor->execute($actions, []);
        $this->assertTrue($result->success);
        $this->assertEquals(42, $result->data[0]['data']['value']);
    }
}
