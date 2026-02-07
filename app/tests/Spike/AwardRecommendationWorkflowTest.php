<?php
declare(strict_types=1);

namespace App\Test\Spike;

use App\Services\WorkflowEngine\WorkflowBridge;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Workflow\Event\GuardEvent;

/**
 * Spike test modelling the KMP Awards recommendation workflow — the most
 * complex workflow in the system: 11 states, free-form in-progress
 * movement, scheduling flow, guards, metadata, and parallel approval.
 */
class AwardRecommendationWorkflowTest extends TestCase
{
    private WorkflowBridge $bridge;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bridge = new WorkflowBridge();
    }

    protected function tearDown(): void
    {
        $this->bridge->clearCache();
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Config helpers
    // -------------------------------------------------------------------------

    private function getRecommendationConfig(): array
    {
        return [
            'slug' => 'award-recommendations',
            'name' => 'Award Recommendations',
            'type' => 'state_machine',
            'initial_states' => ['submitted'],
            'states' => [
                ['slug' => 'submitted', 'label' => 'Submitted', 'state_type' => 'initial', 'status_category' => 'In Progress'],
                ['slug' => 'in-consideration', 'label' => 'In Consideration', 'state_type' => 'intermediate', 'status_category' => 'In Progress'],
                ['slug' => 'awaiting-feedback', 'label' => 'Awaiting Feedback', 'state_type' => 'intermediate', 'status_category' => 'In Progress'],
                ['slug' => 'deferred', 'label' => 'Deferred till Later', 'state_type' => 'intermediate', 'status_category' => 'In Progress'],
                ['slug' => 'king-approved', 'label' => 'King Approved', 'state_type' => 'intermediate', 'status_category' => 'In Progress'],
                ['slug' => 'queen-approved', 'label' => 'Queen Approved', 'state_type' => 'intermediate', 'status_category' => 'In Progress'],
                ['slug' => 'need-to-schedule', 'label' => 'Need to Schedule', 'state_type' => 'intermediate', 'status_category' => 'Scheduling',
                 'metadata' => ['visible' => ['planToGiveBlock'], 'disabled' => ['domain', 'award', 'member', 'branch']]],
                ['slug' => 'scheduled', 'label' => 'Scheduled', 'state_type' => 'intermediate', 'status_category' => 'To Give',
                 'metadata' => ['required' => ['planToGiveEvent'], 'visible' => ['planToGiveBlock'], 'disabled' => ['domain', 'award', 'member', 'branch']]],
                ['slug' => 'announced-not-given', 'label' => 'Announced Not Given', 'state_type' => 'intermediate', 'status_category' => 'To Give'],
                ['slug' => 'given', 'label' => 'Given', 'state_type' => 'final', 'status_category' => 'Closed',
                 'metadata' => ['required' => ['planToGiveEvent', 'givenDate'], 'visible' => ['planToGiveBlock', 'givenBlock'], 'disabled' => ['domain', 'award', 'member', 'branch'], 'set' => ['close_reason' => 'Given']]],
                ['slug' => 'no-action', 'label' => 'No Action', 'state_type' => 'final', 'status_category' => 'Closed',
                 'metadata' => ['required' => ['closeReason'], 'visible' => ['closeReasonBlock'], 'disabled' => ['domain', 'award', 'member', 'branch', 'courtAvailability', 'callIntoCourt']]],
            ],
            'transitions' => [
                // In Progress bidirectional — one transition per target state
                ['slug' => 'to-submitted', 'label' => 'Move to Submitted',
                 'from' => ['in-consideration', 'awaiting-feedback', 'deferred', 'king-approved', 'queen-approved'], 'to' => 'submitted'],
                ['slug' => 'to-in-consideration', 'label' => 'Move to In Consideration',
                 'from' => ['submitted', 'awaiting-feedback', 'deferred', 'king-approved', 'queen-approved'], 'to' => 'in-consideration'],
                ['slug' => 'to-awaiting-feedback', 'label' => 'Move to Awaiting Feedback',
                 'from' => ['submitted', 'in-consideration', 'deferred', 'king-approved', 'queen-approved'], 'to' => 'awaiting-feedback'],
                ['slug' => 'to-deferred', 'label' => 'Defer till Later',
                 'from' => ['submitted', 'in-consideration', 'awaiting-feedback', 'king-approved', 'queen-approved'], 'to' => 'deferred'],
                ['slug' => 'to-king-approved', 'label' => 'King Approved',
                 'from' => ['submitted', 'in-consideration', 'awaiting-feedback', 'deferred', 'queen-approved'], 'to' => 'king-approved'],
                ['slug' => 'to-queen-approved', 'label' => 'Queen Approved',
                 'from' => ['submitted', 'in-consideration', 'awaiting-feedback', 'deferred', 'king-approved'], 'to' => 'queen-approved'],

                // In Progress → Scheduling
                ['slug' => 'schedule', 'label' => 'Schedule',
                 'from' => ['submitted', 'in-consideration', 'awaiting-feedback', 'deferred', 'king-approved', 'queen-approved'], 'to' => 'need-to-schedule'],

                // Scheduling & To Give flow
                ['slug' => 'mark-scheduled', 'label' => 'Mark Scheduled',
                 'from' => ['need-to-schedule', 'announced-not-given'], 'to' => 'scheduled'],
                ['slug' => 'mark-given', 'label' => 'Mark Given',
                 'from' => ['scheduled', 'announced-not-given'], 'to' => 'given'],
                ['slug' => 'announce-not-given', 'label' => 'Announce Not Given',
                 'from' => 'scheduled', 'to' => 'announced-not-given'],
                ['slug' => 'reschedule', 'label' => 'Reschedule',
                 'from' => 'scheduled', 'to' => 'need-to-schedule'],

                // Close — from any non-final state
                ['slug' => 'close-no-action', 'label' => 'Close - No Action',
                 'from' => ['submitted', 'in-consideration', 'awaiting-feedback', 'deferred', 'king-approved', 'queen-approved', 'need-to-schedule', 'scheduled', 'announced-not-given'], 'to' => 'no-action'],
            ],
        ];
    }

    /**
     * Parallel approval workflow (type=workflow) for test 11.
     */
    private function getParallelApprovalConfig(): array
    {
        return [
            'slug' => 'parallel-approval',
            'name' => 'Parallel Approval',
            'type' => 'workflow',
            'initial_states' => ['submitted'],
            'states' => [
                ['slug' => 'submitted', 'label' => 'Submitted', 'state_type' => 'initial', 'status_category' => 'Open'],
                ['slug' => 'king-review', 'label' => 'King Review', 'state_type' => 'intermediate', 'status_category' => 'Review'],
                ['slug' => 'queen-review', 'label' => 'Queen Review', 'state_type' => 'intermediate', 'status_category' => 'Review'],
                ['slug' => 'king-approved', 'label' => 'King Approved', 'state_type' => 'intermediate', 'status_category' => 'Approved'],
                ['slug' => 'queen-approved', 'label' => 'Queen Approved', 'state_type' => 'intermediate', 'status_category' => 'Approved'],
                ['slug' => 'fully-approved', 'label' => 'Fully Approved', 'state_type' => 'final', 'status_category' => 'Done'],
            ],
            'transitions' => [
                ['slug' => 'start-review', 'label' => 'Start Review', 'from' => 'submitted', 'to' => ['king-review', 'queen-review']],
                ['slug' => 'king-approve', 'label' => 'King Approve', 'from' => 'king-review', 'to' => 'king-approved'],
                ['slug' => 'queen-approve', 'label' => 'Queen Approve', 'from' => 'queen-review', 'to' => 'queen-approved'],
                ['slug' => 'finalize', 'label' => 'Finalize', 'from' => ['king-approved', 'queen-approved'], 'to' => 'fully-approved'],
            ],
        ];
    }

    private function makeSubject(?string $state = null): \stdClass
    {
        $subject = new \stdClass();
        $subject->currentState = $state;
        $subject->givenDate = null;
        $subject->closeReason = null;

        return $subject;
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    /**
     * 1. Build 11-state workflow, verify state count.
     */
    public function testBuildRecommendationWorkflow(): void
    {
        $workflow = $this->bridge->buildFromConfig($this->getRecommendationConfig());

        $this->assertEquals('award-recommendations', $workflow->getName());

        $places = $workflow->getDefinition()->getPlaces();
        $this->assertCount(11, $places);

        $expectedStates = [
            'submitted', 'in-consideration', 'awaiting-feedback', 'deferred',
            'king-approved', 'queen-approved', 'need-to-schedule', 'scheduled',
            'announced-not-given', 'given', 'no-action',
        ];
        foreach ($expectedStates as $state) {
            $this->assertArrayHasKey($state, $places, "State '$state' should exist");
        }
    }

    /**
     * 2. Read place metadata, group states by status_category, verify groupings.
     */
    public function testStatusCategoryGrouping(): void
    {
        $workflow = $this->bridge->buildFromConfig($this->getRecommendationConfig());
        $metadataStore = $workflow->getDefinition()->getMetadataStore();
        $places = $workflow->getDefinition()->getPlaces();

        $groups = [];
        foreach (array_keys($places) as $place) {
            $meta = $metadataStore->getPlaceMetadata($place);
            $category = $meta['status_category'] ?? 'Unknown';
            $groups[$category][] = $place;
        }

        // Sort for stable comparison
        foreach ($groups as &$g) {
            sort($g);
        }
        unset($g);

        $this->assertEquals(
            ['awaiting-feedback', 'deferred', 'in-consideration', 'king-approved', 'queen-approved', 'submitted'],
            $groups['In Progress'],
        );
        $this->assertEquals(['need-to-schedule'], $groups['Scheduling']);
        $this->assertEquals(['announced-not-given', 'scheduled'], $groups['To Give']);
        $this->assertEquals(['given', 'no-action'], $groups['Closed']);
    }

    /**
     * 3. From each "In Progress" state, verify transitions to all other
     *    "In Progress" states are available.
     */
    public function testInProgressStatesTransitionFreely(): void
    {
        $workflow = $this->bridge->buildFromConfig($this->getRecommendationConfig());

        $inProgressStates = [
            'submitted', 'in-consideration', 'awaiting-feedback',
            'deferred', 'king-approved', 'queen-approved',
        ];

        // Map target state → transition name
        $transitionFor = [
            'submitted' => 'to-submitted',
            'in-consideration' => 'to-in-consideration',
            'awaiting-feedback' => 'to-awaiting-feedback',
            'deferred' => 'to-deferred',
            'king-approved' => 'to-king-approved',
            'queen-approved' => 'to-queen-approved',
        ];

        foreach ($inProgressStates as $from) {
            $subject = $this->makeSubject($from);
            $enabled = array_map(
                fn($t) => $t->getName(),
                $workflow->getEnabledTransitions($subject),
            );

            foreach ($inProgressStates as $to) {
                if ($from === $to) {
                    continue;
                }
                $this->assertContains(
                    $transitionFor[$to],
                    $enabled,
                    "From '$from', transition '{$transitionFor[$to]}' should be enabled",
                );
            }
        }
    }

    /**
     * 4. Walk the scheduling flow: Submitted → Need to Schedule → Scheduled → Given.
     */
    public function testSchedulingFlow(): void
    {
        $workflow = $this->bridge->buildFromConfig($this->getRecommendationConfig());
        $subject = $this->makeSubject('submitted');

        $workflow->apply($subject, 'schedule');
        $this->assertEquals('need-to-schedule', $subject->currentState);

        $workflow->apply($subject, 'mark-scheduled');
        $this->assertEquals('scheduled', $subject->currentState);

        $workflow->apply($subject, 'mark-given');
        $this->assertEquals('given', $subject->currentState);

        // Given is terminal
        $this->assertEmpty($workflow->getEnabledTransitions($subject));
    }

    /**
     * 5. Verify state metadata for "need-to-schedule" contains field visibility rules.
     */
    public function testFieldVisibilityMetadata(): void
    {
        $workflow = $this->bridge->buildFromConfig($this->getRecommendationConfig());
        $metadataStore = $workflow->getDefinition()->getMetadataStore();

        $meta = $metadataStore->getPlaceMetadata('need-to-schedule');

        $this->assertArrayHasKey('visible', $meta);
        $this->assertContains('planToGiveBlock', $meta['visible']);

        $this->assertArrayHasKey('disabled', $meta);
        $this->assertContains('domain', $meta['disabled']);
        $this->assertContains('award', $meta['disabled']);
        $this->assertContains('member', $meta['disabled']);
        $this->assertContains('branch', $meta['disabled']);
    }

    /**
     * 6. Guard blocks "mark-given" when givenDate is null.
     */
    public function testGuardBlocksGivenWithoutDate(): void
    {
        $workflow = $this->bridge->buildFromConfig($this->getRecommendationConfig());
        $dispatcher = $this->bridge->getDispatcher('award-recommendations');

        $dispatcher->addListener(
            'workflow.award-recommendations.guard.mark-given',
            function (GuardEvent $event) {
                $subject = $event->getSubject();
                if (empty($subject->givenDate)) {
                    $event->setBlocked(true, 'givenDate is required');
                }
            }
        );

        $subject = $this->makeSubject('scheduled');
        $subject->givenDate = null;

        $this->assertFalse(
            $workflow->can($subject, 'mark-given'),
            'mark-given should be blocked without givenDate',
        );
    }

    /**
     * 7. Guard allows "mark-given" when givenDate is set.
     */
    public function testGuardAllowsGivenWithDate(): void
    {
        $workflow = $this->bridge->buildFromConfig($this->getRecommendationConfig());
        $dispatcher = $this->bridge->getDispatcher('award-recommendations');

        $dispatcher->addListener(
            'workflow.award-recommendations.guard.mark-given',
            function (GuardEvent $event) {
                $subject = $event->getSubject();
                if (empty($subject->givenDate)) {
                    $event->setBlocked(true, 'givenDate is required');
                }
            }
        );

        $subject = $this->makeSubject('scheduled');
        $subject->givenDate = '2025-03-15';

        $this->assertTrue(
            $workflow->can($subject, 'mark-given'),
            'mark-given should be allowed with givenDate set',
        );
    }

    /**
     * 8. "close-no-action" is enabled from every non-final state.
     */
    public function testNoActionFromAnyNonFinalState(): void
    {
        $workflow = $this->bridge->buildFromConfig($this->getRecommendationConfig());

        $nonFinalStates = [
            'submitted', 'in-consideration', 'awaiting-feedback', 'deferred',
            'king-approved', 'queen-approved', 'need-to-schedule', 'scheduled',
            'announced-not-given',
        ];

        foreach ($nonFinalStates as $state) {
            $subject = $this->makeSubject($state);
            $this->assertTrue(
                $workflow->can($subject, 'close-no-action'),
                "close-no-action should be enabled from '$state'",
            );
        }
    }

    /**
     * 9. "Given" and "No Action" have zero enabled transitions.
     */
    public function testTerminalStatesHaveNoTransitions(): void
    {
        $workflow = $this->bridge->buildFromConfig($this->getRecommendationConfig());

        foreach (['given', 'no-action'] as $terminal) {
            $subject = $this->makeSubject($terminal);
            $this->assertEmpty(
                $workflow->getEnabledTransitions($subject),
                "Terminal state '$terminal' should have no enabled transitions",
            );
        }
    }

    /**
     * 10. Entered listener on "given" sets closeReason on the subject.
     */
    public function testEnteredActionSetsCloseReason(): void
    {
        $workflow = $this->bridge->buildFromConfig($this->getRecommendationConfig());
        $dispatcher = $this->bridge->getDispatcher('award-recommendations');

        $dispatcher->addListener(
            'workflow.award-recommendations.entered.given',
            function ($event) {
                $event->getSubject()->closeReason = 'Given';
            }
        );

        $subject = $this->makeSubject('scheduled');
        $this->assertNull($subject->closeReason);

        $workflow->apply($subject, 'mark-given');

        $this->assertEquals('given', $subject->currentState);
        $this->assertEquals('Given', $subject->closeReason);
    }

    /**
     * 11. Parallel approval with type=workflow: subject occupies both
     *     "king-review" and "queen-review" simultaneously. Both must
     *     approve before "finalize" becomes available.
     */
    public function testParallelApprovalWithWorkflowType(): void
    {
        $workflow = $this->bridge->buildFromConfig($this->getParallelApprovalConfig());

        $subject = new \stdClass();
        $subject->currentState = null;

        // Initialise — lands on 'submitted'
        $marking = $workflow->getMarking($subject);
        $this->assertArrayHasKey('submitted', $marking->getPlaces());

        // Fork into parallel review
        $workflow->apply($subject, 'start-review');
        $this->assertIsArray($subject->currentState);
        $this->assertContains('king-review', $subject->currentState);
        $this->assertContains('queen-review', $subject->currentState);

        // finalize must NOT be available yet
        $this->assertFalse($workflow->can($subject, 'finalize'), 'finalize blocked before any approval');

        // King approves
        $workflow->apply($subject, 'king-approve');
        $this->assertContains('king-approved', $subject->currentState);
        $this->assertContains('queen-review', $subject->currentState);

        // finalize still blocked (queen hasn't approved)
        $this->assertFalse($workflow->can($subject, 'finalize'), 'finalize blocked with only king approval');

        // Queen approves
        $workflow->apply($subject, 'queen-approve');
        $this->assertContains('king-approved', $subject->currentState);
        $this->assertContains('queen-approved', $subject->currentState);

        // NOW finalize is available
        $this->assertTrue($workflow->can($subject, 'finalize'), 'finalize available after both approvals');

        // Apply finalize
        $workflow->apply($subject, 'finalize');
        $this->assertContains('fully-approved', $subject->currentState);
    }
}