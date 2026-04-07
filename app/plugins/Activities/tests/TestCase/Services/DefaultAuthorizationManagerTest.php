<?php

declare(strict_types=1);

namespace Activities\Test\TestCase\Services;

use Activities\Model\Entity\Authorization;
use App\Test\TestCase\BaseTestCase;
use Cake\I18n\DateTime;
use Cake\ORM\TableRegistry;
use App\Services\ActiveWindowManager\DefaultActiveWindowManager;

/**
 * Activities\Services\DefaultAuthorizationManager Test Case
 *
 * Tests the DefaultAuthorizationManager service including request,
 * activate, revoke, and retract workflows for activity authorizations.
 * Approval and denial are handled by the unified workflow engine.
 *
 * @uses \Activities\Services\DefaultAuthorizationManager
 */
class DefaultAuthorizationManagerTest extends BaseTestCase
{
    /**
     * Service under test (mock with mailer stubbed)
     *
     * @var \Activities\Services\DefaultAuthorizationManager|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $authManager;

    /**
     * Authorizations table
     *
     * @var \Activities\Model\Table\AuthorizationsTable
     */
    protected $Authorizations;

    /**
     * Activities table
     *
     * @var \Activities\Model\Table\ActivitiesTable
     */
    protected $Activities;

    /**
     * Members table
     *
     * @var \App\Model\Table\MembersTable
     */
    protected $Members;

    /**
     * Test activity ID from seed data (Armored, id=1)
     */
    private const TEST_ACTIVITY_ID = 1;

    /**
     * Test activity that requires 1 approver
     */
    private const TEST_SINGLE_APPROVAL_ACTIVITY_ID = 1;

    /**
     * setUp method
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->skipIfPostgres();

        $this->Authorizations = TableRegistry::getTableLocator()->get('Activities.Authorizations');
        $this->Activities = TableRegistry::getTableLocator()->get('Activities.Activities');
        $this->Members = TableRegistry::getTableLocator()->get('Members');

        $activeWindowManager = new DefaultActiveWindowManager();
        $triggerDispatcher = $this->createMock(\App\Services\WorkflowEngine\TriggerDispatcher::class);

        // Create partial mock to stub out mailer (avoids email transport issues in tests)
        $this->authManager = $this->getMockBuilder(\Activities\Services\DefaultAuthorizationManager::class)
            ->setConstructorArgs([$activeWindowManager, $triggerDispatcher])
            ->onlyMethods(['getMailer'])
            ->getMock();

        // Stub getMailer to return a mock mailer that does nothing on send()
        $mockMailer = $this->createMock(\Cake\Mailer\Mailer::class);
        $mockMailer->method('send')->willReturn([]);
        $this->authManager->method('getMailer')->willReturn($mockMailer);
    }

    /**
     * tearDown method
     *
     * @return void
     */
    protected function tearDown(): void
    {
        unset($this->authManager);
        unset($this->Authorizations);
        unset($this->Activities);
        unset($this->Members);

        parent::tearDown();
    }

    // =========================================================================
    // Helper methods
    // =========================================================================

    /**
     * Find a member ID that has no pending authorization for the given activity
     *
     * @param int $activityId
     * @return int
     */
    private function findMemberWithoutPending(int $activityId): int
    {
        // Use Agatha — check if she has a pending request for this activity
        $pending = $this->Authorizations->find()
            ->where([
                'member_id' => self::TEST_MEMBER_AGATHA_ID,
                'activity_id' => $activityId,
                'status' => Authorization::PENDING_STATUS,
            ])
            ->count();

        if ($pending === 0) {
            return self::TEST_MEMBER_AGATHA_ID;
        }

        // Try Bryce
        $pending = $this->Authorizations->find()
            ->where([
                'member_id' => self::TEST_MEMBER_BRYCE_ID,
                'activity_id' => $activityId,
                'status' => Authorization::PENDING_STATUS,
            ])
            ->count();

        if ($pending === 0) {
            return self::TEST_MEMBER_BRYCE_ID;
        }

        $this->fail('Could not find a member without a pending authorization for activity ' . $activityId);

        return 0;
    }

    // =========================================================================
    // Tests for request()
    // =========================================================================

    /**
     * Test creating a new authorization request succeeds
     */
    public function testRequestNewAuthorizationSuccess(): void
    {
        $activity = $this->Activities->find()
            ->orderBy(['id' => 'DESC'])
            ->first();
        $this->assertNotNull($activity, 'Need at least one activity in seed data');

        $requesterId = $this->findMemberWithoutPending($activity->id);

        $result = $this->authManager->request(
            $requesterId,
            $activity->id,
            self::ADMIN_MEMBER_ID,
            false,
        );

        $this->assertTrue($result->success, 'New authorization request should succeed');

        // Verify authorization record was created
        $auth = $this->Authorizations->find()
            ->where([
                'member_id' => $requesterId,
                'activity_id' => $activity->id,
                'status' => Authorization::PENDING_STATUS,
            ])
            ->orderBy(['id' => 'DESC'])
            ->first();
        $this->assertNotNull($auth, 'Authorization record should exist');
        $this->assertEquals(Authorization::PENDING_STATUS, $auth->status);
        $this->assertFalse((bool)$auth->is_renewal);
    }

    /**
     * Test duplicate pending request is rejected
     */
    public function testRequestDuplicatePendingFails(): void
    {
        $activity = $this->Activities->find()->orderBy(['id' => 'DESC'])->first();
        $requesterId = $this->findMemberWithoutPending($activity->id);

        // First request
        $result1 = $this->authManager->request(
            $requesterId,
            $activity->id,
            self::ADMIN_MEMBER_ID,
            false,
        );
        $this->assertTrue($result1->success, 'First request should succeed');

        // Duplicate request
        $result2 = $this->authManager->request(
            $requesterId,
            $activity->id,
            self::ADMIN_MEMBER_ID,
            false,
        );

        $this->assertFalse($result2->success, 'Duplicate should be rejected');
        $this->assertStringContainsString('already a pending request', $result2->reason);
    }

    /**
     * Test renewal request without existing approved authorization fails
     */
    public function testRequestRenewalWithoutExistingApprovedFails(): void
    {
        $activity = $this->Activities->find()->orderBy(['id' => 'DESC'])->first();
        $requesterId = $this->findMemberWithoutPending($activity->id);

        $approvedCount = $this->Authorizations->find()
            ->where([
                'member_id' => $requesterId,
                'activity_id' => $activity->id,
                'status' => Authorization::APPROVED_STATUS,
                'expires_on >' => DateTime::now(),
            ])
            ->count();

        if ($approvedCount > 0) {
            $this->markTestSkipped('Member already has approved auth for this activity');
        }

        $result = $this->authManager->request(
            $requesterId,
            $activity->id,
            self::ADMIN_MEMBER_ID,
            true,
        );

        $this->assertFalse($result->success, 'Renewal without existing auth should fail');
        $this->assertStringContainsString('no existing authorization', $result->reason);
    }

    // =========================================================================
    // Tests for revoke()
    // =========================================================================

    /**
     * Test revoking an approved authorization
     */
    public function testRevokeApprovedAuthorizationSuccess(): void
    {
        $approvedAuth = $this->Authorizations->find()
            ->where([
                'status' => Authorization::APPROVED_STATUS,
                'expires_on >' => DateTime::now(),
            ])
            ->first();

        if (!$approvedAuth) {
            $this->markTestSkipped('No approved authorization in seed data to revoke');
        }

        $revokedReason = 'Safety policy violation';
        $result = $this->authManager->revoke(
            $approvedAuth->id,
            self::ADMIN_MEMBER_ID,
            $revokedReason,
        );

        $this->assertTrue($result->success, 'Revocation should succeed');

        $updatedAuth = $this->Authorizations->get($approvedAuth->id);
        $this->assertEquals(Authorization::REVOKED_STATUS, $updatedAuth->status);
    }

    // =========================================================================
    // Tests for retract()
    // =========================================================================

    /**
     * Test retracting a pending authorization by the requester
     */
    public function testRetractPendingAuthorizationSuccess(): void
    {
        $activity = $this->Activities->find()->where(['id' => 3])->first();
        $this->assertNotNull($activity);

        $requesterId = $this->findMemberWithoutPending($activity->id);

        // Create request
        $result = $this->authManager->request(
            $requesterId,
            $activity->id,
            self::ADMIN_MEMBER_ID,
            false,
        );
        $this->assertTrue($result->success, 'Test setup: request should succeed');

        $auth = $this->Authorizations->find()
            ->where([
                'member_id' => $requesterId,
                'activity_id' => $activity->id,
                'status' => Authorization::PENDING_STATUS,
            ])
            ->orderBy(['id' => 'DESC'])
            ->first();
        $this->assertNotNull($auth, 'Test setup: authorization should exist');

        // Retract
        $retractResult = $this->authManager->retract(
            $auth->id,
            $requesterId,
        );

        $this->assertTrue($retractResult->success, 'Retraction should succeed');
        $this->assertArrayHasKey('authorization', $retractResult->data, 'Result data should contain authorization');

        $updatedAuth = $this->Authorizations->get($auth->id);
        $this->assertEquals(Authorization::RETRACTED_STATUS, $updatedAuth->status);
    }

    /**
     * Test retracting a non-pending authorization fails
     */
    public function testRetractNonPendingFails(): void
    {
        $approvedAuth = $this->Authorizations->find()
            ->where(['status' => Authorization::APPROVED_STATUS])
            ->first();

        if (!$approvedAuth) {
            $this->markTestSkipped('No approved authorization in seed data');
        }

        $result = $this->authManager->retract(
            $approvedAuth->id,
            $approvedAuth->member_id,
        );

        $this->assertFalse($result->success, 'Should fail for non-pending authorization');
        $this->assertStringContainsString('pending', $result->reason);
    }

    /**
     * Test retracting by a different member fails
     */
    public function testRetractByDifferentMemberFails(): void
    {
        $activity = $this->Activities->find()->where(['id' => 5])->first();
        $this->assertNotNull($activity);

        $requesterId = $this->findMemberWithoutPending($activity->id);

        // Create request
        $result = $this->authManager->request(
            $requesterId,
            $activity->id,
            self::ADMIN_MEMBER_ID,
            false,
        );
        $this->assertTrue($result->success, 'Test setup: request should succeed');

        $auth = $this->Authorizations->find()
            ->where([
                'member_id' => $requesterId,
                'activity_id' => $activity->id,
                'status' => Authorization::PENDING_STATUS,
            ])
            ->orderBy(['id' => 'DESC'])
            ->first();
        $this->assertNotNull($auth, 'Test setup: authorization should exist');

        // Try to retract as a different member
        $differentMemberId = ($requesterId === self::TEST_MEMBER_AGATHA_ID)
            ? self::TEST_MEMBER_BRYCE_ID
            : self::TEST_MEMBER_AGATHA_ID;

        $retractResult = $this->authManager->retract(
            $auth->id,
            $differentMemberId,
        );

        $this->assertFalse($retractResult->success, 'Retraction by non-owner should fail');
        $this->assertStringContainsString('your own', $retractResult->reason);
    }

    /**
     * Test retracting a non-existent authorization fails
     */
    public function testRetractNonExistentFails(): void
    {
        $result = $this->authManager->retract(999999, self::ADMIN_MEMBER_ID);

        $this->assertFalse($result->success, 'Should fail for non-existent authorization');
        $this->assertStringContainsString('not found', $result->reason);
    }

    // =========================================================================
    // Tests for full request workflow
    // =========================================================================

    /**
     * Test complete workflow: request creates pending authorization
     */
    public function testFullRequestCreatesAuthorizationWorkflow(): void
    {
        $activity = $this->Activities->find()
            ->where(['num_required_authorizors' => 1])
            ->first();
        $this->assertNotNull($activity);

        $requesterId = $this->findMemberWithoutPending($activity->id);

        $requestResult = $this->authManager->request(
            $requesterId,
            $activity->id,
            self::ADMIN_MEMBER_ID,
            false,
        );
        $this->assertTrue($requestResult->success, 'Request should succeed');

        $auth = $this->Authorizations->find()
            ->where([
                'member_id' => $requesterId,
                'activity_id' => $activity->id,
                'status' => Authorization::PENDING_STATUS,
            ])
            ->orderBy(['id' => 'DESC'])
            ->first();
        $this->assertNotNull($auth);
        $this->assertEquals(Authorization::PENDING_STATUS, $auth->status);
    }
}
