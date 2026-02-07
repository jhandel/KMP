<?php
declare(strict_types=1);

namespace App\Test\Spike;

use App\Services\WorkflowEngine\CakeOrmMarkingStore;
use App\Services\WorkflowEngine\WorkflowBridge;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Workflow\Event\GuardEvent;

/**
 * Spike test validating symfony/workflow integration with KMP.
 */
class WorkflowSpikeTest extends TestCase
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

    /**
     * Simple 3-state workflow: draft → review → published
     */
    private function getSimpleConfig(): array
    {
        return [
            'slug' => 'test-simple',
            'name' => 'Simple Test Workflow',
            'type' => 'state_machine',
            'initial_states' => ['draft'],
            'states' => [
                ['slug' => 'draft', 'label' => 'Draft', 'state_type' => 'initial', 'status_category' => 'Open'],
                ['slug' => 'review', 'label' => 'In Review', 'state_type' => 'intermediate', 'status_category' => 'Open'],
                ['slug' => 'published', 'label' => 'Published', 'state_type' => 'final', 'status_category' => 'Closed'],
                ['slug' => 'rejected', 'label' => 'Rejected', 'state_type' => 'final', 'status_category' => 'Closed'],
            ],
            'transitions' => [
                ['slug' => 'submit', 'label' => 'Submit for Review', 'from' => 'draft', 'to' => 'review'],
                ['slug' => 'approve', 'label' => 'Approve', 'from' => 'review', 'to' => 'published'],
                ['slug' => 'reject', 'label' => 'Reject', 'from' => 'review', 'to' => 'rejected'],
                ['slug' => 'revise', 'label' => 'Send Back', 'from' => 'review', 'to' => 'draft'],
            ],
        ];
    }

    public function testBuildFromConfig(): void
    {
        $workflow = $this->bridge->buildFromConfig($this->getSimpleConfig());
        $this->assertNotNull($workflow);
        $this->assertEquals('test-simple', $workflow->getName());
    }

    public function testInitialMarking(): void
    {
        $workflow = $this->bridge->buildFromConfig($this->getSimpleConfig());
        $subject = new \stdClass();
        $subject->currentState = null;

        $marking = $workflow->getMarking($subject);
        $this->assertArrayHasKey('draft', $marking->getPlaces());
        // After getMarking, the subject should be updated to initial state
        $this->assertEquals('draft', $subject->currentState);
    }

    public function testCanCheckTransition(): void
    {
        $workflow = $this->bridge->buildFromConfig($this->getSimpleConfig());
        $subject = new \stdClass();
        $subject->currentState = 'draft';

        $this->assertTrue($workflow->can($subject, 'submit'));
        $this->assertFalse($workflow->can($subject, 'approve'));
        $this->assertFalse($workflow->can($subject, 'reject'));
    }

    public function testApplyTransition(): void
    {
        $workflow = $this->bridge->buildFromConfig($this->getSimpleConfig());
        $subject = new \stdClass();
        $subject->currentState = 'draft';

        $workflow->apply($subject, 'submit');
        $this->assertEquals('review', $subject->currentState);

        $this->assertTrue($workflow->can($subject, 'approve'));
        $this->assertTrue($workflow->can($subject, 'reject'));
        $this->assertTrue($workflow->can($subject, 'revise'));
        $this->assertFalse($workflow->can($subject, 'submit'));
    }

    public function testGetEnabledTransitions(): void
    {
        $workflow = $this->bridge->buildFromConfig($this->getSimpleConfig());
        $subject = new \stdClass();
        $subject->currentState = 'review';

        $transitions = $workflow->getEnabledTransitions($subject);
        $names = array_map(fn($t) => $t->getName(), $transitions);

        $this->assertContains('approve', $names);
        $this->assertContains('reject', $names);
        $this->assertContains('revise', $names);
        $this->assertNotContains('submit', $names);
    }

    public function testGuardEventBlocksTransition(): void
    {
        $config = $this->getSimpleConfig();
        $workflow = $this->bridge->buildFromConfig($config);
        $dispatcher = $this->bridge->getDispatcher('test-simple');

        // Register a guard that blocks 'approve' unless subject has isApproved=true
        $dispatcher->addListener(
            'workflow.test-simple.guard.approve',
            function (GuardEvent $event) {
                $subject = $event->getSubject();
                if (empty($subject->isApproved)) {
                    $event->setBlocked(true, 'Approval not granted');
                }
            }
        );

        $subject = new \stdClass();
        $subject->currentState = 'review';
        $subject->isApproved = false;

        // Approve should be blocked
        $this->assertFalse($workflow->can($subject, 'approve'));

        // But reject and revise should still work
        $this->assertTrue($workflow->can($subject, 'reject'));

        // Now grant approval
        $subject->isApproved = true;
        $this->assertTrue($workflow->can($subject, 'approve'));
    }

    public function testTransitionEventFires(): void
    {
        $config = $this->getSimpleConfig();
        $workflow = $this->bridge->buildFromConfig($config);
        $dispatcher = $this->bridge->getDispatcher('test-simple');

        $firedEvents = [];
        $dispatcher->addListener(
            'workflow.test-simple.entered.review',
            function () use (&$firedEvents) {
                $firedEvents[] = 'entered_review';
            }
        );
        $dispatcher->addListener(
            'workflow.test-simple.leave.draft',
            function () use (&$firedEvents) {
                $firedEvents[] = 'leave_draft';
            }
        );

        $subject = new \stdClass();
        $subject->currentState = 'draft';
        $workflow->apply($subject, 'submit');

        $this->assertContains('leave_draft', $firedEvents);
        $this->assertContains('entered_review', $firedEvents);
    }

    public function testMetadataAccessible(): void
    {
        $workflow = $this->bridge->buildFromConfig($this->getSimpleConfig());
        $metadataStore = $workflow->getDefinition()->getMetadataStore();

        // Workflow-level metadata
        $this->assertEquals('test-simple', $metadataStore->getWorkflowMetadata()['slug']);

        // Place metadata
        $draftMeta = $metadataStore->getPlaceMetadata('draft');
        $this->assertEquals('Draft', $draftMeta['label']);
        $this->assertEquals('initial', $draftMeta['state_type']);
        $this->assertEquals('Open', $draftMeta['status_category']);
    }

    public function testContextPassedDuringApply(): void
    {
        $config = $this->getSimpleConfig();
        $workflow = $this->bridge->buildFromConfig($config);
        $dispatcher = $this->bridge->getDispatcher('test-simple');

        $capturedContext = null;
        $dispatcher->addListener(
            'workflow.test-simple.transition.submit',
            function ($event) use (&$capturedContext) {
                $capturedContext = $event->getContext();
            }
        );

        $subject = new \stdClass();
        $subject->currentState = 'draft';
        $workflow->apply($subject, 'submit', ['triggered_by' => 42, 'reason' => 'Ready for review']);

        $this->assertNotNull($capturedContext);
        $this->assertEquals(42, $capturedContext['triggered_by']);
        $this->assertEquals('Ready for review', $capturedContext['reason']);
    }

    public function testFinalStateHasNoTransitions(): void
    {
        $workflow = $this->bridge->buildFromConfig($this->getSimpleConfig());
        $subject = new \stdClass();
        $subject->currentState = 'published';

        $transitions = $workflow->getEnabledTransitions($subject);
        $this->assertEmpty($transitions);
    }

    public function testCacheReturnsSameInstance(): void
    {
        $config = $this->getSimpleConfig();
        $w1 = $this->bridge->buildFromConfig($config);
        $w2 = $this->bridge->buildFromConfig($config);
        $this->assertSame($w1, $w2);
    }

    public function testClearCacheRebuilds(): void
    {
        $config = $this->getSimpleConfig();
        $w1 = $this->bridge->buildFromConfig($config);
        $this->bridge->clearCache('test-simple');
        $w2 = $this->bridge->buildFromConfig($config);
        $this->assertNotSame($w1, $w2);
    }
}
