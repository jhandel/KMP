<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreatePlatformTenancy extends BaseMigration
{
    public bool $autoId = false;

    /**
     * Create platform tenant registry tables.
     *
     * @return void
     */
    public function change(): void
    {
        $this->table('tenants', ['id' => false])
            ->addColumn('id', 'integer', [
                'autoIncrement' => true,
                'default' => null,
                'limit' => 11,
                'null' => false,
            ])
            ->addColumn('slug', 'string', [
                'limit' => 80,
                'null' => false,
            ])
            ->addColumn('display_name', 'string', [
                'limit' => 255,
                'null' => false,
            ])
            ->addColumn('status', 'string', [
                'default' => 'provisioning',
                'limit' => 32,
                'null' => false,
            ])
            ->addColumn('schema_version', 'string', [
                'default' => null,
                'limit' => 64,
                'null' => true,
            ])
            ->addColumn('primary_host', 'string', [
                'default' => null,
                'limit' => 255,
                'null' => true,
            ])
            ->addColumn('path_prefix', 'string', [
                'default' => null,
                'limit' => 128,
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
            ->addPrimaryKey(['id'])
            ->addIndex(['slug'], [
                'name' => 'idx_tenants_slug',
                'unique' => true,
            ])
            ->addIndex(['status'], [
                'name' => 'idx_tenants_status',
            ])
            ->addIndex(['primary_host'], [
                'name' => 'idx_tenants_primary_host',
                'unique' => true,
            ])
            ->create();

        $this->table('tenant_aliases', ['id' => false])
            ->addColumn('id', 'integer', [
                'autoIncrement' => true,
                'default' => null,
                'limit' => 11,
                'null' => false,
            ])
            ->addColumn('tenant_id', 'integer', [
                'limit' => 11,
                'null' => false,
            ])
            ->addColumn('alias_type', 'string', [
                'default' => 'host',
                'limit' => 16,
                'null' => false,
            ])
            ->addColumn('value', 'string', [
                'limit' => 255,
                'null' => false,
            ])
            ->addColumn('normalized_value', 'string', [
                'limit' => 255,
                'null' => false,
            ])
            ->addColumn('priority', 'integer', [
                'default' => 100,
                'limit' => 11,
                'null' => false,
            ])
            ->addColumn('is_active', 'boolean', [
                'default' => true,
                'null' => false,
            ])
            ->addColumn('created', 'datetime', [
                'default' => null,
                'null' => false,
            ])
            ->addColumn('modified', 'datetime', [
                'default' => null,
                'null' => false,
            ])
            ->addPrimaryKey(['id'])
            ->addForeignKey('tenant_id', 'tenants', 'id', [
                'constraint' => 'fk_tenant_aliases_tenant',
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
            ])
            ->addIndex(['tenant_id'], [
                'name' => 'idx_tenant_aliases_tenant',
            ])
            ->addIndex(['alias_type', 'normalized_value'], [
                'name' => 'idx_tenant_aliases_type_value',
                'unique' => true,
            ])
            ->addIndex(['alias_type', 'is_active', 'priority'], [
                'name' => 'idx_tenant_aliases_lookup',
            ])
            ->create();

        $this->table('tenant_database_configs', ['id' => false])
            ->addColumn('id', 'integer', [
                'autoIncrement' => true,
                'default' => null,
                'limit' => 11,
                'null' => false,
            ])
            ->addColumn('tenant_id', 'integer', [
                'limit' => 11,
                'null' => false,
            ])
            ->addColumn('connection_role', 'string', [
                'default' => 'primary',
                'limit' => 16,
                'null' => false,
            ])
            ->addColumn('driver', 'string', [
                'limit' => 255,
                'null' => false,
            ])
            ->addColumn('host', 'string', [
                'limit' => 255,
                'null' => false,
            ])
            ->addColumn('port', 'integer', [
                'default' => null,
                'limit' => 11,
                'null' => true,
            ])
            ->addColumn('database_name', 'string', [
                'limit' => 255,
                'null' => false,
            ])
            ->addColumn('username', 'string', [
                'default' => null,
                'limit' => 255,
                'null' => true,
            ])
            ->addColumn('secret_reference', 'string', [
                'default' => null,
                'limit' => 512,
                'null' => true,
            ])
            ->addColumn('encrypted_dsn', 'text', [
                'default' => null,
                'null' => true,
            ])
            ->addColumn('read_enabled', 'boolean', [
                'default' => true,
                'null' => false,
            ])
            ->addColumn('write_enabled', 'boolean', [
                'default' => true,
                'null' => false,
            ])
            ->addColumn('is_active', 'boolean', [
                'default' => true,
                'null' => false,
            ])
            ->addColumn('metadata', 'json', [
                'default' => null,
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
            ->addPrimaryKey(['id'])
            ->addForeignKey('tenant_id', 'tenants', 'id', [
                'constraint' => 'fk_tenant_db_configs_tenant',
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
            ])
            ->addIndex(['tenant_id'], [
                'name' => 'idx_tenant_db_configs_tenant',
            ])
            ->addIndex(['tenant_id', 'connection_role'], [
                'name' => 'idx_tenant_db_configs_role',
                'unique' => true,
            ])
            ->addIndex(['is_active'], [
                'name' => 'idx_tenant_db_configs_active',
            ])
            ->create();

        $this->table('tenant_service_configs', ['id' => false])
            ->addColumn('id', 'integer', [
                'autoIncrement' => true,
                'default' => null,
                'limit' => 11,
                'null' => false,
            ])
            ->addColumn('tenant_id', 'integer', [
                'limit' => 11,
                'null' => false,
            ])
            ->addColumn('service_name', 'string', [
                'limit' => 64,
                'null' => false,
            ])
            ->addColumn('config_key', 'string', [
                'default' => 'default',
                'limit' => 64,
                'null' => false,
            ])
            ->addColumn('adapter', 'string', [
                'default' => null,
                'limit' => 64,
                'null' => true,
            ])
            ->addColumn('secret_reference', 'string', [
                'default' => null,
                'limit' => 512,
                'null' => true,
            ])
            ->addColumn('metadata', 'json', [
                'default' => null,
                'null' => true,
            ])
            ->addColumn('is_active', 'boolean', [
                'default' => true,
                'null' => false,
            ])
            ->addColumn('created', 'datetime', [
                'default' => null,
                'null' => false,
            ])
            ->addColumn('modified', 'datetime', [
                'default' => null,
                'null' => false,
            ])
            ->addPrimaryKey(['id'])
            ->addForeignKey('tenant_id', 'tenants', 'id', [
                'constraint' => 'fk_tenant_service_configs_tenant',
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
            ])
            ->addIndex(['tenant_id'], [
                'name' => 'idx_tenant_service_configs_tenant',
            ])
            ->addIndex(['tenant_id', 'service_name', 'config_key'], [
                'name' => 'idx_tenant_service_configs_key',
                'unique' => true,
            ])
            ->addIndex(['service_name', 'is_active'], [
                'name' => 'idx_tenant_service_configs_lookup',
            ])
            ->create();
    }
}
