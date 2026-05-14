<?php
declare(strict_types=1);

namespace App\Services\Tenant;

use App\Services\Platform\PlatformSecretService;
use Cake\Cache\Cache;
use Cake\Datasource\ConnectionManager;
use Cake\Datasource\Exception\MissingDatasourceConfigException;
use Cake\ORM\TableRegistry;
use InvalidArgumentException;

/**
 * Applies the request-scoped tenant datasource configuration safely.
 */
class TenantConnectionFactory
{
    /**
     * @var array<string, mixed>|null
     */
    private static ?array $baseModelCacheConfig = null;

    /**
     * Configure the named tenant connection for a resolved tenant.
     *
     * @param \App\Services\Tenant\TenantContext $context Tenant context
     * @return void
     */
    public function configure(TenantContext $context): void
    {
        TenantContext::setCurrent($context);
        $this->configureModelMetadataCache($context);
        $databaseConfig = $this->selectPrimaryConfig($context);
        $currentConfig = (array)(ConnectionManager::getConfig('tenant')
            ?? ConnectionManager::getConfig('default')
            ?? []);
        $nextConfig = $this->buildConnectionConfig($currentConfig, $databaseConfig);

        $aliases = ConnectionManager::aliases();
        $testConfig = (array)(ConnectionManager::getConfig('test') ?? []);
        if (
            ($aliases['tenant'] ?? null) === 'test'
            && ($testConfig['database'] ?? null) === ($nextConfig['database'] ?? null)
        ) {
            $this->resetOrmState();

            return;
        }

        ConnectionManager::dropAlias('tenant');
        ConnectionManager::drop('tenant');
        ConnectionManager::setConfig('tenant', $nextConfig);
        $this->resetOrmState();
    }

    /**
     * Reset locator/schema state after a tenant datasource switch.
     *
     * @return void
     */
    public function resetOrmState(): void
    {
        if (TenantContext::getCurrent() === null) {
            $this->restoreBaseModelMetadataCache();
        }
        TableRegistry::getTableLocator()->clear();
        Cache::clear('_cake_model_');
    }

    /**
     * Scope CakePHP schema metadata cache to the active tenant.
     *
     * @param \App\Services\Tenant\TenantContext $context Tenant context
     * @return void
     */
    private function configureModelMetadataCache(TenantContext $context): void
    {
        self::$baseModelCacheConfig ??= (array)Cache::getConfig('_cake_model_');
        $config = self::$baseModelCacheConfig;
        $basePrefix = (string)($config['prefix'] ?? '');
        $config['prefix'] = $basePrefix . TenantContext::cacheKeyPrefix($context) . '_model_';

        Cache::drop('_cake_model_');
        Cache::setConfig('_cake_model_', $config);
    }

    /**
     * Restore the non-tenant schema metadata cache when no tenant is active.
     *
     * @return void
     */
    private function restoreBaseModelMetadataCache(): void
    {
        if (self::$baseModelCacheConfig === null) {
            return;
        }

        Cache::drop('_cake_model_');
        Cache::setConfig('_cake_model_', self::$baseModelCacheConfig);
    }

    /**
     * @param array<string, mixed> $currentConfig Existing tenant/default config
     * @param array<string, mixed> $databaseConfig Platform database metadata
     * @return array<string, mixed>
     */
    protected function buildConnectionConfig(array $currentConfig, array $databaseConfig): array
    {
        $config = $currentConfig;
        $config['name'] = 'tenant';

        foreach (
            [
                'driver' => 'driver',
                'host' => 'host',
                'port' => 'port',
                'databaseName' => 'database',
                'username' => 'username',
            ] as $source => $target
        ) {
            if (
                array_key_exists($source, $databaseConfig)
                && $databaseConfig[$source] !== null
                && $databaseConfig[$source] !== ''
            ) {
                $config[$target] = $databaseConfig[$source];
            }
        }

        $metadata = is_array($databaseConfig['metadata'] ?? null) ? $databaseConfig['metadata'] : [];
        if (!empty($metadata['url']) && is_string($metadata['url'])) {
            $config['url'] = $metadata['url'];
        }
        if (!empty($metadata['passwordEnv']) && is_string($metadata['passwordEnv'])) {
            $config['password'] = env($metadata['passwordEnv'], $config['password'] ?? null);
        }
        if (!empty($metadata['password']) && is_string($metadata['password'])) {
            $config['password'] = $metadata['password'];
        }

        $secret = (new PlatformSecretService())->resolveSecretReference($databaseConfig['secretReference'] ?? null);
        if ($secret !== null) {
            $config['password'] = $secret;
        }

        if (empty($config['database']) && empty($config['url'])) {
            throw new InvalidArgumentException('Tenant database configuration must include a database name or URL.');
        }

        return $config;
    }

    /**
     * @param \App\Services\Tenant\TenantContext $context Tenant context
     * @return array<string, mixed>
     */
    private function selectPrimaryConfig(TenantContext $context): array
    {
        foreach ($context->databaseConfigs as $config) {
            if (($config['isActive'] ?? false) && ($config['connectionRole'] ?? 'primary') === 'primary') {
                return $config;
            }
        }

        foreach ($context->databaseConfigs as $config) {
            if ($config['isActive'] ?? false) {
                return $config;
            }
        }

        throw new MissingDatasourceConfigException(['name' => sprintf('tenant:%s', $context->slug)]);
    }
}
