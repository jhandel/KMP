<?php

declare(strict_types=1);

use Migrations\AbstractMigration;

/**
 * Adds denormalized current_approver_id FK to workflow_approvals
 * for sortable/filterable "Assigned To" column in the approvals grid.
 */
class AddCurrentApproverIdToWorkflowApprovals extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('workflow_approvals');
        $table->addColumn('current_approver_id', 'integer', [
            'null' => true,
            'default' => null,
            'after' => 'approver_config',
        ]);
        $table->addIndex(['current_approver_id'], ['name' => 'idx_wa_current_approver']);
        $table->update();

        // Backfill from JSON approver_config for MySQL
        $this->execute("
            UPDATE workflow_approvals
            SET current_approver_id = JSON_UNQUOTE(JSON_EXTRACT(approver_config, '$.current_approver_id'))
            WHERE approver_config IS NOT NULL
              AND JSON_EXTRACT(approver_config, '$.current_approver_id') IS NOT NULL
              AND status = 'pending'
        ");
    }

    public function down(): void
    {
        $table = $this->table('workflow_approvals');
        $table->removeColumn('current_approver_id');
        $table->update();
    }
}
