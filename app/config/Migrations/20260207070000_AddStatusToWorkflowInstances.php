<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Add status column to workflow_instances for quick filtering of
 * active vs completed vs cancelled instances.
 */
class AddStatusToWorkflowInstances extends BaseMigration
{
    public function change(): void
    {
        $table = $this->table('workflow_instances');
        $table->addColumn('status', 'string', [
            'limit' => 50,
            'default' => 'active',
            'null' => false,
            'after' => 'completed_at',
        ]);
        $table->addIndex(['status'], [
            'name' => 'idx_workflow_instances_status',
        ]);
        $table->update();

        // Backfill: mark existing instances with completed_at as 'completed'
        $this->execute("UPDATE workflow_instances SET status = 'completed' WHERE completed_at IS NOT NULL");
        $this->execute("UPDATE workflow_instances SET status = 'active' WHERE completed_at IS NULL");
    }
}
