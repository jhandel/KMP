<?php

declare(strict_types=1);

namespace App\Test\TestCase\Services\WorkflowEngine;

use App\Services\WorkflowEngine\Conditions\ConditionInterface;
use App\Services\WorkflowEngine\DefaultRuleEvaluator;
use Cake\TestSuite\TestCase;

/**
 * Tests for DefaultRuleEvaluator condition evaluation.
 *
 * Covers boolean combinators, all built-in condition types, and custom registration.
 */
class DefaultRuleEvaluatorTest extends TestCase
{
    protected DefaultRuleEvaluator $evaluator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->evaluator = new DefaultRuleEvaluator();
    }

    // ==================================================
    // BOOLEAN COMBINATORS
    // ==================================================

    public function testAllConditionsPass(): void
    {
        $condition = ['all' => [
            ['permission' => 'test.perm1'],
            ['permission' => 'test.perm2'],
        ]];
        $context = ['user_permissions' => ['test.perm1', 'test.perm2']];
        $this->assertTrue($this->evaluator->evaluate($condition, $context));
    }

    public function testAllConditionsFailsWhenOneFails(): void
    {
        $condition = ['all' => [
            ['permission' => 'test.perm1'],
            ['permission' => 'test.missing'],
        ]];
        $context = ['user_permissions' => ['test.perm1']];
        $this->assertFalse($this->evaluator->evaluate($condition, $context));
    }

    public function testAllEmptyIsVacuouslyTrue(): void
    {
        $this->assertTrue($this->evaluator->evaluate(['all' => []], []));
    }

    public function testAnyConditionPasses(): void
    {
        $condition = ['any' => [
            ['permission' => 'test.missing'],
            ['role' => 'Admin'],
        ]];
        $context = ['user_permissions' => [], 'user_roles' => ['Admin']];
        $this->assertTrue($this->evaluator->evaluate($condition, $context));
    }

    public function testAnyConditionFailsWhenAllFail(): void
    {
        $condition = ['any' => [
            ['permission' => 'test.missing'],
            ['role' => 'Admin'],
        ]];
        $context = ['user_permissions' => [], 'user_roles' => ['User']];
        $this->assertFalse($this->evaluator->evaluate($condition, $context));
    }

    public function testAnyEmptyReturnsFalse(): void
    {
        $this->assertFalse($this->evaluator->evaluate(['any' => []], []));
    }

    public function testNotInvertsTrue(): void
    {
        $condition = ['not' => ['permission' => 'test.perm1']];
        $context = ['user_permissions' => ['test.perm1']];
        $this->assertFalse($this->evaluator->evaluate($condition, $context));
    }

    public function testNotInvertsFalse(): void
    {
        $condition = ['not' => ['permission' => 'test.missing']];
        $context = ['user_permissions' => []];
        $this->assertTrue($this->evaluator->evaluate($condition, $context));
    }

    public function testNestedCombinators(): void
    {
        $condition = ['all' => [
            ['permission' => 'test.perm1'],
            ['any' => [
                ['role' => 'Crown'],
                ['role' => 'Steward'],
            ]],
        ]];
        $context = ['user_permissions' => ['test.perm1'], 'user_roles' => ['Steward']];
        $this->assertTrue($this->evaluator->evaluate($condition, $context));
    }

    public function testNestedCombinatorsFailsWhenInnerFails(): void
    {
        $condition = ['all' => [
            ['permission' => 'test.perm1'],
            ['any' => [
                ['role' => 'Crown'],
                ['role' => 'Steward'],
            ]],
        ]];
        $context = ['user_permissions' => ['test.perm1'], 'user_roles' => ['Seneschal']];
        $this->assertFalse($this->evaluator->evaluate($condition, $context));
    }

    public function testDeeplyNestedCombinators(): void
    {
        $condition = ['any' => [
            ['all' => [
                ['permission' => 'test.perm1'],
                ['not' => ['role' => 'Banned']],
            ]],
            ['role' => 'SuperAdmin'],
        ]];
        $context = ['user_permissions' => ['test.perm1'], 'user_roles' => []];
        $this->assertTrue($this->evaluator->evaluate($condition, $context));
    }

    // ==================================================
    // PERMISSION CONDITION
    // ==================================================

    public function testPermissionConditionPasses(): void
    {
        $condition = ['permission' => 'Awards.canApprove'];
        $context = ['user_permissions' => ['Awards.canApprove', 'Members.view']];
        $this->assertTrue($this->evaluator->evaluate($condition, $context));
    }

    public function testPermissionConditionFails(): void
    {
        $condition = ['permission' => 'Awards.canApprove'];
        $context = ['user_permissions' => ['Members.view']];
        $this->assertFalse($this->evaluator->evaluate($condition, $context));
    }

    public function testPermissionWithEmptyPermissions(): void
    {
        $condition = ['permission' => 'Awards.canApprove'];
        $context = ['user_permissions' => []];
        $this->assertFalse($this->evaluator->evaluate($condition, $context));
    }

    public function testPermissionWithMissingPermissionsKey(): void
    {
        $condition = ['permission' => 'Awards.canApprove'];
        $this->assertFalse($this->evaluator->evaluate($condition, []));
    }

    // ==================================================
    // ROLE CONDITION
    // ==================================================

    public function testRoleConditionPasses(): void
    {
        $condition = ['role' => 'Crown'];
        $context = ['user_roles' => ['Crown', 'Seneschal']];
        $this->assertTrue($this->evaluator->evaluate($condition, $context));
    }

    public function testRoleConditionFails(): void
    {
        $condition = ['role' => 'Crown'];
        $context = ['user_roles' => ['Seneschal']];
        $this->assertFalse($this->evaluator->evaluate($condition, $context));
    }

    public function testRoleWithEmptyRoles(): void
    {
        $condition = ['role' => 'Crown'];
        $context = ['user_roles' => []];
        $this->assertFalse($this->evaluator->evaluate($condition, []));
    }

    // ==================================================
    // FIELD CONDITION
    // ==================================================

    public function testFieldConditionEq(): void
    {
        $condition = ['field' => 'entity.status', 'operator' => 'eq', 'value' => 'active'];
        $context = ['entity' => ['status' => 'active']];
        $this->assertTrue($this->evaluator->evaluate($condition, $context));
    }

    public function testFieldConditionEqFails(): void
    {
        $condition = ['field' => 'entity.status', 'operator' => 'eq', 'value' => 'active'];
        $context = ['entity' => ['status' => 'closed']];
        $this->assertFalse($this->evaluator->evaluate($condition, $context));
    }

    public function testFieldConditionNeq(): void
    {
        $condition = ['field' => 'entity.status', 'operator' => 'neq', 'value' => 'closed'];
        $context = ['entity' => ['status' => 'active']];
        $this->assertTrue($this->evaluator->evaluate($condition, $context));
    }

    public function testFieldConditionGt(): void
    {
        $condition = ['field' => 'entity.count', 'operator' => 'gt', 'value' => 5];
        $context = ['entity' => ['count' => 10]];
        $this->assertTrue($this->evaluator->evaluate($condition, $context));
    }

    public function testFieldConditionGte(): void
    {
        $condition = ['field' => 'entity.count', 'operator' => 'gte', 'value' => 10];
        $context = ['entity' => ['count' => 10]];
        $this->assertTrue($this->evaluator->evaluate($condition, $context));
    }

    public function testFieldConditionLt(): void
    {
        $condition = ['field' => 'entity.count', 'operator' => 'lt', 'value' => 10];
        $context = ['entity' => ['count' => 5]];
        $this->assertTrue($this->evaluator->evaluate($condition, $context));
    }

    public function testFieldConditionLte(): void
    {
        $condition = ['field' => 'entity.count', 'operator' => 'lte', 'value' => 10];
        $context = ['entity' => ['count' => 10]];
        $this->assertTrue($this->evaluator->evaluate($condition, $context));
    }

    public function testFieldConditionIn(): void
    {
        $condition = ['field' => 'entity.type', 'operator' => 'in', 'value' => ['A', 'B', 'C']];
        $context = ['entity' => ['type' => 'B']];
        $this->assertTrue($this->evaluator->evaluate($condition, $context));
    }

    public function testFieldConditionInFails(): void
    {
        $condition = ['field' => 'entity.type', 'operator' => 'in', 'value' => ['A', 'B', 'C']];
        $context = ['entity' => ['type' => 'D']];
        $this->assertFalse($this->evaluator->evaluate($condition, $context));
    }

    public function testFieldConditionNotIn(): void
    {
        $condition = ['field' => 'entity.type', 'operator' => 'not_in', 'value' => ['A', 'B']];
        $context = ['entity' => ['type' => 'C']];
        $this->assertTrue($this->evaluator->evaluate($condition, $context));
    }

    public function testFieldConditionIsSet(): void
    {
        $condition = ['field' => 'entity.name', 'operator' => 'is_set'];
        $context = ['entity' => ['name' => 'John']];
        $this->assertTrue($this->evaluator->evaluate($condition, $context));
    }

    public function testFieldConditionIsSetFailsWhenMissing(): void
    {
        $condition = ['field' => 'entity.name', 'operator' => 'is_set'];
        $context = ['entity' => []];
        $this->assertFalse($this->evaluator->evaluate($condition, $context));
    }

    public function testFieldConditionIsEmpty(): void
    {
        $condition = ['field' => 'entity.name', 'operator' => 'is_empty'];
        $context = ['entity' => ['name' => '']];
        $this->assertTrue($this->evaluator->evaluate($condition, $context));
    }

    public function testFieldConditionIsEmptyWithNull(): void
    {
        $condition = ['field' => 'entity.missing', 'operator' => 'is_empty'];
        $context = ['entity' => []];
        $this->assertTrue($this->evaluator->evaluate($condition, $context));
    }

    public function testFieldConditionIsEmptyWithEmptyArray(): void
    {
        $condition = ['field' => 'entity.tags', 'operator' => 'is_empty'];
        $context = ['entity' => ['tags' => []]];
        $this->assertTrue($this->evaluator->evaluate($condition, $context));
    }

    public function testFieldConditionContains(): void
    {
        $condition = ['field' => 'entity.description', 'operator' => 'contains', 'value' => 'important'];
        $context = ['entity' => ['description' => 'This is an important notice']];
        $this->assertTrue($this->evaluator->evaluate($condition, $context));
    }

    public function testFieldConditionStartsWith(): void
    {
        $condition = ['field' => 'entity.name', 'operator' => 'starts_with', 'value' => 'Sir'];
        $context = ['entity' => ['name' => 'Sir Lancelot']];
        $this->assertTrue($this->evaluator->evaluate($condition, $context));
    }

    public function testFieldConditionEndsWith(): void
    {
        $condition = ['field' => 'entity.email', 'operator' => 'ends_with', 'value' => '@example.com'];
        $context = ['entity' => ['email' => 'test@example.com']];
        $this->assertTrue($this->evaluator->evaluate($condition, $context));
    }

    public function testFieldConditionDotNotation(): void
    {
        $condition = ['field' => 'entity.award.level', 'operator' => 'eq', 'value' => 'AoA'];
        $context = ['entity' => ['award' => ['level' => 'AoA']]];
        $this->assertTrue($this->evaluator->evaluate($condition, $context));
    }

    public function testFieldConditionFallbackToEntityPrefix(): void
    {
        // FieldCondition tries direct path first, then prefixes with "entity."
        $condition = ['field' => 'status', 'operator' => 'eq', 'value' => 'active'];
        $context = ['entity' => ['status' => 'active']];
        $this->assertTrue($this->evaluator->evaluate($condition, $context));
    }

    public function testFieldConditionMissingFieldReturnsFalse(): void
    {
        $condition = ['field' => null, 'operator' => 'eq', 'value' => 'test'];
        $this->assertFalse($this->evaluator->evaluate($condition, []));
    }

    public function testFieldConditionUnknownOperatorReturnsFalse(): void
    {
        $condition = ['field' => 'entity.status', 'operator' => 'invalid_op', 'value' => 'test'];
        $context = ['entity' => ['status' => 'active']];
        $this->assertFalse($this->evaluator->evaluate($condition, $context));
    }

    // ==================================================
    // OWNERSHIP CONDITION
    // ==================================================

    public function testOwnershipRequester(): void
    {
        $condition = ['ownership' => 'requester'];
        $context = ['user_id' => 42, 'entity' => ['requester_id' => 42]];
        $this->assertTrue($this->evaluator->evaluate($condition, $context));
    }

    public function testOwnershipRequesterViaCreatedBy(): void
    {
        $condition = ['ownership' => 'requester'];
        $context = ['user_id' => 42, 'entity' => ['created_by' => 42]];
        $this->assertTrue($this->evaluator->evaluate($condition, $context));
    }

    public function testOwnershipRequesterFails(): void
    {
        $condition = ['ownership' => 'requester'];
        $context = ['user_id' => 42, 'entity' => ['requester_id' => 99]];
        $this->assertFalse($this->evaluator->evaluate($condition, $context));
    }

    public function testOwnershipRecipient(): void
    {
        $condition = ['ownership' => 'recipient'];
        $context = ['user_id' => 42, 'entity' => ['member_id' => 42]];
        $this->assertTrue($this->evaluator->evaluate($condition, $context));
    }

    public function testOwnershipRecipientFails(): void
    {
        $condition = ['ownership' => 'recipient'];
        $context = ['user_id' => 42, 'entity' => ['member_id' => 99]];
        $this->assertFalse($this->evaluator->evaluate($condition, $context));
    }

    public function testOwnershipParentOfMinor(): void
    {
        $condition = ['ownership' => 'parent_of_minor'];
        $context = [
            'user_id' => 10,
            'user_managed_member_ids' => [42, 43],
            'entity' => ['member_id' => 42],
        ];
        $this->assertTrue($this->evaluator->evaluate($condition, $context));
    }

    public function testOwnershipParentOfMinorFailsWhenNotManaged(): void
    {
        $condition = ['ownership' => 'parent_of_minor'];
        $context = [
            'user_id' => 10,
            'user_managed_member_ids' => [43, 44],
            'entity' => ['member_id' => 42],
        ];
        $this->assertFalse($this->evaluator->evaluate($condition, $context));
    }

    public function testOwnershipAny(): void
    {
        $condition = ['ownership' => 'any'];
        $context = ['user_id' => 42, 'entity' => ['member_id' => 42]];
        $this->assertTrue($this->evaluator->evaluate($condition, $context));
    }

    public function testOwnershipWithNoUserId(): void
    {
        $condition = ['ownership' => 'requester'];
        $context = ['entity' => ['requester_id' => 42]];
        $this->assertFalse($this->evaluator->evaluate($condition, $context));
    }

    public function testOwnershipUnknownTypeReturnsFalse(): void
    {
        $condition = ['ownership' => 'unknown_type'];
        $context = ['user_id' => 42, 'entity' => ['requester_id' => 42]];
        $this->assertFalse($this->evaluator->evaluate($condition, $context));
    }

    // ==================================================
    // WORKFLOW CONTEXT CONDITION
    // ==================================================

    public function testWorkflowContextConditionEq(): void
    {
        $condition = ['workflow_context' => 'approval_count', 'operator' => 'eq', 'value' => 3];
        $context = ['instance' => ['context' => ['approval_count' => 3]]];
        $this->assertTrue($this->evaluator->evaluate($condition, $context));
    }

    public function testWorkflowContextConditionGte(): void
    {
        $condition = ['workflow_context' => 'approval_count', 'operator' => 'gte', 'value' => 2];
        $context = ['instance' => ['context' => ['approval_count' => 3]]];
        $this->assertTrue($this->evaluator->evaluate($condition, $context));
    }

    public function testWorkflowContextConditionFailsWhenKeyMissing(): void
    {
        $condition = ['workflow_context' => 'missing_key', 'operator' => 'eq', 'value' => 1];
        $context = ['instance' => ['context' => []]];
        $this->assertFalse($this->evaluator->evaluate($condition, $context));
    }

    public function testWorkflowContextConditionIn(): void
    {
        $condition = ['workflow_context' => 'status', 'operator' => 'in', 'value' => ['pending', 'active']];
        $context = ['instance' => ['context' => ['status' => 'active']]];
        $this->assertTrue($this->evaluator->evaluate($condition, $context));
    }

    // ==================================================
    // APPROVAL GATE CONDITION
    // ==================================================

    public function testApprovalGateMet(): void
    {
        $condition = ['approval_gate' => 'final_review', 'status' => 'met'];
        $context = ['approval_gates' => ['final_review' => ['is_met' => true]]];
        $this->assertTrue($this->evaluator->evaluate($condition, $context));
    }

    public function testApprovalGateNotMet(): void
    {
        $condition = ['approval_gate' => 'final_review', 'status' => 'not_met'];
        $context = ['approval_gates' => ['final_review' => ['is_met' => false]]];
        $this->assertTrue($this->evaluator->evaluate($condition, $context));
    }

    public function testApprovalGateMetFailsWhenNotMet(): void
    {
        $condition = ['approval_gate' => 'final_review', 'status' => 'met'];
        $context = ['approval_gates' => ['final_review' => ['is_met' => false]]];
        $this->assertFalse($this->evaluator->evaluate($condition, $context));
    }

    public function testApprovalGateMissingGateReturnsFalse(): void
    {
        $condition = ['approval_gate' => 'nonexistent', 'status' => 'met'];
        $context = ['approval_gates' => []];
        $this->assertFalse($this->evaluator->evaluate($condition, $context));
    }

    // ==================================================
    // TIME CONDITION
    // ==================================================

    public function testTimeStateDurationGt(): void
    {
        // Set state_entered_at to 2 hours ago
        $twoHoursAgo = new \DateTime('-2 hours');
        $condition = ['time' => 'state_duration', 'operator' => 'gt', 'value' => 1, 'unit' => 'hours'];
        $context = ['state_entered_at' => $twoHoursAgo];
        $this->assertTrue($this->evaluator->evaluate($condition, $context));
    }

    public function testTimeStateDurationLt(): void
    {
        // Set state_entered_at to 30 seconds ago
        $justNow = new \DateTime('-30 seconds');
        $condition = ['time' => 'state_duration', 'operator' => 'lt', 'value' => 1, 'unit' => 'hours'];
        $context = ['state_entered_at' => $justNow];
        $this->assertTrue($this->evaluator->evaluate($condition, $context));
    }

    public function testTimeStateDurationWithNoEnteredAt(): void
    {
        $condition = ['time' => 'state_duration', 'operator' => 'gt', 'value' => 1, 'unit' => 'hours'];
        $this->assertFalse($this->evaluator->evaluate($condition, []));
    }

    public function testTimeUnknownTypeReturnsFalse(): void
    {
        $condition = ['time' => 'unknown_type', 'operator' => 'gt', 'value' => 1];
        $this->assertFalse($this->evaluator->evaluate($condition, []));
    }

    // ==================================================
    // EDGE CASES
    // ==================================================

    public function testEmptyConditionReturnsFalse(): void
    {
        $this->assertFalse($this->evaluator->evaluate([], []));
    }

    public function testUnknownConditionKeyReturnsFalse(): void
    {
        $condition = ['unknown_key' => 'value'];
        $this->assertFalse($this->evaluator->evaluate($condition, []));
    }

    public function testTypePropertyDetectsCustomType(): void
    {
        // When 'type' is set, it uses that key to find the condition handler
        $condition = ['type' => 'permission', 'permission' => 'test.perm'];
        $context = ['user_permissions' => ['test.perm']];
        $this->assertTrue($this->evaluator->evaluate($condition, $context));
    }

    // ==================================================
    // CUSTOM CONDITION REGISTRATION
    // ==================================================

    public function testRegisterCustomCondition(): void
    {
        $customCondition = new class implements ConditionInterface {
            public function evaluate(array $params, array $context): bool
            {
                return ($context['custom_flag'] ?? false) === true;
            }

            public function getName(): string
            {
                return 'custom';
            }

            public function getDescription(): string
            {
                return 'Custom condition';
            }

            public function getParameterSchema(): array
            {
                return [];
            }
        };

        $this->evaluator->registerConditionType('custom', $customCondition);
        $this->assertContains('custom', $this->evaluator->getRegisteredConditionTypes());
    }

    public function testCustomConditionEvaluates(): void
    {
        $customCondition = new class implements ConditionInterface {
            public function evaluate(array $params, array $context): bool
            {
                return ($context['custom_flag'] ?? false) === true;
            }

            public function getName(): string
            {
                return 'custom_eval';
            }

            public function getDescription(): string
            {
                return 'Custom eval condition';
            }

            public function getParameterSchema(): array
            {
                return [];
            }
        };

        $this->evaluator->registerConditionType('custom_eval', $customCondition);
        $this->assertTrue($this->evaluator->evaluate(['type' => 'custom_eval'], ['custom_flag' => true]));
        $this->assertFalse($this->evaluator->evaluate(['type' => 'custom_eval'], ['custom_flag' => false]));
    }

    public function testGetRegisteredConditionTypesIncludesBuiltIns(): void
    {
        $types = $this->evaluator->getRegisteredConditionTypes();
        $this->assertContains('permission', $types);
        $this->assertContains('role', $types);
        $this->assertContains('field', $types);
        $this->assertContains('ownership', $types);
        $this->assertContains('approval_gate', $types);
        $this->assertContains('time', $types);
        $this->assertContains('workflow_context', $types);
    }
}
