<?php

declare(strict_types=1);

use Migrations\AbstractMigration;

/**
 * Enhance workflow approval gates with:
 * - threshold_config JSON for dynamic threshold resolution (fixed/app_setting/entity_field)
 * - on_satisfied_transition_id for auto-transition when gate is met
 * - on_denied_transition_id for auto-transition on denial
 * - approval_order on workflow_approvals for chain support
 * - delegated_from_id on workflow_approvals for delegation tracking
 * - unique constraint on (instance, gate, approver) for uniqueness enforcement
 */
class EnhanceApprovalGates extends AbstractMigration
{
    public function up(): void
    {
        // Add new columns to workflow_approval_gates
        $table = $this->table('workflow_approval_gates');
        if (!$table->hasColumn('threshold_config')) {
            $table->addColumn('threshold_config', 'text', [
                'default' => null,
                'null' => true,
                'after' => 'required_count',
                'comment' => 'JSON config: {"type":"fixed|app_setting|entity_field","key":"...","default":1}',
            ]);
        }
        if (!$table->hasColumn('on_satisfied_transition_id')) {
            $table->addColumn('on_satisfied_transition_id', 'integer', [
                'default' => null,
                'limit' => 11,
                'null' => true,
                'after' => 'timeout_transition_id',
            ]);
        }
        if (!$table->hasColumn('on_denied_transition_id')) {
            $table->addColumn('on_denied_transition_id', 'integer', [
                'default' => null,
                'limit' => 11,
                'null' => true,
                'after' => 'on_satisfied_transition_id',
            ]);
        }
        $table->update();

        // Add new columns to workflow_approvals
        $table2 = $this->table('workflow_approvals');
        if (!$table2->hasColumn('approval_order')) {
            $table2->addColumn('approval_order', 'integer', [
                'default' => null,
                'limit' => 11,
                'null' => true,
                'after' => 'notes',
            ]);
        }
        if (!$table2->hasColumn('delegated_from_id')) {
            $table2->addColumn('delegated_from_id', 'integer', [
                'default' => null,
                'limit' => 11,
                'null' => true,
                'after' => 'approval_order',
            ]);
        }
        if (!$table2->hasIndex(['workflow_instance_id', 'approval_gate_id', 'approver_id'])) {
            $table2->addIndex(
                ['workflow_instance_id', 'approval_gate_id', 'approver_id'],
                ['name' => 'idx_unique_approver_per_gate', 'unique' => true]
            );
        }
        $table2->update();
    }

    public function down(): void
    {
        $this->table('workflow_approvals')
            ->removeIndex(['workflow_instance_id', 'approval_gate_id', 'approver_id'])
            ->removeColumn('approval_order')
            ->removeColumn('delegated_from_id')
            ->update();

        $this->table('workflow_approval_gates')
            ->removeColumn('threshold_config')
            ->removeColumn('on_satisfied_transition_id')
            ->removeColumn('on_denied_transition_id')
            ->update();
    }
}
