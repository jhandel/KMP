<?php

declare(strict_types=1);

namespace App\Test\TestCase\Policy;

use App\KMP\KmpIdentityInterface;
use App\Policy\ApprovalsControllerPolicy;
use App\Policy\WorkflowApprovalsTablePolicy;
use App\Policy\WorkflowDefinitionsControllerPolicy;
use App\Policy\WorkflowDefinitionsTablePolicy;
use App\Policy\WorkflowInstancesControllerPolicy;
use App\Policy\WorkflowInstancesTablePolicy;
use App\Policy\WorkflowsControllerPolicy;
use Cake\ORM\Table;
use Cake\TestSuite\TestCase;

/**
 * Tests for workflow policy classes.
 */
class WorkflowPolicyTest extends TestCase
{
    private WorkflowDefinitionsTablePolicy $tablePolicy;
    private WorkflowsControllerPolicy $controllerPolicy;
    private WorkflowDefinitionsControllerPolicy $definitionsControllerPolicy;
    private WorkflowInstancesControllerPolicy $instancesControllerPolicy;
    private ApprovalsControllerPolicy $approvalsControllerPolicy;
    private WorkflowApprovalsTablePolicy $approvalsTablePolicy;
    private WorkflowInstancesTablePolicy $instancesTablePolicy;
    private Table $table;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tablePolicy = new WorkflowDefinitionsTablePolicy();
        $this->controllerPolicy = new WorkflowsControllerPolicy();
        $this->definitionsControllerPolicy = new WorkflowDefinitionsControllerPolicy();
        $this->instancesControllerPolicy = new WorkflowInstancesControllerPolicy();
        $this->approvalsControllerPolicy = new ApprovalsControllerPolicy();
        $this->approvalsTablePolicy = new WorkflowApprovalsTablePolicy();
        $this->instancesTablePolicy = new WorkflowInstancesTablePolicy();
        // Use a stub Table to avoid database dependency
        $this->table = $this->createMock(Table::class);
    }

    /**
     * Create a mock super user.
     */
    private function makeSuperUser(): KmpIdentityInterface
    {
        $user = $this->createMock(KmpIdentityInterface::class);
        $user->method('isSuperUser')->willReturn(true);
        $user->method('getIdentifier')->willReturn(1);
        $user->method('getPolicies')->willReturn([]);

        return $user;
    }

    /**
     * Create a mock regular user with no policies.
     */
    private function makeRegularUser(?int $id = 100): KmpIdentityInterface
    {
        $user = $this->createMock(KmpIdentityInterface::class);
        $user->method('isSuperUser')->willReturn(false);
        $user->method('getIdentifier')->willReturn($id);
        $user->method('getPolicies')->willReturn([]);

        return $user;
    }

    // =====================================================
    // WorkflowDefinitionsTablePolicy – super user bypass
    // =====================================================

    public function testTablePolicySuperUserBeforeReturnsTrue(): void
    {
        $superUser = $this->makeSuperUser();
        $result = $this->tablePolicy->before($superUser, $this->table, 'index');
        $this->assertTrue($result);
    }

    public function testTablePolicySuperUserCanIndex(): void
    {
        $this->assertTrue($this->tablePolicy->canIndex($this->makeSuperUser(), $this->table));
    }

    public function testTablePolicySuperUserCanDesigner(): void
    {
        $this->assertTrue($this->tablePolicy->canDesigner($this->makeSuperUser(), $this->table));
    }

    public function testTablePolicySuperUserCanSave(): void
    {
        $this->assertTrue($this->tablePolicy->canSave($this->makeSuperUser(), $this->table));
    }

    public function testTablePolicySuperUserCanPublish(): void
    {
        $this->assertTrue($this->tablePolicy->canPublish($this->makeSuperUser(), $this->table));
    }

    // =====================================================
    // WorkflowDefinitionsTablePolicy – regular user blocked
    // =====================================================

    public function testTablePolicyRegularUserCannotIndex(): void
    {
        $this->assertFalse($this->tablePolicy->canIndex($this->makeRegularUser(), $this->table));
    }

    public function testTablePolicyRegularUserCannotDesigner(): void
    {
        $this->assertFalse($this->tablePolicy->canDesigner($this->makeRegularUser(), $this->table));
    }

    public function testTablePolicyRegularUserCannotSave(): void
    {
        $this->assertFalse($this->tablePolicy->canSave($this->makeRegularUser(), $this->table));
    }

    public function testTablePolicyRegularUserCannotPublish(): void
    {
        $this->assertFalse($this->tablePolicy->canPublish($this->makeRegularUser(), $this->table));
    }

    public function testTablePolicyRegularUserCannotAdd(): void
    {
        $this->assertFalse($this->tablePolicy->canAdd($this->makeRegularUser(), $this->table));
    }

    public function testTablePolicyRegularUserCannotInstances(): void
    {
        $this->assertFalse($this->tablePolicy->canInstances($this->makeRegularUser(), $this->table));
    }

    public function testTablePolicyRegularUserCannotVersions(): void
    {
        $this->assertFalse($this->tablePolicy->canVersions($this->makeRegularUser(), $this->table));
    }

    public function testTablePolicyRegularUserCannotToggleActive(): void
    {
        $this->assertFalse($this->tablePolicy->canToggleActive($this->makeRegularUser(), $this->table));
    }

    public function testTablePolicyRegularUserCannotCreateDraft(): void
    {
        $this->assertFalse($this->tablePolicy->canCreateDraft($this->makeRegularUser(), $this->table));
    }

    public function testTablePolicyRegularUserCannotMigrateInstances(): void
    {
        $this->assertFalse($this->tablePolicy->canMigrateInstances($this->makeRegularUser(), $this->table));
    }

    // =====================================================
    // WorkflowApprovalsTablePolicy – approvals open to authenticated
    // =====================================================

    public function testTablePolicyAuthenticatedUserCanApprovals(): void
    {
        $this->assertTrue($this->approvalsTablePolicy->canApprovals($this->makeRegularUser(), $this->table));
    }

    public function testTablePolicyAuthenticatedUserCanRecordApproval(): void
    {
        $this->assertTrue($this->approvalsTablePolicy->canRecordApproval($this->makeRegularUser(), $this->table));
    }

    public function testTablePolicyNullIdentifierCannotApprovals(): void
    {
        $user = $this->makeRegularUser(null);
        $this->assertFalse($this->approvalsTablePolicy->canApprovals($user, $this->table));
    }

    // =====================================================
    // WorkflowsControllerPolicy – super user bypass (legacy)
    // =====================================================

    public function testControllerPolicySuperUserBeforeReturnsTrue(): void
    {
        $result = $this->controllerPolicy->before($this->makeSuperUser(), [], 'index');
        $this->assertTrue($result);
    }

    public function testControllerPolicySuperUserCanIndex(): void
    {
        $result = $this->controllerPolicy->before($this->makeSuperUser(), [], 'index');
        $this->assertTrue($result);
    }

    // =====================================================
    // WorkflowsControllerPolicy – regular user denied admin (legacy)
    // =====================================================

    public function testControllerPolicyRegularUserDeniedIndex(): void
    {
        $user = $this->makeRegularUser();
        $beforeResult = $this->controllerPolicy->before($user, [], 'index');
        $this->assertNull($beforeResult);

        $this->assertFalse($this->controllerPolicy->canIndex($user, []));
    }

    public function testControllerPolicyRegularUserDeniedDesigner(): void
    {
        $user = $this->makeRegularUser();
        $this->assertFalse($this->controllerPolicy->canDesigner($user, []));
    }

    public function testControllerPolicyRegularUserDeniedSave(): void
    {
        $user = $this->makeRegularUser();
        $this->assertFalse($this->controllerPolicy->canSave($user, []));
    }

    // =====================================================
    // ApprovalsControllerPolicy – approvals open
    // =====================================================

    public function testControllerPolicyAuthenticatedUserCanApprovals(): void
    {
        $this->assertTrue($this->approvalsControllerPolicy->canApprovals($this->makeRegularUser(), []));
    }

    public function testControllerPolicyAuthenticatedUserCanRecordApproval(): void
    {
        $this->assertTrue($this->approvalsControllerPolicy->canRecordApproval($this->makeRegularUser(), []));
    }

    // =====================================================
    // WorkflowDefinitionsControllerPolicy – super user bypass
    // =====================================================

    public function testDefinitionsControllerPolicySuperUserBefore(): void
    {
        $result = $this->definitionsControllerPolicy->before($this->makeSuperUser(), [], 'index');
        $this->assertTrue($result);
    }

    public function testDefinitionsControllerPolicyRegularUserDenied(): void
    {
        $user = $this->makeRegularUser();
        $this->assertFalse($this->definitionsControllerPolicy->canIndex($user, []));
    }

    // =====================================================
    // WorkflowInstancesControllerPolicy
    // =====================================================

    public function testInstancesControllerPolicySuperUserBefore(): void
    {
        $result = $this->instancesControllerPolicy->before($this->makeSuperUser(), [], 'instances');
        $this->assertTrue($result);
    }

    public function testInstancesControllerPolicyRegularUserDenied(): void
    {
        $user = $this->makeRegularUser();
        $this->assertFalse($this->instancesControllerPolicy->canInstances($user, []));
    }

    // =====================================================
    // WorkflowInstancesTablePolicy
    // =====================================================

    public function testInstancesTablePolicySuperUserCanInstances(): void
    {
        $this->assertTrue($this->instancesTablePolicy->canInstances($this->makeSuperUser(), $this->table));
    }

    public function testInstancesTablePolicyRegularUserCannotInstances(): void
    {
        $this->assertFalse($this->instancesTablePolicy->canInstances($this->makeRegularUser(), $this->table));
    }

    // =====================================================
    // WorkflowApprovalsTablePolicy – admin actions
    // =====================================================

    public function testApprovalsTablePolicySuperUserCanAllApprovals(): void
    {
        $this->assertTrue($this->approvalsTablePolicy->canAllApprovals($this->makeSuperUser(), $this->table));
    }

    public function testApprovalsTablePolicyRegularUserCannotAllApprovals(): void
    {
        $this->assertFalse($this->approvalsTablePolicy->canAllApprovals($this->makeRegularUser(), $this->table));
    }

    public function testApprovalsTablePolicyRegularUserCannotReassign(): void
    {
        $this->assertFalse($this->approvalsTablePolicy->canReassignApproval($this->makeRegularUser(), $this->table));
    }
}