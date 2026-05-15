<?php
declare(strict_types=1);

namespace App\Test\TestCase\Services\Tenant;

use App\Services\Tenant\TenantConnectionAccessor;
use App\Services\Tenant\TenantContext;
use App\Services\Tenant\TenantContextAccessor;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;

class TenantConnectionAccessorTest extends TestCase
{
    /**
     * @var array<string, string>
     */
    private array $originalAliases = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalAliases = ConnectionManager::aliases();
        foreach ($this->originalAliases as $alias => $_source) {
            ConnectionManager::dropAlias($alias);
        }

        ConnectionManager::alias('test', 'default');
        ConnectionManager::alias('test_debug_kit', 'tenant');
        ConnectionManager::alias('test', 'platform');
    }

    protected function tearDown(): void
    {
        TenantContextAccessor::set(null);
        TenantContext::clearCurrent();
        foreach (ConnectionManager::aliases() as $alias => $_source) {
            ConnectionManager::dropAlias($alias);
        }
        foreach ($this->originalAliases as $alias => $source) {
            ConnectionManager::alias($source, $alias);
        }
        parent::tearDown();
    }

    public function testTenantDomainReturnsDefaultWithoutTenantContext(): void
    {
        $accessor = new TenantConnectionAccessor();

        $this->assertSame(
            ConnectionManager::get('default'),
            $accessor->tenantDomain(),
        );
    }

    public function testTenantDomainReturnsTenantWithTenantContext(): void
    {
        $context = new TenantContext(7, 'tenant-a', 'Tenant A', 'active', null, null, 'tenant-a.example.org');
        TenantContextAccessor::set($context);

        $accessor = new TenantConnectionAccessor();

        $this->assertSame(
            ConnectionManager::get('tenant'),
            $accessor->tenantDomain(),
        );
    }

    public function testTransactionalUsesResolvedTenantConnection(): void
    {
        $context = new TenantContext(8, 'tenant-b', 'Tenant B', 'active', null, null, 'tenant-b.example.org');
        TenantContextAccessor::set($context);

        $accessor = new TenantConnectionAccessor();
        $captured = null;
        $result = $accessor->transactional(static function ($connection) use (&$captured): string {
            $captured = $connection;

            return 'ok';
        });

        $this->assertSame('ok', $result);
        $this->assertSame(ConnectionManager::get('tenant'), $captured);
    }
}
