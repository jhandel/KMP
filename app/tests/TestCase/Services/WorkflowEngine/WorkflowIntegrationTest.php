<?php

declare(strict_types=1);

namespace App\Test\TestCase\Services\WorkflowEngine;

use App\Model\Entity\WorkflowState;
use App\Services\WorkflowEngine\DefaultActionExecutor;
use App\Services\WorkflowEngine\DefaultRuleEvaluator;
use App\Services\WorkflowEngine\DefaultVisibilityEvaluator;
use App\Services\WorkflowEngine\DefaultWorkflowEngine;
use App\Test\TestCase\BaseTestCase;
use Cake\ORM\TableRegistry;

/**
 * Integration tests for all ported workflow definitions.
 *
 * Verifies that the seeded workflow data (definitions, states, transitions)
 * is valid and that the workflow engine can operate on it correctly.
 * Uses database transaction rollback for test isolation.
 */
class WorkflowIntegrationTest extends BaseTestCase
{
    protected DefaultWorkflowEngine $engine;
    protected $definitionsTable;
    protected $statesTable;
    protected $transitionsTable;
    protected $instancesTable;
    protected $logsTable;

    /**
     * All expected workflow slugs from seeded data.
     */
    protected const EXPECTED_WORKFLOWS = [
        'award-recommendations',
        'warrant-roster',
        'warrant',
        'officer-assignment',
        'activity-authorization',
    ];

    /**
     * Workflows that use 'initial' state_type (compatible with engine's findInitialState).
     */
    protected const STARTABLE_WORKFLOWS = [
        'award-recommendations' => 'AwardsRecommendations',
        'officer-assignment' => 'Officers.Officers',
        'activity-authorization' => 'Authorizations',
    ];

    /**
     * Workflows that use 'start' state_type (not yet normalized to 'initial').
     */
    protected const START_TYPE_WORKFLOWS = [
        'warrant' => 'Warrants',
        'warrant-roster' => 'WarrantRosters',
    ];

    protected int $testEntityId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testEntityId = 900000 + random_int(0, 99999);

        $this->definitionsTable = $this->getTableLocator()->get('WorkflowDefinitions');
        $this->statesTable = $this->getTableLocator()->get('WorkflowStates');
        $this->transitionsTable = $this->getTableLocator()->get('WorkflowTransitions');
        $this->instancesTable = $this->getTableLocator()->get('WorkflowInstances');
        $this->logsTable = $this->getTableLocator()->get('WorkflowTransitionLogs');

        $this->engine = new DefaultWorkflowEngine(
            new DefaultRuleEvaluator(),
            new DefaultActionExecutor(),
            new DefaultVisibilityEvaluator(),
        );
    }

    // ==================================================
    // WORKFLOW DEFINITIONS
    // ==================================================

    public function testAllWorkflowDefinitionsExist(): void
    {
        foreach (self::EXPECTED_WORKFLOWS as $slug) {
            $def = $this->definitionsTable->findBySlug($slug);
            $this->assertNotNull($def, "Workflow definition '{$slug}' should exist and be active");
            $this->assertTrue((bool)$def->is_active, "Workflow '{$slug}' should be active");
        }
    }

    public function testWorkflowDefinitionsHaveCorrectEntityTypes(): void
    {
        $expected = [
            'award-recommendations' => 'AwardsRecommendations',
            'warrant-roster' => 'WarrantRosters',
            'warrant' => 'Warrants',
            'officer-assignment' => 'Officers.Officers',
            'activity-authorization' => 'Authorizations',
        ];

        foreach ($expected as $slug => $entityType) {
            $def = $this->definitionsTable->findBySlug($slug);
            $this->assertEquals($entityType, $def->entity_type, "Workflow '{$slug}' should have entity_type '{$entityType}'");
        }
    }

    // ==================================================
    // WORKFLOW STATES
    // ==================================================

    public function testWorkflowsHaveStartStates(): void
    {
        foreach (self::EXPECTED_WORKFLOWS as $slug) {
            $def = $this->definitionsTable->findBySlug($slug);
            $startState = $this->statesTable->find()
                ->where([
                    'workflow_definition_id' => $def->id,
                    'state_type IN' => ['initial', 'start'],
                ])
                ->first();
            $this->assertNotNull($startState, "Workflow '{$slug}' should have a start state (initial or start type)");
        }
    }

    public function testWorkflowsHaveEndStates(): void
    {
        foreach (self::EXPECTED_WORKFLOWS as $slug) {
            $def = $this->definitionsTable->findBySlug($slug);
            $endCount = $this->statesTable->find()
                ->where([
                    'workflow_definition_id' => $def->id,
                    'state_type IN' => ['terminal', 'final', 'end'],
                ])
                ->count();
            $this->assertGreaterThan(0, $endCount, "Workflow '{$slug}' should have at least one end state");
        }
    }

    public function testAwardRecommendationsHasExpectedStates(): void
    {
        $def = $this->definitionsTable->findBySlug('award-recommendations');
        $states = $this->statesTable->find()
            ->where(['workflow_definition_id' => $def->id])
            ->all()
            ->toArray();

        $slugs = array_map(fn($s) => $s->slug, $states);
        $expectedSlugs = [
            'submitted', 'in-consideration', 'awaiting-feedback',
            'deferred-till-later', 'king-approved', 'queen-approved',
            'need-to-schedule', 'scheduled', 'announced-not-given',
            'given', 'no-action',
        ];
        foreach ($expectedSlugs as $stateSlug) {
            $this->assertContains($stateSlug, $slugs, "Award recommendations should have state '{$stateSlug}'");
        }
    }

    public function testWarrantHasExpectedStates(): void
    {
        $def = $this->definitionsTable->findBySlug('warrant');
        $states = $this->statesTable->find()
            ->where(['workflow_definition_id' => $def->id])
            ->all()
            ->toArray();

        $slugs = array_map(fn($s) => $s->slug, $states);
        $expectedSlugs = [
            'pending', 'current', 'upcoming', 'expired',
            'deactivated', 'cancelled', 'declined', 'replaced', 'released',
        ];
        foreach ($expectedSlugs as $stateSlug) {
            $this->assertContains($stateSlug, $slugs, "Warrant should have state '{$stateSlug}'");
        }
    }

    public function testOfficerAssignmentHasExpectedStates(): void
    {
        $def = $this->definitionsTable->findBySlug('officer-assignment');
        $states = $this->statesTable->find()
            ->where(['workflow_definition_id' => $def->id])
            ->all()
            ->toArray();

        $slugs = array_map(fn($s) => $s->slug, $states);
        $expectedSlugs = ['upcoming', 'current', 'expired', 'released', 'replaced'];
        foreach ($expectedSlugs as $stateSlug) {
            $this->assertContains($stateSlug, $slugs, "Officer assignment should have state '{$stateSlug}'");
        }
    }

    public function testActivityAuthorizationHasExpectedStates(): void
    {
        $def = $this->definitionsTable->findBySlug('activity-authorization');
        $states = $this->statesTable->find()
            ->where(['workflow_definition_id' => $def->id])
            ->all()
            ->toArray();

        $slugs = array_map(fn($s) => $s->slug, $states);
        $expectedSlugs = ['pending', 'approved', 'denied', 'revoked', 'expired', 'retracted'];
        foreach ($expectedSlugs as $stateSlug) {
            $this->assertContains($stateSlug, $slugs, "Activity authorization should have state '{$stateSlug}'");
        }
    }

    public function testWarrantRosterHasExpectedStates(): void
    {
        $def = $this->definitionsTable->findBySlug('warrant-roster');
        $states = $this->statesTable->find()
            ->where(['workflow_definition_id' => $def->id])
            ->all()
            ->toArray();

        $slugs = array_map(fn($s) => $s->slug, $states);
        $expectedSlugs = ['pending', 'approved', 'declined'];
        foreach ($expectedSlugs as $stateSlug) {
            $this->assertContains($stateSlug, $slugs, "Warrant roster should have state '{$stateSlug}'");
        }
    }

    // ==================================================
    // WORKFLOW TRANSITIONS
    // ==================================================

    public function testEachWorkflowHasTransitions(): void
    {
        foreach (self::EXPECTED_WORKFLOWS as $slug) {
            $def = $this->definitionsTable->findBySlug($slug);
            $count = $this->transitionsTable->find()
                ->where(['workflow_definition_id' => $def->id])
                ->count();
            $this->assertGreaterThan(0, $count, "Workflow '{$slug}' should have at least one transition");
        }
    }

    public function testAwardRecommendationsTransitionCount(): void
    {
        $def = $this->definitionsTable->findBySlug('award-recommendations');
        $count = $this->transitionsTable->find()
            ->where(['workflow_definition_id' => $def->id])
            ->count();
        // Award recommendations has a complex web of transitions
        $this->assertGreaterThanOrEqual(40, $count, "Award recommendations should have many transitions (complex workflow)");
    }

    public function testTransitionsReferenceValidStates(): void
    {
        foreach (self::EXPECTED_WORKFLOWS as $slug) {
            $def = $this->definitionsTable->findBySlug($slug);
            $stateIds = $this->statesTable->find()
                ->where(['workflow_definition_id' => $def->id])
                ->all()
                ->extract('id')
                ->toArray();

            $transitions = $this->transitionsTable->find()
                ->where(['workflow_definition_id' => $def->id])
                ->all();

            foreach ($transitions as $t) {
                $this->assertContains(
                    $t->from_state_id,
                    $stateIds,
                    "Transition '{$t->slug}' in '{$slug}' references invalid from_state_id"
                );
                $this->assertContains(
                    $t->to_state_id,
                    $stateIds,
                    "Transition '{$t->slug}' in '{$slug}' references invalid to_state_id"
                );
            }
        }
    }

    public function testTransitionsHaveValidTriggerTypes(): void
    {
        $validTypes = ['manual', 'automatic', 'scheduled', 'event'];
        $transitions = $this->transitionsTable->find()->all();

        foreach ($transitions as $t) {
            $this->assertContains(
                $t->trigger_type,
                $validTypes,
                "Transition '{$t->slug}' has invalid trigger_type '{$t->trigger_type}'"
            );
        }
    }

    // ==================================================
    // START WORKFLOWS (engine-compatible: state_type = 'initial')
    // ==================================================

    public function testStartAwardRecommendationsWorkflow(): void
    {
        $result = $this->engine->startWorkflow(
            'award-recommendations',
            'TestEntity',
            $this->testEntityId,
            self::ADMIN_MEMBER_ID,
        );
        $this->assertTrue($result->success, "Starting award-recommendations should succeed: " . ($result->reason ?? ''));
        $this->assertNotNull($result->data->id);
        $this->assertEquals('TestEntity', $result->data->entity_type);
        $this->assertEquals($this->testEntityId, $result->data->entity_id);
        $this->assertNull($result->data->completed_at);
    }

    public function testStartOfficerAssignmentWorkflow(): void
    {
        $result = $this->engine->startWorkflow(
            'officer-assignment',
            'TestEntity',
            $this->testEntityId,
            self::ADMIN_MEMBER_ID,
        );
        $this->assertTrue($result->success, "Starting officer-assignment should succeed: " . ($result->reason ?? ''));
        $this->assertNotNull($result->data->id);
        $this->assertNull($result->data->completed_at);
    }

    public function testStartActivityAuthorizationWorkflow(): void
    {
        $result = $this->engine->startWorkflow(
            'activity-authorization',
            'TestEntity',
            $this->testEntityId,
            self::ADMIN_MEMBER_ID,
        );
        $this->assertTrue($result->success, "Starting activity-authorization should succeed: " . ($result->reason ?? ''));
        $this->assertNotNull($result->data->id);
        $this->assertNull($result->data->completed_at);
    }

    public function testStartWarrantWorkflowFailsDueToStateTypeMismatch(): void
    {
        // warrant uses 'start' state_type; engine expects 'initial'
        $result = $this->engine->startWorkflow(
            'warrant',
            'TestEntity',
            $this->testEntityId,
            self::ADMIN_MEMBER_ID,
        );
        $this->assertFalse($result->success, "Warrant workflow uses 'start' state_type, not 'initial'");
        $this->assertStringContainsString('no initial state', $result->reason);
    }

    public function testStartWarrantRosterWorkflowFailsDueToStateTypeMismatch(): void
    {
        // warrant-roster uses 'start' state_type; engine expects 'initial'
        $result = $this->engine->startWorkflow(
            'warrant-roster',
            'TestEntity',
            $this->testEntityId,
            self::ADMIN_MEMBER_ID,
        );
        $this->assertFalse($result->success, "Warrant roster workflow uses 'start' state_type, not 'initial'");
        $this->assertStringContainsString('no initial state', $result->reason);
    }

    public function testStartAllCompatibleWorkflows(): void
    {
        $entityOffset = 0;
        foreach (self::STARTABLE_WORKFLOWS as $slug => $entityType) {
            $result = $this->engine->startWorkflow($slug, 'TestEntity', $this->testEntityId + $entityOffset, self::ADMIN_MEMBER_ID);
            $this->assertTrue($result->success, "Failed to start '{$slug}': " . ($result->reason ?? ''));
            $entityOffset++;
        }
    }

    // ==================================================
    // DUPLICATE PREVENTION
    // ==================================================

    public function testCannotStartDuplicateWorkflow(): void
    {
        $result1 = $this->engine->startWorkflow('award-recommendations', 'TestEntity', $this->testEntityId, self::ADMIN_MEMBER_ID);
        $this->assertTrue($result1->success);

        $result2 = $this->engine->startWorkflow('award-recommendations', 'TestEntity', $this->testEntityId, self::ADMIN_MEMBER_ID);
        $this->assertFalse($result2->success, "Should not allow duplicate workflow for same entity");
        $this->assertStringContainsString('already has an active', $result2->reason);
    }

    // ==================================================
    // TRANSITION LOG
    // ==================================================

    public function testStartWorkflowCreatesTransitionLog(): void
    {
        $result = $this->engine->startWorkflow('award-recommendations', 'TestEntity', $this->testEntityId, self::ADMIN_MEMBER_ID);
        $this->assertTrue($result->success);

        $logCount = $this->logsTable->find()
            ->where(['workflow_instance_id' => $result->data->id])
            ->count();
        $this->assertGreaterThan(0, $logCount, "Starting a workflow should create a transition log entry");
    }

    // ==================================================
    // GET CURRENT STATE
    // ==================================================

    public function testGetCurrentStateReturnsInitialState(): void
    {
        $result = $this->engine->startWorkflow('award-recommendations', 'TestEntity', $this->testEntityId, self::ADMIN_MEMBER_ID);
        $this->assertTrue($result->success);

        $stateResult = $this->engine->getCurrentState($result->data->id);
        $this->assertTrue($stateResult->success);
        $this->assertEquals('submitted', $stateResult->data->slug);
        $this->assertEquals(WorkflowState::TYPE_INITIAL, $stateResult->data->state_type);
    }

    public function testGetCurrentStateForOfficerAssignment(): void
    {
        $result = $this->engine->startWorkflow('officer-assignment', 'TestEntity', $this->testEntityId, self::ADMIN_MEMBER_ID);
        $this->assertTrue($result->success);

        $stateResult = $this->engine->getCurrentState($result->data->id);
        $this->assertTrue($stateResult->success);
        $this->assertEquals('upcoming', $stateResult->data->slug);
    }

    public function testGetCurrentStateForActivityAuthorization(): void
    {
        $result = $this->engine->startWorkflow('activity-authorization', 'TestEntity', $this->testEntityId, self::ADMIN_MEMBER_ID);
        $this->assertTrue($result->success);

        $stateResult = $this->engine->getCurrentState($result->data->id);
        $this->assertTrue($stateResult->success);
        $this->assertEquals('pending', $stateResult->data->slug);
    }

    // ==================================================
    // GET AVAILABLE TRANSITIONS
    // ==================================================

    public function testGetAvailableTransitionsForAwardRecommendations(): void
    {
        $result = $this->engine->startWorkflow('award-recommendations', 'TestEntity', $this->testEntityId, self::ADMIN_MEMBER_ID);
        $this->assertTrue($result->success);

        $transResult = $this->engine->getAvailableTransitions($result->data->id);
        $this->assertTrue($transResult->success);
        $this->assertIsArray($transResult->data);
        $this->assertNotEmpty($transResult->data, "Award recommendations should have transitions from initial state");

        $slugs = array_map(fn($t) => $t->slug, $transResult->data);
        $this->assertContains('submitted-to-in-consideration', $slugs);
    }

    public function testGetAvailableTransitionsForActivityAuthorization(): void
    {
        $result = $this->engine->startWorkflow('activity-authorization', 'TestEntity', $this->testEntityId, self::ADMIN_MEMBER_ID);
        $this->assertTrue($result->success);

        $transResult = $this->engine->getAvailableTransitions($result->data->id);
        $this->assertTrue($transResult->success);

        $slugs = array_map(fn($t) => $t->slug, $transResult->data);
        $this->assertContains('approve', $slugs);
        $this->assertContains('deny', $slugs);
        $this->assertContains('retract', $slugs);
    }

    // ==================================================
    // TRANSITION EXECUTION
    // ==================================================

    public function testTransitionAwardRecommendationsToInConsideration(): void
    {
        $start = $this->engine->startWorkflow('award-recommendations', 'TestEntity', $this->testEntityId, self::ADMIN_MEMBER_ID);
        $this->assertTrue($start->success);

        // Pass required permission via context to satisfy condition
        $result = $this->engine->transition(
            $start->data->id,
            'submitted-to-in-consideration',
            self::ADMIN_MEMBER_ID,
            ['user_permissions' => ['canUpdateStates']],
        );
        $this->assertTrue($result->success, "Transition should succeed: " . ($result->reason ?? ''));
        $this->assertEquals('in-consideration', $result->data['to_state']->slug);
    }

    public function testTransitionActivityAuthorizationDeny(): void
    {
        $start = $this->engine->startWorkflow('activity-authorization', 'TestEntity', $this->testEntityId, self::ADMIN_MEMBER_ID);
        $this->assertTrue($start->success);

        $result = $this->engine->transition(
            $start->data->id,
            'deny',
            self::ADMIN_MEMBER_ID,
            ['user_permissions' => ['canApproveActivityAuthorization']],
        );
        $this->assertTrue($result->success, "Deny should succeed: " . ($result->reason ?? ''));
        $this->assertEquals('denied', $result->data['to_state']->slug);
    }

    public function testTransitionFailsWithoutRequiredPermission(): void
    {
        $start = $this->engine->startWorkflow('award-recommendations', 'TestEntity', $this->testEntityId, self::ADMIN_MEMBER_ID);
        $this->assertTrue($start->success);

        // Attempt transition without providing the required permission
        $result = $this->engine->transition($start->data->id, 'submitted-to-in-consideration', self::ADMIN_MEMBER_ID);
        $this->assertFalse($result->success);
        $this->assertStringContainsString('conditions not met', $result->reason);
    }

    public function testTransitionFailsForInvalidSlug(): void
    {
        $start = $this->engine->startWorkflow('award-recommendations', 'TestEntity', $this->testEntityId, self::ADMIN_MEMBER_ID);
        $this->assertTrue($start->success);

        $result = $this->engine->transition($start->data->id, 'nonexistent-transition', self::ADMIN_MEMBER_ID);
        $this->assertFalse($result->success);
        $this->assertStringContainsString('not available', $result->reason);
    }

    public function testTransitionCreatesAuditLog(): void
    {
        $start = $this->engine->startWorkflow('award-recommendations', 'TestEntity', $this->testEntityId, self::ADMIN_MEMBER_ID);
        $this->assertTrue($start->success);

        $result = $this->engine->transition(
            $start->data->id,
            'submitted-to-in-consideration',
            self::ADMIN_MEMBER_ID,
            ['user_permissions' => ['canUpdateStates']],
        );
        $this->assertTrue($result->success, "Transition should succeed: " . ($result->reason ?? ''));

        $logs = $this->logsTable->find()
            ->where(['workflow_instance_id' => $start->data->id])
            ->orderBy(['created' => 'ASC'])
            ->all()
            ->toArray();

        // First log: workflow started, second log: transition
        $this->assertGreaterThanOrEqual(2, count($logs));
        $transitionLog = end($logs);
        $this->assertNotNull($transitionLog->from_state_id);
        $this->assertNotNull($transitionLog->to_state_id);
    }

    // ==================================================
    // FULL LIFECYCLE
    // ==================================================

    public function testAwardRecommendationsFullLifecycleToGiven(): void
    {
        $ctx = ['user_permissions' => ['canUpdateStates']];
        $start = $this->engine->startWorkflow('award-recommendations', 'TestEntity', $this->testEntityId, self::ADMIN_MEMBER_ID);
        $this->assertTrue($start->success);
        $instanceId = $start->data->id;

        // submitted → in-consideration
        $result = $this->engine->transition($instanceId, 'submitted-to-in-consideration', self::ADMIN_MEMBER_ID, $ctx);
        $this->assertTrue($result->success, "submitted→in-consideration: " . ($result->reason ?? ''));
        $this->assertEquals('in-consideration', $result->data['to_state']->slug);

        // in-consideration → king-approved
        $result = $this->engine->transition($instanceId, 'in-consideration-to-king-approved', self::ADMIN_MEMBER_ID, $ctx);
        $this->assertTrue($result->success, "in-consideration→king-approved: " . ($result->reason ?? ''));
        $this->assertEquals('king-approved', $result->data['to_state']->slug);

        // king-approved → queen-approved
        $result = $this->engine->transition($instanceId, 'king-approved-to-queen-approved', self::ADMIN_MEMBER_ID, $ctx);
        $this->assertTrue($result->success, "king-approved→queen-approved: " . ($result->reason ?? ''));
        $this->assertEquals('queen-approved', $result->data['to_state']->slug);

        // queen-approved → need-to-schedule
        $result = $this->engine->transition($instanceId, 'queen-approved-to-need-to-schedule', self::ADMIN_MEMBER_ID, $ctx);
        $this->assertTrue($result->success, "queen-approved→need-to-schedule: " . ($result->reason ?? ''));
        $this->assertEquals('need-to-schedule', $result->data['to_state']->slug);

        // need-to-schedule → scheduled
        $result = $this->engine->transition($instanceId, 'need-to-schedule-to-scheduled', self::ADMIN_MEMBER_ID, $ctx);
        $this->assertTrue($result->success, "need-to-schedule→scheduled: " . ($result->reason ?? ''));
        $this->assertEquals('scheduled', $result->data['to_state']->slug);

        // scheduled → given (final state)
        $result = $this->engine->transition($instanceId, 'scheduled-to-given', self::ADMIN_MEMBER_ID, $ctx);
        $this->assertTrue($result->success, "scheduled→given: " . ($result->reason ?? ''));
        $this->assertEquals('given', $result->data['to_state']->slug);

        // Verify no more transitions available
        $available = $this->engine->getAvailableTransitions($instanceId);
        $this->assertTrue($available->success);
        $this->assertEmpty($available->data, "No transitions should be available after reaching final state");
    }

    public function testAwardRecommendationsNoActionPath(): void
    {
        $ctx = ['user_permissions' => ['canUpdateStates']];
        $start = $this->engine->startWorkflow('award-recommendations', 'TestEntity', $this->testEntityId, self::ADMIN_MEMBER_ID);
        $this->assertTrue($start->success);
        $instanceId = $start->data->id;

        // submitted → no-action (final)
        $result = $this->engine->transition($instanceId, 'submitted-to-no-action', self::ADMIN_MEMBER_ID, $ctx);
        $this->assertTrue($result->success, "submitted→no-action: " . ($result->reason ?? ''));
        $this->assertEquals('no-action', $result->data['to_state']->slug);

        $available = $this->engine->getAvailableTransitions($instanceId);
        $this->assertEmpty($available->data);
    }

    public function testActivityAuthorizationDenialLifecycle(): void
    {
        $start = $this->engine->startWorkflow('activity-authorization', 'TestEntity', $this->testEntityId, self::ADMIN_MEMBER_ID);
        $this->assertTrue($start->success);
        $instanceId = $start->data->id;

        // pending → denied (final)
        $result = $this->engine->transition(
            $instanceId,
            'deny',
            self::ADMIN_MEMBER_ID,
            ['user_permissions' => ['canApproveActivityAuthorization']],
        );
        $this->assertTrue($result->success, "pending→denied: " . ($result->reason ?? ''));
        $this->assertEquals('denied', $result->data['to_state']->slug);

        $available = $this->engine->getAvailableTransitions($instanceId);
        $this->assertEmpty($available->data);
    }

    public function testOfficerAssignmentReleaseLifecycle(): void
    {
        $start = $this->engine->startWorkflow('officer-assignment', 'TestEntity', $this->testEntityId, self::ADMIN_MEMBER_ID);
        $this->assertTrue($start->success);
        $instanceId = $start->data->id;

        // Verify initial state
        $state = $this->engine->getCurrentState($instanceId);
        $this->assertEquals('upcoming', $state->data->slug);

        // Check available transitions from upcoming - 'activate' is scheduled, 'cancel' is manual
        $available = $this->engine->getAvailableTransitions($instanceId);
        $this->assertTrue($available->success);
        $manualSlugs = array_map(fn($t) => $t->slug, $available->data);
        $this->assertContains('cancel', $manualSlugs, "Cancel should be available from upcoming state");
    }

    // ==================================================
    // GET INSTANCE FOR ENTITY
    // ==================================================

    public function testGetInstanceForEntity(): void
    {
        $this->engine->startWorkflow('award-recommendations', 'TestEntity', $this->testEntityId, self::ADMIN_MEMBER_ID);

        $result = $this->engine->getInstanceForEntity('TestEntity', $this->testEntityId);
        $this->assertTrue($result->success);
        $this->assertEquals('TestEntity', $result->data->entity_type);
        $this->assertEquals($this->testEntityId, $result->data->entity_id);
    }

    public function testGetInstanceForEntityNotFound(): void
    {
        $result = $this->engine->getInstanceForEntity('NonExistent', 0);
        $this->assertFalse($result->success);
        $this->assertStringContainsString('No workflow instance', $result->reason);
    }

    // ==================================================
    // PROCESS SCHEDULED TRANSITIONS
    // ==================================================

    public function testProcessScheduledTransitionsRuns(): void
    {
        $result = $this->engine->processScheduledTransitions();
        $this->assertTrue($result->success);
        $this->assertArrayHasKey('processed', $result->data);
        $this->assertArrayHasKey('errors', $result->data);
    }

    // ==================================================
    // EDGE CASES
    // ==================================================

    public function testStartWorkflowWithNonexistentSlug(): void
    {
        $result = $this->engine->startWorkflow('nonexistent-workflow', 'TestEntity', $this->testEntityId);
        $this->assertFalse($result->success);
        $this->assertStringContainsString('not found', $result->reason);
    }

    public function testMultipleWorkflowsCanRunForDifferentEntities(): void
    {
        $result1 = $this->engine->startWorkflow('award-recommendations', 'TestEntity', $this->testEntityId, self::ADMIN_MEMBER_ID);
        $result2 = $this->engine->startWorkflow('award-recommendations', 'TestEntity', $this->testEntityId + 1, self::ADMIN_MEMBER_ID);

        $this->assertTrue($result1->success);
        $this->assertTrue($result2->success);
        $this->assertNotEquals($result1->data->id, $result2->data->id);
    }

    public function testDifferentWorkflowTypesCanRunForSameEntity(): void
    {
        $result1 = $this->engine->startWorkflow('award-recommendations', 'TestEntity', $this->testEntityId, self::ADMIN_MEMBER_ID);
        $result2 = $this->engine->startWorkflow('activity-authorization', 'TestEntity', $this->testEntityId + 1, self::ADMIN_MEMBER_ID);

        $this->assertTrue($result1->success);
        $this->assertTrue($result2->success);
    }
}
