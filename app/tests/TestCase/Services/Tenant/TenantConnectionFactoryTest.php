<?php
declare(strict_types=1);

namespace App\Test\TestCase\Services\Tenant;

use App\Services\Platform\PlatformSecretService;
use App\Services\Tenant\TenantConnectionFactory;
use App\Services\Tenant\TenantContext;
use Cake\Cache\Cache;
use Cake\Database\Connection;
use Cake\Database\Driver\Sqlite;
use Cake\Datasource\ConnectionManager;
use Cake\Datasource\Exception\MissingDatasourceConfigException;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;
use Migrations\Migrations;

class TenantConnectionFactoryTest extends TestCase
{
    /**
     * @var array<string, mixed>|null
     */
    private ?array $originalTenantConfig = null;

    /**
     * @var array<string, mixed>|null
     */
    private ?array $originalModelCacheConfig = null;

    /**
     * @var array<string, string>
     */
    private array $originalAliases = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalTenantConfig = ConnectionManager::getConfig('tenant');
        $this->originalModelCacheConfig = Cache::getConfig('_cake_model_');
        $this->originalAliases = ConnectionManager::aliases();
        ConnectionManager::dropAlias('tenant');
    }

    protected function tearDown(): void
    {
        ConnectionManager::drop('tenant');
        TenantContext::clearCurrent();
        Cache::drop('_cake_model_');
        if ($this->originalModelCacheConfig !== null) {
            Cache::setConfig('_cake_model_', $this->originalModelCacheConfig);
        }
        if ($this->originalTenantConfig !== null) {
            ConnectionManager::setConfig('tenant', $this->originalTenantConfig);
        }
        foreach (ConnectionManager::aliases() as $alias => $source) {
            ConnectionManager::dropAlias($alias);
        }
        foreach ($this->originalAliases as $alias => $source) {
            ConnectionManager::alias($source, $alias);
        }
        putenv('PLATFORM_SECRET_KEY');
        unset($_ENV['PLATFORM_SECRET_KEY']);
        (new PlatformSecretService())->clearCache();
        TableRegistry::getTableLocator()->clear();
        parent::tearDown();
    }

    public function testConfigureReplacesTenantConnectionWithoutMutatingDefault(): void
    {
        $defaultConfig = ConnectionManager::getConfig('default');
        ConnectionManager::drop('tenant');
        ConnectionManager::setConfig('tenant', [
            'className' => Connection::class,
            'driver' => Sqlite::class,
            'database' => 'legacy.sqlite',
            'cacheMetadata' => true,
        ]);
        TableRegistry::getTableLocator()->setConfig('Members', ['className' => 'App\\Model\\Table\\MembersTable']);
        TableRegistry::getTableLocator()->get('Members');
        $this->assertTrue(TableRegistry::getTableLocator()->exists('Members'));

        $context = new TenantContext(
            10,
            'tenant-a',
            'Tenant A',
            'active',
            '2026.04',
            'tenant-a.example.org',
            'tenant-a.example.org',
            [[
                'connectionRole' => 'primary',
                'driver' => Sqlite::class,
                'host' => 'ignored-for-sqlite',
                'port' => null,
                'databaseName' => 'tenant-a.sqlite',
                'username' => null,
                'secretReference' => null,
                'readEnabled' => true,
                'writeEnabled' => true,
                'isActive' => true,
                'metadata' => [],
            ]],
        );

        (new TenantConnectionFactory())->configure($context);
        $tenantConfig = ConnectionManager::getConfig('tenant');

        $this->assertSame('tenant-a.sqlite', $tenantConfig['database']);
        $this->assertSame(Sqlite::class, $tenantConfig['driver']);
        $this->assertSame($defaultConfig, ConnectionManager::getConfig('default'));
        $this->assertFalse(TableRegistry::getTableLocator()->exists('Members'));
        $this->assertSame('tenant-a', TenantContext::getCurrent()?->slug);
        $this->assertStringContainsString(
            'tenant_10_tenant-a_model_',
            (string)(Cache::getConfig('_cake_model_')['prefix'] ?? ''),
        );
    }

    public function testConfigureResolvesManagedDatabaseSecret(): void
    {
        putenv('PLATFORM_SECRET_KEY=test-platform-secret-key-32-chars-minimum');
        $_ENV['PLATFORM_SECRET_KEY'] = 'test-platform-secret-key-32-chars-minimum';
        $this->loadPlatformMigrations();
        (new PlatformSecretService())->storeSecret('tenant/20/database/primary', 'managed-db-password');
        ConnectionManager::drop('tenant');
        ConnectionManager::setConfig('tenant', [
            'className' => Connection::class,
            'driver' => Sqlite::class,
            'database' => 'legacy.sqlite',
            'cacheMetadata' => true,
        ]);

        $context = new TenantContext(
            20,
            'tenant-secret',
            'Tenant Secret',
            'active',
            null,
            'secret.example.org',
            'secret.example.org',
            [[
                'connectionRole' => 'primary',
                'driver' => Sqlite::class,
                'host' => 'ignored-for-sqlite',
                'port' => null,
                'databaseName' => 'secret.sqlite',
                'username' => 'db-user',
                'secretReference' => 'managed:tenant/20/database/primary',
                'readEnabled' => true,
                'writeEnabled' => true,
                'isActive' => true,
                'metadata' => [],
            ]],
        );

        (new TenantConnectionFactory())->configure($context);

        $this->assertSame('managed-db-password', ConnectionManager::getConfig('tenant')['password']);
    }

    public function testSwitchingTenantsReplacesDataConnectionAndCachePrefix(): void
    {
        ConnectionManager::drop('tenant');
        ConnectionManager::setConfig('tenant', [
            'className' => Connection::class,
            'driver' => Sqlite::class,
            'database' => 'legacy.sqlite',
            'cacheMetadata' => true,
        ]);
        $factory = new TenantConnectionFactory();
        $tenantA = new TenantContext(
            31,
            'tenant-a',
            'Tenant A',
            'active',
            null,
            'tenant-a.example.org',
            'tenant-a.example.org',
            [[
                'connectionRole' => 'primary',
                'driver' => Sqlite::class,
                'databaseName' => 'tenant-a.sqlite',
                'isActive' => true,
            ]],
        );
        $tenantB = new TenantContext(
            32,
            'tenant-b',
            'Tenant B',
            'active',
            null,
            'tenant-b.example.org',
            'tenant-b.example.org',
            [[
                'connectionRole' => 'primary',
                'driver' => Sqlite::class,
                'databaseName' => 'tenant-b.sqlite',
                'isActive' => true,
            ]],
        );

        $factory->configure($tenantA);
        $tenantAPrefix = (string)(Cache::getConfig('_cake_model_')['prefix'] ?? '');
        $this->assertSame('tenant-a.sqlite', ConnectionManager::getConfig('tenant')['database']);
        $this->assertStringContainsString('tenant_31_tenant-a_model_', $tenantAPrefix);

        $factory->configure($tenantB);
        $tenantBPrefix = (string)(Cache::getConfig('_cake_model_')['prefix'] ?? '');
        $this->assertSame('tenant-b.sqlite', ConnectionManager::getConfig('tenant')['database']);
        $this->assertStringContainsString('tenant_32_tenant-b_model_', $tenantBPrefix);
        $this->assertStringNotContainsString('tenant_31_tenant-a_model_', $tenantBPrefix);

        TenantContext::clearCurrent();
        $factory->resetOrmState();
        $this->assertSame(
            (string)($this->originalModelCacheConfig['prefix'] ?? ''),
            (string)(Cache::getConfig('_cake_model_')['prefix'] ?? ''),
        );
    }

    public function testConfigureThrowsWhenTenantHasNoActiveDatabaseConfig(): void
    {
        $context = new TenantContext(
            21,
            'tenant-no-db',
            'Tenant No DB',
            'active',
            null,
            'tenant-no-db.example.org',
            'tenant-no-db.example.org',
            [],
        );

        $this->expectException(MissingDatasourceConfigException::class);
        (new TenantConnectionFactory())->configure($context);
    }

    private function loadPlatformMigrations(): void
    {
        (new Migrations())->migrate([
            'connection' => 'test',
            'source' => 'PlatformMigrations',
        ]);
        $this->getTableLocator()->get('PlatformSecrets')->deleteAll([]);
        (new PlatformSecretService())->clearCache();
    }
}
