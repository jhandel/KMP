<?php

declare(strict_types=1);

namespace App\Test\TestCase\Services\WorkflowEngine;

use App\Services\WorkflowEngine\Conditions\ApprovalGateCondition;
use App\Services\WorkflowEngine\Conditions\ConditionInterface;
use App\Services\WorkflowEngine\Conditions\FieldCondition;
use App\Services\WorkflowEngine\Conditions\OwnershipCondition;
use App\Services\WorkflowEngine\Conditions\PermissionCondition;
use App\Services\WorkflowEngine\Conditions\RoleCondition;
use App\Services\WorkflowEngine\Conditions\TimeCondition;
use App\Services\WorkflowEngine\Conditions\WorkflowContextCondition;
use App\Services\WorkflowEngine\RuleEvaluator;
use Cake\TestSuite\TestCase;

class RuleEvaluatorTest extends TestCase
{
    private RuleEvaluator $evaluator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->evaluator = new RuleEvaluator();
    }

    // ── Empty condition ──

    public function testEmptyConditionReturnsTrue(): void
    {
        $this->assertTrue($this->evaluator->evaluate([], []));
    }

    // ── FieldCondition: eq ──

    public function testFieldEq(): void
    {
        $condition = ['type' => 'field', 'field' => 'entity.status', 'operator' => 'eq', 'value' => 'active'];
        $context = ['entity' => ['status' => 'active']];
        $this->assertTrue($this->evaluator->evaluate($condition, $context));
    }

    public function testFieldEqFalse(): void
    {
        $condition = ['type' => 'field', 'field' => 'entity.status', 'operator' => 'eq', 'value' => 'active'];
        $context = ['entity' => ['status' => 'pending']];
        $this->assertFalse($this->evaluator->evaluate($condition, $context));
    }

    // ── FieldCondition: neq ──

    public function testFieldNeq(): void
    {
        $condition = ['type' => 'field', 'field' => 'entity.status', 'operator' => 'neq', 'value' => 'deleted'];
        $context = ['entity' => ['status' => 'active']];
        $this->assertTrue($this->evaluator->evaluate($condition, $context));
    }

    public function testFieldNeqFalse(): void
    {
        $condition = ['type' => 'field', 'field' => 'entity.status', 'operator' => 'neq', 'value' => 'active'];
        $context = ['entity' => ['status' => 'active']];
        $this->assertFalse($this->evaluator->evaluate($condition, $context));
    }

    // ── FieldCondition: gt ──

    public function testFieldGt(): void
    {
        $condition = ['type' => 'field', 'field' => 'entity.score', 'operator' => 'gt', 'value' => 50];
        $context = ['entity' => ['score' => 75]];
        $this->assertTrue($this->evaluator->evaluate($condition, $context));
    }

    public function testFieldGtFalse(): void
    {
        $condition = ['type' => 'field', 'field' => 'entity.score', 'operator' => 'gt', 'value' => 50];
        $context = ['entity' => ['score' => 50]];
        $this->assertFalse($this->evaluator->evaluate($condition, $context));
    }

    // ── FieldCondition: gte ──

    public function testFieldGte(): void
    {
        $condition = ['type' => 'field', 'field' => 'entity.score', 'operator' => 'gte', 'value' => 50];
        $context = ['entity' => ['score' => 50]];
        $this->assertTrue($this->evaluator->evaluate($condition, $context));
    }

    public function testFieldGteFalse(): void
    {
        $condition = ['type' => 'field', 'field' => 'entity.score', 'operator' => 'gte', 'value' => 50];
        $context = ['entity' => ['score' => 49]];
        $this->assertFalse($this->evaluator->evaluate($condition, $context));
    }

    // ── FieldCondition: lt ──

    public function testFieldLt(): void
    {
        $condition = ['type' => 'field', 'field' => 'entity.score', 'operator' => 'lt', 'value' => 50];
        $context = ['entity' => ['score' => 25]];
        $this->assertTrue($this->evaluator->evaluate($condition, $context));
    }

    public function testFieldLtFalse(): void
    {
        $condition = ['type' => 'field', 'field' => 'entity.score', 'operator' => 'lt', 'value' => 50];
        $context = ['entity' => ['score' => 50]];
        $this->assertFalse($this->evaluator->evaluate($condition, $context));
    }

    // ── FieldCondition: lte ──

    public function testFieldLte(): void
    {
        $condition = ['type' => 'field', 'field' => 'entity.score', 'operator' => 'lte', 'value' => 50];
        $context = ['entity' => ['score' => 50]];
        $this->assertTrue($this->evaluator->evaluate($condition, $context));
    }

    public function testFieldLteFalse(): void
    {
        $condition = ['type' => 'field', 'field' => 'entity.score', 'operator' => 'lte', 'value' => 50];
        $context = ['entity' => ['score' => 51]];
        $this->assertFalse($this->evaluator->evaluate($condition, $context));
    }

    // ── FieldCondition: in ──

    public function testFieldIn(): void
    {
        $condition = ['type' => 'field', 'field' => 'entity.status', 'operator' => 'in', 'value' => ['active', 'pending']];
        $context = ['entity' => ['status' => 'active']];
        $this->assertTrue($this->evaluator->evaluate($condition, $context));
    }

    public function testFieldInFalse(): void
    {
        $condition = ['type' => 'field', 'field' => 'entity.status', 'operator' => 'in', 'value' => ['active', 'pending']];
        $context = ['entity' => ['status' => 'deleted']];
        $this->assertFalse($this->evaluator->evaluate($condition, $context));
    }

    // ── FieldCondition: not_in ──

    public function testFieldNotIn(): void
    {
        $condition = ['type' => 'field', 'field' => 'entity.status', 'operator' => 'not_in', 'value' => ['deleted', 'archived']];
        $context = ['entity' => ['status' => 'active']];
        $this->assertTrue($this->evaluator->evaluate($condition, $context));
    }

    public function testFieldNotInFalse(): void
    {
        $condition = ['type' => 'field', 'field' => 'entity.status', 'operator' => 'not_in', 'value' => ['deleted', 'archived']];
        $context = ['entity' => ['status' => 'deleted']];
        $this->assertFalse($this->evaluator->evaluate($condition, $context));
    }

    // ── FieldCondition: is_set ──

    public function testFieldIsSet(): void
    {
        $condition = ['type' => 'field', 'field' => 'entity.name', 'operator' => 'is_set'];
        $context = ['entity' => ['name' => 'Test']];
        $this->assertTrue($this->evaluator->evaluate($condition, $context));
    }

    public function testFieldIsSetFalse(): void
    {
        $condition = ['type' => 'field', 'field' => 'entity.name', 'operator' => 'is_set'];
        $context = ['entity' => []];
        $this->assertFalse($this->evaluator->evaluate($condition, $context));
    }

    // ── FieldCondition: is_empty ──

    public function testFieldIsEmpty(): void
    {
        $condition = ['type' => 'field', 'field' => 'entity.name', 'operator' => 'is_empty'];
        $context = ['entity' => ['name' => '']];
        $this->assertTrue($this->evaluator->evaluate($condition, $context));
    }

    public function testFieldIsEmptyNull(): void
    {
        $condition = ['type' => 'field', 'field' => 'entity.missing', 'operator' => 'is_empty'];
        $context = ['entity' => []];
        $this->assertTrue($this->evaluator->evaluate($condition, $context));
    }

    public function testFieldIsEmptyFalse(): void
    {
        $condition = ['type' => 'field', 'field' => 'entity.name', 'operator' => 'is_empty'];
        $context = ['entity' => ['name' => 'Test']];
        $this->assertFalse($this->evaluator->evaluate($condition, $context));
    }

    // ── FieldCondition: contains ──

    public function testFieldContains(): void
    {
        $condition = ['type' => 'field', 'field' => 'entity.description', 'operator' => 'contains', 'value' => 'workflow'];
        $context = ['entity' => ['description' => 'This is a workflow engine']];
        $this->assertTrue($this->evaluator->evaluate($condition, $context));
    }

    public function testFieldContainsFalse(): void
    {
        $condition = ['type' => 'field', 'field' => 'entity.description', 'operator' => 'contains', 'value' => 'missing'];
        $context = ['entity' => ['description' => 'This is a test']];
        $this->assertFalse($this->evaluator->evaluate($condition, $context));
    }

    // ── FieldCondition: starts_with ──

    public function testFieldStartsWith(): void
    {
        $condition = ['type' => 'field', 'field' => 'entity.code', 'operator' => 'starts_with', 'value' => 'WF-'];
        $context = ['entity' => ['code' => 'WF-001']];
        $this->assertTrue($this->evaluator->evaluate($condition, $context));
    }

    public function testFieldStartsWithFalse(): void
    {
        $condition = ['type' => 'field', 'field' => 'entity.code', 'operator' => 'starts_with', 'value' => 'WF-'];
        $context = ['entity' => ['code' => 'XX-001']];
        $this->assertFalse($this->evaluator->evaluate($condition, $context));
    }

    // ── FieldCondition: ends_with ──

    public function testFieldEndsWith(): void
    {
        $condition = ['type' => 'field', 'field' => 'entity.email', 'operator' => 'ends_with', 'value' => '@example.com'];
        $context = ['entity' => ['email' => 'user@example.com']];
        $this->assertTrue($this->evaluator->evaluate($condition, $context));
    }

    public function testFieldEndsWithFalse(): void
    {
        $condition = ['type' => 'field', 'field' => 'entity.email', 'operator' => 'ends_with', 'value' => '@example.com'];
        $context = ['entity' => ['email' => 'user@other.org']];
        $this->assertFalse($this->evaluator->evaluate($condition, $context));
    }

    // ── FieldCondition: unknown operator ──

    public function testFieldUnknownOperatorReturnsFalse(): void
    {
        $condition = ['type' => 'field', 'field' => 'entity.status', 'operator' => 'bogus', 'value' => 'x'];
        $context = ['entity' => ['status' => 'x']];
        $this->assertFalse($this->evaluator->evaluate($condition, $context));
    }

    // ── FieldCondition: dot notation resolution ──

    public function testFieldDotNotationDeepPath(): void
    {
        $condition = ['type' => 'field', 'field' => 'entity.address.city', 'operator' => 'eq', 'value' => 'Portland'];
        $context = ['entity' => ['address' => ['city' => 'Portland']]];
        $this->assertTrue($this->evaluator->evaluate($condition, $context));
    }

    public function testFieldDotNotationFallbackToEntity(): void
    {
        // Path "status" without "entity." prefix should fallback to entity key
        $condition = ['type' => 'field', 'field' => 'status', 'operator' => 'eq', 'value' => 'active'];
        $context = ['entity' => ['status' => 'active']];
        $this->assertTrue($this->evaluator->evaluate($condition, $context));
    }

    public function testFieldMissingFieldReturnsFalse(): void
    {
        $condition = ['type' => 'field', 'field' => 'entity.nonexistent', 'operator' => 'eq', 'value' => 'test'];
        $context = ['entity' => ['status' => 'active']];
        $this->assertFalse($this->evaluator->evaluate($condition, $context));
    }

    public function testFieldMissingFieldParamReturnsFalse(): void
    {
        $condition = ['type' => 'field', 'operator' => 'eq', 'value' => 'test'];
        $context = ['entity' => ['status' => 'active']];
        $this->assertFalse($this->evaluator->evaluate($condition, $context));
    }

    public function testFieldBooleanEq(): void
    {
        $condition = ['type' => 'field', 'field' => 'entity.warrantable', 'operator' => 'eq', 'value' => true];
        $context = ['entity' => ['warrantable' => true]];
        $this->assertTrue($this->evaluator->evaluate($condition, $context));
    }

    // ── FieldCondition: default operator is eq ──

    public function testFieldDefaultOperatorIsEq(): void
    {
        $condition = ['type' => 'field', 'field' => 'entity.status', 'value' => 'active'];
        $context = ['entity' => ['status' => 'active']];
        $this->assertTrue($this->evaluator->evaluate($condition, $context));
    }

    // ── RoleCondition ──

    public function testRoleHasRole(): void
    {
        $condition = ['type' => 'role', 'role' => 'admin'];
        $context = ['user' => ['roles' => ['admin', 'editor']]];
        $this->assertTrue($this->evaluator->evaluate($condition, $context));
    }

    public function testRoleMissingRole(): void
    {
        $condition = ['type' => 'role', 'role' => 'admin'];
        $context = ['user' => ['roles' => ['editor']]];
        $this->assertFalse($this->evaluator->evaluate($condition, $context));
    }

    public function testRoleNoRolesArray(): void
    {
        $condition = ['type' => 'role', 'role' => 'admin'];
        $context = ['user' => []];
        $this->assertFalse($this->evaluator->evaluate($condition, $context));
    }

    public function testRoleMissingRoleParam(): void
    {
        $condition = ['type' => 'role'];
        $context = ['user' => ['roles' => ['admin']]];
        $this->assertFalse($this->evaluator->evaluate($condition, $context));
    }

    // ── PermissionCondition ──

    public function testPermissionHasPermission(): void
    {
        $condition = ['type' => 'permission', 'permission' => 'can_approve_warrants'];
        $context = ['user' => ['permissions' => ['can_approve_warrants', 'can_view_reports']]];
        $this->assertTrue($this->evaluator->evaluate($condition, $context));
    }

    public function testPermissionMissingPermission(): void
    {
        $condition = ['type' => 'permission', 'permission' => 'can_approve_warrants'];
        $context = ['user' => ['permissions' => ['can_view_reports']]];
        $this->assertFalse($this->evaluator->evaluate($condition, $context));
    }

    public function testPermissionNoPermissionsArray(): void
    {
        $condition = ['type' => 'permission', 'permission' => 'can_approve_warrants'];
        $context = ['user' => []];
        $this->assertFalse($this->evaluator->evaluate($condition, $context));
    }

    // ── OwnershipCondition ──

    public function testOwnershipCreatorMatch(): void
    {
        $condition = ['type' => 'ownership', 'ownership' => 'creator'];
        $context = ['user' => ['id' => 42], 'entity' => ['created_by' => 42]];
        $this->assertTrue($this->evaluator->evaluate($condition, $context));
    }

    public function testOwnershipCreatorNoMatch(): void
    {
        $condition = ['type' => 'ownership', 'ownership' => 'creator'];
        $context = ['user' => ['id' => 42], 'entity' => ['created_by' => 99]];
        $this->assertFalse($this->evaluator->evaluate($condition, $context));
    }

    public function testOwnershipAssignedTo(): void
    {
        $condition = ['type' => 'ownership', 'ownership' => 'assigned_to'];
        $context = ['user' => ['id' => 42], 'entity' => ['assigned_to' => 42]];
        $this->assertTrue($this->evaluator->evaluate($condition, $context));
    }

    public function testOwnershipMember(): void
    {
        $condition = ['type' => 'ownership', 'ownership' => 'member'];
        $context = ['user' => ['id' => 7], 'entity' => ['member_id' => 7]];
        $this->assertTrue($this->evaluator->evaluate($condition, $context));
    }

    public function testOwnershipCustomField(): void
    {
        $condition = ['type' => 'ownership', 'ownership' => 'reviewer_id'];
        $context = ['user' => ['id' => 10], 'entity' => ['reviewer_id' => 10]];
        $this->assertTrue($this->evaluator->evaluate($condition, $context));
    }

    public function testOwnershipNoUserId(): void
    {
        $condition = ['type' => 'ownership', 'ownership' => 'creator'];
        $context = ['user' => [], 'entity' => ['created_by' => 42]];
        $this->assertFalse($this->evaluator->evaluate($condition, $context));
    }

    // ── TimeCondition: after ──

    public function testTimeAfter(): void
    {
        $condition = ['type' => 'time', 'time' => 'after', 'date_field' => 'entity.expires_on'];
        $context = [
            'entity' => ['expires_on' => '2020-01-01'],
            '_now' => '2025-06-01',
        ];
        $this->assertTrue($this->evaluator->evaluate($condition, $context));
    }

    public function testTimeAfterFalse(): void
    {
        $condition = ['type' => 'time', 'time' => 'after', 'date_field' => 'entity.expires_on'];
        $context = [
            'entity' => ['expires_on' => '2030-01-01'],
            '_now' => '2025-06-01',
        ];
        $this->assertFalse($this->evaluator->evaluate($condition, $context));
    }

    // ── TimeCondition: before ──

    public function testTimeBefore(): void
    {
        $condition = ['type' => 'time', 'time' => 'before', 'value' => '2030-01-01'];
        $context = ['_now' => '2025-06-01'];
        $this->assertTrue($this->evaluator->evaluate($condition, $context));
    }

    public function testTimeBeforeFalse(): void
    {
        $condition = ['type' => 'time', 'time' => 'before', 'value' => '2020-01-01'];
        $context = ['_now' => '2025-06-01'];
        $this->assertFalse($this->evaluator->evaluate($condition, $context));
    }

    // ── TimeCondition: elapsed_days ──

    public function testTimeElapsedDays(): void
    {
        $condition = [
            'type' => 'time',
            'time' => 'elapsed_days',
            'date_field' => 'entity.created',
            'operator' => 'gte',
            'value' => 180,
        ];
        $context = [
            'entity' => ['created' => '2024-01-01'],
            '_now' => '2025-06-01',
        ];
        $this->assertTrue($this->evaluator->evaluate($condition, $context));
    }

    public function testTimeElapsedDaysFalse(): void
    {
        $condition = [
            'type' => 'time',
            'time' => 'elapsed_days',
            'date_field' => 'entity.created',
            'operator' => 'gte',
            'value' => 180,
        ];
        $context = [
            'entity' => ['created' => '2025-05-01'],
            '_now' => '2025-06-01',
        ];
        $this->assertFalse($this->evaluator->evaluate($condition, $context));
    }

    // ── TimeCondition: between ──

    public function testTimeBetween(): void
    {
        $condition = ['type' => 'time', 'time' => 'between', 'start' => '2025-01-01', 'end' => '2025-12-31'];
        $context = ['_now' => '2025-06-15'];
        $this->assertTrue($this->evaluator->evaluate($condition, $context));
    }

    public function testTimeBetweenFalse(): void
    {
        $condition = ['type' => 'time', 'time' => 'between', 'start' => '2025-01-01', 'end' => '2025-12-31'];
        $context = ['_now' => '2026-03-01'];
        $this->assertFalse($this->evaluator->evaluate($condition, $context));
    }

    // ── TimeCondition: business_hours ──

    public function testTimeBusinessHours(): void
    {
        // Wednesday at 10am
        $condition = ['type' => 'time', 'time' => 'business_hours'];
        $context = ['_now' => '2025-06-04 10:00:00']; // Wednesday
        $this->assertTrue($this->evaluator->evaluate($condition, $context));
    }

    public function testTimeBusinessHoursWeekend(): void
    {
        $condition = ['type' => 'time', 'time' => 'business_hours'];
        $context = ['_now' => '2025-06-07 10:00:00']; // Saturday
        $this->assertFalse($this->evaluator->evaluate($condition, $context));
    }

    public function testTimeBusinessHoursLateEvening(): void
    {
        $condition = ['type' => 'time', 'time' => 'business_hours'];
        $context = ['_now' => '2025-06-04 20:00:00']; // Wednesday 8pm
        $this->assertFalse($this->evaluator->evaluate($condition, $context));
    }

    // ── TimeCondition: unknown mode ──

    public function testTimeUnknownMode(): void
    {
        $condition = ['type' => 'time', 'time' => 'invalid_mode'];
        $context = ['_now' => '2025-06-01'];
        $this->assertFalse($this->evaluator->evaluate($condition, $context));
    }

    // ── WorkflowContextCondition ──

    public function testWorkflowContextGte(): void
    {
        $condition = ['type' => 'workflow_context', 'workflow_context' => 'approval_count', 'operator' => 'gte', 'value' => 3];
        $context = ['workflow_context' => ['approval_count' => 5]];
        $this->assertTrue($this->evaluator->evaluate($condition, $context));
    }

    public function testWorkflowContextGteFalse(): void
    {
        $condition = ['type' => 'workflow_context', 'workflow_context' => 'approval_count', 'operator' => 'gte', 'value' => 3];
        $context = ['workflow_context' => ['approval_count' => 2]];
        $this->assertFalse($this->evaluator->evaluate($condition, $context));
    }

    public function testWorkflowContextEq(): void
    {
        $condition = ['type' => 'workflow_context', 'workflow_context' => 'stage', 'operator' => 'eq', 'value' => 'review'];
        $context = ['workflow_context' => ['stage' => 'review']];
        $this->assertTrue($this->evaluator->evaluate($condition, $context));
    }

    public function testWorkflowContextIsSet(): void
    {
        $condition = ['type' => 'workflow_context', 'workflow_context' => 'reviewer_id', 'operator' => 'is_set'];
        $context = ['workflow_context' => ['reviewer_id' => 42]];
        $this->assertTrue($this->evaluator->evaluate($condition, $context));
    }

    public function testWorkflowContextMissingKey(): void
    {
        $condition = ['type' => 'workflow_context', 'workflow_context' => 'nonexistent', 'operator' => 'eq', 'value' => 'x'];
        $context = ['workflow_context' => []];
        $this->assertFalse($this->evaluator->evaluate($condition, $context));
    }

    // ── ApprovalGateCondition ──

    public function testApprovalGateSatisfiedSpecificGate(): void
    {
        $condition = ['type' => 'approval_gate', 'approval_gate' => 'satisfied', 'gate_id' => 5];
        $context = ['approval_gates' => [5 => ['status' => 'satisfied']]];
        $this->assertTrue($this->evaluator->evaluate($condition, $context));
    }

    public function testApprovalGateSatisfiedSpecificGateFalse(): void
    {
        $condition = ['type' => 'approval_gate', 'approval_gate' => 'satisfied', 'gate_id' => 5];
        $context = ['approval_gates' => [5 => ['status' => 'pending']]];
        $this->assertFalse($this->evaluator->evaluate($condition, $context));
    }

    public function testApprovalGateSatisfiedAllGates(): void
    {
        $condition = ['type' => 'approval_gate', 'approval_gate' => 'satisfied'];
        $context = ['approval_gates' => [
            1 => ['status' => 'satisfied'],
            2 => ['status' => 'satisfied'],
        ]];
        $this->assertTrue($this->evaluator->evaluate($condition, $context));
    }

    public function testApprovalGateSatisfiedAllGatesOnePending(): void
    {
        $condition = ['type' => 'approval_gate', 'approval_gate' => 'satisfied'];
        $context = ['approval_gates' => [
            1 => ['status' => 'satisfied'],
            2 => ['status' => 'pending'],
        ]];
        $this->assertFalse($this->evaluator->evaluate($condition, $context));
    }

    public function testApprovalGatePendingSpecificGate(): void
    {
        $condition = ['type' => 'approval_gate', 'approval_gate' => 'pending', 'gate_id' => 5];
        $context = ['approval_gates' => [5 => ['status' => 'pending']]];
        $this->assertTrue($this->evaluator->evaluate($condition, $context));
    }

    public function testApprovalGatePendingAnyGate(): void
    {
        $condition = ['type' => 'approval_gate', 'approval_gate' => 'pending'];
        $context = ['approval_gates' => [
            1 => ['status' => 'satisfied'],
            2 => ['status' => 'pending'],
        ]];
        $this->assertTrue($this->evaluator->evaluate($condition, $context));
    }

    public function testApprovalGateRejected(): void
    {
        $condition = ['type' => 'approval_gate', 'approval_gate' => 'rejected', 'gate_id' => 3];
        $context = ['approval_gates' => [3 => ['status' => 'rejected']]];
        $this->assertTrue($this->evaluator->evaluate($condition, $context));
    }

    public function testApprovalGateEmptyGates(): void
    {
        $condition = ['type' => 'approval_gate', 'approval_gate' => 'satisfied'];
        $context = ['approval_gates' => []];
        $this->assertFalse($this->evaluator->evaluate($condition, $context));
    }

    // ── Combinators: all (AND) ──

    public function testAllCombinatorAllTrue(): void
    {
        $condition = [
            'all' => [
                ['type' => 'role', 'role' => 'admin'],
                ['type' => 'field', 'field' => 'entity.status', 'operator' => 'eq', 'value' => 'active'],
            ],
        ];
        $context = [
            'user' => ['roles' => ['admin']],
            'entity' => ['status' => 'active'],
        ];
        $this->assertTrue($this->evaluator->evaluate($condition, $context));
    }

    public function testAllCombinatorOneFalse(): void
    {
        $condition = [
            'all' => [
                ['type' => 'role', 'role' => 'admin'],
                ['type' => 'field', 'field' => 'entity.status', 'operator' => 'eq', 'value' => 'active'],
            ],
        ];
        $context = [
            'user' => ['roles' => ['editor']],
            'entity' => ['status' => 'active'],
        ];
        $this->assertFalse($this->evaluator->evaluate($condition, $context));
    }

    // ── Combinators: any (OR) ──

    public function testAnyCombinatorOneTrue(): void
    {
        $condition = [
            'any' => [
                ['type' => 'role', 'role' => 'admin'],
                ['type' => 'role', 'role' => 'editor'],
            ],
        ];
        $context = ['user' => ['roles' => ['editor']]];
        $this->assertTrue($this->evaluator->evaluate($condition, $context));
    }

    public function testAnyCombinatorAllFalse(): void
    {
        $condition = [
            'any' => [
                ['type' => 'role', 'role' => 'admin'],
                ['type' => 'role', 'role' => 'editor'],
            ],
        ];
        $context = ['user' => ['roles' => ['viewer']]];
        $this->assertFalse($this->evaluator->evaluate($condition, $context));
    }

    // ── Combinators: not ──

    public function testNotCombinator(): void
    {
        $condition = [
            'not' => ['type' => 'role', 'role' => 'banned'],
        ];
        $context = ['user' => ['roles' => ['admin']]];
        $this->assertTrue($this->evaluator->evaluate($condition, $context));
    }

    public function testNotCombinatorInverts(): void
    {
        $condition = [
            'not' => ['type' => 'role', 'role' => 'admin'],
        ];
        $context = ['user' => ['roles' => ['admin']]];
        $this->assertFalse($this->evaluator->evaluate($condition, $context));
    }

    // ── Nested Combinators ──

    public function testNestedCombinators2Levels(): void
    {
        // (admin OR editor) AND entity.status == active
        $condition = [
            'all' => [
                [
                    'any' => [
                        ['type' => 'role', 'role' => 'admin'],
                        ['type' => 'role', 'role' => 'editor'],
                    ],
                ],
                ['type' => 'field', 'field' => 'entity.status', 'operator' => 'eq', 'value' => 'active'],
            ],
        ];
        $context = [
            'user' => ['roles' => ['editor']],
            'entity' => ['status' => 'active'],
        ];
        $this->assertTrue($this->evaluator->evaluate($condition, $context));
    }

    public function testNestedCombinators3Levels(): void
    {
        // NOT (role==banned AND entity.status==deleted)
        $condition = [
            'not' => [
                'all' => [
                    ['type' => 'role', 'role' => 'banned'],
                    ['type' => 'field', 'field' => 'entity.status', 'operator' => 'eq', 'value' => 'deleted'],
                ],
            ],
        ];
        $context = [
            'user' => ['roles' => ['admin']],
            'entity' => ['status' => 'active'],
        ];
        $this->assertTrue($this->evaluator->evaluate($condition, $context));
    }

    public function testNestedCombinators3LevelsFalse(): void
    {
        // NOT (role==banned AND entity.status==deleted) → should be false when both match
        $condition = [
            'not' => [
                'all' => [
                    ['type' => 'role', 'role' => 'banned'],
                    ['type' => 'field', 'field' => 'entity.status', 'operator' => 'eq', 'value' => 'deleted'],
                ],
            ],
        ];
        $context = [
            'user' => ['roles' => ['banned']],
            'entity' => ['status' => 'deleted'],
        ];
        $this->assertFalse($this->evaluator->evaluate($condition, $context));
    }

    public function testDeeplyNestedAnyAllNot(): void
    {
        // any[ all[role=admin, perm=approve], not[field.status=locked] ]
        $condition = [
            'any' => [
                [
                    'all' => [
                        ['type' => 'role', 'role' => 'admin'],
                        ['type' => 'permission', 'permission' => 'can_approve'],
                    ],
                ],
                [
                    'not' => ['type' => 'field', 'field' => 'entity.status', 'operator' => 'eq', 'value' => 'locked'],
                ],
            ],
        ];
        // User is not admin, but status is not locked → second branch true
        $context = [
            'user' => ['roles' => ['viewer'], 'permissions' => []],
            'entity' => ['status' => 'active'],
        ];
        $this->assertTrue($this->evaluator->evaluate($condition, $context));
    }

    // ── Unknown type → false ──

    public function testUnknownTypeReturnsFalse(): void
    {
        $condition = ['type' => 'nonexistent_type', 'value' => 'x'];
        $this->assertFalse($this->evaluator->evaluate($condition, []));
    }

    public function testNoTypeNoDetectableKeyReturnsFalse(): void
    {
        $condition = ['foo' => 'bar'];
        $this->assertFalse($this->evaluator->evaluate($condition, []));
    }

    // ── Plugin registration of custom condition ──

    public function testCustomConditionRegistration(): void
    {
        $custom = new class implements ConditionInterface {
            public function evaluate(array $params, array $context): bool
            {
                return ($context['custom_value'] ?? null) === ($params['expected'] ?? null);
            }

            public function getName(): string
            {
                return 'custom';
            }

            public function getDescription(): string
            {
                return 'Custom test condition';
            }

            public function getParameterSchema(): array
            {
                return [];
            }
        };

        $this->evaluator->registerConditionType('custom', $custom);
        $this->assertContains('custom', $this->evaluator->getRegisteredTypes());

        $condition = ['type' => 'custom', 'expected' => 'yes'];
        $context = ['custom_value' => 'yes'];
        $this->assertTrue($this->evaluator->evaluate($condition, $context));
    }

    public function testCustomConditionRegistrationFalse(): void
    {
        $custom = new class implements ConditionInterface {
            public function evaluate(array $params, array $context): bool
            {
                return ($context['custom_value'] ?? null) === ($params['expected'] ?? null);
            }

            public function getName(): string
            {
                return 'custom';
            }

            public function getDescription(): string
            {
                return 'Custom test condition';
            }

            public function getParameterSchema(): array
            {
                return [];
            }
        };

        $this->evaluator->registerConditionType('custom', $custom);

        $condition = ['type' => 'custom', 'expected' => 'yes'];
        $context = ['custom_value' => 'no'];
        $this->assertFalse($this->evaluator->evaluate($condition, $context));
    }

    // ── Auto-detection of type from keys ──

    public function testAutoDetectFieldType(): void
    {
        $condition = ['field' => 'entity.status', 'operator' => 'eq', 'value' => 'active'];
        $context = ['entity' => ['status' => 'active']];
        $this->assertTrue($this->evaluator->evaluate($condition, $context));
    }

    public function testAutoDetectRoleType(): void
    {
        $condition = ['role' => 'admin'];
        $context = ['user' => ['roles' => ['admin']]];
        $this->assertTrue($this->evaluator->evaluate($condition, $context));
    }

    public function testAutoDetectPermissionType(): void
    {
        $condition = ['permission' => 'can_edit'];
        $context = ['user' => ['permissions' => ['can_edit']]];
        $this->assertTrue($this->evaluator->evaluate($condition, $context));
    }

    public function testAutoDetectOwnershipType(): void
    {
        $condition = ['ownership' => 'creator'];
        $context = ['user' => ['id' => 1], 'entity' => ['created_by' => 1]];
        $this->assertTrue($this->evaluator->evaluate($condition, $context));
    }

    public function testAutoDetectTimeType(): void
    {
        $condition = ['time' => 'before', 'value' => '2030-01-01'];
        $context = ['_now' => '2025-06-01'];
        $this->assertTrue($this->evaluator->evaluate($condition, $context));
    }

    public function testAutoDetectWorkflowContextType(): void
    {
        $condition = ['workflow_context' => 'count', 'operator' => 'eq', 'value' => 5];
        $context = ['workflow_context' => ['count' => 5]];
        $this->assertTrue($this->evaluator->evaluate($condition, $context));
    }

    public function testAutoDetectApprovalGateType(): void
    {
        $condition = ['approval_gate' => 'satisfied'];
        $context = ['approval_gates' => [1 => ['status' => 'satisfied']]];
        $this->assertTrue($this->evaluator->evaluate($condition, $context));
    }

    // ── Registered types list ──

    public function testGetRegisteredTypesIncludesBuiltIns(): void
    {
        $types = $this->evaluator->getRegisteredTypes();
        $expected = ['field', 'role', 'permission', 'ownership', 'time', 'workflow_context', 'approval_gate'];
        foreach ($expected as $type) {
            $this->assertContains($type, $types);
        }
    }

    // ── Individual condition metadata ──

    public function testFieldConditionMetadata(): void
    {
        $c = new FieldCondition();
        $this->assertSame('field', $c->getName());
        $this->assertNotEmpty($c->getDescription());
        $this->assertArrayHasKey('field', $c->getParameterSchema());
    }

    public function testRoleConditionMetadata(): void
    {
        $c = new RoleCondition();
        $this->assertSame('role', $c->getName());
        $this->assertNotEmpty($c->getDescription());
        $this->assertArrayHasKey('role', $c->getParameterSchema());
    }

    public function testPermissionConditionMetadata(): void
    {
        $c = new PermissionCondition();
        $this->assertSame('permission', $c->getName());
        $this->assertNotEmpty($c->getDescription());
        $this->assertArrayHasKey('permission', $c->getParameterSchema());
    }

    public function testOwnershipConditionMetadata(): void
    {
        $c = new OwnershipCondition();
        $this->assertSame('ownership', $c->getName());
        $this->assertNotEmpty($c->getDescription());
        $this->assertArrayHasKey('ownership', $c->getParameterSchema());
    }

    public function testTimeConditionMetadata(): void
    {
        $c = new TimeCondition();
        $this->assertSame('time', $c->getName());
        $this->assertNotEmpty($c->getDescription());
        $this->assertArrayHasKey('time', $c->getParameterSchema());
    }

    public function testWorkflowContextConditionMetadata(): void
    {
        $c = new WorkflowContextCondition();
        $this->assertSame('workflow_context', $c->getName());
        $this->assertNotEmpty($c->getDescription());
        $this->assertArrayHasKey('workflow_context', $c->getParameterSchema());
    }

    public function testApprovalGateConditionMetadata(): void
    {
        $c = new ApprovalGateCondition();
        $this->assertSame('approval_gate', $c->getName());
        $this->assertNotEmpty($c->getDescription());
        $this->assertArrayHasKey('approval_gate', $c->getParameterSchema());
    }

    // ── Field is_empty with empty array ──

    public function testFieldIsEmptyArray(): void
    {
        $condition = ['type' => 'field', 'field' => 'entity.tags', 'operator' => 'is_empty'];
        $context = ['entity' => ['tags' => []]];
        $this->assertTrue($this->evaluator->evaluate($condition, $context));
    }
}
