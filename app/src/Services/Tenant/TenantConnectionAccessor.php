<?php
declare(strict_types=1);

namespace App\Services\Tenant;

use Cake\Database\Connection;
use Cake\Datasource\ConnectionManager;

/**
 * Intentional connection/transaction accessor for tenant-aware runtime code.
 */
class TenantConnectionAccessor
{
    /**
     * Resolve the tenant-domain data connection.
     *
     * Uses tenant when a tenant context is active, otherwise falls back to
     * default for legacy/non-tenant execution paths.
     *
     * @param \App\Services\Tenant\TenantContext|null $context Optional explicit context
     * @return \Cake\Database\Connection
     */
    public function tenantDomain(?TenantContext $context = null): Connection
    {
        $context ??= TenantContextAccessor::get();

        return $context !== null ? $this->tenant() : $this->default();
    }

    /**
     * Tenant data-plane connection.
     *
     * @return \Cake\Database\Connection
     */
    public function tenant(): Connection
    {
        return ConnectionManager::get('tenant');
    }

    /**
     * Platform control-plane connection.
     *
     * @return \Cake\Database\Connection
     */
    public function platform(): Connection
    {
        return ConnectionManager::get('platform');
    }

    /**
     * Default app/legacy connection.
     *
     * @return \Cake\Database\Connection
     */
    public function default(): Connection
    {
        return ConnectionManager::get('default');
    }

    /**
     * Run work in a transaction on the resolved tenant-domain connection.
     *
     * @template T
     * @param callable(\Cake\Database\Connection):T $callback Transaction callback
     * @param \App\Services\Tenant\TenantContext|null $context Optional explicit context
     * @return T
     */
    public function transactional(callable $callback, ?TenantContext $context = null): mixed
    {
        return $this->tenantDomain($context)->transactional(static function (Connection $connection) use ($callback): mixed {
            return $callback($connection);
        });
    }
}
