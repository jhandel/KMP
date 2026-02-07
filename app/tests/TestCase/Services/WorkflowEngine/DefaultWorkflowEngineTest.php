<?php
declare(strict_types=1);

namespace App\Test\TestCase\Services\WorkflowEngine;

use App\Services\WorkflowEngine\DefaultWorkflowEngine;
use App\Services\WorkflowEngine\WorkflowBridge;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

class DefaultWorkflowEngineTest extends TestCase
{
    private DefaultWorkflowEngine $engine;
    private WorkflowBridge $bridge;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bridge = new WorkflowBridge();
        $this->engine = new DefaultWorkflowEngine($this->bridge);
    }

    /**
     * Helper: invoke the private evaluateConditions method via reflection.
     */
    private function evaluateConditions(array $condition, array $context, array $conditionTypes): bool
    {
        $method = new ReflectionMethod($this->engine, 'evaluateConditions');
        $method->setAccessible(true);

        return $method->invoke($this->engine, $condition, $context, $conditionTypes);
    }

    /**
     * Helper: read a private property via reflection.
     */
    private function getPrivateProperty(string $name): mixed
    {
        $prop = new ReflectionProperty($this->engine, $name);
        $prop->setAccessible(true);

        return $prop->getValue($this->engine);
    }

    // ---------------------------------------------------------------
    // 1. Instantiation
    // ---------------------------------------------------------------

    public function testEngineCanBeInstantiated(): void
    {
        $this->assertInstanceOf(DefaultWorkflowEngine::class, $this->engine);
    }

    // ---------------------------------------------------------------
    // 2. registerConditionType
    // ---------------------------------------------------------------

    public function testRegisterConditionTypeStoresCallable(): void
    {
        $fn = fn(array $params, array $ctx): bool => true;
        $this->engine->registerConditionType('my_cond', $fn);

        $types = $this->getPrivateProperty('conditionTypes');
        $this->assertArrayHasKey('my_cond', $types);
        $this->assertSame($fn, $types['my_cond']);
    }

    public function testRegisterMultipleConditionTypes(): void
    {
        $fnA = fn(): bool => true;
        $fnB = fn(): bool => false;

        $this->engine->registerConditionType('cond_a', $fnA);
        $this->engine->registerConditionType('cond_b', $fnB);

        $types = $this->getPrivateProperty('conditionTypes');
        $this->assertCount(2, $types);
        $this->assertSame($fnA, $types['cond_a']);
        $this->assertSame($fnB, $types['cond_b']);
    }

    // ---------------------------------------------------------------
    // 3. registerActionType
    // ---------------------------------------------------------------

    public function testRegisterActionTypeStoresCallable(): void
    {
        $fn = fn(array $params, array $ctx): bool => true;
        $this->engine->registerActionType('my_action', $fn);

        $types = $this->getPrivateProperty('actionTypes');
        $this->assertArrayHasKey('my_action', $types);
        $this->assertSame($fn, $types['my_action']);
    }

    // ---------------------------------------------------------------
    // 4. evaluateConditions – empty / unknown
    // ---------------------------------------------------------------

    public function testEmptyConditionReturnsFalse(): void
    {
        $result = $this->evaluateConditions([], [], []);
        $this->assertFalse($result);
    }

    public function testUnknownConditionTypeReturnsFalse(): void
    {
        $condition = ['type' => 'does_not_exist', 'value' => 42];
        $result = $this->evaluateConditions($condition, [], []);
        $this->assertFalse($result);
    }

    // ---------------------------------------------------------------
    // 5. evaluateConditions – single condition with registered type
    // ---------------------------------------------------------------

    public function testSingleConditionDelegatesToCallable(): void
    {
        $called = false;
        $evaluator = function (array $params, array $ctx) use (&$called): bool {
            $called = true;
            return $params['expected'] === true;
        };

        $condition = ['type' => 'check', 'expected' => true];
        $types = ['check' => $evaluator];

        $result = $this->evaluateConditions($condition, [], $types);
        $this->assertTrue($result);
        $this->assertTrue($called);
    }

    public function testSingleConditionReceivesContext(): void
    {
        $evaluator = fn(array $params, array $ctx): bool => ($ctx['role'] ?? '') === 'admin';

        $condition = ['type' => 'role_check'];
        $types = ['role_check' => $evaluator];

        $this->assertTrue($this->evaluateConditions($condition, ['role' => 'admin'], $types));
        $this->assertFalse($this->evaluateConditions($condition, ['role' => 'user'], $types));
    }

    // ---------------------------------------------------------------
    // 6. all combinator
    // ---------------------------------------------------------------

    public function testAllWithAllTrueReturnsTrue(): void
    {
        $types = ['yes' => fn(): bool => true];
        $condition = [
            'all' => [
                ['type' => 'yes'],
                ['type' => 'yes'],
            ],
        ];
        $this->assertTrue($this->evaluateConditions($condition, [], $types));
    }

    public function testAllWithOneFalseReturnsFalse(): void
    {
        $types = [
            'yes' => fn(): bool => true,
            'no'  => fn(): bool => false,
        ];
        $condition = [
            'all' => [
                ['type' => 'yes'],
                ['type' => 'no'],
            ],
        ];
        $this->assertFalse($this->evaluateConditions($condition, [], $types));
    }

    public function testAllWithEmptyArrayReturnsTrue(): void
    {
        // Vacuous truth: "all" over an empty set is true
        $condition = ['all' => []];
        $this->assertTrue($this->evaluateConditions($condition, [], []));
    }

    // ---------------------------------------------------------------
    // 7. any combinator
    // ---------------------------------------------------------------

    public function testAnyWithOneTrueReturnsTrue(): void
    {
        $types = [
            'yes' => fn(): bool => true,
            'no'  => fn(): bool => false,
        ];
        $condition = [
            'any' => [
                ['type' => 'no'],
                ['type' => 'yes'],
            ],
        ];
        $this->assertTrue($this->evaluateConditions($condition, [], $types));
    }

    public function testAnyWithAllFalseReturnsFalse(): void
    {
        $types = ['no' => fn(): bool => false];
        $condition = [
            'any' => [
                ['type' => 'no'],
                ['type' => 'no'],
            ],
        ];
        $this->assertFalse($this->evaluateConditions($condition, [], $types));
    }

    public function testAnyWithEmptyArrayReturnsFalse(): void
    {
        $condition = ['any' => []];
        $this->assertFalse($this->evaluateConditions($condition, [], []));
    }

    // ---------------------------------------------------------------
    // 8. not combinator
    // ---------------------------------------------------------------

    public function testNotInvertsTrue(): void
    {
        $types = ['yes' => fn(): bool => true];
        $condition = ['not' => ['type' => 'yes']];
        $this->assertFalse($this->evaluateConditions($condition, [], $types));
    }

    public function testNotInvertsFalse(): void
    {
        $types = ['no' => fn(): bool => false];
        $condition = ['not' => ['type' => 'no']];
        $this->assertTrue($this->evaluateConditions($condition, [], $types));
    }

    // ---------------------------------------------------------------
    // 9. nested combinators
    // ---------------------------------------------------------------

    public function testNestedAllInsideAny(): void
    {
        $types = [
            'yes' => fn(): bool => true,
            'no'  => fn(): bool => false,
        ];
        // any([ all([yes, yes]), no ]) → true
        $condition = [
            'any' => [
                [
                    'all' => [
                        ['type' => 'yes'],
                        ['type' => 'yes'],
                    ],
                ],
                ['type' => 'no'],
            ],
        ];
        $this->assertTrue($this->evaluateConditions($condition, [], $types));
    }

    public function testDeepNesting(): void
    {
        $types = [
            'yes' => fn(): bool => true,
            'no'  => fn(): bool => false,
        ];
        // all([ not(no), any([ all([yes, yes]), not(yes) ]) ])
        // = all([ true, any([ true, false ]) ])
        // = all([ true, true ])
        // = true
        $condition = [
            'all' => [
                ['not' => ['type' => 'no']],
                [
                    'any' => [
                        [
                            'all' => [
                                ['type' => 'yes'],
                                ['type' => 'yes'],
                            ],
                        ],
                        ['not' => ['type' => 'yes']],
                    ],
                ],
            ],
        ];
        $this->assertTrue($this->evaluateConditions($condition, [], $types));
    }

    public function testDeepNestingFalseCase(): void
    {
        $types = [
            'no' => fn(): bool => false,
        ];
        // all([ not(not(no)) ])
        // = all([ not(true) ])
        // = all([ false ])
        // = false
        $condition = [
            'all' => [
                ['not' => ['not' => ['type' => 'no']]],
            ],
        ];
        $this->assertFalse($this->evaluateConditions($condition, [], $types));
    }

    // ---------------------------------------------------------------
    // 10. register then evaluate (integration-lite)
    // ---------------------------------------------------------------

    public function testRegisterThenEvaluateViaReflection(): void
    {
        $this->engine->registerConditionType(
            'min_value',
            fn(array $params, array $ctx): bool => ($ctx['value'] ?? 0) >= ($params['min'] ?? 0),
        );

        $types = $this->getPrivateProperty('conditionTypes');
        $condition = ['type' => 'min_value', 'min' => 10];

        $this->assertTrue($this->evaluateConditions($condition, ['value' => 15], $types));
        $this->assertFalse($this->evaluateConditions($condition, ['value' => 5], $types));
    }
}
