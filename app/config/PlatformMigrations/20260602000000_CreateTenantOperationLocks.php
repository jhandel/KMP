<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateTenantOperationLocks extends BaseMigration
{
    /**
     * Create tenant operation lock leases used by destructive admin operations.
     */
    public function change(): void
    {
        if ($this->hasTable('tenant_operation_locks')) {
            return;
        }

        $table = $this->table('tenant_operation_locks');
        $table
            ->addColumn('tenant_id', 'integer', ['limit' => 11, 'null' => false])
            ->addColumn('operation', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('owner', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('lease_token', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('lease_acquired_at', 'datetime', ['null' => false])
            ->addColumn('lease_expires_at', 'datetime', ['null' => false])
            ->addColumn('heartbeat_at', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('status_message', 'string', ['limit' => 1024, 'null' => true, 'default' => null])
            ->addColumn('metadata', 'json', ['null' => true, 'default' => null])
            ->addColumn('tenant_operation_job_id', 'integer', ['limit' => 11, 'null' => true, 'default' => null])
            ->addColumn('stale_recovered_at', 'datetime', ['null' => true, 'default' => null])
            ->addTimestamps()
            ->addIndex(['tenant_id'], ['name' => 'idx_tenant_operation_lock_tenant', 'unique' => true])
            ->addIndex(['lease_expires_at'], ['name' => 'idx_tenant_operation_lock_lease_expiry'])
            ->addIndex(['operation'], ['name' => 'idx_tenant_operation_lock_operation'])
            ->create();
    }
}
