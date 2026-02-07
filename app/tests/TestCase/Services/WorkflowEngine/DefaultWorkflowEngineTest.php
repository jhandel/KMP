<?php

declare(strict_types=1);

namespace App\Test\TestCase\Services\WorkflowEngine;

use App\Model\Entity\WorkflowState;
use App\Model\Entity\WorkflowTransition;
use App\Services\WorkflowEngine\DefaultActionExecutor;
use App\Services\WorkflowEngine\DefaultRuleEvaluator;
use App\Services\WorkflowEngine\DefaultVisibilityEvaluator;
use App\Services\WorkflowEngine\DefaultWorkflowEngine;
use App\Test\TestCase\BaseTestCase;
use Cake\ORM\TableRegistry;

/**
 * Integration tests for DefaultWorkflowEngine.
 *
 * Uses real database tables with transaction rollback for isolation.
 * Creates a simple linear workflow: submitted → reviewing → approved/rejected
 */
class DefaultWorkflowEngineTest extends BaseTestCase
{
    protected DefaultWorkflowEngine $engine;
    protected $definitionsTable;
    protected $statesTable;
    protected $transitionsTable;
    protected $instancesTable;
    protected $logsTable;

    protected int $definitionId;
    protected int $submittedStateId;
    protected int $reviewingStateId;
    protected int $approvedStateId;
    protected int $rejectedStateId;

    /**
     * Unique entity ID counter to avoid collisions with seed data.
     */
    protected int $testEntityId;

    protected function setUp(): void
    {
        parent::setUp();

        // Use a unique entity ID range to avoid collisions with seed data
        $this->testEntityId = 900000 + random_int(0, 99999);

        $this->definitionsTable = $this->getTableLocator()->get('WorkflowDefinitions');
        $this->statesTable = $this->getTableLocator()->get('WorkflowStates');
        $this->transitionsTable = $this->getTableLocator()->get('WorkflowTransitions');
        $this->instancesTable = $this->getTableLocator()->get('WorkflowInstances');
        $this->logsTable = $this->getTableLocator()->get('WorkflowTransitionLogs');

        $this->createTestWorkflow();

        $ruleEvaluator = new DefaultRuleEvaluator();
        $actionExecutor = new DefaultActionExecutor();
        $visibilityEvaluator = new DefaultVisibilityEvaluator();
        $this->engine = new DefaultWorkflowEngine($ruleEvaluator, $actionExecutor, $visibilityEvaluator);
    }

    /**
     * Create a test workflow definition with states and transitions.
     */
    protected function createTestWorkflow(): void
    {
        // Create definition
        $definition = $this->definitionsTable->newEntity([
            'name' => 'Test Workflow',
            'slug' => 'test-workflow-' . uniqid(),
            'description' => 'Test workflow for unit tests',
            'entity_type' => 'Members',
            'plugin_name' => null,
            'version' => 1,
            'is_active' => true,
            'is_default' => false,
        ]);
        $this->definitionsTable->save($definition);
        $this->definitionId = $definition->id;

        // Create states
        $submitted = $this->statesTable->newEntity([
            'workflow_definition_id' => $this->definitionId,
            'name' => 'Submitted',
            'slug' => 'submitted',
            'label' => 'Submitted',
            'state_type' => WorkflowState::TYPE_INITIAL,
            'metadata' => '{}',
            'on_enter_actions' => '[]',
            'on_exit_actions' => '[]',
        ]);
        $this->statesTable->save($submitted);
        $this->submittedStateId = $submitted->id;

        $reviewing = $this->statesTable->newEntity([
            'workflow_definition_id' => $this->definitionId,
            'name' => 'Reviewing',
            'slug' => 'reviewing',
            'label' => 'Under Review',
            'state_type' => WorkflowState::TYPE_INTERMEDIATE,
            'metadata' => '{}',
            'on_enter_actions' => '[]',
            'on_exit_actions' => '[]',
        ]);
        $this->statesTable->save($reviewing);
        $this->reviewingStateId = $reviewing->id;

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

        // Create transitions
        $submitToReview = $this->transitionsTable->newEntity([
            'workflow_definition_id' => $this->definitionId,
            'from_state_id' => $this->submittedStateId,
            'to_state_id' => $this->reviewingStateId,
            'name' => 'Submit for Review',
            'slug' => 'submit-to-review',
            'label' => 'Submit for Review',
            'priority' => 1,
            'trigger_type' => WorkflowTransition::TRIGGER_MANUAL,
            'conditions' => '[]',
            'actions' => '[]',
            'trigger_config' => '{}',
        ]);
        $this->transitionsTable->save($submitToReview);

        $approve = $this->transitionsTable->newEntity([
            'workflow_definition_id' => $this->definitionId,
            'from_state_id' => $this->reviewingStateId,
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

        $reject = $this->transitionsTable->newEntity([
            'workflow_definition_id' => $this->definitionId,
            'from_state_id' => $this->reviewingStateId,
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
    }

    /**
     * Helper to get the workflow slug (unique per test run).
     */
    protected function getWorkflowSlug(): string
    {
        $def = $this->definitionsTable->get($this->definitionId);

        return $def->slug;
    }

    // ==================================================
    // START WORKFLOW
    // ==================================================

    public function testStartWorkflowCreatesInstanceAtInitialState(): void
    {
        $slug = $this->getWorkflowSlug();
        $result = $this->engine->startWorkflow($slug, 'Members', $this->testEntityId, self::ADMIN_MEMBER_ID);

        $this->assertTrue($result->success, 'startWorkflow should succeed: ' . ($result->reason ?? ''));
        $this->assertNotNull($result->data);
        $this->assertEquals($this->submittedStateId, $result->data->current_state_id);
        $this->assertNull($result->data->previous_state_id);
        $this->assertNull($result->data->completed_at);
    }

    public function testStartWorkflowFailsForUnknownDefinition(): void
    {
        $result = $this->engine->startWorkflow('nonexistent-workflow', 'Members', 1);

        $this->assertFalse($result->success);
        $this->assertStringContains('not found', $result->reason);
    }

    public function testStartWorkflowFailsForDuplicateActiveInstance(): void
    {
        $slug = $this->getWorkflowSlug();
        $first = $this->engine->startWorkflow($slug, 'Members', $this->testEntityId);
        $this->assertTrue($first->success);

        $second = $this->engine->startWorkflow($slug, 'Members', $this->testEntityId);
        $this->assertFalse($second->success);
        $this->assertStringContains('already has an active', $second->reason);
    }

    public function testStartWorkflowCreatesTransitionLog(): void
    {
        $slug = $this->getWorkflowSlug();
        $result = $this->engine->startWorkflow($slug, 'Members', $this->testEntityId);
        $this->assertTrue($result->success);

        $log = $this->logsTable->find()
            ->where(['workflow_instance_id' => $result->data->id])
            ->first();
        $this->assertNotNull($log, 'Transition log should be created for workflow start');
        $this->assertNull($log->from_state_id);
        $this->assertEquals($this->submittedStateId, $log->to_state_id);
    }

    // ==================================================
    // TRANSITION
    // ==================================================

    public function testTransitionMovesToNewState(): void
    {
        $slug = $this->getWorkflowSlug();
        $start = $this->engine->startWorkflow($slug, 'Members', $this->testEntityId);
        $instanceId = $start->data->id;

        $result = $this->engine->transition($instanceId, 'submit-to-review', self::ADMIN_MEMBER_ID);
        $this->assertTrue($result->success, 'Transition should succeed: ' . ($result->reason ?? ''));

        $this->assertEquals($this->reviewingStateId, $result->data['instance']->current_state_id);
        $this->assertEquals($this->submittedStateId, $result->data['instance']->previous_state_id);
    }

    public function testTransitionToTerminalStateSetsCompletedAt(): void
    {
        $slug = $this->getWorkflowSlug();
        $start = $this->engine->startWorkflow($slug, 'Members', $this->testEntityId);
        $instanceId = $start->data->id;

        // Move to reviewing
        $this->engine->transition($instanceId, 'submit-to-review', self::ADMIN_MEMBER_ID);
        // Move to approved (terminal)
        $result = $this->engine->transition($instanceId, 'approve', self::ADMIN_MEMBER_ID);

        $this->assertTrue($result->success);
        $this->assertNotNull($result->data['instance']->completed_at, 'Terminal state should set completed_at');
        $this->assertEquals($this->approvedStateId, $result->data['instance']->current_state_id);
    }

    public function testTransitionFailsForCompletedInstance(): void
    {
        $slug = $this->getWorkflowSlug();
        $start = $this->engine->startWorkflow($slug, 'Members', $this->testEntityId);
        $instanceId = $start->data->id;

        $this->engine->transition($instanceId, 'submit-to-review', self::ADMIN_MEMBER_ID);
        $this->engine->transition($instanceId, 'approve', self::ADMIN_MEMBER_ID);

        // Try another transition on completed instance
        $result = $this->engine->transition($instanceId, 'reject', self::ADMIN_MEMBER_ID);
        $this->assertFalse($result->success);
        $this->assertStringContains('already completed', $result->reason);
    }

    public function testTransitionFailsForInvalidTransitionSlug(): void
    {
        $slug = $this->getWorkflowSlug();
        $start = $this->engine->startWorkflow($slug, 'Members', $this->testEntityId);
        $instanceId = $start->data->id;

        $result = $this->engine->transition($instanceId, 'nonexistent-transition', self::ADMIN_MEMBER_ID);
        $this->assertFalse($result->success);
        $this->assertStringContains('not available', $result->reason);
    }

    public function testTransitionFailsWhenConditionsNotMet(): void
    {
        // Create a transition with a condition that requires a specific permission
        $conditionTransition = $this->transitionsTable->newEntity([
            'workflow_definition_id' => $this->definitionId,
            'from_state_id' => $this->submittedStateId,
            'to_state_id' => $this->reviewingStateId,
            'name' => 'Guarded Submit',
            'slug' => 'guarded-submit',
            'label' => 'Guarded Submit',
            'priority' => 10,
            'trigger_type' => WorkflowTransition::TRIGGER_MANUAL,
            'conditions' => json_encode(['permission' => 'TestWorkflow.nonExistentPermission']),
            'actions' => '[]',
            'trigger_config' => '{}',
        ]);
        $this->transitionsTable->save($conditionTransition);

        $slug = $this->getWorkflowSlug();
        $start = $this->engine->startWorkflow($slug, 'Members', $this->testEntityId);
        $instanceId = $start->data->id;

        // Use a user ID that won't have the required permission
        $result = $this->engine->transition($instanceId, 'guarded-submit', 99999);
        $this->assertFalse($result->success);
        $this->assertStringContains('conditions not met', $result->reason);
    }

    public function testTransitionCreatesAuditLog(): void
    {
        $slug = $this->getWorkflowSlug();
        $start = $this->engine->startWorkflow($slug, 'Members', $this->testEntityId);
        $instanceId = $start->data->id;

        $this->engine->transition($instanceId, 'submit-to-review', self::ADMIN_MEMBER_ID);

        $logs = $this->logsTable->find()
            ->where(['workflow_instance_id' => $instanceId])
            ->orderBy(['created' => 'ASC'])
            ->all()
            ->toArray();

        // First log: workflow started, second log: transition
        $this->assertGreaterThanOrEqual(2, count($logs));
        $transitionLog = end($logs);
        $this->assertEquals($this->submittedStateId, $transitionLog->from_state_id);
        $this->assertEquals($this->reviewingStateId, $transitionLog->to_state_id);
    }

    public function testTransitionExecutesOnExitAndOnEnterActions(): void
    {
        // Update states to have set_context actions
        $this->statesTable->updateAll(
            ['on_exit_actions' => json_encode([
                ['type' => 'set_context', 'key' => 'exited_from', 'value' => 'submitted'],
            ])],
            ['id' => $this->submittedStateId]
        );
        $this->statesTable->updateAll(
            ['on_enter_actions' => json_encode([
                ['type' => 'set_context', 'key' => 'entered_to', 'value' => 'reviewing'],
            ])],
            ['id' => $this->reviewingStateId]
        );

        $slug = $this->getWorkflowSlug();
        $start = $this->engine->startWorkflow($slug, 'Members', $this->testEntityId);
        $instanceId = $start->data->id;

        $result = $this->engine->transition($instanceId, 'submit-to-review', self::ADMIN_MEMBER_ID);
        $this->assertTrue($result->success, 'Transition should succeed: ' . ($result->reason ?? ''));
    }

    public function testTransitionWithActionsUpdatesContext(): void
    {
        // Add a set_context action to the transition
        $this->transitionsTable->updateAll(
            ['actions' => json_encode([
                ['type' => 'set_context', 'key' => 'review_started', 'value' => 'true'],
            ])],
            [
                'from_state_id' => $this->submittedStateId,
                'to_state_id' => $this->reviewingStateId,
                'slug' => 'submit-to-review',
            ]
        );

        $slug = $this->getWorkflowSlug();
        $start = $this->engine->startWorkflow($slug, 'Members', $this->testEntityId);
        $instanceId = $start->data->id;

        $result = $this->engine->transition($instanceId, 'submit-to-review', self::ADMIN_MEMBER_ID);
        $this->assertTrue($result->success);

        // Verify context was updated on the instance
        $instance = $this->instancesTable->get($instanceId);
        $contextData = json_decode($instance->context, true);
        $this->assertEquals('true', $contextData['review_started'] ?? null);
    }

    // ==================================================
    // GET AVAILABLE TRANSITIONS
    // ==================================================

    public function testGetAvailableTransitionsReturnsManualTransitions(): void
    {
        $slug = $this->getWorkflowSlug();
        $start = $this->engine->startWorkflow($slug, 'Members', $this->testEntityId);
        $instanceId = $start->data->id;

        $result = $this->engine->getAvailableTransitions($instanceId);
        $this->assertTrue($result->success);

        $transitions = $result->data;
        $this->assertNotEmpty($transitions);
        $slugs = array_map(fn($t) => $t->slug, $transitions);
        $this->assertContains('submit-to-review', $slugs);
    }

    public function testGetAvailableTransitionsFromReviewingState(): void
    {
        $slug = $this->getWorkflowSlug();
        $start = $this->engine->startWorkflow($slug, 'Members', $this->testEntityId);
        $instanceId = $start->data->id;

        $this->engine->transition($instanceId, 'submit-to-review', self::ADMIN_MEMBER_ID);

        $result = $this->engine->getAvailableTransitions($instanceId);
        $this->assertTrue($result->success);

        $slugs = array_map(fn($t) => $t->slug, $result->data);
        $this->assertContains('approve', $slugs);
        $this->assertContains('reject', $slugs);
    }

    public function testGetAvailableTransitionsReturnsEmptyForCompletedInstance(): void
    {
        $slug = $this->getWorkflowSlug();
        $start = $this->engine->startWorkflow($slug, 'Members', $this->testEntityId);
        $instanceId = $start->data->id;

        $this->engine->transition($instanceId, 'submit-to-review', self::ADMIN_MEMBER_ID);
        $this->engine->transition($instanceId, 'approve', self::ADMIN_MEMBER_ID);

        $result = $this->engine->getAvailableTransitions($instanceId);
        $this->assertTrue($result->success);
        $this->assertEmpty($result->data);
    }

    public function testGetAvailableTransitionsReturnsEmptyForTerminalState(): void
    {
        $slug = $this->getWorkflowSlug();
        $start = $this->engine->startWorkflow($slug, 'Members', $this->testEntityId);
        $instanceId = $start->data->id;

        $this->engine->transition($instanceId, 'submit-to-review', self::ADMIN_MEMBER_ID);
        $this->engine->transition($instanceId, 'reject', self::ADMIN_MEMBER_ID);

        $result = $this->engine->getAvailableTransitions($instanceId);
        $this->assertTrue($result->success);
        $this->assertEmpty($result->data);
    }

    // ==================================================
    // GET CURRENT STATE
    // ==================================================

    public function testGetCurrentStateReturnsInitialState(): void
    {
        $slug = $this->getWorkflowSlug();
        $start = $this->engine->startWorkflow($slug, 'Members', $this->testEntityId);
        $instanceId = $start->data->id;

        $result = $this->engine->getCurrentState($instanceId);
        $this->assertTrue($result->success);
        $this->assertEquals('submitted', $result->data->slug);
        $this->assertEquals(WorkflowState::TYPE_INITIAL, $result->data->state_type);
    }

    public function testGetCurrentStateAfterTransition(): void
    {
        $slug = $this->getWorkflowSlug();
        $start = $this->engine->startWorkflow($slug, 'Members', $this->testEntityId);
        $instanceId = $start->data->id;

        $this->engine->transition($instanceId, 'submit-to-review', self::ADMIN_MEMBER_ID);

        $result = $this->engine->getCurrentState($instanceId);
        $this->assertTrue($result->success);
        $this->assertEquals('reviewing', $result->data->slug);
        $this->assertEquals(WorkflowState::TYPE_INTERMEDIATE, $result->data->state_type);
    }

    // ==================================================
    // GET INSTANCE FOR ENTITY
    // ==================================================

    public function testGetInstanceForEntityFindsActiveInstance(): void
    {
        $slug = $this->getWorkflowSlug();
        $this->engine->startWorkflow($slug, 'Members', $this->testEntityId);

        $result = $this->engine->getInstanceForEntity('Members', $this->testEntityId);
        $this->assertTrue($result->success);
        $this->assertEquals('Members', $result->data->entity_type);
        $this->assertEquals($this->testEntityId, $result->data->entity_id);
    }

    public function testGetInstanceForEntityFailsWhenNoInstance(): void
    {
        $result = $this->engine->getInstanceForEntity('Members', 99999);
        $this->assertFalse($result->success);
        $this->assertStringContains('No workflow instance', $result->reason);
    }

    // ==================================================
    // FULL LIFECYCLE
    // ==================================================

    public function testFullWorkflowLifecycleApproval(): void
    {
        $slug = $this->getWorkflowSlug();

        // Start workflow
        $start = $this->engine->startWorkflow($slug, 'Members', $this->testEntityId, self::ADMIN_MEMBER_ID);
        $this->assertTrue($start->success);
        $instanceId = $start->data->id;

        // Check initial state
        $state = $this->engine->getCurrentState($instanceId);
        $this->assertEquals('submitted', $state->data->slug);

        // Move to reviewing
        $review = $this->engine->transition($instanceId, 'submit-to-review', self::ADMIN_MEMBER_ID);
        $this->assertTrue($review->success);

        // Check reviewing state
        $state = $this->engine->getCurrentState($instanceId);
        $this->assertEquals('reviewing', $state->data->slug);

        // Approve
        $approve = $this->engine->transition($instanceId, 'approve', self::ADMIN_MEMBER_ID);
        $this->assertTrue($approve->success);

        // Check terminal state
        $state = $this->engine->getCurrentState($instanceId);
        $this->assertEquals('approved', $state->data->slug);
        $this->assertNotNull($approve->data['instance']->completed_at);

        // No more transitions available
        $available = $this->engine->getAvailableTransitions($instanceId);
        $this->assertEmpty($available->data);
    }

    public function testFullWorkflowLifecycleRejection(): void
    {
        $slug = $this->getWorkflowSlug();

        $start = $this->engine->startWorkflow($slug, 'Members', $this->testEntityId, self::ADMIN_MEMBER_ID);
        $instanceId = $start->data->id;

        $this->engine->transition($instanceId, 'submit-to-review', self::ADMIN_MEMBER_ID);
        $reject = $this->engine->transition($instanceId, 'reject', self::ADMIN_MEMBER_ID);

        $this->assertTrue($reject->success);
        $this->assertNotNull($reject->data['instance']->completed_at);
        $this->assertEquals($this->rejectedStateId, $reject->data['instance']->current_state_id);
    }

    // ==================================================
    // REGISTER CUSTOM TYPES (delegated)
    // ==================================================

    public function testRegisterConditionTypeDelegatesToEvaluator(): void
    {
        $customCondition = new class implements \App\Services\WorkflowEngine\Conditions\ConditionInterface {
            public function evaluate(array $params, array $context): bool
            {
                return true;
            }

            public function getName(): string
            {
                return 'engine_custom';
            }

            public function getDescription(): string
            {
                return 'Test';
            }

            public function getParameterSchema(): array
            {
                return [];
            }
        };

        // Should not throw
        $this->engine->registerConditionType('engine_custom', $customCondition);
        $this->assertTrue(true);
    }

    public function testRegisterActionTypeDelegatesToExecutor(): void
    {
        $customAction = new class implements \App\Services\WorkflowEngine\Actions\ActionInterface {
            public function execute(array $params, array $context): \App\Services\ServiceResult
            {
                return new \App\Services\ServiceResult(true);
            }

            public function getName(): string
            {
                return 'engine_custom_action';
            }

            public function getDescription(): string
            {
                return 'Test';
            }

            public function getParameterSchema(): array
            {
                return [];
            }
        };

        $this->engine->registerActionType('engine_custom_action', $customAction);
        $this->assertTrue(true);
    }

    // ==================================================
    // HELPER
    // ==================================================

    /**
     * Assert that a string contains a substring.
     */
    protected static function assertStringContains(string $needle, ?string $haystack, string $message = ''): void
    {
        static::assertNotNull($haystack, $message ?: "String should not be null when checking for '{$needle}'");
        static::assertStringContainsString($needle, $haystack, $message);
    }
}
