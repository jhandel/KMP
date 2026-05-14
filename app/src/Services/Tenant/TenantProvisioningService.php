<?php
declare(strict_types=1);

namespace App\Services\Tenant;

use App\Model\Entity\Tenant;
use App\Model\Entity\TenantAlias;
use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;
use Cake\Datasource\Exception\MissingDatasourceConfigException;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\ORM\TableRegistry;
use RuntimeException;
use Throwable;

/**
 * Platform tenant provisioning and operational health service.
 */
class TenantProvisioningService
{
    use LocatorAwareTrait;

    /**
     * Constructor.
     *
     * @param \App\Services\Tenant\TenantConnectionFactory $connectionFactory Connection factory
     * @param \App\Services\Tenant\TenantMigrationService $migrationService Migration service
     */
    public function __construct(
        private readonly TenantConnectionFactory $connectionFactory = new TenantConnectionFactory(),
        private readonly TenantMigrationService $migrationService = new TenantMigrationService(),
    ) {
    }

    /**
     * @param array<string, mixed> $data Tenant and database options
     * @return \App\Model\Entity\Tenant
     */
    public function createOrUpdateTenant(array $data): Tenant
    {
        $slug = $this->normalizeSlug((string)$data['slug']);
        $tenants = $this->fetchTable('Tenants');
        $tenant = $tenants->find()
            ->where(['Tenants.slug' => $slug])
            ->contain(['TenantAliases', 'TenantDatabaseConfigs', 'TenantServiceConfigs'])
            ->first();

        $payload = [
            'slug' => $slug,
            'display_name' => (string)($data['display_name'] ?: $slug),
            'status' => (string)($data['status'] ?? Tenant::STATUS_PROVISIONING),
            'schema_version' => $data['schema_version'] ?? null,
            'primary_host' => $this->nullableString($data['primary_host'] ?? null),
            'path_prefix' => $this->nullableString($data['path_prefix'] ?? null),
        ];

        if ($tenant === null) {
            $tenant = $tenants->newEntity($payload);
        } else {
            $tenant = $tenants->patchEntity($tenant, $payload);
        }
        $tenants->saveOrFail($tenant);

        $tenant = $this->reloadTenant((int)$tenant->id);
        if (!empty($payload['primary_host'])) {
            $this->ensureHostAlias($tenant, (string)$payload['primary_host'], 0);
        }
        foreach ((array)($data['aliases'] ?? []) as $alias) {
            $this->ensureHostAlias($tenant, (string)$alias, 100);
        }
        $this->ensureDatabaseConfig($tenant, $data);
        $this->ensureServiceConfigs($tenant, $data);

        return $this->reloadTenant((int)$tenant->id);
    }

    /**
     * @param string $slug Tenant slug
     * @return \App\Model\Entity\Tenant
     */
    public function getTenant(string $slug): Tenant
    {
        $tenant = $this->fetchTable('Tenants')->find()
            ->where(['Tenants.slug' => $this->normalizeSlug($slug)])
            ->contain(['TenantAliases', 'TenantDatabaseConfigs', 'TenantServiceConfigs'])
            ->first();
        if (!$tenant instanceof Tenant) {
            throw new RuntimeException(sprintf('Tenant "%s" was not found.', $slug));
        }

        return $tenant;
    }

    /**
     * @return array<int, \App\Model\Entity\Tenant>
     */
    public function listTenants(): array
    {
        return $this->fetchTable('Tenants')->find()
            ->contain(['TenantAliases', 'TenantDatabaseConfigs', 'TenantServiceConfigs'])
            ->orderByAsc('Tenants.slug')
            ->all()
            ->toList();
    }

    /**
     * @param string $slug Tenant slug
     * @param string $status New status
     * @return \App\Model\Entity\Tenant
     */
    public function setStatus(string $slug, string $status): Tenant
    {
        $tenant = $this->getTenant($slug);
        $valid = [
            Tenant::STATUS_ACTIVE,
            Tenant::STATUS_DISABLED,
            Tenant::STATUS_MAINTENANCE,
            Tenant::STATUS_FAILED,
            Tenant::STATUS_PROVISIONING,
        ];
        if (!in_array($status, $valid, true)) {
            throw new RuntimeException(sprintf('Unsupported tenant status "%s".', $status));
        }
        $tenant->status = $status;
        $this->fetchTable('Tenants')->saveOrFail($tenant);

        return $tenant;
    }

    /**
     * Configure tenant connection and run tenant migrations.
     *
     * @param \App\Model\Entity\Tenant $tenant Tenant
     * @param string|null $plugin Optional plugin limit
     * @return string Stored schema version
     */
    public function migrateTenant(Tenant $tenant, ?string $plugin = null): string
    {
        $context = TenantContext::fromTenant($tenant, (string)($tenant->primary_host ?? $tenant->slug));
        $this->connectionFactory->configure($context);
        $this->migrationService->migrate('tenant', $plugin);

        $tenant->schema_version = $this->migrationService->targetSchemaVersion();
        if ($tenant->status === Tenant::STATUS_PROVISIONING) {
            $tenant->status = Tenant::STATUS_ACTIVE;
        }
        $this->fetchTable('Tenants')->saveOrFail($tenant);

        return (string)$tenant->schema_version;
    }

    /**
     * Attempt to create the physical database when explicitly requested.
     *
     * @param array<string, mixed> $data Database options
     * @return string|null Message when skipped
     */
    public function createPhysicalDatabase(array $data): ?string
    {
        $database = (string)($data['database_name'] ?? '');
        if ($database === '') {
            throw new RuntimeException('A database name is required to create a tenant database.');
        }
        if (!preg_match('/^[A-Za-z0-9_]+$/', $database)) {
            throw new RuntimeException('Database creation only supports simple alphanumeric/underscore names.');
        }

        $driver = strtolower((string)($data['driver'] ?? ''));
        $platform = ConnectionManager::get('platform');
        $platformDriver = strtolower(get_class($platform->getDriver()));

        if (str_contains($driver, 'mysql') && str_contains($platformDriver, 'mysql')) {
            $platform->execute(sprintf(
                'CREATE DATABASE IF NOT EXISTS `%s` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
                $database,
            ));

            return null;
        }

        if (
            (str_contains($driver, 'postgres') || str_contains($driver, 'pgsql'))
            && str_contains($platformDriver, 'postgres')
        ) {
            $exists = $platform->execute('SELECT 1 FROM pg_database WHERE datname = ?', [$database])->fetch('assoc');
            if (!$exists) {
                $platform->execute(sprintf('CREATE DATABASE "%s"', $database));
            }

            return null;
        }

        return 'Physical database creation skipped: driver/server combination is not supported safely.';
    }

    /**
     * @param \App\Model\Entity\Tenant $tenant Tenant
     * @return array<string, mixed>
     */
    public function doctor(Tenant $tenant): array
    {
        $checks = [
            'tenant_status' => ['ok' => $tenant->status === Tenant::STATUS_ACTIVE, 'message' => $tenant->status],
            'schema_version' => $this->checkSchemaVersion($tenant),
            'database_config' => [
                'ok' => !empty($tenant->tenant_database_configs),
                'message' => 'primary config present',
            ],
            'database_reachable' => ['ok' => false, 'message' => 'not checked'],
            'required_app_settings' => ['ok' => false, 'message' => 'not checked'],
        ];

        try {
            $context = TenantContext::fromTenant($tenant, (string)($tenant->primary_host ?? $tenant->slug));
            $this->connectionFactory->configure($context);
            ConnectionManager::get('tenant')->execute('SELECT 1')->fetch();
            $checks['database_reachable'] = ['ok' => true, 'message' => 'reachable'];
            $checks['required_app_settings'] = $this->checkRequiredAppSettings();
        } catch (Throwable $e) {
            $checks['database_reachable'] = ['ok' => false, 'message' => $e->getMessage()];
        }

        return $checks;
    }

    /**
     * @param int $id Tenant id
     * @return \App\Model\Entity\Tenant
     */
    private function reloadTenant(int $id): Tenant
    {
        return $this->fetchTable('Tenants')->get(
            $id,
            contain: ['TenantAliases', 'TenantDatabaseConfigs', 'TenantServiceConfigs'],
        );
    }

    /**
     * Normalize a tenant slug for storage and lookup.
     *
     * @param string $slug Tenant slug
     * @return string
     */
    private function normalizeSlug(string $slug): string
    {
        return strtolower(trim($slug));
    }

    /**
     * Convert empty input to null.
     *
     * @param mixed $value Input value
     * @return string|null
     */
    private function nullableString(mixed $value): ?string
    {
        $value = trim((string)$value);

        return $value === '' ? null : $value;
    }

    /**
     * Ensure a host alias exists for a tenant.
     *
     * @param \App\Model\Entity\Tenant $tenant Tenant
     * @param string $host Host alias
     * @param int $priority Alias priority
     * @return void
     */
    private function ensureHostAlias(Tenant $tenant, string $host, int $priority): void
    {
        $host = TenantRegistry::normalizeHost($host);
        if ($host === '') {
            return;
        }
        $aliases = $this->fetchTable('TenantAliases');
        $alias = $aliases->find()->where([
            'TenantAliases.alias_type' => TenantAlias::TYPE_HOST,
            'TenantAliases.normalized_value' => $host,
        ])->first();
        if ($alias !== null && (int)$alias->tenant_id !== (int)$tenant->id) {
            throw new RuntimeException(sprintf('Host alias "%s" is already assigned to another tenant.', $host));
        }
        $payload = [
            'tenant_id' => $tenant->id,
            'alias_type' => TenantAlias::TYPE_HOST,
            'value' => $host,
            'normalized_value' => $host,
            'priority' => $priority,
            'is_active' => true,
        ];
        $alias = $alias === null ? $aliases->newEntity($payload) : $aliases->patchEntity($alias, $payload);
        $aliases->saveOrFail($alias);
    }

    /**
     * @param \App\Model\Entity\Tenant $tenant Tenant
     * @param array<string, mixed> $data Options
     * @return void
     */
    private function ensureDatabaseConfig(Tenant $tenant, array $data): void
    {
        $configs = $this->fetchTable('TenantDatabaseConfigs');
        $config = $configs->find()->where([
            'TenantDatabaseConfigs.tenant_id' => $tenant->id,
            'TenantDatabaseConfigs.connection_role' => 'primary',
        ])->first();

        $metadata = [];
        if (!empty($data['database_url'])) {
            $metadata['url'] = (string)$data['database_url'];
        }
        if (!empty($data['password_env'])) {
            $metadata['passwordEnv'] = (string)$data['password_env'];
        }

        $tenantConfig = (array)(
            ConnectionManager::getConfig('tenant')
            ?? ConnectionManager::getConfig('default')
            ?? []
        );
        $payload = [
            'tenant_id' => $tenant->id,
            'connection_role' => 'primary',
            'driver' => (string)($data['driver'] ?: ($tenantConfig['driver'] ?? 'Cake\\Database\\Driver\\Mysql')),
            'host' => (string)($data['host'] ?: ($tenantConfig['host'] ?? 'localhost')),
            'port' => $data['port'] === null || $data['port'] === '' ? null : (int)$data['port'],
            'database_name' => (string)($data['database_name'] ?: ($tenantConfig['database'] ?? '')),
            'username' => $this->nullableString($data['username'] ?? ($tenantConfig['username'] ?? null)),
            'secret_reference' => $this->nullableString($data['secret_reference'] ?? null),
            'read_enabled' => true,
            'write_enabled' => true,
            'is_active' => true,
            'metadata' => $this->encodeMetadata($metadata),
        ];
        if ($payload['database_name'] === '' && empty($metadata['url'])) {
            throw new RuntimeException('Tenant database configuration requires --database-name or --database-url.');
        }

        $config = $config === null ? $configs->newEntity($payload) : $configs->patchEntity($config, $payload);
        $configs->saveOrFail($config);
    }

    /**
     * Store tenant-specific email/storage runtime configuration metadata.
     *
     * @param \App\Model\Entity\Tenant $tenant Tenant
     * @param array<string, mixed> $data Options
     * @return void
     */
    private function ensureServiceConfigs(Tenant $tenant, array $data): void
    {
        $emailMetadata = $this->decodeJsonOption($data['email_config_json'] ?? null, 'email-config-json');
        if ($emailMetadata !== null || !empty($data['email_secret_reference'])) {
            $this->upsertServiceConfig($tenant, [
                'service_name' => 'email',
                'config_key' => 'default',
                'adapter' => null,
                'secret_reference' => $this->nullableString($data['email_secret_reference'] ?? null),
                'metadata' => $emailMetadata ?? [],
                'is_active' => true,
            ]);
        }

        $storageMetadata = $this->decodeJsonOption($data['storage_config_json'] ?? null, 'storage-config-json');
        if (
            $storageMetadata !== null
            || !empty($data['storage_adapter'])
            || !empty($data['storage_secret_reference'])
        ) {
            $this->upsertServiceConfig($tenant, [
                'service_name' => 'storage',
                'config_key' => 'default',
                'adapter' => $this->nullableString($data['storage_adapter'] ?? null),
                'secret_reference' => $this->nullableString($data['storage_secret_reference'] ?? null),
                'metadata' => $storageMetadata ?? [],
                'is_active' => true,
            ]);
        }
    }

    /**
     * @param \App\Model\Entity\Tenant $tenant Tenant
     * @param array<string, mixed> $payload Service config payload
     * @return void
     */
    private function upsertServiceConfig(Tenant $tenant, array $payload): void
    {
        $configs = $this->fetchTable('TenantServiceConfigs');
        $config = $configs->find()->where([
            'TenantServiceConfigs.tenant_id' => $tenant->id,
            'TenantServiceConfigs.service_name' => $payload['service_name'],
            'TenantServiceConfigs.config_key' => $payload['config_key'],
        ])->first();
        $payload['tenant_id'] = $tenant->id;
        if (array_key_exists('metadata', $payload) && is_array($payload['metadata'])) {
            $payload['metadata'] = $this->encodeMetadata($payload['metadata']);
        }
        $config = $config === null ? $configs->newEntity($payload) : $configs->patchEntity($config, $payload);
        $configs->saveOrFail($config);
    }

    /**
     * @param mixed $value JSON option value
     * @param string $optionName Option name for errors
     * @return array<string, mixed>|null
     */
    private function decodeJsonOption(mixed $value, string $optionName): ?array
    {
        $value = trim((string)($value ?? ''));
        if ($value === '') {
            return null;
        }
        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            throw new RuntimeException(sprintf('--%s must be a JSON object.', $optionName));
        }

        return $decoded;
    }

    /**
     * Encode metadata for platform datastores that expose JSON columns as text.
     *
     * @param array<string, mixed> $metadata Metadata
     * @return string|null
     */
    private function encodeMetadata(array $metadata): ?string
    {
        if ($metadata === []) {
            return null;
        }

        return json_encode($metadata, JSON_THROW_ON_ERROR);
    }

    /**
     * @param \App\Model\Entity\Tenant $tenant Tenant
     * @return array{ok: bool, message: string}
     */
    private function checkSchemaVersion(Tenant $tenant): array
    {
        $required = Configure::read('Tenancy.requiredSchemaVersion');
        if (!is_string($required) || $required === '') {
            return ['ok' => true, 'message' => (string)($tenant->schema_version ?? 'not required')];
        }

        return [
            'ok' => (string)($tenant->schema_version ?? '') === $required,
            'message' => sprintf('current=%s required=%s', (string)($tenant->schema_version ?? ''), $required),
        ];
    }

    /**
     * @return array{ok: bool, message: string}
     */
    private function checkRequiredAppSettings(): array
    {
        try {
            $settings = TableRegistry::getTableLocator()->get('AppSettings');
            $missing = [];
            foreach (['KMP.KingdomName', 'KMP.ShortSiteTitle', 'KMP.LongSiteTitle'] as $name) {
                if (!$settings->exists(['name' => $name])) {
                    $missing[] = $name;
                }
            }
        } catch (MissingDatasourceConfigException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'Unable to inspect app_settings: ' . $e->getMessage()];
        }

        return empty($missing)
            ? ['ok' => true, 'message' => 'required settings present']
            : ['ok' => false, 'message' => 'missing: ' . implode(', ', $missing)];
    }
}
