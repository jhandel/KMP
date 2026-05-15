<?php
declare(strict_types=1);

namespace App\Test\TestCase\Services\Tenant;

use App\Services\Telemetry\TenantMetrics;
use App\Services\Tenant\TenantConnectionPoolMonitor;
use App\Services\Tenant\TenantContext;
use Cake\Database\Connection;
use Cake\Database\Driver\Sqlite;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;

class TenantConnectionPoolMonitorTest extends TestCase
{
    /**
     * @var array<string, mixed>|null
     */
    private ?array $originalTenantConfig = null;

    /**
     * @var array<string, string>
     */
    private array $originalAliases = [];

    protected function setUp(): void
    {
        parent::setUp();
        TenantMetrics::reset();
        $this->originalTenantConfig = ConnectionManager::getConfig('tenant');
        $this->originalAliases = ConnectionManager::aliases();
    }

    protected function tearDown(): void
    {
        TenantContext::clearCurrent();
        TenantMetrics::reset();
        ConnectionManager::dropAlias('tenant');
        ConnectionManager::drop('tenant');
        foreach (ConnectionManager::aliases() as $alias => $source) {
            ConnectionManager::dropAlias($alias);
        }
        foreach ($this->originalAliases as $alias => $source) {
            ConnectionManager::alias($source, $alias);
        }
        if (!isset($this->originalAliases['tenant']) && $this->originalTenantConfig !== null) {
            ConnectionManager::setConfig('tenant', $this->originalTenantConfig);
        }
        parent::tearDown();
    }

    public function testSampleTenantPoolSkipsWhenNoTenantContext(): void
    {
        $sample = (new TenantConnectionPoolMonitor())->sampleTenantPool();
        $this->assertFalse((bool)($sample['sampled'] ?? true));
        $this->assertSame('no_tenant_context', (string)($sample['reason'] ?? ''));
    }

    public function testSampleTenantPoolTracksUnsupportedDriverWithoutSensitiveLabels(): void
    {
        ConnectionManager::dropAlias('tenant');
        ConnectionManager::drop('tenant');
        ConnectionManager::setConfig('tenant', [
            'className' => Connection::class,
            'driver' => Sqlite::class,
            'database' => 'tenant-pool-monitor.sqlite',
            'cacheMetadata' => true,
        ]);
        TenantContext::setCurrent(new TenantContext(
            77,
            'tenant-monitor',
            'Tenant Monitor',
            'active',
            '2026.04',
            'tenant-monitor.example.org',
            'tenant-monitor.example.org',
            [[
                'connectionRole' => 'primary',
                'driver' => Sqlite::class,
                'databaseName' => 'tenant-pool-monitor.sqlite',
                'isActive' => true,
            ]],
        ));

        $sample = (new TenantConnectionPoolMonitor())->sampleTenantPool();

        $this->assertFalse((bool)($sample['sampled'] ?? true));
        $this->assertSame('unsupported_driver', (string)($sample['reason'] ?? ''));
        $snapshot = TenantMetrics::snapshot();
        $this->assertSame(
            1,
            (int)($snapshot['counters']['kmp_tenant_connection_pool_probe_error_total']['driver=sqlite,error=unsupported_driver'] ?? 0),
        );
    }
}
