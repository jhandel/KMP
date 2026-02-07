<?php
declare(strict_types=1);

namespace App\Test\Spike;

use App\Services\WorkflowEngine\WorkflowBridge;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Workflow\Event\GuardEvent;
use Symfony\Component\Workflow\Workflow;

/**
 * Spike test proving two different warrant workflow variants can coexist.
 *
 * Variant 1: Ansteorra Roster (6-month batch cycles, multi-approval)
 * Variant 2: Warrant-on-Hire (individual warrants, single approval)
 */
class WarrantWorkflowVariantsTest extends TestCase
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

    /**
     * Ansteorra 6-month batch roster workflow configuration.
     */
    private function getRosterConfig(): array
    {
        return [
            'slug' => 'warrant-roster',
            'name' => 'Ansteorra Warrant Roster',
            'type' => 'state_machine',
            'initial_states' => ['draft'],
            'states' => [
                ['slug' => 'draft', 'label' => 'Draft', 'state_type' => 'initial', 'status_category' => 'In Progress'],
                ['slug' => 'submitted', 'label' => 'Submitted', 'state_type' => 'intermediate', 'status_category' => 'In Progress'],
                ['slug' => 'under_review', 'label' => 'Under Review', 'state_type' => 'intermediate', 'status_category' => 'Approval'],
                ['slug' => 'partially_approved', 'label' => 'Partially Approved', 'state_type' => 'intermediate', 'status_category' => 'Approval'],
                ['slug' => 'approved', 'label' => 'Approved', 'state_type' => 'intermediate', 'status_category' => 'Active'],
                ['slug' => 'issued', 'label' => 'Issued', 'state_type' => 'intermediate', 'status_category' => 'Active'],
                ['slug' => 'expired', 'label' => 'Expired', 'state_type' => 'final', 'status_category' => 'Closed'],
                ['slug' => 'rejected', 'label' => 'Rejected', 'state_type' => 'final', 'status_category' => 'Closed'],
            ],
            'transitions' => [
                ['slug' => 'submit', 'label' => 'Submit Roster', 'from' => 'draft', 'to' => 'submitted'],
                ['slug' => 'start_review', 'label' => 'Start Review', 'from' => 'submitted', 'to' => 'under_review'],
                ['slug' => 'partial_approve', 'label' => 'Record Partial Approval', 'from' => 'under_review', 'to' => 'partially_approved'],
                ['slug' => 'add_approval', 'label' => 'Add Another Approval', 'from' => 'partially_approved', 'to' => 'partially_approved'],
                ['slug' => 'approve', 'label' => 'Final Approve', 'from' => 'partially_approved', 'to' => 'approved'],
                ['slug' => 'issue', 'label' => 'Issue Warrants', 'from' => 'approved', 'to' => 'issued'],
                ['slug' => 'expire', 'label' => 'Expire', 'from' => 'issued', 'to' => 'expired'],
                ['slug' => 'reject_from_review', 'label' => 'Reject', 'from' => 'under_review', 'to' => 'rejected'],
                ['slug' => 'reject_from_partial', 'label' => 'Reject', 'from' => 'partially_approved', 'to' => 'rejected'],
            ],
        ];
    }

    /**
     * Individual warrant-on-hire workflow configuration.
     */
    private function getWarrantOnHireConfig(): array
    {
        return [
            'slug' => 'warrant-on-hire',
            'name' => 'Warrant on Hire',
            'type' => 'state_machine',
            'initial_states' => ['requested'],
            'states' => [
                ['slug' => 'requested', 'label' => 'Requested', 'state_type' => 'initial', 'status_category' => 'In Progress'],
                ['slug' => 'pending_approval', 'label' => 'Pending Approval', 'state_type' => 'intermediate', 'status_category' => 'In Progress'],
                ['slug' => 'active', 'label' => 'Active', 'state_type' => 'intermediate', 'status_category' => 'Active'],
                ['slug' => 'revoked', 'label' => 'Revoked', 'state_type' => 'final', 'status_category' => 'Closed'],
                ['slug' => 'expired', 'label' => 'Expired', 'state_type' => 'final', 'status_category' => 'Closed'],
                ['slug' => 'denied', 'label' => 'Denied', 'state_type' => 'final', 'status_category' => 'Closed'],
            ],
            'transitions' => [
                ['slug' => 'request', 'label' => 'Request Warrant', 'from' => 'requested', 'to' => 'pending_approval'],
                ['slug' => 'approve', 'label' => 'Approve', 'from' => 'pending_approval', 'to' => 'active'],
                ['slug' => 'deny', 'label' => 'Deny', 'from' => 'pending_approval', 'to' => 'denied'],
                ['slug' => 'revoke', 'label' => 'Revoke', 'from' => 'active', 'to' => 'revoked'],
                ['slug' => 'expire', 'label' => 'Expire', 'from' => 'active', 'to' => 'expired'],
            ],
        ];
    }

    /**
     * Create a roster subject with approval tracking fields.
     */
    private function makeRosterSubject(
        string $state = 'draft',
        int $requiredApprovals = 3,
        int $currentApprovals = 0,
        array $approvers = [],
        ?int $pendingApprover = null,
    ): \stdClass {
        $subject = new \stdClass();
        $subject->currentState = $state;
        $subject->requiredApprovals = $requiredApprovals;
        $subject->currentApprovals = $currentApprovals;
        $subject->approvers = $approvers;
        $subject->pendingApprover = $pendingApprover;
        return $subject;
    }

    /**
     * Create a warrant-on-hire subject.
     */
    private function makeHireSubject(
        string $state = 'requested',
        bool $officeRequiresWarrant = true,
    ): \stdClass {
        $subject = new \stdClass();
        $subject->currentState = $state;
        $subject->officeRequiresWarrant = $officeRequiresWarrant;
        return $subject;
    }

    /**
     * Build roster workflow with standard guards attached.
     */
    private function buildRosterWorkflowWithGuards(): Workflow
    {
        $workflow = $this->bridge->buildFromConfig($this->getRosterConfig());
        $dispatcher = $this->bridge->getDispatcher('warrant-roster');

        // Guard: "approve" requires currentApprovals >= requiredApprovals
        $dispatcher->addListener(
            'workflow.warrant-roster.guard.approve',
            function (GuardEvent $event) {
                $s = $event->getSubject();
                if ($s->currentApprovals < $s->requiredApprovals) {
                    $event->setBlocked(true, sprintf(
                        'Need %d approvals, have %d',
                        $s->requiredApprovals,
                        $s->currentApprovals,
                    ));
                }
            }
        );

        // Guard: "add_approval" and "partial_approve" block duplicate approvers.
        // The pending approver is set on subject->pendingApprover before can()/apply().
        foreach (['add_approval', 'partial_approve'] as $transition) {
            $dispatcher->addListener(
                "workflow.warrant-roster.guard.{$transition}",
                function (GuardEvent $event) {
                    $s = $event->getSubject();
                    $approverId = $s->pendingApprover ?? null;
                    if ($approverId !== null && in_array($approverId, $s->approvers, true)) {
                        $event->setBlocked(true, 'Duplicate approver');
                    }
                }
            );
        }

        return $workflow;
    }

    /**
     * Build warrant-on-hire workflow with standard guards attached.
     */
    private function buildHireWorkflowWithGuards(): Workflow
    {
        $workflow = $this->bridge->buildFromConfig($this->getWarrantOnHireConfig());
        $dispatcher = $this->bridge->getDispatcher('warrant-on-hire');

        // Guard: "request" blocked if office doesn't require warrant
        $dispatcher->addListener(
            'workflow.warrant-on-hire.guard.request',
            function (GuardEvent $event) {
                $s = $event->getSubject();
                if (!$s->officeRequiresWarrant) {
                    $event->setBlocked(true, 'Office does not require a warrant');
                }
            }
        );

        return $workflow;
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    /**
     * Verify 8-state roster workflow builds correctly.
     */
    public function testBuildRosterWorkflow(): void
    {
        $workflow = $this->bridge->buildFromConfig($this->getRosterConfig());

        $this->assertEquals('warrant-roster', $workflow->getName());

        $places = $workflow->getDefinition()->getPlaces();
        $this->assertCount(8, $places);
        $this->assertArrayHasKey('draft', $places);
        $this->assertArrayHasKey('issued', $places);
        $this->assertArrayHasKey('expired', $places);
        $this->assertArrayHasKey('rejected', $places);

        // Verify metadata
        $meta = $workflow->getDefinition()->getMetadataStore();
        $this->assertEquals('Approval', $meta->getPlaceMetadata('under_review')['status_category']);
        $this->assertEquals('Closed', $meta->getPlaceMetadata('rejected')['status_category']);
    }

    /**
     * Verify 6-state individual warrant workflow builds correctly.
     */
    public function testBuildWarrantOnHireWorkflow(): void
    {
        $workflow = $this->bridge->buildFromConfig($this->getWarrantOnHireConfig());

        $this->assertEquals('warrant-on-hire', $workflow->getName());

        $places = $workflow->getDefinition()->getPlaces();
        $this->assertCount(6, $places);
        $this->assertArrayHasKey('requested', $places);
        $this->assertArrayHasKey('active', $places);
        $this->assertArrayHasKey('denied', $places);

        $meta = $workflow->getDefinition()->getMetadataStore();
        $this->assertEquals('Active', $meta->getPlaceMetadata('active')['status_category']);
        $this->assertEquals('Closed', $meta->getPlaceMetadata('denied')['status_category']);
    }

    /**
     * Both workflows can coexist and operate on separate subjects.
     */
    public function testBothWorkflowsCoexistBySlug(): void
    {
        $roster = $this->bridge->buildFromConfig($this->getRosterConfig());
        $hire = $this->bridge->buildFromConfig($this->getWarrantOnHireConfig());

        $this->assertNotSame($roster, $hire);
        $this->assertEquals('warrant-roster', $roster->getName());
        $this->assertEquals('warrant-on-hire', $hire->getName());

        // Operate both independently
        $rosterSubject = $this->makeRosterSubject('draft');
        $hireSubject = $this->makeHireSubject('requested');

        $roster->apply($rosterSubject, 'submit');
        $this->assertEquals('submitted', $rosterSubject->currentState);

        $hire->apply($hireSubject, 'request');
        $this->assertEquals('pending_approval', $hireSubject->currentState);

        // Each workflow is unaffected by the other
        $this->assertEquals('submitted', $rosterSubject->currentState);
        $this->assertEquals('pending_approval', $hireSubject->currentState);
    }

    /**
     * Guard blocks "approve" until N approvals reached (threshold=3).
     */
    public function testRosterDynamicApprovalThreshold(): void
    {
        $workflow = $this->buildRosterWorkflowWithGuards();

        // Subject at partially_approved with only 2 of 3 required approvals
        $subject = $this->makeRosterSubject(
            state: 'partially_approved',
            requiredApprovals: 3,
            currentApprovals: 2,
        );

        $this->assertFalse($workflow->can($subject, 'approve'), 'Should be blocked: only 2/3 approvals');

        // Reach threshold
        $subject->currentApprovals = 3;
        $this->assertTrue($workflow->can($subject, 'approve'), 'Should be allowed: 3/3 approvals');

        // Exceed threshold also works
        $subject->currentApprovals = 5;
        $this->assertTrue($workflow->can($subject, 'approve'), 'Should be allowed: exceeds threshold');
    }

    /**
     * Guard blocks duplicate approvers on partial_approve and add_approval.
     */
    public function testRosterUniqueApproverEnforcement(): void
    {
        $workflow = $this->buildRosterWorkflowWithGuards();

        $subject = $this->makeRosterSubject(
            state: 'under_review',
            requiredApprovals: 3,
            currentApprovals: 0,
            approvers: [],
            pendingApprover: 101,
        );

        // First approver succeeds
        $this->assertTrue($workflow->can($subject, 'partial_approve'));
        $workflow->apply($subject, 'partial_approve');
        $subject->approvers[] = 101;
        $subject->currentApprovals = 1;
        $this->assertEquals('partially_approved', $subject->currentState);

        // Same approver blocked on add_approval
        $subject->pendingApprover = 101;
        $this->assertFalse(
            $workflow->can($subject, 'add_approval'),
            'Duplicate approver 101 should be blocked',
        );

        // Different approver succeeds
        $subject->pendingApprover = 102;
        $this->assertTrue(
            $workflow->can($subject, 'add_approval'),
            'New approver 102 should be allowed',
        );
    }

    /**
     * Walk through full roster lifecycle: Draft → Submitted → Under Review →
     * Partially Approved → Approved → Issued.
     */
    public function testRosterPartialApprovalFlow(): void
    {
        $workflow = $this->buildRosterWorkflowWithGuards();
        $subject = $this->makeRosterSubject('draft', requiredApprovals: 3);

        // Draft → Submitted
        $workflow->apply($subject, 'submit');
        $this->assertEquals('submitted', $subject->currentState);

        // Submitted → Under Review
        $workflow->apply($subject, 'start_review');
        $this->assertEquals('under_review', $subject->currentState);

        // Under Review → Partially Approved (first approval)
        $subject->pendingApprover = 1;
        $workflow->apply($subject, 'partial_approve');
        $subject->approvers[] = 1;
        $subject->currentApprovals = 1;
        $this->assertEquals('partially_approved', $subject->currentState);

        // Add second approval
        $subject->pendingApprover = 2;
        $workflow->apply($subject, 'add_approval');
        $subject->approvers[] = 2;
        $subject->currentApprovals = 2;
        $this->assertEquals('partially_approved', $subject->currentState);

        // Add third approval — now threshold met
        $subject->pendingApprover = 3;
        $workflow->apply($subject, 'add_approval');
        $subject->approvers[] = 3;
        $subject->currentApprovals = 3;
        $this->assertEquals('partially_approved', $subject->currentState);

        // Partially Approved → Approved (threshold met)
        $this->assertTrue($workflow->can($subject, 'approve'));
        $workflow->apply($subject, 'approve');
        $this->assertEquals('approved', $subject->currentState);

        // Approved → Issued
        $workflow->apply($subject, 'issue');
        $this->assertEquals('issued', $subject->currentState);
    }

    /**
     * "Issued" → "Expired" transition is available (simulates cron check).
     */
    public function testRosterExpirationTransition(): void
    {
        $workflow = $this->bridge->buildFromConfig($this->getRosterConfig());

        $subject = $this->makeRosterSubject('issued');

        // The expire transition should be available from "issued"
        $transitions = $workflow->getEnabledTransitions($subject);
        $names = array_map(fn($t) => $t->getName(), $transitions);
        $this->assertContains('expire', $names);
        $this->assertCount(1, $transitions, 'Only expire should be available from issued');

        // Apply it
        $workflow->apply($subject, 'expire');
        $this->assertEquals('expired', $subject->currentState);

        // No further transitions from expired
        $this->assertEmpty($workflow->getEnabledTransitions($subject));
    }

    /**
     * Guard blocks "request" if office doesn't require a warrant.
     */
    public function testWarrantOnHireFieldCondition(): void
    {
        $workflow = $this->buildHireWorkflowWithGuards();

        // Office that does NOT require warrant
        $noWarrant = $this->makeHireSubject('requested', officeRequiresWarrant: false);
        $this->assertFalse(
            $workflow->can($noWarrant, 'request'),
            'Should block: office does not require warrant',
        );

        // Office that DOES require warrant
        $needsWarrant = $this->makeHireSubject('requested', officeRequiresWarrant: true);
        $this->assertTrue(
            $workflow->can($needsWarrant, 'request'),
            'Should allow: office requires warrant',
        );
    }

    /**
     * Single approval transitions individual warrant to "Active".
     */
    public function testWarrantOnHireSingleApproval(): void
    {
        $workflow = $this->buildHireWorkflowWithGuards();
        $subject = $this->makeHireSubject('requested', officeRequiresWarrant: true);

        // Requested → Pending Approval
        $workflow->apply($subject, 'request');
        $this->assertEquals('pending_approval', $subject->currentState);

        // Pending Approval → Active (single approval, no multi-approval guard)
        $this->assertTrue($workflow->can($subject, 'approve'));
        $workflow->apply($subject, 'approve');
        $this->assertEquals('active', $subject->currentState);
    }

    /**
     * "Active" has transitions to both "Revoked" and "Expired".
     */
    public function testWarrantOnHireLongLivedState(): void
    {
        $workflow = $this->bridge->buildFromConfig($this->getWarrantOnHireConfig());
        $subject = $this->makeHireSubject('active');

        $transitions = $workflow->getEnabledTransitions($subject);
        $names = array_map(fn($t) => $t->getName(), $transitions);

        $this->assertContains('revoke', $names);
        $this->assertContains('expire', $names);
        $this->assertCount(2, $transitions, 'Active should have exactly revoke and expire');

        // Both terminal states are reachable
        $s1 = $this->makeHireSubject('active');
        $workflow->apply($s1, 'revoke');
        $this->assertEquals('revoked', $s1->currentState);

        $s2 = $this->makeHireSubject('active');
        $workflow->apply($s2, 'expire');
        $this->assertEquals('expired', $s2->currentState);
    }

    /**
     * "Rejected" is reachable from both Under Review and Partially Approved.
     */
    public function testRosterRejectionFromMultipleStates(): void
    {
        $workflow = $this->bridge->buildFromConfig($this->getRosterConfig());

        // Reject from Under Review
        $s1 = $this->makeRosterSubject('under_review');
        $this->assertTrue($workflow->can($s1, 'reject_from_review'));
        $workflow->apply($s1, 'reject_from_review');
        $this->assertEquals('rejected', $s1->currentState);
        $this->assertEmpty($workflow->getEnabledTransitions($s1));

        // Reject from Partially Approved
        $s2 = $this->makeRosterSubject('partially_approved');
        $this->assertTrue($workflow->can($s2, 'reject_from_partial'));
        $workflow->apply($s2, 'reject_from_partial');
        $this->assertEquals('rejected', $s2->currentState);
        $this->assertEmpty($workflow->getEnabledTransitions($s2));
    }

    /**
     * Performance: 200 subjects, getEnabledTransitions() on each < 100ms total.
     */
    public function testPerformanceBulkEnabledTransitions(): void
    {
        $workflow = $this->bridge->buildFromConfig($this->getRosterConfig());

        $states = ['draft', 'submitted', 'under_review', 'partially_approved', 'approved', 'issued'];
        $subjects = [];
        for ($i = 0; $i < 200; $i++) {
            $subjects[] = $this->makeRosterSubject($states[$i % count($states)]);
        }

        $start = hrtime(true);
        foreach ($subjects as $subject) {
            $workflow->getEnabledTransitions($subject);
        }
        $elapsedMs = (hrtime(true) - $start) / 1_000_000;

        $this->assertLessThan(
            100.0,
            $elapsedMs,
            sprintf('Bulk getEnabledTransitions took %.2f ms (limit: 100ms)', $elapsedMs),
        );
    }
}
