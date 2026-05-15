<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreatePlatformAdminEmailCodes extends BaseMigration
{
    public bool $autoId = false;

    /**
     * Store short-lived hashed platform admin email verification codes.
     */
    public function change(): void
    {
        $this->table('platform_admin_email_codes', ['id' => false])
            ->addColumn('id', 'integer', [
                'autoIncrement' => true,
                'limit' => 11,
                'null' => false,
            ])
            ->addColumn('platform_admin_id', 'integer', [
                'limit' => 11,
                'null' => false,
            ])
            ->addColumn('purpose', 'string', [
                'limit' => 32,
                'null' => false,
            ])
            ->addColumn('code_hash', 'string', [
                'limit' => 255,
                'null' => false,
            ])
            ->addColumn('expires_at', 'datetime', [
                'null' => false,
            ])
            ->addColumn('attempts', 'integer', [
                'default' => 0,
                'limit' => 11,
                'null' => false,
            ])
            ->addColumn('max_attempts', 'integer', [
                'default' => 5,
                'limit' => 11,
                'null' => false,
            ])
            ->addColumn('ip_address', 'string', [
                'default' => null,
                'limit' => 64,
                'null' => true,
            ])
            ->addColumn('user_agent', 'string', [
                'default' => null,
                'limit' => 512,
                'null' => true,
            ])
            ->addColumn('used_at', 'datetime', [
                'default' => null,
                'null' => true,
            ])
            ->addColumn('created', 'datetime', [
                'null' => false,
            ])
            ->addColumn('modified', 'datetime', [
                'null' => false,
            ])
            ->addPrimaryKey(['id'])
            ->addForeignKey('platform_admin_id', 'platform_admins', 'id', [
                'constraint' => 'fk_platform_email_codes_admin',
                'delete' => 'CASCADE',
            ])
            ->addIndex(['platform_admin_id', 'purpose', 'used_at', 'expires_at'], [
                'name' => 'idx_platform_email_codes_lookup',
            ])
            ->create();
    }
}
