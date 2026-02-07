<?php

declare(strict_types=1);

namespace App\Test\TestCase\Services\WorkflowEngine;

use App\Services\WorkflowEngine\RuleEvaluator;
use App\Services\WorkflowEngine\VisibilityEvaluator;
use Cake\TestSuite\TestCase;

class VisibilityEvaluatorTest extends TestCase
{
    private VisibilityEvaluator $evaluator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->evaluator = new VisibilityEvaluator(new RuleEvaluator());
    }

    public function testCanInstantiateWithRuleEvaluator(): void
    {
        $ruleEvaluator = new RuleEvaluator();
        $evaluator = new VisibilityEvaluator($ruleEvaluator);
        $this->assertInstanceOf(VisibilityEvaluator::class, $evaluator);
    }

    public function testCanInstantiateWithoutRuleEvaluator(): void
    {
        $evaluator = new VisibilityEvaluator();
        $this->assertInstanceOf(VisibilityEvaluator::class, $evaluator);
    }

    public function testGetVisibilityProfileReturnsCorrectStructure(): void
    {
        // Without a DB table, evaluateRule/getFieldsForRule will catch the
        // exception and return defaults (fail-open).
        $profile = $this->evaluator->getVisibilityProfile(1, ['user_id' => 42]);

        $this->assertArrayHasKey('can_view_entity', $profile);
        $this->assertArrayHasKey('can_edit_entity', $profile);
        $this->assertArrayHasKey('visible_fields', $profile);
        $this->assertArrayHasKey('editable_fields', $profile);

        // Defaults when no rules exist (fail-open)
        $this->assertTrue($profile['can_view_entity']);
        $this->assertTrue($profile['can_edit_entity']);
        $this->assertSame(['*'], $profile['visible_fields']);
        $this->assertSame(['*'], $profile['editable_fields']);
    }

    public function testCanViewEntityDefaultsToTrue(): void
    {
        $this->assertTrue($this->evaluator->canViewEntity(999, []));
    }

    public function testCanEditEntityDefaultsToTrue(): void
    {
        $this->assertTrue($this->evaluator->canEditEntity(999, []));
    }

    public function testGetVisibleFieldsDefaultsToWildcard(): void
    {
        $this->assertSame(['*'], $this->evaluator->getVisibleFields(999, []));
    }

    public function testGetEditableFieldsDefaultsToWildcard(): void
    {
        $this->assertSame(['*'], $this->evaluator->getEditableFields(999, []));
    }
}
