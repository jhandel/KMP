<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Entity;

use App\Model\Entity\TenantOperationJob;
use App\Test\TestCase\BaseTestCase;

class TenantOperationJobTest extends BaseTestCase
{
    public function testLifecycleStateFallsBackToStatus(): void
    {
        $job = new TenantOperationJob([
            'status' => TenantOperationJob::STATUS_APPROVED,
        ]);

        $this->assertSame(TenantOperationJob::STATUS_APPROVED, $job->lifecycle_state);
    }

    public function testLifecycleStatePrefersState(): void
    {
        $job = new TenantOperationJob([
            'status' => TenantOperationJob::STATUS_QUEUED,
            'state' => TenantOperationJob::STATUS_RUNNING,
        ]);

        $this->assertSame(TenantOperationJob::STATUS_RUNNING, $job->lifecycle_state);
    }

    public function testTerminalStateDetection(): void
    {
        $job = new TenantOperationJob(['state' => TenantOperationJob::STATUS_COMPLETED]);
        $this->assertTrue($job->isTerminalState());

        $job = new TenantOperationJob(['state' => TenantOperationJob::STATUS_BLOCKED]);
        $this->assertFalse($job->isTerminalState());
    }
}
