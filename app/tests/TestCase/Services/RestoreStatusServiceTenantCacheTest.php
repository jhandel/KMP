<?php
declare(strict_types=1);

namespace App\Test\TestCase\Services;

use App\Services\RestoreStatusService;
use App\Services\Tenant\TenantContext;
use Cake\Cache\Cache;
use Cake\TestSuite\TestCase;

class RestoreStatusServiceTenantCacheTest extends TestCase
{
    protected function tearDown(): void
    {
        Cache::delete(TenantContext::cacheKey('restore.lock'), 'restore_status');
        Cache::delete(TenantContext::cacheKey('restore.status'), 'restore_status');
        TenantContext::clearCurrent();
        Cache::delete(TenantContext::cacheKey('restore.lock'), 'restore_status');
        Cache::delete(TenantContext::cacheKey('restore.status'), 'restore_status');
        parent::tearDown();
    }

    public function testRestoreStatusKeysAreTenantPrefixedWhenContextIsActive(): void
    {
        TenantContext::setCurrent(new TenantContext(
            7,
            'tenant-b',
            'Tenant B',
            'active',
            null,
            null,
            'tenant-b.example.org',
        ));

        $service = new RestoreStatusService();
        $this->assertTrue($service->acquireLock(['message' => 'tenant restore']));

        $this->assertIsArray(Cache::read(TenantContext::cacheKey('restore.lock'), 'restore_status'));
        $this->assertIsArray(Cache::read(TenantContext::cacheKey('restore.status'), 'restore_status'));
        $this->assertNull(Cache::read('restore.lock', 'restore_status'));
        $this->assertNull(Cache::read('restore.status', 'restore_status'));
    }

    public function testIdenticalRestoreStatusKeysStayIsolatedAcrossTenants(): void
    {
        $service = new RestoreStatusService();
        $tenantA = $this->tenantContext(71, 'tenant-a');
        $tenantB = $this->tenantContext(72, 'tenant-b');

        TenantContext::setCurrent($tenantA);
        $this->assertTrue($service->acquireLock(['owner' => 'tenant-a']));
        $tenantALockKey = TenantContext::cacheKey('restore.lock');

        TenantContext::setCurrent($tenantB);
        $this->assertNull(Cache::read(TenantContext::cacheKey('restore.lock'), 'restore_status'));
        $this->assertTrue($service->acquireLock(['owner' => 'tenant-b']));
        $tenantBLockKey = TenantContext::cacheKey('restore.lock');

        $this->assertNotSame($tenantALockKey, $tenantBLockKey);
        $this->assertSame('tenant-b', Cache::read($tenantBLockKey, 'restore_status')['owner']);

        TenantContext::setCurrent($tenantA);
        $this->assertSame('tenant-a', Cache::read(TenantContext::cacheKey('restore.lock'), 'restore_status')['owner']);
        $this->assertNotSame(
            Cache::read($tenantALockKey, 'restore_status')['owner'],
            Cache::read($tenantBLockKey, 'restore_status')['owner'],
        );
    }

    private function tenantContext(int $id, string $slug): TenantContext
    {
        return new TenantContext(
            $id,
            $slug,
            strtoupper($slug),
            'active',
            null,
            $slug . '.example.org',
            $slug . '.example.org',
        );
    }
}
