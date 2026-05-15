<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class AddParentJobLinkToTenantOperationJobs extends BaseMigration
{
    /**
     * Add explicit parent-child linkage for tenant operation jobs.
     */
    public function change(): void
    {
        $table = $this->table('tenant_operation_jobs');

        if (!$table->hasColumn('parent_tenant_operation_job_id')) {
            $table->addColumn('parent_tenant_operation_job_id', 'integer', [
                'default' => null,
                'limit' => 11,
                'null' => true,
                'after' => 'platform_admin_id',
            ]);
        }

        if (!$table->hasIndex(['parent_tenant_operation_job_id'])) {
            $table->addIndex(['parent_tenant_operation_job_id'], [
                'name' => 'idx_tenant_operation_jobs_parent',
            ]);
        }

        if (!$table->hasForeignKey('parent_tenant_operation_job_id')) {
            $table->addForeignKey(
                'parent_tenant_operation_job_id',
                'tenant_operation_jobs',
                'id',
                [
                    'constraint' => 'fk_tenant_operation_jobs_parent',
                    'delete' => 'SET_NULL',
                    'update' => 'NO_ACTION',
                ],
            );
        }

        $table->update();

        $connection = $this->getAdapter()->getConnection();
        $rows = $connection->execute(
            'SELECT id, input FROM tenant_operation_jobs WHERE parent_tenant_operation_job_id IS NULL AND input IS NOT NULL'
        )->fetchAll('assoc');

        foreach ($rows as $row) {
            $input = $row['input'] ?? null;
            if (!is_string($input) || $input === '') {
                continue;
            }
            $decoded = json_decode($input, true);
            if (!is_array($decoded)) {
                continue;
            }
            $parentId = isset($decoded['parent_operation_id']) ? (int)$decoded['parent_operation_id'] : 0;
            if ($parentId < 1) {
                continue;
            }
            $connection->execute(
                'UPDATE tenant_operation_jobs SET parent_tenant_operation_job_id = :parentId WHERE id = :id',
                [
                    'parentId' => $parentId,
                    'id' => (int)$row['id'],
                ],
            );
        }
    }
}
