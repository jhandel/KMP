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
