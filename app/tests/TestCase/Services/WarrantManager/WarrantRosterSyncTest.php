<?php

declare(strict_types=1);

namespace App\Test\TestCase\Services\WarrantManager;

use App\Model\Entity\Warrant;
use App\Model\Entity\WarrantRoster;
use App\Services\ServiceResult;
use App\Services\WarrantManager\DefaultWarrantManager;
use App\Services\WarrantManager\WarrantManagerInterface;
use App\Services\WorkflowEngine\Providers\WarrantWorkflowActions;
use App\Test\TestCase\BaseTestCase;
use Cake\I18n\DateTime;
use Cake\ORM\TableRegistry;

/**
 * Tests for warrant roster ↔ workflow approval sync.
 *
 * Covers syncWorkflowApprovalToRoster(), activateApprovedRoster(),
 * and the integration points in WarrantWorkflowActions.
 */
class WarrantRosterSyncTest extends BaseTestCase
{
    private $rosterTable;
    private $rosterApprovalsTable;
    private $warrantTable;
    private $approvalsTable;
    private $responsesTable;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rosterTable = TableRegistry::getTableLocator()->get('WarrantRosters');
        $this->rosterApprovalsTable = TableRegistry::getTableLocator()->get('WarrantRosterApprovals');
        $this->warrantTable = TableRegistry::getTableLocator()->get('Warrants');
        $this->approvalsTable = TableRegistry::getTableLocator()->get('WorkflowApprovals');
        $this->responsesTable = TableRegistry::getTableLocator()->get('WorkflowApprovalResponses');
    }

    /**
     * Build a DefaultWarrantManager with email sending stubbed out.
     */
    private function createWarrantManager(): WarrantManagerInterface
    {
        $awm = $this->createMock(\App\Services\ActiveWindowManager\ActiveWindowManagerInterface::class);
        $td = $this->createMock(\App\Services\WorkflowEngine\TriggerDispatcher::class);
        $am = $this->createMock(\App\Services\WorkflowEngine\WorkflowApprovalManagerInterface::class);
        $we = $this->createMock(\App\Services\WorkflowEngine\WorkflowEngineInterface::class);

        $wm = $this->getMockBuilder(DefaultWarrantManager::class)
            ->setConstructorArgs([$awm, $td, $am, $we])
            ->onlyMethods(['queueMail'])
            ->getMock();
        $wm->method('queueMail')->willReturnCallback(function () {});

        return $wm;
    }

    /**
     * Create a pending roster with a single pending warrant.
     *
     * @return array [rosterId, warrantId]
     */
    private function createPendingRoster(int $approvalsRequired = 1): array
    {
        $roster = $this->rosterTable->newEmptyEntity();
        $roster->name = 'Sync Test Roster ' . uniqid();
        $roster->description = 'Test';
        $roster->approvals_required = $approvalsRequired;
        $roster->approval_count = 0;
        $roster->status = WarrantRoster::STATUS_PENDING;
        $roster->created_on = new DateTime();
        $this->rosterTable->saveOrFail($roster);

        $warrant = $this->warrantTable->newEmptyEntity();
        $warrant->name = 'Test Warrant';
        $warrant->warrant_roster_id = $roster->id;
        $warrant->member_id = self::ADMIN_MEMBER_ID;
        $warrant->entity_type = 'Branches';
        $warrant->entity_id = self::KINGDOM_BRANCH_ID;
        $warrant->status = Warrant::PENDING_STATUS;
        $warrant->start_on = new DateTime();
        $warrant->expires_on = (new DateTime())->modify('+1 year');
        $this->warrantTable->saveOrFail($warrant);

        return [$roster->id, $warrant->id];
    }

    /**
     * Create workflow context objects: definition, version, instance, log, and approval gate.
     *
     * @return array [instanceId, approvalId]
     */
    private function createWorkflowApprovalContext(int $requiredCount = 1): array
    {
        $defTable = TableRegistry::getTableLocator()->get('WorkflowDefinitions');
        $def = $defTable->newEntity([
            'name' => 'WRS Test ' . uniqid(),
            'slug' => 'wrs-' . uniqid(),
            'trigger_type' => 'manual',
        ]);
        $defTable->saveOrFail($def);

        $versionsTable = TableRegistry::getTableLocator()->get('WorkflowVersions');
        $version = $versionsTable->newEntity([
            'workflow_definition_id' => $def->id,
            'version_number' => 1,
            'definition' => ['nodes' => [
                'trigger1' => ['type' => 'trigger', 'outputs' => [['target' => 'end1']]],
                'end1' => ['type' => 'end', 'outputs' => []],
            ]],
            'status' => 'published',
        ]);
        $versionsTable->saveOrFail($version);

        $instancesTable = TableRegistry::getTableLocator()->get('WorkflowInstances');
        $instance = $instancesTable->newEntity([
            'workflow_definition_id' => $def->id,
            'workflow_version_id' => $version->id,
            'status' => 'waiting',
        ]);
        $instancesTable->saveOrFail($instance);

        $logsTable = TableRegistry::getTableLocator()->get('WorkflowExecutionLogs');
        $log = $logsTable->newEntity([
            'workflow_instance_id' => $instance->id,
            'node_id' => 'approval_node',
            'node_type' => 'approval',
            'status' => 'waiting',
        ]);
        $logsTable->saveOrFail($log);

        $approval = $this->approvalsTable->newEntity([
            'workflow_instance_id' => $instance->id,
            'execution_log_id' => $log->id,
            'node_id' => 'approval_node',
            'approver_type' => 'member',
            'approver_config' => [],
            'required_count' => $requiredCount,
            'approved_count' => 0,
            'rejected_count' => 0,
            'status' => 'approved',
        ]);
        $this->approvalsTable->saveOrFail($approval);

        return [$instance->id, $approval->id];
    }

    /**
     * Add a workflow approval response.
     */
    private function addApprovalResponse(int $approvalId, int $memberId, string $decision = 'approve', ?string $comment = null): void
    {
        $response = $this->responsesTable->newEntity([
            'workflow_approval_id' => $approvalId,
            'member_id' => $memberId,
            'decision' => $decision,
            'comment' => $comment,
            'responded_at' => new DateTime(),
        ]);
        $this->responsesTable->saveOrFail($response);
    }

    // =====================================================
    // syncWorkflowApprovalToRoster()
    // =====================================================

    public function testSyncCreatesApprovalRecord(): void
    {
        $wm = $this->createWarrantManager();
        [$rosterId] = $this->createPendingRoster();

        $result = $wm->syncWorkflowApprovalToRoster($rosterId, self::ADMIN_MEMBER_ID);

        $this->assertTrue($result->isSuccess());

        $record = $this->rosterApprovalsTable->find()
            ->where(['warrant_roster_id' => $rosterId, 'approver_id' => self::ADMIN_MEMBER_ID])
            ->first();
        $this->assertNotNull($record, 'Approval record should be created');
    }

    public function testSyncIncrementsApprovalCount(): void
    {
        $wm = $this->createWarrantManager();
        [$rosterId] = $this->createPendingRoster(2);

        $wm->syncWorkflowApprovalToRoster($rosterId, self::ADMIN_MEMBER_ID);

        $roster = $this->rosterTable->get($rosterId);
        $this->assertEquals(1, $roster->approval_count);
    }

    public function testSyncDedupGuardSkipsDuplicate(): void
    {
        $wm = $this->createWarrantManager();
        [$rosterId] = $this->createPendingRoster(2);

        $wm->syncWorkflowApprovalToRoster($rosterId, self::ADMIN_MEMBER_ID);
        $wm->syncWorkflowApprovalToRoster($rosterId, self::ADMIN_MEMBER_ID);

        // Should still be 1 approval record
        $count = $this->rosterApprovalsTable->find()
            ->where(['warrant_roster_id' => $rosterId, 'approver_id' => self::ADMIN_MEMBER_ID])
            ->count();
        $this->assertEquals(1, $count);

        // approval_count should still be 1 (not incremented on duplicate)
        $roster = $this->rosterTable->get($rosterId);
        $this->assertEquals(1, $roster->approval_count);
    }

    public function testSyncReturnsTrueOnDuplicate(): void
    {
        $wm = $this->createWarrantManager();
        [$rosterId] = $this->createPendingRoster();

        $wm->syncWorkflowApprovalToRoster($rosterId, self::ADMIN_MEMBER_ID);
        $result = $wm->syncWorkflowApprovalToRoster($rosterId, self::ADMIN_MEMBER_ID);

        $this->assertTrue($result->isSuccess(), 'Duplicate sync should return success (idempotent)');
    }

    public function testSyncHandlesNullNotesAndApprovedOn(): void
    {
        $wm = $this->createWarrantManager();
        [$rosterId] = $this->createPendingRoster();

        $result = $wm->syncWorkflowApprovalToRoster($rosterId, self::ADMIN_MEMBER_ID, null, null);

        $this->assertTrue($result->isSuccess());

        $record = $this->rosterApprovalsTable->find()
            ->where(['warrant_roster_id' => $rosterId])
            ->first();
        $this->assertNotNull($record->approved_on, 'approved_on should default to now');
    }

    // =====================================================
    // activateApprovedRoster()
    // =====================================================

    public function testActivateApprovedRosterActivatesPendingWarrants(): void
    {
        $wm = $this->createWarrantManager();
        [$rosterId, $warrantId] = $this->createPendingRoster();

        // Set roster to approved
        $roster = $this->rosterTable->get($rosterId);
        $roster->status = WarrantRoster::STATUS_APPROVED;
        $roster->approval_count = 1;
        $this->rosterTable->saveOrFail($roster);

        $result = $wm->activateApprovedRoster($rosterId, self::ADMIN_MEMBER_ID);

        $this->assertTrue($result->isSuccess());

        $warrant = $this->warrantTable->get($warrantId);
        $this->assertEquals(Warrant::CURRENT_STATUS, $warrant->status);
    }

    public function testActivateApprovedRosterSetsApprovedDate(): void
    {
        $wm = $this->createWarrantManager();
        [$rosterId, $warrantId] = $this->createPendingRoster();

        $roster = $this->rosterTable->get($rosterId);
        $roster->status = WarrantRoster::STATUS_APPROVED;
        $this->rosterTable->saveOrFail($roster);

        $wm->activateApprovedRoster($rosterId, self::ADMIN_MEMBER_ID);

        $warrant = $this->warrantTable->get($warrantId);
        $this->assertNotNull($warrant->approved_date);
    }

    public function testActivateIsIdempotentWhenAlreadyActive(): void
    {
        $wm = $this->createWarrantManager();
        [$rosterId, $warrantId] = $this->createPendingRoster();

        $roster = $this->rosterTable->get($rosterId);
        $roster->status = WarrantRoster::STATUS_APPROVED;
        $this->rosterTable->saveOrFail($roster);

        // First activation
        $wm->activateApprovedRoster($rosterId, self::ADMIN_MEMBER_ID);

        // Second activation should be idempotent (no pending warrants)
        $result = $wm->activateApprovedRoster($rosterId, self::ADMIN_MEMBER_ID);
        $this->assertTrue($result->isSuccess(), 'Re-activation should be idempotent');
    }

    public function testActivateReturnsSuccessResult(): void
    {
        $wm = $this->createWarrantManager();
        [$rosterId] = $this->createPendingRoster();

        $roster = $this->rosterTable->get($rosterId);
        $roster->status = WarrantRoster::STATUS_APPROVED;
        $this->rosterTable->saveOrFail($roster);

        $result = $wm->activateApprovedRoster($rosterId, self::ADMIN_MEMBER_ID);

        $this->assertInstanceOf(ServiceResult::class, $result);
        $this->assertTrue($result->isSuccess());
    }

    // =====================================================
    // Integration: activateWarrants workflow action
    // =====================================================

    public function testActivateWarrantsSyncsApprovalsRequired(): void
    {
        $wm = $this->createWarrantManager();
        $actions = new WarrantWorkflowActions($wm);
        [$rosterId] = $this->createPendingRoster(1);
        [$instanceId, $approvalId] = $this->createWorkflowApprovalContext(3);
        $this->addApprovalResponse($approvalId, self::ADMIN_MEMBER_ID);

        $context = [
            'instanceId' => $instanceId,
            'triggeredBy' => self::ADMIN_MEMBER_ID,
            'resumeData' => ['approverId' => self::ADMIN_MEMBER_ID],
        ];
        $config = [
            'rosterId' => $rosterId,
        ];

        $actions->activateWarrants($context, $config);

        $roster = $this->rosterTable->get($rosterId);
        $this->assertEquals(3, $roster->approvals_required, 'Should sync from workflow approval config');
    }

    public function testActivateWarrantsSyncsAllApprovalResponses(): void
    {
        $wm = $this->createWarrantManager();
        $actions = new WarrantWorkflowActions($wm);
        [$rosterId] = $this->createPendingRoster(2);
        [$instanceId, $approvalId] = $this->createWorkflowApprovalContext(2);

        $this->addApprovalResponse($approvalId, self::ADMIN_MEMBER_ID);
        $this->addApprovalResponse($approvalId, self::TEST_MEMBER_AGATHA_ID);

        $context = [
            'instanceId' => $instanceId,
            'triggeredBy' => self::ADMIN_MEMBER_ID,
            'resumeData' => ['approverId' => self::ADMIN_MEMBER_ID],
        ];
        $config = [
            'rosterId' => $rosterId,
        ];

        $actions->activateWarrants($context, $config);

        $approvalRecords = $this->rosterApprovalsTable->find()
            ->where(['warrant_roster_id' => $rosterId])
            ->count();
        $this->assertEquals(2, $approvalRecords, 'Both approval responses should sync to roster');
    }

    public function testActivateWarrantsSetsRosterStatusApproved(): void
    {
        $wm = $this->createWarrantManager();
        $actions = new WarrantWorkflowActions($wm);
        [$rosterId] = $this->createPendingRoster();
        [$instanceId, $approvalId] = $this->createWorkflowApprovalContext();
        $this->addApprovalResponse($approvalId, self::ADMIN_MEMBER_ID);

        $context = [
            'instanceId' => $instanceId,
            'triggeredBy' => self::ADMIN_MEMBER_ID,
            'resumeData' => ['approverId' => self::ADMIN_MEMBER_ID],
        ];
        $config = [
            'rosterId' => $rosterId,
        ];

        $actions->activateWarrants($context, $config);

        $roster = $this->rosterTable->get($rosterId);
        $this->assertEquals(WarrantRoster::STATUS_APPROVED, $roster->status);
    }

    public function testActivateWarrantsActivatesAfterSync(): void
    {
        $wm = $this->createWarrantManager();
        $actions = new WarrantWorkflowActions($wm);
        [$rosterId, $warrantId] = $this->createPendingRoster();
        [$instanceId, $approvalId] = $this->createWorkflowApprovalContext();
        $this->addApprovalResponse($approvalId, self::ADMIN_MEMBER_ID);

        $context = [
            'instanceId' => $instanceId,
            'triggeredBy' => self::ADMIN_MEMBER_ID,
            'resumeData' => ['approverId' => self::ADMIN_MEMBER_ID],
        ];
        $config = [
            'rosterId' => $rosterId,
        ];

        $output = $actions->activateWarrants($context, $config);

        $this->assertTrue($output['activated']);
        $this->assertGreaterThanOrEqual(1, $output['count']);

        $warrant = $this->warrantTable->get($warrantId);
        $this->assertEquals(Warrant::CURRENT_STATUS, $warrant->status);
    }

    // =====================================================
    // Integration: declineRoster workflow action
    // =====================================================

    public function testDeclineRosterSyncsApproveResponsesBeforeDecline(): void
    {
        $wm = $this->createWarrantManager();
        $actions = new WarrantWorkflowActions($wm);
        [$rosterId] = $this->createPendingRoster(2);

        // Create a workflow approval context with status 'rejected'
        $defTable = TableRegistry::getTableLocator()->get('WorkflowDefinitions');
        $def = $defTable->newEntity([
            'name' => 'Decline Test ' . uniqid(),
            'slug' => 'decline-' . uniqid(),
            'trigger_type' => 'manual',
        ]);
        $defTable->saveOrFail($def);

        $versionsTable = TableRegistry::getTableLocator()->get('WorkflowVersions');
        $version = $versionsTable->newEntity([
            'workflow_definition_id' => $def->id,
            'version_number' => 1,
            'definition' => ['nodes' => [
                'trigger1' => ['type' => 'trigger', 'outputs' => [['target' => 'end1']]],
                'end1' => ['type' => 'end', 'outputs' => []],
            ]],
            'status' => 'published',
        ]);
        $versionsTable->saveOrFail($version);

        $instancesTable = TableRegistry::getTableLocator()->get('WorkflowInstances');
        $instance = $instancesTable->newEntity([
            'workflow_definition_id' => $def->id,
            'workflow_version_id' => $version->id,
            'status' => 'completed',
        ]);
        $instancesTable->saveOrFail($instance);

        $logsTable = TableRegistry::getTableLocator()->get('WorkflowExecutionLogs');
        $log = $logsTable->newEntity([
            'workflow_instance_id' => $instance->id,
            'node_id' => 'approval_node',
            'node_type' => 'approval',
            'status' => 'completed',
        ]);
        $logsTable->saveOrFail($log);

        $approval = $this->approvalsTable->newEntity([
            'workflow_instance_id' => $instance->id,
            'execution_log_id' => $log->id,
            'node_id' => 'approval_node',
            'approver_type' => 'member',
            'approver_config' => [],
            'required_count' => 2,
            'approved_count' => 1,
            'rejected_count' => 1,
            'status' => 'rejected',
        ]);
        $this->approvalsTable->saveOrFail($approval);

        // One approve, one reject
        $this->addApprovalResponse($approval->id, self::ADMIN_MEMBER_ID, 'approve', 'Looks good');
        $this->addApprovalResponse($approval->id, self::TEST_MEMBER_AGATHA_ID, 'reject', 'Denied');

        $context = [
            'instanceId' => $instance->id,
            'triggeredBy' => self::TEST_MEMBER_AGATHA_ID,
        ];
        $config = [
            'rosterId' => $rosterId,
            'reason' => 'Workflow declined',
            'rejecterId' => self::TEST_MEMBER_AGATHA_ID,
        ];

        $actions->declineRoster($context, $config);

        // The approve response should have been synced to roster
        $approvalRecords = $this->rosterApprovalsTable->find()
            ->where(['warrant_roster_id' => $rosterId])
            ->count();
        $this->assertEquals(1, $approvalRecords, 'Only approve responses should be synced before decline');
    }

    // =====================================================
    // Edge cases
    // =====================================================

    public function testActivateWarrantsWithZeroResponses(): void
    {
        $wm = $this->createWarrantManager();
        $actions = new WarrantWorkflowActions($wm);
        [$rosterId] = $this->createPendingRoster();
        [$instanceId] = $this->createWorkflowApprovalContext();
        // No responses added

        $context = [
            'instanceId' => $instanceId,
            'triggeredBy' => self::ADMIN_MEMBER_ID,
            'resumeData' => ['approverId' => self::ADMIN_MEMBER_ID],
        ];
        $config = [
            'rosterId' => $rosterId,
        ];

        // Should not crash — roster gets marked approved but with 0 synced responses
        $output = $actions->activateWarrants($context, $config);

        // Still activates warrants (approval gate already passed in workflow)
        $this->assertTrue($output['activated']);

        $approvalRecords = $this->rosterApprovalsTable->find()
            ->where(['warrant_roster_id' => $rosterId])
            ->count();
        $this->assertEquals(0, $approvalRecords);
    }

    public function testMixedApproveRejectOnlySyncsApprovals(): void
    {
        $wm = $this->createWarrantManager();
        $actions = new WarrantWorkflowActions($wm);
        [$rosterId] = $this->createPendingRoster(2);
        [$instanceId, $approvalId] = $this->createWorkflowApprovalContext(2);

        // One approve, one reject — only approve decision should be synced
        $this->addApprovalResponse($approvalId, self::ADMIN_MEMBER_ID, 'approve');
        $this->addApprovalResponse($approvalId, self::TEST_MEMBER_AGATHA_ID, 'reject');

        $context = [
            'instanceId' => $instanceId,
            'triggeredBy' => self::ADMIN_MEMBER_ID,
            'resumeData' => ['approverId' => self::ADMIN_MEMBER_ID],
        ];
        $config = [
            'rosterId' => $rosterId,
        ];

        $actions->activateWarrants($context, $config);

        // Only the approve response should produce a roster approval
        $approvalRecords = $this->rosterApprovalsTable->find()
            ->where(['warrant_roster_id' => $rosterId])
            ->count();
        $this->assertEquals(1, $approvalRecords, 'Only approve decisions should sync to roster');
    }
}
