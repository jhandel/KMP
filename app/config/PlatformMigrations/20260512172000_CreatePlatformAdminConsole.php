<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreatePlatformAdminConsole extends BaseMigration
{
    public bool $autoId = false;

    /**
     * Create platform admin identity, audit, and operation tables.
     *
     * @return void
     */
    public function change(): void
    {
        $this->table('platform_admins', ['id' => false])
            ->addColumn('id', 'integer', [
                'autoIncrement' => true,
                'limit' => 11,
                'null' => false,
            ])
            ->addColumn('email', 'string', [
                'limit' => 255,
                'null' => false,
            ])
            ->addColumn('display_name', 'string', [
                'limit' => 255,
                'null' => false,
            ])
            ->addColumn('password_hash', 'string', [
                'limit' => 255,
                'null' => false,
            ])
            ->addColumn('status', 'string', [
                'default' => 'active',
                'limit' => 32,
                'null' => false,
            ])
            ->addColumn('require_password_change', 'boolean', [
                'default' => false,
                'null' => false,
            ])
            ->addColumn('failed_attempts', 'integer', [
                'default' => 0,
                'limit' => 11,
                'null' => false,
            ])
            ->addColumn('locked_until', 'datetime', [
                'default' => null,
                'null' => true,
            ])
            ->addColumn('last_login_at', 'datetime', [
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
            ->addIndex(['email'], [
                'name' => 'idx_platform_admins_email',
                'unique' => true,
            ])
            ->addIndex(['status'], [
                'name' => 'idx_platform_admins_status',
            ])
            ->create();

        $this->table('platform_admin_webauthn_credentials', ['id' => false])
            ->addColumn('id', 'integer', [
                'autoIncrement' => true,
                'limit' => 11,
                'null' => false,
            ])
            ->addColumn('platform_admin_id', 'integer', [
                'limit' => 11,
                'null' => false,
            ])
            ->addColumn('credential_id', 'string', [
                'limit' => 512,
                'null' => false,
            ])
            ->addColumn('public_key', 'text', [
                'null' => false,
            ])
            ->addColumn('sign_count', 'integer', [
                'default' => 0,
                'limit' => 11,
                'null' => false,
            ])
            ->addColumn('label', 'string', [
                'default' => null,
                'limit' => 255,
                'null' => true,
            ])
            ->addColumn('last_used_at', 'datetime', [
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
                'constraint' => 'fk_platform_webauthn_admin',
                'delete' => 'CASCADE',
            ])
            ->addIndex(['platform_admin_id'], [
                'name' => 'idx_platform_webauthn_admin',
            ])
            ->addIndex(['credential_id'], [
                'name' => 'idx_platform_webauthn_credential',
                'unique' => true,
            ])
            ->create();

        $this->table('platform_admin_recovery_codes', ['id' => false])
            ->addColumn('id', 'integer', [
                'autoIncrement' => true,
                'limit' => 11,
                'null' => false,
            ])
            ->addColumn('platform_admin_id', 'integer', [
                'limit' => 11,
                'null' => false,
            ])
            ->addColumn('code_hash', 'string', [
                'limit' => 255,
                'null' => false,
            ])
            ->addColumn('used_at', 'datetime', [
                'default' => null,
                'null' => true,
            ])
            ->addColumn('created', 'datetime', [
                'null' => false,
            ])
            ->addPrimaryKey(['id'])
            ->addForeignKey('platform_admin_id', 'platform_admins', 'id', [
                'constraint' => 'fk_platform_recovery_admin',
                'delete' => 'CASCADE',
            ])
            ->addIndex(['platform_admin_id', 'used_at'], [
                'name' => 'idx_platform_recovery_available',
            ])
            ->create();

        $this->table('platform_service_configs', ['id' => false])
            ->addColumn('id', 'integer', [
                'autoIncrement' => true,
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
                'null' => false,
            ])
            ->addColumn('modified', 'datetime', [
                'null' => false,
            ])
            ->addPrimaryKey(['id'])
            ->addIndex(['service_name', 'config_key'], [
                'name' => 'idx_platform_service_configs_key',
                'unique' => true,
            ])
            ->addIndex(['service_name', 'is_active'], [
                'name' => 'idx_platform_service_configs_lookup',
            ])
            ->create();

        $this->table('platform_admin_sessions', ['id' => false])
            ->addColumn('id', 'integer', [
                'autoIncrement' => true,
                'limit' => 11,
                'null' => false,
            ])
            ->addColumn('platform_admin_id', 'integer', [
                'limit' => 11,
                'null' => false,
            ])
            ->addColumn('token_hash', 'string', [
                'limit' => 255,
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
            ->addColumn('last_seen_at', 'datetime', [
                'null' => false,
            ])
            ->addColumn('expires_at', 'datetime', [
                'null' => false,
            ])
            ->addColumn('revoked_at', 'datetime', [
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
                'constraint' => 'fk_platform_sessions_admin',
                'delete' => 'CASCADE',
            ])
            ->addIndex(['token_hash'], [
                'name' => 'idx_platform_sessions_token',
                'unique' => true,
            ])
            ->addIndex(['platform_admin_id', 'revoked_at'], [
                'name' => 'idx_platform_sessions_admin_active',
            ])
            ->create();

        $this->table('platform_audit_events', ['id' => false])
            ->addColumn('id', 'integer', [
                'autoIncrement' => true,
                'limit' => 11,
                'null' => false,
            ])
            ->addColumn('platform_admin_id', 'integer', [
                'default' => null,
                'limit' => 11,
                'null' => true,
            ])
            ->addColumn('tenant_id', 'integer', [
                'default' => null,
                'limit' => 11,
                'null' => true,
            ])
            ->addColumn('event_type', 'string', [
                'limit' => 64,
                'null' => false,
            ])
            ->addColumn('severity', 'string', [
                'default' => 'info',
                'limit' => 16,
                'null' => false,
            ])
            ->addColumn('action', 'string', [
                'limit' => 128,
                'null' => false,
            ])
            ->addColumn('result', 'string', [
                'limit' => 32,
                'null' => false,
            ])
            ->addColumn('subject_type', 'string', [
                'default' => null,
                'limit' => 128,
                'null' => true,
            ])
            ->addColumn('subject_id', 'string', [
                'default' => null,
                'limit' => 128,
                'null' => true,
            ])
            ->addColumn('request_id', 'string', [
                'default' => null,
                'limit' => 128,
                'null' => true,
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
            ->addColumn('metadata', 'json', [
                'default' => null,
                'null' => true,
            ])
            ->addColumn('previous_hash', 'string', [
                'default' => null,
                'limit' => 64,
                'null' => true,
            ])
            ->addColumn('event_hash', 'string', [
                'limit' => 64,
                'null' => false,
            ])
            ->addColumn('created', 'datetime', [
                'null' => false,
            ])
            ->addPrimaryKey(['id'])
            ->addForeignKey('platform_admin_id', 'platform_admins', 'id', [
                'constraint' => 'fk_platform_audit_admin',
                'delete' => 'SET_NULL',
            ])
            ->addForeignKey('tenant_id', 'tenants', 'id', [
                'constraint' => 'fk_platform_audit_tenant',
                'delete' => 'SET_NULL',
            ])
            ->addIndex(['event_type', 'created'], [
                'name' => 'idx_platform_audit_type_created',
            ])
            ->addIndex(['tenant_id', 'created'], [
                'name' => 'idx_platform_audit_tenant_created',
            ])
            ->create();

        $this->table('tenant_operation_jobs', ['id' => false])
            ->addColumn('id', 'integer', [
                'autoIncrement' => true,
                'limit' => 11,
                'null' => false,
            ])
            ->addColumn('tenant_id', 'integer', [
                'default' => null,
                'limit' => 11,
                'null' => true,
            ])
            ->addColumn('platform_admin_id', 'integer', [
                'limit' => 11,
                'null' => false,
            ])
            ->addColumn('operation', 'string', [
                'limit' => 64,
                'null' => false,
            ])
            ->addColumn('status', 'string', [
                'default' => 'queued',
                'limit' => 32,
                'null' => false,
            ])
            ->addColumn('input', 'json', [
                'default' => null,
                'null' => true,
            ])
            ->addColumn('result', 'json', [
                'default' => null,
                'null' => true,
            ])
            ->addColumn('error_message', 'text', [
                'default' => null,
                'null' => true,
            ])
            ->addColumn('webauthn_assertion_id', 'string', [
                'default' => null,
                'limit' => 255,
                'null' => true,
            ])
            ->addColumn('started_at', 'datetime', [
                'default' => null,
                'null' => true,
            ])
            ->addColumn('completed_at', 'datetime', [
                'default' => null,
                'null' => true,
            ])
            ->addColumn('cancelled_at', 'datetime', [
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
            ->addForeignKey('tenant_id', 'tenants', 'id', [
                'constraint' => 'fk_tenant_operation_jobs_tenant',
                'delete' => 'SET_NULL',
            ])
            ->addForeignKey('platform_admin_id', 'platform_admins', 'id', [
                'constraint' => 'fk_tenant_operation_jobs_admin',
                'delete' => 'RESTRICT',
            ])
            ->addIndex(['status', 'created'], [
                'name' => 'idx_tenant_operation_jobs_status',
            ])
            ->addIndex(['tenant_id', 'created'], [
                'name' => 'idx_tenant_operation_jobs_tenant',
            ])
            ->create();

        $this->table('tenant_operation_approvals', ['id' => false])
            ->addColumn('id', 'integer', [
                'autoIncrement' => true,
                'limit' => 11,
                'null' => false,
            ])
            ->addColumn('tenant_operation_job_id', 'integer', [
                'limit' => 11,
                'null' => false,
            ])
            ->addColumn('platform_admin_id', 'integer', [
                'limit' => 11,
                'null' => false,
            ])
            ->addColumn('approval_type', 'string', [
                'limit' => 32,
                'null' => false,
            ])
            ->addColumn('approved_at', 'datetime', [
                'default' => null,
                'null' => true,
            ])
            ->addColumn('created', 'datetime', [
                'null' => false,
            ])
            ->addPrimaryKey(['id'])
            ->addForeignKey('tenant_operation_job_id', 'tenant_operation_jobs', 'id', [
                'constraint' => 'fk_tenant_operation_approvals_job',
                'delete' => 'CASCADE',
            ])
            ->addForeignKey('platform_admin_id', 'platform_admins', 'id', [
                'constraint' => 'fk_tenant_operation_approvals_admin',
                'delete' => 'RESTRICT',
            ])
            ->addIndex(['tenant_operation_job_id', 'platform_admin_id', 'approval_type'], [
                'name' => 'idx_tenant_operation_approvals_unique',
                'unique' => true,
            ])
            ->create();
    }
}
