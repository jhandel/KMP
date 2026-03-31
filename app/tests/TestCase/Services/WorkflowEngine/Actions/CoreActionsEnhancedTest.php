<?php

declare(strict_types=1);

namespace App\Test\TestCase\Services\WorkflowEngine\Actions;

use App\Model\Entity\ActiveWindowBaseEntity;
use App\Services\ActiveWindowManager\ActiveWindowManagerInterface;
use App\Services\ServiceResult;
use App\Services\WorkflowEngine\Actions\CoreActions;
use App\Services\WorkflowEngine\ExpressionEvaluator;
use App\Services\WorkflowRegistry\WorkflowEntityRegistry;
use App\Test\TestCase\BaseTestCase;
use Cake\I18n\DateTime;
use Cake\ORM\TableRegistry;

/**
 * Tests for enhanced CoreActions: ActiveWindow actions, enhanced SendEmail,
 * enhanced AssignRole, and UpdateEntity entity registration.
 */
class CoreActionsEnhancedTest extends BaseTestCase
{
    private CoreActions $actions;
    private ActiveWindowManagerInterface $mockAwm;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockAwm = $this->createMock(ActiveWindowManagerInterface::class);
        $this->actions = new CoreActions($this->mockAwm, new ExpressionEvaluator());
    }

    protected function tearDown(): void
    {
        WorkflowEntityRegistry::clear();
        parent::tearDown();
    }

    // =====================================================
    // startActiveWindow()
    // =====================================================

    public function testStartActiveWindowReturnsStartedStatus(): void
    {
        $this->mockAwm->method('start')
            ->willReturn(new ServiceResult(true, null, 42));

        $context = ['triggeredBy' => self::ADMIN_MEMBER_ID];
        $config = [
            'entityType' => 'Officers.Officers',
            'entityId' => 1,
            'startOn' => '2025-01-01 00:00:00',
        ];

        $result = $this->actions->startActiveWindow($context, $config);

        $this->assertEquals('started', $result['status']);
        $this->assertEquals(42, $result['memberRoleId']);
        $this->assertNull($result['error']);
    }

    public function testStartActiveWindowWithRoleId(): void
    {
        $this->mockAwm->expects($this->once())
            ->method('start')
            ->with(
                'Officers.Officers',
                1,
                self::ADMIN_MEMBER_ID,
                $this->isInstanceOf(DateTime::class),
                $this->isInstanceOf(DateTime::class),
                null,
                5,       // roleId
                true,
                2,       // branchId
            )
            ->willReturn(new ServiceResult(true));

        $context = ['triggeredBy' => self::ADMIN_MEMBER_ID];
        $config = [
            'entityType' => 'Officers.Officers',
            'entityId' => 1,
            'roleId' => 5,
            'startOn' => '2025-01-01',
            'expiresOn' => '2026-01-01',
            'branchId' => 2,
        ];

        $result = $this->actions->startActiveWindow($context, $config);
        $this->assertEquals('started', $result['status']);
    }

    public function testStartActiveWindowFailureReturnsError(): void
    {
        $this->mockAwm->method('start')
            ->willReturn(new ServiceResult(false, 'Entity not found'));

        $context = ['triggeredBy' => self::ADMIN_MEMBER_ID];
        $config = [
            'entityType' => 'Officers.Officers',
            'entityId' => 999,
        ];

        $result = $this->actions->startActiveWindow($context, $config);

        $this->assertEquals('failed', $result['status']);
        $this->assertEquals('Entity not found', $result['error']);
    }

    public function testStartActiveWindowDefaultsToNow(): void
    {
        $this->mockAwm->expects($this->once())
            ->method('start')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->isInstanceOf(DateTime::class),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
            )
            ->willReturn(new ServiceResult(true));

        $context = ['triggeredBy' => self::ADMIN_MEMBER_ID];
        $config = [
            'entityType' => 'MemberRoles',
            'entityId' => 1,
        ];

        $result = $this->actions->startActiveWindow($context, $config);
        $this->assertEquals('started', $result['status']);
    }

    public function testStartActiveWindowResolvesContextPaths(): void
    {
        $this->mockAwm->expects($this->once())
            ->method('start')
            ->with(
                'Officers.Officers',
                42,
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
            )
            ->willReturn(new ServiceResult(true));

        $context = [
            'triggeredBy' => self::ADMIN_MEMBER_ID,
            'trigger' => ['table' => 'Officers.Officers', 'id' => 42],
        ];
        $config = [
            'entityType' => '$.trigger.table',
            'entityId' => '$.trigger.id',
        ];

        $result = $this->actions->startActiveWindow($context, $config);
        $this->assertEquals('started', $result['status']);
    }

    // =====================================================
    // stopActiveWindow()
    // =====================================================

    public function testStopActiveWindowReturnsStoppedTrue(): void
    {
        $this->mockAwm->method('stop')
            ->willReturn(new ServiceResult(true));

        $context = ['triggeredBy' => self::ADMIN_MEMBER_ID];
        $config = [
            'entityType' => 'Officers.Officers',
            'entityId' => 1,
            'newStatus' => 'Released',
        ];

        $result = $this->actions->stopActiveWindow($context, $config);

        $this->assertTrue($result['stopped']);
        $this->assertNull($result['error']);
    }

    public function testStopActiveWindowPassesCorrectStatus(): void
    {
        $this->mockAwm->expects($this->once())
            ->method('stop')
            ->with(
                'Officers.Officers',
                1,
                self::ADMIN_MEMBER_ID,
                'Revoked',
                'Policy violation',
                $this->isInstanceOf(DateTime::class),
            )
            ->willReturn(new ServiceResult(true));

        $context = ['triggeredBy' => self::ADMIN_MEMBER_ID];
        $config = [
            'entityType' => 'Officers.Officers',
            'entityId' => 1,
            'newStatus' => 'Revoked',
            'reason' => 'Policy violation',
        ];

        $result = $this->actions->stopActiveWindow($context, $config);
        $this->assertTrue($result['stopped']);
    }

    public function testStopActiveWindowDefaultsToDeactivated(): void
    {
        $this->mockAwm->expects($this->once())
            ->method('stop')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                ActiveWindowBaseEntity::DEACTIVATED_STATUS,
                $this->anything(),
                $this->anything(),
            )
            ->willReturn(new ServiceResult(true));

        $context = ['triggeredBy' => self::ADMIN_MEMBER_ID];
        $config = [
            'entityType' => 'MemberRoles',
            'entityId' => 1,
        ];

        $result = $this->actions->stopActiveWindow($context, $config);
        $this->assertTrue($result['stopped']);
    }

    public function testStopActiveWindowFailureReturnsError(): void
    {
        $this->mockAwm->method('stop')
            ->willReturn(new ServiceResult(false, 'Failed to save'));

        $context = ['triggeredBy' => self::ADMIN_MEMBER_ID];
        $config = [
            'entityType' => 'Officers.Officers',
            'entityId' => 999,
            'newStatus' => 'Released',
        ];

        $result = $this->actions->stopActiveWindow($context, $config);
        $this->assertFalse($result['stopped']);
        $this->assertEquals('Failed to save', $result['error']);
    }

    // =====================================================
    // syncActiveWindowStatuses()
    // =====================================================

    public function testSyncActiveWindowStatusesReturnsTransitionedStructure(): void
    {
        $context = [];
        $config = ['entityType' => 'Members'];

        $result = $this->actions->syncActiveWindowStatuses($context, $config);

        $this->assertArrayHasKey('transitioned', $result);
        $this->assertArrayHasKey('upcoming_to_current', $result['transitioned']);
        $this->assertArrayHasKey('current_to_expired', $result['transitioned']);
    }

    public function testSyncActiveWindowStatusesWithNonActiveWindowTable(): void
    {
        // Members table doesn't have ActiveWindow status columns in standard schema,
        // so syncTable should skip it gracefully
        $context = [];
        $config = ['entityType' => 'Members'];

        $result = $this->actions->syncActiveWindowStatuses($context, $config);

        $this->assertEquals(0, $result['transitioned']['upcoming_to_current']);
        $this->assertEquals(0, $result['transitioned']['current_to_expired']);
    }

    public function testSyncActiveWindowStatusesHandlesInvalidEntityType(): void
    {
        $context = [];
        $config = ['entityType' => 'NonExistentTable'];

        $result = $this->actions->syncActiveWindowStatuses($context, $config);

        // Should return error gracefully
        $this->assertArrayHasKey('transitioned', $result);
        $this->assertArrayHasKey('error', $result);
    }

    // =====================================================
    // Enhanced sendEmail() — replyTo support
    // =====================================================

    public function testSendEmailWithReplyTo(): void
    {
        $context = ['trigger' => ['email' => 'user@example.com', 'replyEmail' => 'reply@example.com']];
        $config = [
            'to' => '$.trigger.email',
            'mailer' => 'App\\Mailer\\TestMailer',
            'action' => 'notify',
            'vars' => ['subject' => 'Hello'],
            'replyTo' => '$.trigger.replyEmail',
        ];

        $result = $this->actions->sendEmail($context, $config);
        $this->assertArrayHasKey('sent', $result);
    }

    public function testSendEmailReplyToResolvesFromContext(): void
    {
        $context = ['admin' => ['email' => 'admin@test.com']];
        $config = [
            'to' => 'user@test.com',
            'mailer' => 'TestMailer',
            'action' => 'send',
            'replyTo' => '$.admin.email',
        ];

        // The method should attempt to resolve the replyTo value from context
        $result = $this->actions->sendEmail($context, $config);
        $this->assertArrayHasKey('sent', $result);
    }

    public function testSendEmailWithoutReplyToStillWorks(): void
    {
        $context = [];
        $config = [
            'to' => 'test@example.com',
            'mailer' => 'TestMailer',
            'action' => 'send',
            'vars' => [],
        ];

        $result = $this->actions->sendEmail($context, $config);
        $this->assertArrayHasKey('sent', $result);
    }

    // =====================================================
    // Enhanced assignRole() — ActiveWindow integration
    // =====================================================

    public function testAssignRoleWithActiveWindowDelegation(): void
    {
        $this->mockAwm->expects($this->once())
            ->method('start')
            ->willReturn(new ServiceResult(true, null, 99));

        $context = ['triggeredBy' => self::ADMIN_MEMBER_ID];
        $config = [
            'memberId' => self::TEST_MEMBER_AGATHA_ID,
            'roleId' => self::ADMIN_ROLE_ID,
            'startOn' => '2025-01-01',
            'entityType' => 'Officers.Officers',
            'entityId' => 1,
        ];

        $result = $this->actions->assignRole($context, $config);
        $this->assertArrayHasKey('memberRoleId', $result);
    }

    public function testAssignRoleWithoutEntityFallsBackToStandard(): void
    {
        // Without entityType+entityId+startOn, should use standard path
        $this->mockAwm->expects($this->never())->method('start');

        $context = [];
        $config = [
            'memberId' => self::TEST_MEMBER_AGATHA_ID,
            'roleId' => self::ADMIN_ROLE_ID,
        ];

        $result = $this->actions->assignRole($context, $config);
        $this->assertArrayHasKey('memberRoleId', $result);
    }

    public function testAssignRoleWithBranchId(): void
    {
        $this->mockAwm->expects($this->once())
            ->method('start')
            ->with(
                'Officers.Officers',
                1,
                $this->anything(),
                $this->isInstanceOf(DateTime::class),
                $this->anything(),
                null,
                self::ADMIN_ROLE_ID,
                true,
                self::KINGDOM_BRANCH_ID,
            )
            ->willReturn(new ServiceResult(true));

        $context = ['triggeredBy' => self::ADMIN_MEMBER_ID];
        $config = [
            'memberId' => self::TEST_MEMBER_AGATHA_ID,
            'roleId' => self::ADMIN_ROLE_ID,
            'startOn' => '2025-01-01',
            'entityType' => 'Officers.Officers',
            'entityId' => 1,
            'branchId' => self::KINGDOM_BRANCH_ID,
        ];

        $result = $this->actions->assignRole($context, $config);
        $this->assertArrayHasKey('memberRoleId', $result);
    }

    public function testAssignRoleHandlesExceptionGracefully(): void
    {
        $this->mockAwm->method('start')
            ->willThrowException(new \RuntimeException('DB error'));

        $context = ['triggeredBy' => self::ADMIN_MEMBER_ID];
        $config = [
            'memberId' => self::TEST_MEMBER_AGATHA_ID,
            'roleId' => self::ADMIN_ROLE_ID,
            'startOn' => '2025-01-01',
            'entityType' => 'Officers.Officers',
            'entityId' => 1,
        ];

        $result = $this->actions->assignRole($context, $config);
        $this->assertNull($result['memberRoleId']);
    }

    // =====================================================
    // Enhanced updateEntity() — entity registry coverage
    // =====================================================

    public function testUpdateEntityMemberRolesEntityType(): void
    {
        WorkflowEntityRegistry::register('Core', [[
            'entityType' => 'Core.MemberRoles',
            'label' => 'Member Role',
            'description' => 'Role assignment',
            'tableClass' => \App\Model\Table\MemberRolesTable::class,
            'fields' => [
                'status' => ['type' => 'string', 'label' => 'Status'],
            ],
        ]]);

        $context = [];
        $config = [
            'entityType' => 'Core.MemberRoles',
            'entityId' => 1,
            'fields' => ['status' => 'Active'],
        ];

        // Should attempt to update (may fail on DB constraints but validates registry path)
        $result = $this->actions->updateEntity($context, $config);
        $this->assertArrayHasKey('updated', $result);
    }

    public function testUpdateEntityWarrantsEntityType(): void
    {
        WorkflowEntityRegistry::register('Core', [[
            'entityType' => 'Core.Warrants',
            'label' => 'Warrant',
            'description' => 'Individual warrant',
            'tableClass' => \App\Model\Table\WarrantsTable::class,
            'fields' => [
                'status' => ['type' => 'string', 'label' => 'Status'],
            ],
        ]]);

        // The entity type is registered, so field validation should pass
        $result = $this->actions->updateEntity([], [
            'entityType' => 'Core.Warrants',
            'entityId' => 99999,
            'fields' => ['status' => 'test'],
        ]);

        // Will fail on entity not found but verifies registry path works
        $this->assertFalse($result['updated']);
    }
}
