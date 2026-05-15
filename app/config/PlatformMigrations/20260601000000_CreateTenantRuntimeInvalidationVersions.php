<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateTenantRuntimeInvalidationVersions extends BaseMigration
{
    /**
     * Create cross-pod tenant runtime invalidation version table.
     *
     * @return void
     */
    public function change(): void
    {
        $this->table('tenant_runtime_invalidation_versions', ['id' => false])
            ->addColumn('tenant_id', 'integer', [
                'limit' => 11,
                'null' => false,
            ])
            ->addColumn('version', 'biginteger', [
                'default' => 0,
                'null' => false,
            ])
            ->addColumn('last_change_type', 'string', [
                'default' => null,
                'limit' => 64,
                'null' => true,
            ])
            ->addColumn('created', 'datetime', [
                'default' => null,
                'null' => false,
            ])
            ->addColumn('modified', 'datetime', [
                'default' => null,
                'null' => false,
            ])
            ->addPrimaryKey(['tenant_id'])
            ->addIndex(['modified'], [
                'name' => 'idx_tenant_runtime_invalidation_modified',
            ])
            ->create();
    }
}
