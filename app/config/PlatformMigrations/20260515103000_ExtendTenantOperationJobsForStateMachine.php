<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class ExtendTenantOperationJobsForStateMachine extends BaseMigration
{
    /**
     * Extend tenant operation jobs with state machine and orchestration metadata.
     */
    public function change(): void
    {
        $table = $this->table('tenant_operation_jobs');

        if (!$table->hasColumn('state')) {
            $table->addColumn('state', 'string', [
                'default' => 'queued',
                'limit' => 32,
                'null' => false,
            ]);
        }
        if (!$table->hasColumn('idempotency_scope')) {
            $table->addColumn('idempotency_scope', 'string', [
                'default' => 'tenant',
                'limit' => 32,
                'null' => false,
            ]);
        }
        if (!$table->hasColumn('idempotency_key')) {
            $table->addColumn('idempotency_key', 'string', [
                'default' => null,
                'limit' => 255,
                'null' => true,
            ]);
        }
        if (!$table->hasColumn('lease_owner')) {
            $table->addColumn('lease_owner', 'string', [
                'default' => null,
                'limit' => 255,
                'null' => true,
            ]);
        }
        if (!$table->hasColumn('lease_token')) {
            $table->addColumn('lease_token', 'string', [
                'default' => null,
                'limit' => 255,
                'null' => true,
            ]);
        }
        if (!$table->hasColumn('lease_acquired_at')) {
            $table->addColumn('lease_acquired_at', 'datetime', [
                'default' => null,
                'null' => true,
            ]);
        }
        if (!$table->hasColumn('lease_expires_at')) {
            $table->addColumn('lease_expires_at', 'datetime', [
                'default' => null,
                'null' => true,
            ]);
        }
        if (!$table->hasColumn('heartbeat_at')) {
            $table->addColumn('heartbeat_at', 'datetime', [
                'default' => null,
                'null' => true,
            ]);
        }
        if (!$table->hasColumn('progress_percent')) {
            $table->addColumn('progress_percent', 'integer', [
                'default' => null,
                'limit' => 11,
                'null' => true,
            ]);
        }
        if (!$table->hasColumn('status_message')) {
            $table->addColumn('status_message', 'string', [
                'default' => null,
                'limit' => 1024,
                'null' => true,
            ]);
        }
        if (!$table->hasColumn('progress_json')) {
            $table->addColumn('progress_json', 'json', [
                'default' => null,
                'null' => true,
            ]);
        }
        if (!$table->hasColumn('result_json')) {
            $table->addColumn('result_json', 'json', [
                'default' => null,
                'null' => true,
            ]);
        }
        if (!$table->hasColumn('error_json')) {
            $table->addColumn('error_json', 'json', [
                'default' => null,
                'null' => true,
            ]);
        }
        if (!$table->hasColumn('operation_correlation_id')) {
            $table->addColumn('operation_correlation_id', 'string', [
                'default' => null,
                'limit' => 128,
                'null' => true,
            ]);
        }
        if (!$table->hasColumn('operation_image')) {
            $table->addColumn('operation_image', 'string', [
                'default' => null,
                'limit' => 255,
                'null' => true,
            ]);
        }
        if (!$table->hasColumn('operation_version')) {
            $table->addColumn('operation_version', 'string', [
                'default' => null,
                'limit' => 64,
                'null' => true,
            ]);
        }

        $table->update();

        $connection = $this->getAdapter()->getConnection();
        $connection->execute('UPDATE tenant_operation_jobs SET state = status WHERE state IS NULL OR state = \'\'');

        $table = $this->table('tenant_operation_jobs');
        if (!$table->hasIndex(['operation', 'tenant_id', 'idempotency_scope', 'idempotency_key'])) {
            $table->addIndex(['operation', 'tenant_id', 'idempotency_scope', 'idempotency_key'], [
                'name' => 'idx_tenant_operation_jobs_idempotency',
                'unique' => true,
            ]);
        }
        if (!$table->hasIndex(['state', 'created'])) {
            $table->addIndex(['state', 'created'], [
                'name' => 'idx_tenant_operation_jobs_state',
            ]);
        }
        if (!$table->hasIndex(['lease_expires_at'])) {
            $table->addIndex(['lease_expires_at'], [
                'name' => 'idx_tenant_operation_jobs_lease_expiry',
            ]);
        }
        if (!$table->hasIndex(['operation_correlation_id'])) {
            $table->addIndex(['operation_correlation_id'], [
                'name' => 'idx_tenant_operation_jobs_correlation',
            ]);
        }

        $table->update();
    }
}
