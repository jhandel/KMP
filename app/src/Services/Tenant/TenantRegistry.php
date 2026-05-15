<?php
declare(strict_types=1);

namespace App\Services\Tenant;

use App\Model\Entity\Tenant;
use App\Model\Entity\TenantAlias;
use App\Services\Telemetry\TenantMetrics;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\ORM\Query\SelectQuery;

/**
 * Reads tenant registry records from the platform connection only.
 */
class TenantRegistry
{
    use LocatorAwareTrait;

    /**
     * @var array<string, array{tenant: \App\Model\Entity\Tenant|null, tenantId: int|null, tenantVersion: int, globalVersion: int}>
     */
    private static array $hostCache = [];

    /**
     * @param \App\Services\Tenant\TenantInvalidationService|null $invalidationService Invalidation version source
     */
    public function __construct(
        private readonly ?TenantInvalidationService $invalidationService = null,
    ) {
    }

    /**
     * Find a tenant by normalized host alias or primary host.
     *
     * @param string $normalizedHost Normalized host
     * @return \App\Model\Entity\Tenant|null
     */
    public function findTenantForHost(string $normalizedHost): ?Tenant
    {
        $service = $this->invalidationService ?? new TenantInvalidationService();
        $globalVersion = $service->globalVersion();
        $cached = self::$hostCache[$normalizedHost] ?? null;
        if ($cached !== null && $cached['globalVersion'] === $globalVersion) {
            if (
                $cached['tenantId'] === null
                || $cached['tenantVersion'] === $service->tenantVersion((int)$cached['tenantId'])
            ) {
                TenantMetrics::incrementRegistryQueryOutcome('cache_hit');

                return $cached['tenant'];
            }
            TenantMetrics::incrementRegistryQueryOutcome('cache_stale');
        }

        $tenant = $this->findTenantByHostAlias($normalizedHost);
        if ($tenant !== null) {
            TenantMetrics::incrementRegistryQueryOutcome('alias_hit');
            self::$hostCache[$normalizedHost] = [
                'tenant' => $tenant,
                'tenantId' => (int)$tenant->id,
                'tenantVersion' => $service->tenantVersion((int)$tenant->id),
                'globalVersion' => $globalVersion,
            ];

            return $tenant;
        }

        $tenant = $this->findTenantByPrimaryHost($normalizedHost);
        TenantMetrics::incrementRegistryQueryOutcome($tenant === null ? 'miss' : 'primary_hit');
        self::$hostCache[$normalizedHost] = [
            'tenant' => $tenant,
            'tenantId' => $tenant === null ? null : (int)$tenant->id,
            'tenantVersion' => $tenant === null ? 0 : $service->tenantVersion((int)$tenant->id),
            'globalVersion' => $globalVersion,
        ];

        return $tenant;
    }

    /**
     * Normalize host casing, ports, URL wrappers, and trailing dots.
     *
     * @param string $host Raw host or URL
     * @return string
     */
    public static function normalizeHost(string $host): string
    {
        $host = strtolower(trim($host));
        if ($host === '') {
            return '';
        }

        if (str_contains($host, '://')) {
            $parsedHost = parse_url($host, PHP_URL_HOST);
            $host = is_string($parsedHost) ? $parsedHost : $host;
        }

        $host = preg_replace('/[\/?#].*$/', '', $host) ?? $host;
        $host = rtrim($host, '.');

        if (str_starts_with($host, '[')) {
            $end = strpos($host, ']');
            if ($end !== false) {
                return trim(substr($host, 1, $end - 1));
            }
        }

        if (substr_count($host, ':') === 1) {
            $host = preg_replace('/:\d+$/', '', $host) ?? $host;
        }

        return rtrim($host, '.');
    }

    /**
     * Resolve an active host alias.
     *
     * @param string $normalizedHost Normalized host
     * @return \App\Model\Entity\Tenant|null
     */
    private function findTenantByHostAlias(string $normalizedHost): ?Tenant
    {
        $alias = $this->fetchTable('TenantAliases')
            ->find()
            ->contain([
                'Tenants' => [
                    'TenantDatabaseConfigs' => function (SelectQuery $query): SelectQuery {
                        return $query
                            ->where(['TenantDatabaseConfigs.is_active' => true])
                            ->orderByAsc('TenantDatabaseConfigs.connection_role');
                    },
                    'TenantServiceConfigs' => function (SelectQuery $query): SelectQuery {
                        return $query
                            ->where(['TenantServiceConfigs.is_active' => true])
                            ->orderByAsc('TenantServiceConfigs.service_name')
                            ->orderByAsc('TenantServiceConfigs.config_key');
                    },
                ],
            ])
            ->where([
                'TenantAliases.alias_type' => TenantAlias::TYPE_HOST,
                'TenantAliases.normalized_value' => $normalizedHost,
                'TenantAliases.is_active' => true,
            ])
            ->orderByAsc('TenantAliases.priority')
            ->first();

        return $alias?->tenant;
    }

    /**
     * Resolve a tenant by its primary host.
     *
     * @param string $normalizedHost Normalized host
     * @return \App\Model\Entity\Tenant|null
     */
    private function findTenantByPrimaryHost(string $normalizedHost): ?Tenant
    {
        $tenants = $this->fetchTable('Tenants')
            ->find()
            ->contain([
                'TenantDatabaseConfigs' => function (SelectQuery $query): SelectQuery {
                    return $query
                        ->where(['TenantDatabaseConfigs.is_active' => true])
                        ->orderByAsc('TenantDatabaseConfigs.connection_role');
                },
                'TenantServiceConfigs' => function (SelectQuery $query): SelectQuery {
                    return $query
                        ->where(['TenantServiceConfigs.is_active' => true])
                        ->orderByAsc('TenantServiceConfigs.service_name')
                        ->orderByAsc('TenantServiceConfigs.config_key');
                },
            ])
            ->where(['Tenants.primary_host IS NOT' => null])
            ->all();

        foreach ($tenants as $tenant) {
            if ($tenant instanceof Tenant && self::normalizeHost((string)$tenant->primary_host) === $normalizedHost) {
                return $tenant;
            }
        }

        return null;
    }

    /**
     * Clear process-local host resolution cache.
     *
     * @return void
     */
    public static function clearLocalCache(): void
    {
        self::$hostCache = [];
    }
}
