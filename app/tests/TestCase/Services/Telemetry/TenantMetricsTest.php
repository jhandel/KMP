<?php
declare(strict_types=1);

namespace App\Test\TestCase\Services\Telemetry;

use App\Services\Telemetry\TenantMetrics;
use Cake\TestSuite\TestCase;

class TenantMetricsTest extends TestCase
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

    public function testObserveConnectionPoolSampleStoresGaugesAndSaturationRatio(): void
    {
        TenantMetrics::observeConnectionPoolSample('postgres', 18, 6, 2, 26, 32);

        $snapshot = TenantMetrics::snapshot();
        $this->assertSame(
            18.0,
            (float)($snapshot['gauges']['kmp_tenant_connection_pool_connections']['driver=postgres,state=active'] ?? 0.0),
        );
        $this->assertSame(
            2.0,
            (float)($snapshot['gauges']['kmp_tenant_connection_pool_connections']['driver=postgres,state=waiting'] ?? 0.0),
        );
        $this->assertEqualsWithDelta(
            0.5625,
            (float)($snapshot['gauges']['kmp_tenant_connection_pool_saturation_ratio']['driver=postgres'] ?? 0.0),
            0.0001,
        );
    }

    public function testPoolCountersUseBoundedSafeLabels(): void
    {
        TenantMetrics::incrementConnectionPoolSaturationRisk('tenant-alpha', 'SEVERE');
        TenantMetrics::incrementConnectionPoolTimeout('postgres', 'tls_handshake');
        TenantMetrics::incrementConnectionPoolProbeError('postgres', 'permission denied');

        $snapshot = TenantMetrics::snapshot();
        $this->assertSame(
            1,
            (int)($snapshot['counters']['kmp_tenant_connection_pool_saturation_total']['driver=other,risk=normal'] ?? 0),
        );
        $this->assertSame(
            1,
            (int)($snapshot['counters']['kmp_tenant_connection_pool_timeout_total']['driver=postgres,phase=other'] ?? 0),
        );
        $this->assertSame(
            1,
            (int)($snapshot['counters']['kmp_tenant_connection_pool_probe_error_total']['driver=postgres,error=other'] ?? 0),
        );
    }

    public function testConnectionPoolSummaryReturnsDashboardFriendlyShape(): void
    {
        TenantMetrics::observeConnectionPoolSample('postgres', 7, 5, 0, 12, 20);
        TenantMetrics::incrementConnectionPoolSaturationRisk('postgres', 'high');
        TenantMetrics::incrementConnectionPoolTimeout('postgres', 'probe');
        TenantMetrics::incrementConnectionPoolProbeError('postgres', 'timeout');

        $summary = TenantMetrics::connectionPoolSummary();
        $this->assertArrayHasKey('connections', $summary);
        $this->assertArrayHasKey('saturation_ratio', $summary);
        $this->assertArrayHasKey('saturation_total', $summary);
        $this->assertArrayHasKey('timeout_total', $summary);
        $this->assertArrayHasKey('probe_error_total', $summary);
        $this->assertSame(1, (int)($summary['timeout_total']['driver=postgres,phase=probe'] ?? 0));
    }
}
