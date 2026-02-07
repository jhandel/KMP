<?php

declare(strict_types=1);

namespace App\Test\TestCase\Services\WorkflowEngine;

use App\Model\Entity\WorkflowApproval;
use App\Model\Entity\WorkflowState;
use App\Model\Entity\WorkflowTransition;
use App\Services\WorkflowEngine\DefaultActionExecutor;
use App\Services\WorkflowEngine\DefaultRuleEvaluator;
use App\Services\WorkflowEngine\DefaultVisibilityEvaluator;
use App\Services\WorkflowEngine\DefaultWorkflowEngine;
use App\Test\TestCase\BaseTestCase;

/**
 * Tests for Phase 5 approval gate enhancements:
 * unique approvers, dynamic thresholds, token flow, delegation, auto-transitions.
 */
class ApprovalGateTest extends BaseTestCase
{
    protected DefaultWorkflowEngine $engine;
    protected $definitionsTable;
    protected $statesTable;
    protected $transitionsTable;
    protected $instancesTable;
    protected $gatesTable;
    protected $approvalsTable;

    protected int $definitionId;
    protected int $initialStateId;
    protected int $pendingStateId;
    protected int $approvedStateId;
    protected int $rejectedStateId;
    protected int $approveTransitionId;
    protected int $rejectTransitionId;
    protected int $toPendingTransitionId;
    protected int $gateId;
    protected int $testEntityId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testEntityId = 800000 + random_int(0, 99999);

        $this->definitionsTable = $this->getTableLocator()->get('WorkflowDefinitions');
        $this->statesTable = $this->getTableLocator()->get('WorkflowStates');
        $this->transitionsTable = $this->getTableLocator()->get('WorkflowTransitions');
        $this->instancesTable = $this->getTableLocator()->get('WorkflowInstances');
        $this->gatesTable = $this->getTableLocator()->get('WorkflowApprovalGates');
        $this->approvalsTable = $this->getTableLocator()->get('WorkflowApprovals');

        $this->createApprovalWorkflow();

        $ruleEvaluator = new DefaultRuleEvaluator();
        $actionExecutor = new DefaultActionExecutor();
        $visibilityEvaluator = new DefaultVisibilityEvaluator();
        $this->engine = new DefaultWorkflowEngine($ruleEvaluator, $actionExecutor, $visibilityEvaluator);
    }

    protected function createApprovalWorkflow(): void
    {
        $definition = $this->definitionsTable->newEntity([
            'name' => 'Approval Test Workflow',
            'slug' => 'approval-test-' . uniqid(),
            'description' => 'Test workflow for approval gate logic',
            'entity_type' => 'Members',
            'version' => 1,
            'is_active' => true,
            'is_default' => false,
        ]);
        $this->definitionsTable->save($definition);
        $this->definitionId = $definition->id;

        // Initial state (required by engine)
        $initial = $this->statesTable->newEntity([
            'workflow_definition_id' => $this->definitionId,
            'name' => 'Submitted',
            'slug' => 'submitted',
            'label' => 'Submitted',
            'state_type' => WorkflowState::TYPE_INITIAL,
            'metadata' => '{}',
            'on_enter_actions' => '[]',
            'on_exit_actions' => '[]',
        ]);
        $this->statesTable->save($initial);
        $this->initialStateId = $initial->id;

        // Pending = approval state, Approved and Rejected = terminal
        $pending = $this->statesTable->newEntity([
            'workflow_definition_id' => $this->definitionId,
            'name' => 'Pending Approval',
            'slug' => 'pending',
            'label' => 'Pending',
            'state_type' => WorkflowState::TYPE_APPROVAL,
            'metadata' => '{}',
            'on_enter_actions' => '[]',
            'on_exit_actions' => '[]',
        ]);
        $this->statesTable->save($pending);
        $this->pendingStateId = $pending->id;

        $approved = $this->statesTable->newEntity([
            'workflow_definition_id' => $this->definitionId,
            'name' => 'Approved',
            'slug' => 'approved',
            'label' => 'Approved',
            'state_type' => WorkflowState::TYPE_TERMINAL,
            'metadata' => '{}',
            'on_enter_actions' => '[]',
            'on_exit_actions' => '[]',
        ]);
        $this->statesTable->save($approved);
        $this->approvedStateId = $approved->id;

        $rejected = $this->statesTable->newEntity([
            'workflow_definition_id' => $this->definitionId,
            'name' => 'Rejected',
            'slug' => 'rejected',
            'label' => 'Rejected',
            'state_type' => WorkflowState::TYPE_TERMINAL,
            'metadata' => '{}',
            'on_enter_actions' => '[]',
            'on_exit_actions' => '[]',
        ]);
        $this->statesTable->save($rejected);
        $this->rejectedStateId = $rejected->id;

        // Transitions
        $approve = $this->transitionsTable->newEntity([
            'workflow_definition_id' => $this->definitionId,
            'from_state_id' => $this->pendingStateId,
            'to_state_id' => $this->approvedStateId,
            'name' => 'Approve',
            'slug' => 'approve',
            'label' => 'Approve',
            'priority' => 1,
            'trigger_type' => WorkflowTransition::TRIGGER_MANUAL,
            'conditions' => '[]',
            'actions' => '[]',
            'trigger_config' => '{}',
        ]);
        $this->transitionsTable->save($approve);
        $this->approveTransitionId = $approve->id;

        $reject = $this->transitionsTable->newEntity([
            'workflow_definition_id' => $this->definitionId,
            'from_state_id' => $this->pendingStateId,
            'to_state_id' => $this->rejectedStateId,
            'name' => 'Reject',
            'slug' => 'reject',
            'label' => 'Reject',
            'priority' => 2,
            'trigger_type' => WorkflowTransition::TRIGGER_MANUAL,
            'conditions' => '[]',
            'actions' => '[]',
            'trigger_config' => '{}',
        ]);
        $this->transitionsTable->save($reject);
        $this->rejectTransitionId = $reject->id;

        // Transition from initial to pending
        $toPending = $this->transitionsTable->newEntity([
            'workflow_definition_id' => $this->definitionId,
            'from_state_id' => $this->initialStateId,
            'to_state_id' => $this->pendingStateId,
            'name' => 'Send for Approval',
            'slug' => 'send-for-approval',
            'label' => 'Send for Approval',
            'priority' => 1,
            'trigger_type' => WorkflowTransition::TRIGGER_MANUAL,
            'conditions' => '[]',
            'actions' => '[]',
            'trigger_config' => '{}',
        ]);
        $this->transitionsTable->save($toPending);
        $this->toPendingTransitionId = $toPending->id;

        // Default gate: threshold=2, fixed
        $gate = $this->gatesTable->newEntity([
            'workflow_state_id' => $this->pendingStateId,
            'approval_type' => 'threshold',
            'required_count' => 2,
            'threshold_config' => json_encode(['type' => 'fixed', 'value' => 2]),
            'approver_rule' => json_encode(['type' => 'role', 'role' => 'Approver']),
            'allow_delegation' => true,
        ]);
        $this->gatesTable->save($gate);
        $this->gateId = $gate->id;
    }

    protected function createInstance(): int
    {
        $slug = $this->definitionsTable->get($this->definitionId)->slug;
        $result = $this->engine->startWorkflow($slug, 'Members', $this->testEntityId++);
        $this->assertTrue($result->success, 'Failed to start workflow: ' . ($result->reason ?? ''));

        $instanceId = $result->data->id;

        // Move to the pending/approval state
        $transResult = $this->engine->transition($instanceId, 'send-for-approval');
        $this->assertTrue($transResult->success, 'Failed to transition to pending: ' . ($transResult->reason ?? ''));

        return $instanceId;
    }

    // ── Unique Approver Enforcement ──

    public function testRecordApprovalSucceeds(): void
    {
        $instanceId = $this->createInstance();
        $result = $this->engine->recordApproval($instanceId, $this->gateId, 1001, WorkflowApproval::DECISION_APPROVED, 'Looks good');
        $this->assertTrue($result->success);
        $this->assertArrayHasKey('gate_status', $result->data);
        $this->assertEquals(1, $result->data['gate_status']['approved_count']);
    }

    public function testRecordApprovalRejectsDuplicateApprover(): void
    {
        $instanceId = $this->createInstance();
        $this->engine->recordApproval($instanceId, $this->gateId, 1001, WorkflowApproval::DECISION_APPROVED);
        $result = $this->engine->recordApproval($instanceId, $this->gateId, 1001, WorkflowApproval::DECISION_APPROVED);
        $this->assertFalse($result->success);
        $this->assertStringContainsString('already recorded', $result->reason);
    }

    public function testRecordApprovalRejectsInvalidDecision(): void
    {
        $instanceId = $this->createInstance();
        $result = $this->engine->recordApproval($instanceId, $this->gateId, 1001, 'maybe');
        $this->assertFalse($result->success);
        $this->assertStringContainsString('Invalid decision', $result->reason);
    }

    // ── Threshold Resolution ──

    public function testFixedThresholdRequiresTwoApprovals(): void
    {
        $instanceId = $this->createInstance();
        $r1 = $this->engine->recordApproval($instanceId, $this->gateId, 1001, WorkflowApproval::DECISION_APPROVED);
        $this->assertFalse($r1->data['gate_status']['is_met']);

        $r2 = $this->engine->recordApproval($instanceId, $this->gateId, 1002, WorkflowApproval::DECISION_APPROVED);
        $this->assertTrue($r2->data['gate_status']['is_met']);
    }

    public function testAppSettingThresholdResolution(): void
    {
        // Update gate to use app_setting threshold
        $gate = $this->gatesTable->get($this->gateId);
        $gate->threshold_config = json_encode([
            'type' => 'app_setting',
            'key' => 'Test.ApprovalThreshold',
            'default' => 1,
        ]);
        $this->gatesTable->save($gate);

        $instanceId = $this->createInstance();
        // With default=1, one approval should satisfy
        $result = $this->engine->recordApproval($instanceId, $this->gateId, 1001, WorkflowApproval::DECISION_APPROVED);
        $this->assertTrue($result->data['gate_status']['is_met']);
    }

    // ── Token-Based Approval ──

    public function testGenerateApprovalToken(): void
    {
        $instanceId = $this->createInstance();
        $result = $this->engine->generateApprovalToken($instanceId, $this->gateId, 1001);
        $this->assertTrue($result->success);
        $this->assertNotEmpty($result->data['token']);
        $this->assertEquals(64, strlen($result->data['token'])); // 32 bytes = 64 hex chars
    }

    public function testGenerateTokenReturnsExistingForSameApprover(): void
    {
        $instanceId = $this->createInstance();
        $r1 = $this->engine->generateApprovalToken($instanceId, $this->gateId, 1001);
        $r2 = $this->engine->generateApprovalToken($instanceId, $this->gateId, 1001);
        $this->assertEquals($r1->data['token'], $r2->data['token']);
    }

    public function testResolveApprovalByToken(): void
    {
        $instanceId = $this->createInstance();
        $tokenResult = $this->engine->generateApprovalToken($instanceId, $this->gateId, 1001);
        $token = $tokenResult->data['token'];

        $result = $this->engine->resolveApprovalByToken($token, WorkflowApproval::DECISION_APPROVED, 'Via email');
        $this->assertTrue($result->success);
        $this->assertEquals(1, $result->data['gate_status']['approved_count']);
    }

    public function testResolveByTokenFailsForInvalidToken(): void
    {
        $result = $this->engine->resolveApprovalByToken('nonexistent-token', WorkflowApproval::DECISION_APPROVED);
        $this->assertFalse($result->success);
        $this->assertStringContainsString('Invalid', $result->reason);
    }

    public function testResolveByTokenFailsForAlreadyUsedToken(): void
    {
        $instanceId = $this->createInstance();
        $tokenResult = $this->engine->generateApprovalToken($instanceId, $this->gateId, 1001);
        $token = $tokenResult->data['token'];

        $this->engine->resolveApprovalByToken($token, WorkflowApproval::DECISION_APPROVED);
        $result = $this->engine->resolveApprovalByToken($token, WorkflowApproval::DECISION_APPROVED);
        $this->assertFalse($result->success);
    }

    // ── Delegation ──

    public function testDelegateApproval(): void
    {
        $instanceId = $this->createInstance();
        $tokenResult = $this->engine->generateApprovalToken($instanceId, $this->gateId, 1001);

        $approval = $this->approvalsTable->find()
            ->where(['token' => $tokenResult->data['token']])
            ->first();

        $result = $this->engine->delegateApproval($approval->id, 1002);
        $this->assertTrue($result->success);
        $this->assertNotEmpty($result->data['token']);
        $this->assertEquals($approval->id, $result->data['delegated_from']);
    }

    public function testDelegateFailsWhenNotAllowed(): void
    {
        // Disable delegation on gate
        $gate = $this->gatesTable->get($this->gateId);
        $gate->allow_delegation = false;
        $this->gatesTable->save($gate);

        $instanceId = $this->createInstance();
        $tokenResult = $this->engine->generateApprovalToken($instanceId, $this->gateId, 1001);

        $approval = $this->approvalsTable->find()
            ->where(['token' => $tokenResult->data['token']])
            ->first();

        $result = $this->engine->delegateApproval($approval->id, 1002);
        $this->assertFalse($result->success);
        $this->assertStringContainsString('does not allow delegation', $result->reason);
    }

    // ── Auto-Transition on Satisfaction ──

    public function testAutoTransitionOnGateSatisfaction(): void
    {
        // Configure gate to auto-fire approve transition when satisfied
        $gate = $this->gatesTable->get($this->gateId);
        $gate->on_satisfied_transition_id = $this->approveTransitionId;
        $this->gatesTable->save($gate);

        $instanceId = $this->createInstance();
        $this->engine->recordApproval($instanceId, $this->gateId, 1001, WorkflowApproval::DECISION_APPROVED);
        // After first approval, should still be pending (need 2)
        $instance = $this->instancesTable->get($instanceId);
        $this->assertEquals($this->pendingStateId, $instance->current_state_id);

        // Second approval should auto-transition to approved
        $this->engine->recordApproval($instanceId, $this->gateId, 1002, WorkflowApproval::DECISION_APPROVED);
        $instance = $this->instancesTable->get($instanceId);
        $this->assertEquals($this->approvedStateId, $instance->current_state_id);
    }

    // ── Unanimous Denial ──

    public function testUnanimousGateDenialOnFirstDeny(): void
    {
        // Change gate to unanimous type with deny transition
        $gate = $this->gatesTable->get($this->gateId);
        $gate->approval_type = 'unanimous';
        $gate->on_denied_transition_id = $this->rejectTransitionId;
        $this->gatesTable->save($gate);

        $instanceId = $this->createInstance();
        // A single denial should trigger auto-transition to rejected
        $this->engine->recordApproval($instanceId, $this->gateId, 1001, WorkflowApproval::DECISION_DENIED, 'Not good enough');
        $instance = $this->instancesTable->get($instanceId);
        $this->assertEquals($this->rejectedStateId, $instance->current_state_id);
    }

    // ── Any One Gate ──

    public function testAnyOneGateSatisfiedByOneApproval(): void
    {
        $gate = $this->gatesTable->get($this->gateId);
        $gate->approval_type = 'any_one';
        $gate->on_satisfied_transition_id = $this->approveTransitionId;
        $this->gatesTable->save($gate);

        $instanceId = $this->createInstance();
        $this->engine->recordApproval($instanceId, $this->gateId, 1001, WorkflowApproval::DECISION_APPROVED);
        $instance = $this->instancesTable->get($instanceId);
        $this->assertEquals($this->approvedStateId, $instance->current_state_id);
    }
}
