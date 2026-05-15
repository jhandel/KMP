<?php
declare(strict_types=1);

namespace App\Test\TestCase\Services\Tenant;

use App\Model\Entity\Tenant;
use App\Services\Telemetry\TenantMetrics;
use App\Services\Tenant\TenantInvalidationService;
use App\Services\Tenant\TenantRegistry;
use Cake\TestSuite\TestCase;
use Migrations\Migrations;

class TenantRegistryInvalidationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (new Migrations())->migrate([
            'connection' => 'test',
            'source' => 'PlatformMigrations',
        ]);
        $this->getTableLocator()->get('TenantRuntimeInvalidationVersions')->deleteAll([]);
        $this->getTableLocator()->get('TenantAliases')->deleteAll([]);
        $this->getTableLocator()->get('TenantDatabaseConfigs')->deleteAll([]);
        $this->getTableLocator()->get('TenantServiceConfigs')->deleteAll([]);
        $this->getTableLocator()->get('Tenants')->deleteAll([]);
        TenantInvalidationService::clearLocalCache();
        TenantRegistry::clearLocalCache();
        TenantMetrics::reset();
    }

    protected function tearDown(): void
    {
        TenantInvalidationService::clearLocalCache();
        TenantRegistry::clearLocalCache();
        TenantMetrics::reset();
        parent::tearDown();
    }

    public function testHostLookupRefreshesAfterTenantInvalidationBump(): void
    {
        $tenants = $this->getTableLocator()->get('Tenants');
        $aliases = $this->getTableLocator()->get('TenantAliases');
        $tenant = $tenants->newEntity([
            'slug' => 'cache-test',
            'display_name' => 'Cache Test',
            'status' => Tenant::STATUS_ACTIVE,
            'primary_host' => 'cache-test.example.org',
        ]);
        $tenants->saveOrFail($tenant);
        $aliases->saveOrFail($aliases->newEntity([
            'tenant_id' => $tenant->id,
            'alias_type' => 'host',
            'value' => 'cache-test.example.org',
            'normalized_value' => 'cache-test.example.org',
            'priority' => 0,
            'is_active' => true,
        ]));

        $invalidationService = new TenantInvalidationService(pollIntervalSeconds: 1);
        $registry = new TenantRegistry($invalidationService);

        $first = $registry->findTenantForHost('cache-test.example.org');
        $this->assertSame(Tenant::STATUS_ACTIVE, $first?->status);

        $tenant->status = Tenant::STATUS_DISABLED;
        $tenants->saveOrFail($tenant);

        $stillCached = $registry->findTenantForHost('cache-test.example.org');
        $this->assertSame(Tenant::STATUS_ACTIVE, $stillCached?->status);
        $this->assertSame(1, $this->metricCounter('kmp_tenant_registry_query_total', 'outcome=cache_hit'));

        $invalidationService->bumpTenant((int)$tenant->id, 'tenant_status_changed');
        $refreshed = $registry->findTenantForHost('cache-test.example.org');
        $this->assertSame(Tenant::STATUS_DISABLED, $refreshed?->status);
        $this->assertSame(1, $this->metricCounter('kmp_tenant_registry_query_total', 'outcome=cache_stale'));
        $this->assertSame(2, $this->metricCounter('kmp_tenant_registry_query_total', 'outcome=alias_hit'));
    }

    public function testHostLookupRecoversFromMissAfterGlobalInvalidationBump(): void
    {
        $tenants = $this->getTableLocator()->get('Tenants');
        $aliases = $this->getTableLocator()->get('TenantAliases');
        $invalidationService = new TenantInvalidationService(pollIntervalSeconds: 1);
        $registry = new TenantRegistry($invalidationService);

        $this->assertNull($registry->findTenantForHost('late-bind.example.org'));

        $tenant = $tenants->newEntity([
            'slug' => 'late-bind',
            'display_name' => 'Late Bind',
            'status' => Tenant::STATUS_ACTIVE,
            'primary_host' => 'late-bind.example.org',
        ]);
        $tenants->saveOrFail($tenant);
        $aliases->saveOrFail($aliases->newEntity([
            'tenant_id' => $tenant->id,
            'alias_type' => 'host',
            'value' => 'late-bind.example.org',
            'normalized_value' => 'late-bind.example.org',
            'priority' => 0,
            'is_active' => true,
        ]));

        $this->assertNull($registry->findTenantForHost('late-bind.example.org'));

        $invalidationService->bumpGlobal('tenant_registry_updated');
        $resolved = $registry->findTenantForHost('late-bind.example.org');

        $this->assertNotNull($resolved);
        $this->assertSame('late-bind', $resolved?->slug);
        $this->assertSame(1, $this->metricCounter('kmp_tenant_registry_query_total', 'outcome=miss'));
        $this->assertSame(1, $this->metricCounter('kmp_tenant_registry_query_total', 'outcome=cache_hit'));
        $this->assertSame(1, $this->metricCounter('kmp_tenant_registry_query_total', 'outcome=alias_hit'));
    }

    private function metricCounter(string $metric, string $labelKey): int
    {
        $snapshot = TenantMetrics::snapshot();

        return (int)($snapshot['counters'][$metric][$labelKey] ?? 0);
    }
}
