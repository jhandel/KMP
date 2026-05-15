<?php
declare(strict_types=1);

namespace App\Test\TestCase\Services\Tenant;

use App\Services\Tenant\TenantOperationLockException;
use App\Services\Tenant\TenantOperationLockService;
use App\Test\TestCase\BaseTestCase;
use Cake\I18n\DateTime;

class TenantOperationLockServiceTest extends BaseTestCase
{
    private TenantOperationLockService $service;

    private int $tenantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TenantOperationLockService();
        $tenants = $this->getTableLocator()->get('Tenants');
        $tenant = $tenants->find()
            ->select(['id'])
            ->where(['slug' => 'test'])
            ->enableHydration(false)
            ->first();
        if ($tenant === null) {
            $created = $tenants->newEntity([
                'slug' => 'test',
                'display_name' => 'Test Tenant',
                'status' => 'active',
                'primary_host' => 'test.example.test',
            ]);
            $tenants->saveOrFail($created);
            $this->tenantId = (int)$created->id;
        } else {
            $this->tenantId = (int)$tenant['id'];
        }
        $this->getTableLocator()->get('TenantOperationLocks')->deleteAll(['tenant_id' => $this->tenantId]);
    }

    public function testAcquireAndRelease(): void
    {
        $token = $this->service->acquire($this->tenantId, 'tenant_backup', 'test-runner');

        $this->assertNotSame('', $token);
        $this->assertRecordExists('TenantOperationLocks', [
            'tenant_id' => $this->tenantId,
            'lease_token' => $token,
            'operation' => 'tenant_backup',
        ]);

        $this->service->release($this->tenantId, $token);
        $this->assertRecordNotExists('TenantOperationLocks', [
            'tenant_id' => $this->tenantId,
            'lease_token' => $token,
        ]);
    }

    public function testAcquireConflictThrowsOperatorFriendlyMessage(): void
    {
        $this->service->acquire($this->tenantId, 'tenant_backup', 'test-runner-a');

        $this->expectException(TenantOperationLockException::class);
        $this->expectExceptionMessage('Tenant operation lock is held by');
        $this->service->acquire($this->tenantId, 'tenant_restore_cutover', 'test-runner-b');
    }

    public function testAcquireRecoversStaleLease(): void
    {
        $token = $this->service->acquire($this->tenantId, 'tenant_backup', 'test-runner-a');
        $locks = $this->getTableLocator()->get('TenantOperationLocks');
        $lock = $locks->find()->where([
            'tenant_id' => $this->tenantId,
            'lease_token' => $token,
        ])->firstOrFail();
        $lock->lease_expires_at = DateTime::now()->subMinutes(1);
        $lock->heartbeat_at = DateTime::now()->subMinutes(10);
        $locks->saveOrFail($lock);

        $replacementToken = $this->service->acquire($this->tenantId, 'tenant_restore_cutover', 'test-runner-b');
        $replacement = $locks->find()->where(['tenant_id' => $this->tenantId])->firstOrFail();

        $this->assertNotSame($token, $replacementToken);
        $this->assertSame($replacementToken, (string)$replacement->lease_token);
        $this->assertSame('tenant_restore_cutover', (string)$replacement->operation);
        $this->assertNotNull($replacement->stale_recovered_at);
    }

    public function testHeartbeatRefreshesLeaseForOwnedToken(): void
    {
        $token = $this->service->acquire($this->tenantId, 'tenant_backup', 'test-runner');
        $locks = $this->getTableLocator()->get('TenantOperationLocks');
        $before = $locks->find()->where([
            'tenant_id' => $this->tenantId,
            'lease_token' => $token,
        ])->firstOrFail();
        $previousExpiry = $before->lease_expires_at;

        $this->service->heartbeat($this->tenantId, $token, 1200);

        $after = $locks->find()->where([
            'tenant_id' => $this->tenantId,
            'lease_token' => $token,
        ])->firstOrFail();
        $this->assertNotNull($after->heartbeat_at);
        $this->assertNotNull($after->lease_expires_at);
        $this->assertGreaterThan(
            $previousExpiry?->getTimestamp() ?? 0,
            $after->lease_expires_at?->getTimestamp() ?? 0,
        );
    }

    public function testRunWithLockReleasesLockWhenCallbackFails(): void
    {
        try {
            $this->service->runWithLock(
                $this->tenantId,
                'tenant_backup',
                'test-runner',
                function (): void {
                    throw new \RuntimeException('boom');
                },
            );
            $this->fail('Expected runtime exception from lock callback.');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertRecordNotExists('TenantOperationLocks', [
            'tenant_id' => $this->tenantId,
        ]);
    }
}
