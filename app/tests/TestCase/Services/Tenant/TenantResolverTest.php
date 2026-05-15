<?php
declare(strict_types=1);

namespace App\Test\TestCase\Services\Tenant;

use App\Model\Entity\Tenant;
use App\Model\Entity\TenantDatabaseConfig;
use App\Model\Entity\TenantServiceConfig;
use App\Model\Table\TenantAliasesTable;
use App\Model\Table\TenantDatabaseConfigsTable;
use App\Model\Table\TenantRuntimeInvalidationVersionsTable;
use App\Model\Table\TenantServiceConfigsTable;
use App\Model\Table\TenantsTable;
use App\Services\Telemetry\TenantMetrics;
use App\Services\Tenant\TenantResolutionException;
use App\Services\Tenant\TenantResolver;
use Cake\TestSuite\TestCase;

class TenantResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        TenantMetrics::reset();
    }

    protected function tearDown(): void
    {
        TenantMetrics::reset();
        parent::tearDown();
    }

    public function testNormalizeHostLowercasesStripsPortAndTrailingDot(): void
    {
        $this->assertSame('tenant.example.org', TenantResolver::normalizeHost('Tenant.Example.ORG:8443.'));
        $this->assertSame('tenant.example.org', TenantResolver::normalizeHost('https://Tenant.Example.ORG:8443/path'));
        $this->assertSame('tenant.example.org', TenantResolver::normalizeHost(' tenant.example.org. '));
    }

    public function testNormalizeHostHandlesIpv6HostHeader(): void
    {
        $this->assertSame('2001:db8::1', TenantResolver::normalizeHost('[2001:DB8::1]:8443'));
    }

    public function testResolveActiveTenantByAliasHost(): void
    {
        $tenant = $this->tenant(['slug' => 'alias-tenant']);
        $registry = new FakeTenantRegistry([
            'alias.example.org' => $tenant,
        ]);
        $resolver = new TenantResolver($registry);

        $context = $resolver->resolveHost('Alias.Example.ORG:443.');

        $this->assertSame('alias-tenant', $context->slug);
        $this->assertSame('alias.example.org', $context->resolvedHost);
        $this->assertSame(['alias.example.org'], $registry->requestedHosts);
        $this->assertSame(1, $this->metricHistogramCount(
            'kmp_tenant_resolution_duration_ms',
            'outcome=success',
        ));
        $this->assertSame(1, $this->metricCounterValue(
            'kmp_tenant_health_signal_total',
            'signal=resolution_success',
        ));
    }

    public function testResolveActiveTenantByPrimaryHost(): void
    {
        $tenant = $this->tenant([
            'slug' => 'primary-tenant',
            'primary_host' => 'primary.example.org',
        ]);
        $registry = new FakeTenantRegistry([
            'primary.example.org' => $tenant,
        ]);
        $resolver = new TenantResolver($registry);

        $context = $resolver->resolveHost('PRIMARY.example.org');

        $this->assertSame('primary-tenant', $context->slug);
        $this->assertSame('primary.example.org', $context->primaryHost);
    }

    public function testResolveHostStaysWithinUnitLatencyBudget(): void
    {
        $tenant = $this->tenant(['slug' => 'budget-tenant']);
        $resolver = new TenantResolver(new FakeTenantRegistry([
            'budget.example.org' => $tenant,
        ]));

        $startedAt = hrtime(true);
        $context = $resolver->resolveHost('budget.example.org');
        $elapsedMilliseconds = (hrtime(true) - $startedAt) / 1_000_000;

        $this->assertSame('budget-tenant', $context->slug);
        $this->assertLessThan(
            250.0,
            $elapsedMilliseconds,
            sprintf('Tenant resolution exceeded budget: %.2fms', $elapsedMilliseconds),
        );
    }

    public function testResolveRejectsUnknownTenant(): void
    {
        $resolver = new TenantResolver(new FakeTenantRegistry([]));

        $this->expectException(TenantResolutionException::class);
        $this->expectExceptionMessage('No tenant is registered');

        try {
            $resolver->resolveHost('missing.example.org');
        } catch (TenantResolutionException $exception) {
            $this->assertSame(TenantResolutionException::UNKNOWN_TENANT, $exception->getReason());
            $this->assertSame('missing.example.org', $exception->getHost());
            $this->assertSame(1, $this->metricHistogramCount(
                'kmp_tenant_resolution_duration_ms',
                'outcome=unknown_tenant',
            ));
            $this->assertSame(1, $this->metricCounterValue(
                'kmp_tenant_health_signal_total',
                'signal=resolution_unknown_tenant',
            ));

            throw $exception;
        }
    }

    public function testResolveRejectsInactiveTenant(): void
    {
        $tenant = $this->tenant([
            'slug' => 'disabled-tenant',
            'status' => Tenant::STATUS_DISABLED,
        ]);
        $resolver = new TenantResolver(new FakeTenantRegistry([
            'disabled.example.org' => $tenant,
        ]));

        $this->expectException(TenantResolutionException::class);

        try {
            $resolver->resolveHost('disabled.example.org');
        } catch (TenantResolutionException $exception) {
            $this->assertSame(TenantResolutionException::INACTIVE_TENANT, $exception->getReason());
            $this->assertSame('disabled-tenant', $exception->getTenantSlug());

            throw $exception;
        }
    }

    public function testResolveRejectsDrainingTenantWithDrainReason(): void
    {
        $tenant = $this->tenant([
            'slug' => 'draining-tenant',
            'status' => Tenant::STATUS_DRAINING,
        ]);
        $resolver = new TenantResolver(new FakeTenantRegistry([
            'draining.example.org' => $tenant,
        ]));

        $this->expectException(TenantResolutionException::class);

        try {
            $resolver->resolveHost('draining.example.org');
        } catch (TenantResolutionException $exception) {
            $this->assertSame(TenantResolutionException::DRAINING_TENANT, $exception->getReason());
            $this->assertSame('draining-tenant', $exception->getTenantSlug());

            throw $exception;
        }
    }

    public function testResolveRejectsSchemaMismatch(): void
    {
        $tenant = $this->tenant([
            'slug' => 'old-schema',
            'schema_version' => '2024.01',
        ]);
        $resolver = new TenantResolver(new FakeTenantRegistry([
            'old.example.org' => $tenant,
        ]), '2026.04');

        $this->expectException(TenantResolutionException::class);

        try {
            $resolver->resolveHost('old.example.org');
        } catch (TenantResolutionException $exception) {
            $this->assertSame(TenantResolutionException::SCHEMA_MISMATCH, $exception->getReason());

            throw $exception;
        }
    }

    public function testContextIncludesActiveDatabaseConfigMetadata(): void
    {
        $tenant = $this->tenant([
            'tenant_database_configs' => [
                new TenantDatabaseConfig([
                    'id' => 99,
                    'connection_role' => 'primary',
                    'driver' => 'Cake\Database\Driver\Mysql',
                    'host' => 'db.example.org',
                    'port' => 3306,
                    'database_name' => 'tenant_db',
                    'username' => 'tenant_user',
                    'secret_reference' => 'secret://tenant/db',
                    'read_enabled' => true,
                    'write_enabled' => true,
                    'is_active' => true,
                    'metadata' => ['region' => 'local'],
                ]),
            ],
        ]);
        $resolver = new TenantResolver(new FakeTenantRegistry([
            'tenant.example.org' => $tenant,
        ]));

        $context = $resolver->resolveHost('tenant.example.org');

        $this->assertCount(1, $context->databaseConfigs);
        $this->assertSame('tenant_db', $context->databaseConfigs[0]['databaseName']);
        $this->assertSame('secret://tenant/db', $context->databaseConfigs[0]['secretReference']);
    }

    public function testContextIncludesActiveServiceConfigMetadata(): void
    {
        $tenant = $this->tenant([
            'tenant_service_configs' => [
                new TenantServiceConfig([
                    'id' => 7,
                    'service_name' => 'storage',
                    'config_key' => 'default',
                    'adapter' => 's3',
                    'secret_reference' => 'env:TENANT_S3_SECRET',
                    'metadata' => ['s3' => ['bucket' => 'tenant-bucket']],
                    'is_active' => true,
                ]),
            ],
        ]);
        $resolver = new TenantResolver(new FakeTenantRegistry([
            'tenant.example.org' => $tenant,
        ]));

        $context = $resolver->resolveHost('tenant.example.org');

        $this->assertCount(1, $context->serviceConfigs);
        $this->assertSame('storage', $context->serviceConfigs[0]['serviceName']);
        $this->assertSame('s3', $context->serviceConfigs[0]['adapter']);
        $this->assertSame('env:TENANT_S3_SECRET', $context->serviceConfigs[0]['secretReference']);
        $this->assertSame('tenant-bucket', $context->serviceConfigs[0]['metadata']['s3']['bucket']);
    }

    public function testPlatformTablesUsePlatformConnectionName(): void
    {
        $this->assertSame('platform', TenantsTable::defaultConnectionName());
        $this->assertSame('platform', TenantAliasesTable::defaultConnectionName());
        $this->assertSame('platform', TenantDatabaseConfigsTable::defaultConnectionName());
        $this->assertSame('platform', TenantServiceConfigsTable::defaultConnectionName());
        $this->assertSame('platform', TenantRuntimeInvalidationVersionsTable::defaultConnectionName());
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function tenant(array $overrides = []): Tenant
    {
        return new Tenant($overrides + [
            'id' => 42,
            'slug' => 'tenant',
            'display_name' => 'Test Tenant',
            'status' => Tenant::STATUS_ACTIVE,
            'schema_version' => '2026.04',
            'primary_host' => null,
            'tenant_database_configs' => [],
            'tenant_service_configs' => [],
        ]);
    }

    private function metricCounterValue(string $metric, string $labelKey): int
    {
        $snapshot = TenantMetrics::snapshot();

        return (int)($snapshot['counters'][$metric][$labelKey] ?? 0);
    }

    private function metricHistogramCount(string $metric, string $labelKey): int
    {
        $snapshot = TenantMetrics::snapshot();

        return (int)($snapshot['histograms'][$metric][$labelKey]['count'] ?? 0);
    }
}
