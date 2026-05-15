<?php
declare(strict_types=1);

namespace App\Services\Telemetry;

/**
 * Process-local metrics for tenant control-plane visibility.
 *
 * Metrics intentionally use bounded labels and exclude tenant identifiers,
 * hosts, credentials, payloads, or other sensitive data.
 */
class TenantMetrics
{
    /**
     * @var array<string, array<string, int>>
     */
    private static array $counters = [];

    /**
     * @var array<string, array<string, array{count:int,sum:float,max:float}>>
     */
    private static array $histograms = [];

    /**
     * @var array<string, array<string, float>>
     */
    private static array $gauges = [];

    /**
     * Track end-to-end tenant resolution latency by normalized outcome.
     */
    public static function observeTenantResolutionLatency(float $milliseconds, string $outcome): void
    {
        self::observeHistogram('kmp_tenant_resolution_duration_ms', max(0.0, $milliseconds), [
            'outcome' => self::sanitizeOutcome($outcome),
        ]);
    }

    /**
     * Count platform registry lookup outcomes.
     */
    public static function incrementRegistryQueryOutcome(string $outcome): void
    {
        self::incrementCounter('kmp_tenant_registry_query_total', [
            'outcome' => self::sanitizeRegistryOutcome($outcome),
        ]);
    }

    /**
     * Capture currently runnable operation queue depth.
     */
    public static function observeOperationQueueDepth(int $depth): void
    {
        self::setGauge('kmp_tenant_operation_queue_depth', max(0, $depth), [
            'queue' => 'runnable',
        ]);
    }

    /**
     * Track per-attempt operation duration.
     */
    public static function observeOperationDuration(string $operation, float $milliseconds, string $outcome): void
    {
        self::observeHistogram('kmp_tenant_operation_duration_ms', max(0.0, $milliseconds), [
            'operation' => self::sanitizeOperation($operation),
            'outcome' => self::sanitizeOperationOutcome($outcome),
        ]);
    }

    /**
     * Count per-attempt operation outcomes.
     */
    public static function incrementOperationOutcome(string $operation, string $outcome): void
    {
        self::incrementCounter('kmp_tenant_operation_outcome_total', [
            'operation' => self::sanitizeOperation($operation),
            'outcome' => self::sanitizeOperationOutcome($outcome),
        ]);
    }

    /**
     * Track coarse-grained tenant health signals.
     */
    public static function incrementTenantHealthSignal(string $signal): void
    {
        self::incrementCounter('kmp_tenant_health_signal_total', [
            'signal' => self::sanitizeHealthSignal($signal),
        ]);
    }

    /**
     * Track a tenant datasource connection pool sample.
     */
    public static function observeConnectionPoolSample(
        string $driver,
        int $active,
        int $idle,
        int $waiting,
        int $total,
        int $max,
    ): void {
        $labels = ['driver' => self::sanitizeDriver($driver)];
        self::setGauge('kmp_tenant_connection_pool_connections', max(0, $active), $labels + ['state' => 'active']);
        self::setGauge('kmp_tenant_connection_pool_connections', max(0, $idle), $labels + ['state' => 'idle']);
        self::setGauge('kmp_tenant_connection_pool_connections', max(0, $waiting), $labels + ['state' => 'waiting']);
        self::setGauge('kmp_tenant_connection_pool_connections', max(0, $total), $labels + ['state' => 'total']);
        self::setGauge('kmp_tenant_connection_pool_connections', max(0, $max), $labels + ['state' => 'max']);
        if ($max > 0) {
            self::setGauge('kmp_tenant_connection_pool_saturation_ratio', min(1.0, max(0.0, $active / $max)), $labels);
        }
    }

    /**
     * Count saturation-risk samples for pool dashboards and alerts.
     */
    public static function incrementConnectionPoolSaturationRisk(string $driver, string $risk): void
    {
        self::incrementCounter('kmp_tenant_connection_pool_saturation_total', [
            'driver' => self::sanitizeDriver($driver),
            'risk' => self::sanitizePoolRisk($risk),
        ]);
    }

    /**
     * Count pool timeout signals when they are observable.
     */
    public static function incrementConnectionPoolTimeout(string $driver, string $phase): void
    {
        self::incrementCounter('kmp_tenant_connection_pool_timeout_total', [
            'driver' => self::sanitizeDriver($driver),
            'phase' => self::sanitizePoolPhase($phase),
        ]);
    }

    /**
     * Count pool probe failures.
     */
    public static function incrementConnectionPoolProbeError(string $driver, string $error): void
    {
        self::incrementCounter('kmp_tenant_connection_pool_probe_error_total', [
            'driver' => self::sanitizeDriver($driver),
            'error' => self::sanitizePoolProbeError($error),
        ]);
    }

    /**
     * @return array{
     *   counters: array<string, array<string, int>>,
     *   histograms: array<string, array<string, array{count:int,sum:float,max:float}>>,
     *   gauges: array<string, array<string, float>>
     * }
     */
    public static function snapshot(): array
    {
        return [
            'counters' => self::$counters,
            'histograms' => self::$histograms,
            'gauges' => self::$gauges,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function connectionPoolSummary(): array
    {
        return [
            'connections' => self::$gauges['kmp_tenant_connection_pool_connections'] ?? [],
            'saturation_ratio' => self::$gauges['kmp_tenant_connection_pool_saturation_ratio'] ?? [],
            'saturation_total' => self::$counters['kmp_tenant_connection_pool_saturation_total'] ?? [],
            'timeout_total' => self::$counters['kmp_tenant_connection_pool_timeout_total'] ?? [],
            'probe_error_total' => self::$counters['kmp_tenant_connection_pool_probe_error_total'] ?? [],
        ];
    }

    /**
     * Reset all in-process counters for isolated tests.
     */
    public static function reset(): void
    {
        self::$counters = [];
        self::$histograms = [];
        self::$gauges = [];
    }

    /**
     * @param array<string, string> $labels
     */
    private static function incrementCounter(string $metricName, array $labels): void
    {
        $key = self::labelKey($labels);
        self::$counters[$metricName][$key] = (self::$counters[$metricName][$key] ?? 0) + 1;
    }

    /**
     * @param array<string, string> $labels
     */
    private static function observeHistogram(string $metricName, float $value, array $labels): void
    {
        $key = self::labelKey($labels);
        $bucket = self::$histograms[$metricName][$key] ?? ['count' => 0, 'sum' => 0.0, 'max' => 0.0];
        $bucket['count']++;
        $bucket['sum'] += $value;
        $bucket['max'] = max($bucket['max'], $value);
        self::$histograms[$metricName][$key] = $bucket;
    }

    /**
     * @param array<string, string> $labels
     */
    private static function setGauge(string $metricName, float $value, array $labels): void
    {
        self::$gauges[$metricName][self::labelKey($labels)] = $value;
    }

    /**
     * @param array<string, string> $labels
     */
    private static function labelKey(array $labels): string
    {
        ksort($labels);

        $parts = [];
        foreach ($labels as $key => $value) {
            $parts[] = sprintf('%s=%s', $key, $value);
        }

        return implode(',', $parts);
    }

    /**
     * @param string $operation Raw operation identifier
     * @return string
     */
    private static function sanitizeOperation(string $operation): string
    {
        $normalized = strtolower(trim($operation));

        return preg_match('/^[a-z0-9_]{1,64}$/', $normalized) === 1 ? $normalized : 'other';
    }

    /**
     * @param string $outcome Raw tenant resolution outcome
     * @return string
     */
    private static function sanitizeOutcome(string $outcome): string
    {
        return self::enum(
            strtolower(trim($outcome)),
            [
                'success',
                'empty_host',
                'unknown_tenant',
                'draining_tenant',
                'inactive_tenant',
                'schema_mismatch',
                'error',
            ],
            'error',
        );
    }

    /**
     * @param string $outcome Raw registry lookup outcome
     * @return string
     */
    private static function sanitizeRegistryOutcome(string $outcome): string
    {
        return self::enum(
            strtolower(trim($outcome)),
            ['cache_hit', 'cache_stale', 'alias_hit', 'primary_hit', 'miss'],
            'miss',
        );
    }

    /**
     * @param string $outcome Raw operation outcome
     * @return string
     */
    private static function sanitizeOperationOutcome(string $outcome): string
    {
        return self::enum(
            strtolower(trim($outcome)),
            ['success', 'failed', 'cancelled'],
            'failed',
        );
    }

    /**
     * @param string $signal Raw health signal name
     * @return string
     */
    private static function sanitizeHealthSignal(string $signal): string
    {
        return self::enum(
            strtolower(trim($signal)),
            [
                'resolution_success',
                'resolution_empty_host',
                'resolution_unknown_tenant',
                'resolution_draining_tenant',
                'resolution_inactive_tenant',
                'resolution_schema_mismatch',
                'resolution_error',
                'operation_completed',
                'operation_failed',
                'operation_cancelled',
            ],
            'resolution_error',
        );
    }

    /**
     * @param string $driver Raw database driver name
     * @return string
     */
    private static function sanitizeDriver(string $driver): string
    {
        return self::enum(
            strtolower(trim($driver)),
            ['postgres', 'mysql', 'sqlite', 'sqlserver', 'other'],
            'other',
        );
    }

    /**
     * @param string $risk Raw risk label
     * @return string
     */
    private static function sanitizePoolRisk(string $risk): string
    {
        return self::enum(
            strtolower(trim($risk)),
            ['normal', 'high', 'critical'],
            'normal',
        );
    }

    /**
     * @param string $phase Raw timeout phase
     * @return string
     */
    private static function sanitizePoolPhase(string $phase): string
    {
        return self::enum(
            strtolower(trim($phase)),
            ['connect', 'probe', 'query', 'other'],
            'other',
        );
    }

    /**
     * @param string $error Raw pool probe error category
     * @return string
     */
    private static function sanitizePoolProbeError(string $error): string
    {
        return self::enum(
            strtolower(trim($error)),
            ['unsupported_driver', 'permission_denied', 'timeout', 'query_failed', 'other'],
            'other',
        );
    }

    /**
     * @param array<int, string> $allowed
     */
    private static function enum(string $value, array $allowed, string $fallback): string
    {
        return in_array($value, $allowed, true) ? $value : $fallback;
    }
}
