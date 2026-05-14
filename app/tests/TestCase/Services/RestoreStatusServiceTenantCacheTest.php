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
}
