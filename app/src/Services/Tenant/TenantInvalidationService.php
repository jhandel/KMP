<?php
declare(strict_types=1);

namespace App\Services\Tenant;

use Cake\Core\Configure;
use Cake\ORM\Locator\LocatorAwareTrait;
use Throwable;

/**
 * Tenant runtime invalidation versions shared across pods via platform DB.
 */
class TenantInvalidationService
{
    use LocatorAwareTrait;

    public const GLOBAL_TENANT_ID = 0;

    /**
     * @var array<int, array{version: int, fetchedAt: int}>
     */
    private static array $versionCache = [];

    /**
     * @param \App\Services\Tenant\TenantInvalidationPublisherInterface|null $publisher Optional event publisher
     * @param int|null $pollIntervalSeconds Cross-pod reconciliation interval
     */
    public function __construct(
        private readonly ?TenantInvalidationPublisherInterface $publisher = null,
        private readonly ?int $pollIntervalSeconds = null,
    ) {
    }

    /**
     * Bump tenant-scoped runtime invalidation version.
     *
     * @param int $tenantId Tenant id
     * @param string $changeType Change type label
     * @param array<string, mixed> $context Optional event metadata
     * @return int New version
     */
    public function bumpTenant(int $tenantId, string $changeType, array $context = []): int
    {
        $version = $this->incrementVersion($tenantId, $changeType);
        $this->publish($tenantId, $version, $changeType, $context);

        return $version;
    }

    /**
     * Bump global host/registry invalidation version.
     *
     * @param string $changeType Change type label
     * @param array<string, mixed> $context Optional event metadata
     * @return int New version
     */
    public function bumpGlobal(string $changeType, array $context = []): int
    {
        $version = $this->incrementVersion(self::GLOBAL_TENANT_ID, $changeType);
        $this->publish(self::GLOBAL_TENANT_ID, $version, $changeType, $context);

        return $version;
    }

    /**
     * Tenant-specific version.
     *
     * @param int $tenantId Tenant id
     * @return int
     */
    public function tenantVersion(int $tenantId): int
    {
        return $this->readVersion($tenantId);
    }

    /**
     * Global host/registry version.
     *
     * @return int
     */
    public function globalVersion(): int
    {
        return $this->readVersion(self::GLOBAL_TENANT_ID);
    }

    /**
     * Effective version for runtime application (tenant or global changes).
     *
     * @param int $tenantId Tenant id
     * @return int
     */
    public function effectiveVersion(int $tenantId): int
    {
        return max($this->tenantVersion($tenantId), $this->globalVersion());
    }

    /**
     * Reset process-local cache, useful in tests and long-lived workers.
     *
     * @return void
     */
    public static function clearLocalCache(): void
    {
        self::$versionCache = [];
    }

    /**
     * @param int $tenantId Tenant id
     * @param string $changeType Change type label
     * @return int
     */
    private function incrementVersion(int $tenantId, string $changeType): int
    {
        $table = $this->fetchTable('TenantRuntimeInvalidationVersions');
        $record = $table->find()->where(['tenant_id' => $tenantId])->first();
        $nextVersion = $record === null ? 1 : (int)$record->get('version') + 1;
        $payload = [
            'tenant_id' => $tenantId,
            'version' => $nextVersion,
            'last_change_type' => $changeType,
        ];
        $entity = $record === null ? $table->newEntity($payload) : $table->patchEntity($record, $payload);
        $table->saveOrFail($entity);
        self::$versionCache[$tenantId] = ['version' => $nextVersion, 'fetchedAt' => time()];

        return $nextVersion;
    }

    /**
     * @param int $tenantId Tenant id
     * @return int
     */
    private function readVersion(int $tenantId): int
    {
        $pollInterval = $this->pollIntervalSeconds();
        $cached = self::$versionCache[$tenantId] ?? null;
        if ($cached !== null && (time() - $cached['fetchedAt']) < $pollInterval) {
            return $cached['version'];
        }

        $record = $this->fetchTable('TenantRuntimeInvalidationVersions')
            ->find()
            ->where(['tenant_id' => $tenantId])
            ->first();
        $version = $record === null ? 0 : (int)$record->get('version');
        self::$versionCache[$tenantId] = ['version' => $version, 'fetchedAt' => time()];

        return $version;
    }

    /**
     * @return int
     */
    private function pollIntervalSeconds(): int
    {
        if ($this->pollIntervalSeconds !== null) {
            return max(1, $this->pollIntervalSeconds);
        }
        $configured = Configure::read('Tenancy.invalidationPollIntervalSeconds');

        return max(1, is_numeric($configured) ? (int)$configured : 5);
    }

    /**
     * @param int $tenantId Tenant id
     * @param int $version New version
     * @param string $changeType Change type
     * @param array<string, mixed> $context Metadata
     * @return void
     */
    private function publish(int $tenantId, int $version, string $changeType, array $context): void
    {
        $publisher = $this->publisher ?? new NullTenantInvalidationPublisher();
        try {
            $publisher->publish($tenantId, $version, $changeType, $context);
        } catch (Throwable) {
        }
    }
}
