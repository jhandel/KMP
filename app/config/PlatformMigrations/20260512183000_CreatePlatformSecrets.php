<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreatePlatformSecrets extends BaseMigration
{
    public bool $autoId = false;

    /**
     * Create encrypted platform-managed secret storage.
     *
     * @return void
     */
    public function change(): void
    {
        $this->table('platform_secrets', ['id' => false])
            ->addColumn('id', 'integer', [
                'autoIncrement' => true,
                'limit' => 11,
                'null' => false,
            ])
            ->addColumn('name', 'string', [
                'limit' => 255,
                'null' => false,
            ])
            ->addColumn('encrypted_value', 'text', [
                'null' => false,
            ])
            ->addColumn('key_version', 'string', [
                'default' => 'v1',
                'limit' => 64,
                'null' => false,
            ])
            ->addColumn('description', 'string', [
                'default' => null,
                'limit' => 512,
                'null' => true,
            ])
            ->addColumn('created_by_platform_admin_id', 'integer', [
                'default' => null,
                'limit' => 11,
                'null' => true,
            ])
            ->addColumn('created', 'datetime', [
                'null' => false,
            ])
            ->addColumn('modified', 'datetime', [
                'null' => false,
            ])
            ->addPrimaryKey(['id'])
            ->addIndex(['name'], [
                'name' => 'idx_platform_secrets_name',
                'unique' => true,
            ])
            ->addForeignKey('created_by_platform_admin_id', 'platform_admins', 'id', [
                'constraint' => 'fk_platform_secrets_admin',
                'delete' => 'SET_NULL',
            ])
            ->create();
    }
}
