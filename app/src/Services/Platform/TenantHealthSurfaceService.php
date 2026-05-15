<?php
declare(strict_types=1);

namespace App\Services\Platform;

use App\Model\Entity\Tenant;
use App\Model\Entity\TenantOperationJob;
use App\Services\Tenant\TenantMigrationService;
use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;
use DateTimeInterface;
use Throwable;

/**
 * Composes tenant control-plane health signals for health surfaces.
 */
class TenantHealthSurfaceService
{
    use LocatorAwareTrait;

    private const RECENT_FAILURE_LOOKBACK_HOURS = 24;
    private const INVALIDATION_LAG_ATTENTION_SECONDS = 300;
    private const ATTENTION_TENANT_LIMIT = 25;
    private const BACKLOG_STATES = [
        TenantOperationJob::STATUS_QUEUED,
        TenantOperationJob::STATUS_APPROVAL_REQUIRED,
        TenantOperationJob::STATUS_APPROVED,
        TenantOperationJob::STATUS_RUNNING,
        TenantOperationJob::STATUS_HOLD,
        TenantOperationJob::STATUS_BLOCKED,
    ];

    /**
     * Public health summary for unauthenticated /health probes.
     *
     * @return array<string, mixed>
     */
    public function buildPublicHealthSummary(): array
    {
        $summary = $this->buildSummary(false, 0);
        unset($summary['tenant_rows']);

        return $summary;
    }

    /**
     * Platform-admin dashboard health summary with tenant-level rows.
     *
     * @param int $tenantRowLimit Max tenant rows
     * @return array<string, mixed>
     */
    public function buildAdminHealthSummary(int $tenantRowLimit = self::ATTENTION_TENANT_LIMIT): array
    {
        return $this->buildSummary(true, max(1, $tenantRowLimit));
    }

    /**
     * @param bool $includeTenantRows Include tenant-level rows
     * @param int $tenantRowLimit Max tenant rows
     * @return array<string, mixed>
     */
    private function buildSummary(bool $includeTenantRows, int $tenantRowLimit): array
    {
        try {
            $now = DateTime::now();
            $targetSchemaVersion = (new TenantMigrationService())->targetSchemaVersion();
            $tenants = $this->fetchTable('Tenants')->find()
                ->select(['id', 'slug', 'status', 'schema_version'])
                ->orderByAsc('slug')
                ->all()
                ->toList();
            $tenantIds = array_map(
                static fn (Tenant $tenant): int => (int)$tenant->id,
                $tenants,
            );

            $backlogByTenant = $this->countJobsByTenant(self::BACKLOG_STATES);
            $failedByTenant = $this->countJobsByTenant([TenantOperationJob::STATUS_FAILED]);
            $recentFailuresByTenant = $this->countRecentFailuresByTenant(
                DateTime::now()->subHours(self::RECENT_FAILURE_LOOKBACK_HOURS),
            );
            $staleRunningByTenant = $this->countStaleRunningByTenant($now);
            [$invalidationLagByTenant, $invalidationStats] = $this->invalidationLagByTenant($tenantIds, $now);

            $statusCounts = [];
            $migration = [
                'target_schema_version' => $targetSchemaVersion,
                'up_to_date' => 0,
                'outdated' => 0,
                'unknown' => 0,
            ];
            $tenantRows = [];

            foreach ($tenants as $tenant) {
                $tenantId = (int)$tenant->id;
                $status = (string)$tenant->status;
                $schemaVersion = trim((string)($tenant->schema_version ?? ''));
                $migrationState = $this->migrationState($schemaVersion, $targetSchemaVersion);
                $isDraining = $status === Tenant::STATUS_DRAINING;
                $backlog = (int)($backlogByTenant[$tenantId] ?? 0);
                $recentFailures = (int)($recentFailuresByTenant[$tenantId] ?? 0);
                $staleRunning = (int)($staleRunningByTenant[$tenantId] ?? 0);
                $invalidationLagSeconds = $invalidationLagByTenant[$tenantId] ?? null;

                $statusCounts[$status] = (int)($statusCounts[$status] ?? 0) + 1;
                $migration[$migrationState] = (int)($migration[$migrationState] ?? 0) + 1;

                if (!$includeTenantRows) {
                    continue;
                }

                $needsAttention = $migrationState !== 'up_to_date'
                    || $isDraining
                    || $backlog > 0
                    || $recentFailures > 0
                    || $staleRunning > 0
                    || ($invalidationLagSeconds !== null
                        && $invalidationLagSeconds >= self::INVALIDATION_LAG_ATTENTION_SECONDS);
                if (!$needsAttention) {
                    continue;
                }

                $tenantRows[] = [
                    'slug' => (string)$tenant->slug,
                    'status' => $status,
                    'is_draining' => $isDraining,
                    'schema_version' => $schemaVersion === '' ? null : $schemaVersion,
                    'target_schema_version' => $targetSchemaVersion,
                    'migration_state' => $migrationState,
                    'operation_backlog' => $backlog,
                    'recent_failures_24h' => $recentFailures,
                    'stale_running_leases' => $staleRunning,
                    'invalidation_lag_seconds' => $invalidationLagSeconds,
                ];
            }

            usort($tenantRows, static function (array $left, array $right): int {
                $leftScore = ((int)($left['recent_failures_24h'] ?? 0) * 1_000_000)
                    + ((int)($left['operation_backlog'] ?? 0) * 10_000)
                    + ((int)($left['stale_running_leases'] ?? 0) * 1_000)
                    + (int)($left['invalidation_lag_seconds'] ?? 0);
                $rightScore = ((int)($right['recent_failures_24h'] ?? 0) * 1_000_000)
                    + ((int)($right['operation_backlog'] ?? 0) * 10_000)
                    + ((int)($right['stale_running_leases'] ?? 0) * 1_000)
                    + (int)($right['invalidation_lag_seconds'] ?? 0);

                return $rightScore <=> $leftScore ?: strcmp((string)$left['slug'], (string)$right['slug']);
            });
            if ($includeTenantRows) {
                $tenantRows = array_slice($tenantRows, 0, $tenantRowLimit);
            }

            return [
                'available' => true,
                'generated_at' => $now->toIso8601String(),
                'tenant_status_counts' => $statusCounts,
                'migration' => $migration,
                'drain_status' => [
                    'draining' => (int)($statusCounts[Tenant::STATUS_DRAINING] ?? 0),
                ],
                'operation_counts' => [
                    'pending' => array_sum($backlogByTenant),
                    'failed' => array_sum($failedByTenant),
                    'backlog' => array_sum($backlogByTenant),
                    'recent_failures_24h' => array_sum($recentFailuresByTenant),
                    'stale_running_leases' => array_sum($staleRunningByTenant),
                ],
                'invalidation_lag' => $invalidationStats,
                'tenant_rows' => $tenantRows,
            ];
        } catch (Throwable) {
            return [
                'available' => false,
            ];
        }
    }

    /**
     * @param array<int, string> $states
     * @return array<int, int>
     */
    private function countJobsByTenant(array $states): array
    {
        if ($states === []) {
            return [];
        }
        $jobs = $this->fetchTable('TenantOperationJobs');
        $query = $jobs->find()
            ->select([
                'tenant_id',
                'total' => $jobs->find()->func()->count('*'),
            ])
            ->where([
                'tenant_id IS NOT' => null,
                'state IN' => $states,
            ])
            ->groupBy('tenant_id');

        $counts = [];
        foreach ($query as $row) {
            $counts[(int)$row->tenant_id] = (int)$row->total;
        }

        return $counts;
    }

    /**
     * @param \Cake\I18n\DateTime $since Inclusive start
     * @return array<int, int>
     */
    private function countRecentFailuresByTenant(DateTime $since): array
    {
        $jobs = $this->fetchTable('TenantOperationJobs');
        $query = $jobs->find()
            ->select([
                'tenant_id',
                'total' => $jobs->find()->func()->count('*'),
            ])
            ->where([
                'tenant_id IS NOT' => null,
                'state' => TenantOperationJob::STATUS_FAILED,
                'OR' => [
                    ['modified >=' => $since],
                    ['created >=' => $since],
                ],
            ])
            ->groupBy('tenant_id');
        $counts = [];
        foreach ($query as $row) {
            $counts[(int)$row->tenant_id] = (int)$row->total;
        }

        return $counts;
    }

    /**
     * @param \Cake\I18n\DateTime $now Current timestamp
     * @return array<int, int>
     */
    private function countStaleRunningByTenant(DateTime $now): array
    {
        $jobs = $this->fetchTable('TenantOperationJobs');
        $query = $jobs->find()
            ->select([
                'tenant_id',
                'total' => $jobs->find()->func()->count('*'),
            ])
            ->where([
                'tenant_id IS NOT' => null,
                'state' => TenantOperationJob::STATUS_RUNNING,
                'lease_expires_at IS NOT' => null,
                'lease_expires_at <=' => $now,
            ])
            ->groupBy('tenant_id');
        $counts = [];
        foreach ($query as $row) {
            $counts[(int)$row->tenant_id] = (int)$row->total;
        }

        return $counts;
    }

    /**
     * @param array<int, int> $tenantIds Tenant ids
     * @param \Cake\I18n\DateTime $now Current timestamp
     * @return array{0: array<int, int>, 1: array<string, int|float|null|bool>}
     */
    private function invalidationLagByTenant(array $tenantIds, DateTime $now): array
    {
        if ($tenantIds === []) {
            return [[], [
                'available' => false,
                'tracked_tenants' => 0,
                'max_seconds' => null,
                'p95_seconds' => null,
                'avg_seconds' => null,
            ]];
        }
        $versions = $this->fetchTable('TenantRuntimeInvalidationVersions')->find()
            ->select(['tenant_id', 'modified'])
            ->where([
                'tenant_id IN' => $tenantIds,
            ])
            ->all();
        $nowTimestamp = $now->getTimestamp();
        $lags = [];
        foreach ($versions as $version) {
            $modified = $version->modified;
            if (!$modified instanceof DateTimeInterface) {
                continue;
            }
            $lags[(int)$version->tenant_id] = max(0, $nowTimestamp - $modified->getTimestamp());
        }
        $values = array_values($lags);
        sort($values);
        $trackedCount = count($values);
        $avg = $trackedCount > 0 ? (float)(array_sum($values) / $trackedCount) : null;
        $p95 = null;
        if ($trackedCount > 0) {
            $index = (int)max(0, ceil($trackedCount * 0.95) - 1);
            $p95 = $values[$index] ?? end($values);
        }

        return [$lags, [
            'available' => $trackedCount > 0,
            'tracked_tenants' => $trackedCount,
            'max_seconds' => $trackedCount > 0 ? max($values) : null,
            'p95_seconds' => $p95,
            'avg_seconds' => $avg === null ? null : round($avg, 2),
        ]];
    }

    /**
     * @param string $schemaVersion Tenant schema version
     * @param string $targetSchemaVersion Target schema version
     * @return string
     */
    private function migrationState(string $schemaVersion, string $targetSchemaVersion): string
    {
        if ($schemaVersion === '') {
            return 'unknown';
        }
        if ($targetSchemaVersion === '' || $schemaVersion === $targetSchemaVersion) {
            return 'up_to_date';
        }

        return 'outdated';
    }
}
