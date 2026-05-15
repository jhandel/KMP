<?php
declare(strict_types=1);

namespace App\Test\TestCase\Services\Tenant;

use App\Services\Tenant\TenantInvalidationService;
use Cake\TestSuite\TestCase;
use Migrations\Migrations;

class TenantInvalidationServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (new Migrations())->migrate([
            'connection' => 'test',
            'source' => 'PlatformMigrations',
        ]);
        $this->getTableLocator()->get('TenantRuntimeInvalidationVersions')->deleteAll([]);
        TenantInvalidationService::clearLocalCache();
    }

    protected function tearDown(): void
    {
        TenantInvalidationService::clearLocalCache();
        parent::tearDown();
    }

    public function testBumpTenantIncrementsVersion(): void
    {
        $service = new TenantInvalidationService(pollIntervalSeconds: 1);

        $this->assertSame(0, $service->tenantVersion(42));
        $this->assertSame(1, $service->bumpTenant(42, 'tenant_config_updated'));
        $this->assertSame(1, $service->tenantVersion(42));
        $this->assertSame(2, $service->bumpTenant(42, 'tenant_secret_rotated'));
        $this->assertSame(2, $service->tenantVersion(42));
    }

    public function testEffectiveVersionIncludesGlobalBumps(): void
    {
        $service = new TenantInvalidationService(pollIntervalSeconds: 1);
        $service->bumpTenant(12, 'tenant_config_updated');
        $service->bumpGlobal('tenant_registry_updated');

        $this->assertSame(1, $service->tenantVersion(12));
        $this->assertSame(1, $service->globalVersion());
        $this->assertSame(1, $service->effectiveVersion(12));
    }
}
