<?php

declare(strict_types=1);

namespace App\Test\TestCase\Services\WorkflowEngine\Providers;

use App\Services\ActiveWindowManager\ActiveWindowManagerInterface;
use App\Services\ServiceResult;
use App\Services\WarrantManager\WarrantManagerInterface;
use App\Services\WorkflowRegistry\WorkflowActionRegistry;
use App\Services\WorkflowRegistry\WorkflowConditionRegistry;
use App\Test\TestCase\BaseTestCase;
use Cake\I18n\DateTime;
use Cake\ORM\TableRegistry;
use Officers\Model\Entity\Officer;
use Officers\Services\OfficerManagerInterface;
use Officers\Services\OfficerWorkflowActions;
use Officers\Services\OfficerWorkflowConditions;
use Officers\Services\OfficersWorkflowProvider;

/**
 * Tests for Officers plugin workflow actions and conditions.
 */
class OfficersWorkflowActionsTest extends BaseTestCase
{
    private OfficerWorkflowActions $actions;
    private OfficerWorkflowConditions $conditions;
    private ActiveWindowManagerInterface $activeWindowManager;
    private WarrantManagerInterface $warrantManager;
    private OfficerManagerInterface $officerManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->activeWindowManager = $this->createMock(ActiveWindowManagerInterface::class);
        $this->warrantManager = $this->createMock(WarrantManagerInterface::class);
        $this->officerManager = $this->createMock(OfficerManagerInterface::class);

        $this->actions = new OfficerWorkflowActions(
            $this->activeWindowManager,
            $this->warrantManager,
            $this->officerManager,
        );
        $this->conditions = new OfficerWorkflowConditions();
    }

    protected function tearDown(): void
    {
        WorkflowActionRegistry::clear();
        WorkflowConditionRegistry::clear();
        parent::tearDown();
    }

    // =====================================================
    // Provider Registration
    // =====================================================

    public function testProviderRegistersAllActions(): void
    {
        OfficersWorkflowProvider::register();
        $actions = WorkflowActionRegistry::getActionsBySource('Officers');
        $actionKeys = array_column($actions, 'action');

        $this->assertContains('Officers.CreateOfficerRecord', $actionKeys);
        $this->assertContains('Officers.ReleaseOfficer', $actionKeys);
        $this->assertContains('Officers.SendHireNotification', $actionKeys);
        $this->assertContains('Officers.RequestWarrantRoster', $actionKeys);
        $this->assertContains('Officers.CalculateReportingFields', $actionKeys);
        $this->assertContains('Officers.ReleaseConflictingOfficers', $actionKeys);
        $this->assertContains('Officers.RecalculateOfficersForOffice', $actionKeys);
        $this->assertContains('Officers.SendReleaseNotification', $actionKeys);
    }

    public function testProviderRegistersAllConditions(): void
    {
        OfficersWorkflowProvider::register();
        $conditions = WorkflowConditionRegistry::getConditionsBySource('Officers');
        $conditionKeys = array_column($conditions, 'condition');

        $this->assertContains('Officers.OfficeRequiresWarrant', $conditionKeys);
        $this->assertContains('Officers.IsOnlyOnePerBranch', $conditionKeys);
        $this->assertContains('Officers.IsMemberWarrantable', $conditionKeys);
        $this->assertContains('Officers.HasConflictingOfficer', $conditionKeys);
    }

    // =====================================================
    // CalculateReportingFields action
    // =====================================================

    public function testCalculateReportingFieldsForDeputyOffice(): void
    {
        // Office 3 = Kingdom Rapier Marshal, deputy_to_id = 2
        $context = ['triggeredBy' => self::ADMIN_MEMBER_ID];
        $config = [
            'officeId' => 3,
            'branchId' => self::KINGDOM_BRANCH_ID,
        ];

        $result = $this->actions->calculateReportingFieldsAction($context, $config);

        $this->assertArrayHasKey('reports_to_office_id', $result);
        $this->assertArrayHasKey('deputy_to_office_id', $result);
        // Deputy offices set deputy_to_office_id to the deputy_to_id value
        $this->assertEquals(2, $result['deputy_to_office_id']);
        $this->assertEquals(2, $result['reports_to_office_id']);
        $this->assertEquals(self::KINGDOM_BRANCH_ID, $result['deputy_to_branch_id']);
    }

    public function testCalculateReportingFieldsForRegularOffice(): void
    {
        // Office 4 = Regional Rapier Marshal, reports_to_id = 3, no deputy
        // Branch 12 = Central Region, parent = 2 (Kingdom)
        $context = ['triggeredBy' => self::ADMIN_MEMBER_ID];
        $config = [
            'officeId' => 4,
            'branchId' => self::TEST_BRANCH_CENTRAL_REGION_ID,
        ];

        $result = $this->actions->calculateReportingFieldsAction($context, $config);

        $this->assertArrayHasKey('reports_to_office_id', $result);
        $this->assertEquals(3, $result['reports_to_office_id']);
        $this->assertNull($result['deputy_to_office_id']);
    }

    public function testCalculateReportingFieldsWithInvalidOfficeReturnsNulls(): void
    {
        $context = [];
        $config = [
            'officeId' => 999999,
            'branchId' => self::KINGDOM_BRANCH_ID,
        ];

        $result = $this->actions->calculateReportingFieldsAction($context, $config);

        $this->assertNull($result['reports_to_office_id']);
        $this->assertNull($result['deputy_to_office_id']);
    }

    // =====================================================
    // ReleaseConflictingOfficers action
    // =====================================================

    public function testReleaseConflictingOfficersReleasesCurrentOfficers(): void
    {
        // Create a current officer for a one-per-branch office
        $officerTable = TableRegistry::getTableLocator()->get('Officers.Officers');
        $officer = $officerTable->newEmptyEntity();
        $officer->member_id = self::TEST_MEMBER_AGATHA_ID;
        $officer->office_id = 6; // Kingdom Chronicler - only_one_per_branch = 1
        $officer->branch_id = self::KINGDOM_BRANCH_ID;
        $officer->status = Officer::CURRENT_STATUS;
        $officer->start_on = DateTime::now()->subMonths(1);
        $officer->expires_on = DateTime::now()->addMonths(11);
        $officer->approver_id = self::ADMIN_MEMBER_ID;
        $officer->approval_date = DateTime::now();
        $officerTable->save($officer);
        $createdId = $officer->id;

        $context = ['triggeredBy' => self::ADMIN_MEMBER_ID];
        $config = [
            'officeId' => 6,
            'branchId' => self::KINGDOM_BRANCH_ID,
            'newOfficerStartDate' => DateTime::now()->format('Y-m-d H:i:s'),
        ];

        $result = $this->actions->releaseConflictingOfficers($context, $config);

        $this->assertArrayHasKey('releasedOfficerIds', $result);
        $this->assertContains($createdId, $result['releasedOfficerIds']);

        // Verify the officer was actually marked as replaced
        $updated = $officerTable->get($createdId);
        $this->assertEquals(Officer::REPLACED_STATUS, $updated->status);
    }

    public function testReleaseConflictingOfficersWithNoConflictsReturnsEmpty(): void
    {
        $context = ['triggeredBy' => self::ADMIN_MEMBER_ID];
        $config = [
            'officeId' => 999999,
            'branchId' => self::KINGDOM_BRANCH_ID,
            'newOfficerStartDate' => DateTime::now()->format('Y-m-d H:i:s'),
        ];

        $result = $this->actions->releaseConflictingOfficers($context, $config);

        $this->assertEmpty($result['releasedOfficerIds']);
    }

    // =====================================================
    // RecalculateOfficersForOffice action
    // =====================================================

    public function testRecalculateOfficersForOfficeDelegatesToManager(): void
    {
        $this->officerManager->expects($this->once())
            ->method('recalculateOfficersForOffice')
            ->with(6, self::ADMIN_MEMBER_ID)
            ->willReturn(new ServiceResult(true, null, [
                'updated_count' => 3,
                'current_count' => 2,
                'upcoming_count' => 1,
            ]));

        $context = ['triggeredBy' => self::ADMIN_MEMBER_ID];
        $config = [
            'officeId' => 6,
            'updaterId' => self::ADMIN_MEMBER_ID,
        ];

        $result = $this->actions->recalculateOfficersForOffice($context, $config);

        $this->assertEquals(3, $result['updatedCount']);
    }

    public function testRecalculateOfficersForOfficeHandlesFailure(): void
    {
        $this->officerManager->expects($this->once())
            ->method('recalculateOfficersForOffice')
            ->willReturn(new ServiceResult(false, 'Some error'));

        $context = ['triggeredBy' => self::ADMIN_MEMBER_ID];
        $config = ['officeId' => 6];

        $result = $this->actions->recalculateOfficersForOffice($context, $config);

        $this->assertEquals(0, $result['updatedCount']);
    }

    public function testRecalculateOfficersWithoutManagerReturnsZero(): void
    {
        // Create actions instance without officer manager
        $actions = new OfficerWorkflowActions(
            $this->activeWindowManager,
            $this->warrantManager,
            null,
        );

        $context = ['triggeredBy' => self::ADMIN_MEMBER_ID];
        $config = ['officeId' => 6];

        $result = $actions->recalculateOfficersForOffice($context, $config);

        $this->assertEquals(0, $result['updatedCount']);
    }

    // =====================================================
    // SendReleaseNotification action
    // =====================================================

    public function testSendReleaseNotificationReturnsResult(): void
    {
        // Use officer 932 (Agatha, Current, office 14, branch 31)
        $context = ['triggeredBy' => self::ADMIN_MEMBER_ID];
        $config = [
            'officerId' => 932,
            'reason' => 'Term expired',
        ];

        $result = $this->actions->sendReleaseNotification($context, $config);

        $this->assertArrayHasKey('sent', $result);
    }

    public function testSendReleaseNotificationWithInvalidOfficerReturnsFalse(): void
    {
        $context = ['triggeredBy' => self::ADMIN_MEMBER_ID];
        $config = [
            'officerId' => 999999,
            'reason' => 'Test',
        ];

        $result = $this->actions->sendReleaseNotification($context, $config);

        $this->assertFalse($result['sent']);
    }

    // =====================================================
    // HasConflictingOfficer condition
    // =====================================================

    public function testHasConflictingOfficerReturnsTrueWhenConflictExists(): void
    {
        // Officer 932 is Agatha (Current) in office 14, branch 31
        $context = [];
        $config = [
            'officeId' => 14,
            'branchId' => 31,
        ];

        $result = $this->conditions->hasConflictingOfficer($context, $config);

        $this->assertTrue($result);
    }

    public function testHasConflictingOfficerReturnsFalseWhenNone(): void
    {
        $context = [];
        $config = [
            'officeId' => 999999,
            'branchId' => self::KINGDOM_BRANCH_ID,
        ];

        $result = $this->conditions->hasConflictingOfficer($context, $config);

        $this->assertFalse($result);
    }

    public function testHasConflictingOfficerReturnsFalseWithMissingParams(): void
    {
        $context = [];
        $config = [];

        $result = $this->conditions->hasConflictingOfficer($context, $config);

        $this->assertFalse($result);
    }

    // =====================================================
    // Existing conditions (verify they still work)
    // =====================================================

    public function testOfficeRequiresWarrantReturnsTrue(): void
    {
        // Office 2 = Kingdom Earl Marshal, requires_warrant = 1
        $result = $this->conditions->officeRequiresWarrant([], ['officeId' => 2]);
        $this->assertTrue($result);
    }

    public function testIsOnlyOnePerBranchReturnsTrue(): void
    {
        // Office 2 = Kingdom Earl Marshal, only_one_per_branch = 1
        $result = $this->conditions->isOnlyOnePerBranch([], ['officeId' => 2]);
        $this->assertTrue($result);
    }

    public function testIsMemberWarrantableReturnsCorrectly(): void
    {
        // Agatha is warrantable (1), Devon is not (0)
        $this->assertTrue($this->conditions->isMemberWarrantable([], ['memberId' => self::TEST_MEMBER_AGATHA_ID]));
        $this->assertFalse($this->conditions->isMemberWarrantable([], ['memberId' => self::TEST_MEMBER_DEVON_ID]));
    }
}
