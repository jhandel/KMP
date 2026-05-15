<?php
declare(strict_types=1);

namespace App\Services\Tenant;

use App\Services\Telemetry\TenantMetrics;
use Cake\Database\Connection;
use Cake\Database\Driver\Mysql;
use Cake\Database\Driver\Postgres;
use Cake\Datasource\ConnectionManager;
use Throwable;

/**
 * Samples tenant connection pool pressure indicators for observability.
 */
class TenantConnectionPoolMonitor
{
    /**
     * @return array<string, mixed>
     */
    public function sampleTenantPool(): array
    {
        $context = TenantContext::getCurrent();
        if ($context === null) {
            return [
                'sampled' => false,
                'reason' => 'no_tenant_context',
            ];
        }

        $connection = ConnectionManager::get('tenant');
        $driver = $this->driverLabel($connection);

        if ($driver !== 'postgres') {
            TenantMetrics::incrementConnectionPoolProbeError($driver, 'unsupported_driver');

            return [
                'sampled' => false,
                'driver' => $driver,
                'reason' => 'unsupported_driver',
            ];
        }

        try {
            $stats = $this->postgresStats($connection);
            $risk = $this->saturationRisk(
                $stats['active_connections'],
                $stats['max_connections'],
                $stats['waiting_connections'],
            );
            TenantMetrics::observeConnectionPoolSample(
                $driver,
                $stats['active_connections'],
                $stats['idle_connections'],
                $stats['waiting_connections'],
                $stats['total_connections'],
                $stats['max_connections'],
            );
            TenantMetrics::incrementConnectionPoolSaturationRisk($driver, $risk);

            return [
                'sampled' => true,
                'driver' => $driver,
                'risk' => $risk,
                'active_connections' => $stats['active_connections'],
                'idle_connections' => $stats['idle_connections'],
                'waiting_connections' => $stats['waiting_connections'],
                'total_connections' => $stats['total_connections'],
                'max_connections' => $stats['max_connections'],
                'saturation_ratio' => $stats['max_connections'] > 0
                    ? min(1.0, max(0.0, $stats['active_connections'] / $stats['max_connections']))
                    : 0.0,
            ];
        } catch (Throwable $exception) {
            $error = $this->probeErrorCategory($exception);
            TenantMetrics::incrementConnectionPoolProbeError($driver, $error);
            if ($error === 'timeout') {
                TenantMetrics::incrementConnectionPoolTimeout($driver, 'probe');
            }

            return [
                'sampled' => false,
                'driver' => $driver,
                'reason' => 'probe_failed',
                'error' => $error,
            ];
        }
    }

    /**
     * @return array{active_connections:int,idle_connections:int,waiting_connections:int,total_connections:int,max_connections:int}
     */
    private function postgresStats(Connection $connection): array
    {
        /** @var array<string, scalar>|false $row */
        $row = $connection->execute(
            implode(' ', [
                'SELECT',
                "COALESCE(SUM(CASE WHEN state = 'active' THEN 1 ELSE 0 END), 0) AS active_connections,",
                "COALESCE(SUM(CASE WHEN state = 'idle' THEN 1 ELSE 0 END), 0) AS idle_connections,",
                'COALESCE(SUM(CASE WHEN wait_event_type IS NOT NULL THEN 1 ELSE 0 END), 0) AS waiting_connections,',
                'COUNT(*) AS total_connections,',
                "COALESCE(MAX(current_setting('max_connections')::int), 0) AS max_connections",
                'FROM pg_stat_activity',
                'WHERE datname = current_database()',
            ]),
        )->fetch('assoc');
        $row = is_array($row) ? $row : [];

        return [
            'active_connections' => max(0, (int)($row['active_connections'] ?? 0)),
            'idle_connections' => max(0, (int)($row['idle_connections'] ?? 0)),
            'waiting_connections' => max(0, (int)($row['waiting_connections'] ?? 0)),
            'total_connections' => max(0, (int)($row['total_connections'] ?? 0)),
            'max_connections' => max(0, (int)($row['max_connections'] ?? 0)),
        ];
    }

    /**
     * @param int $active Active backend count
     * @param int $max Configured max connections
     * @param int $waiting Waiting backend count
     * @return string
     */
    private function saturationRisk(int $active, int $max, int $waiting): string
    {
        if ($max <= 0) {
            return 'normal';
        }
        if ($waiting > 0 || ($active / $max) >= 0.90) {
            return 'critical';
        }
        if ($active / $max >= 0.75) {
            return 'high';
        }

        return 'normal';
    }

    /**
     * @param \Cake\Database\Connection $connection Tenant connection
     * @return string
     */
    private function driverLabel(Connection $connection): string
    {
        $driver = $connection->getDriver();
        if ($driver instanceof Postgres) {
            return 'postgres';
        }
        if ($driver instanceof Mysql) {
            return 'mysql';
        }
        $driverClass = strtolower($driver::class);
        if (str_contains($driverClass, 'sqlite')) {
            return 'sqlite';
        }
        if (str_contains($driverClass, 'sqlserver')) {
            return 'sqlserver';
        }

        return 'other';
    }

    /**
     * @param \Throwable $exception Probe error
     * @return string
     */
    private function probeErrorCategory(Throwable $exception): string
    {
        $message = strtolower(trim($exception->getMessage()));
        if (str_contains($message, 'permission denied') || str_contains($message, 'insufficient privilege')) {
            return 'permission_denied';
        }
        if (str_contains($message, 'timeout') || str_contains($message, 'timed out')) {
            return 'timeout';
        }
        if ($message !== '') {
            return 'query_failed';
        }

        return 'other';
    }
}
