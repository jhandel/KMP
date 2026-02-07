<?php

declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Create all 8 workflow engine tables.
 *
 * Tables: workflow_definitions, workflow_states, workflow_transitions,
 * workflow_instances, workflow_transition_logs, workflow_visibility_rules,
 * workflow_approval_gates, workflow_approvals.
 */
class CreateWorkflowEngineTables extends BaseMigration
{
    public bool $autoId = false;

    public function change(): void
    {
        // 1. workflow_definitions
        $table = $this->table('workflow_definitions');
        $table->addColumn('id', 'integer', [
            'autoIncrement' => true,
            'default' => null,
            'limit' => 11,
            'null' => false,
        ]);
        $table->addColumn('name', 'string', [
            'limit' => 255,
            'null' => false,
        ]);
        $table->addColumn('slug', 'string', [
            'limit' => 255,
            'null' => false,
        ]);
        $table->addColumn('description', 'text', [
            'default' => null,
            'null' => true,
        ]);
        $table->addColumn('entity_type', 'string', [
            'limit' => 255,
            'null' => false,
        ]);
        $table->addColumn('plugin_name', 'string', [
            'limit' => 255,
            'default' => null,
            'null' => true,
        ]);
        $table->addColumn('version', 'integer', [
            'default' => 1,
            'null' => false,
        ]);
        $table->addColumn('is_active', 'boolean', [
            'default' => true,
            'null' => false,
        ]);
        $table->addColumn('is_default', 'boolean', [
            'default' => false,
            'null' => false,
        ]);
        $table->addColumn('created', 'datetime', [
            'default' => null,
            'null' => false,
        ]);
        $table->addColumn('modified', 'datetime', [
            'default' => null,
            'null' => true,
        ]);
        $table->addColumn('created_by', 'integer', [
            'default' => null,
            'null' => true,
        ]);
        $table->addColumn('modified_by', 'integer', [
            'default' => null,
            'null' => true,
        ]);
        $table->addPrimaryKey(['id']);
        $table->addIndex(['slug', 'version'], [
            'unique' => true,
            'name' => 'idx_workflow_definitions_slug_version',
        ]);
        $table->addIndex(['entity_type'], [
            'name' => 'idx_workflow_definitions_entity_type',
        ]);
        $table->create();

        // 2. workflow_states
        $table = $this->table('workflow_states');
        $table->addColumn('id', 'integer', [
            'autoIncrement' => true,
            'default' => null,
            'limit' => 11,
            'null' => false,
        ]);
        $table->addColumn('workflow_definition_id', 'integer', [
            'null' => false,
        ]);
        $table->addColumn('name', 'string', [
            'limit' => 255,
            'null' => false,
        ]);
        $table->addColumn('slug', 'string', [
            'limit' => 255,
            'null' => false,
        ]);
        $table->addColumn('label', 'string', [
            'limit' => 255,
            'null' => false,
        ]);
        $table->addColumn('description', 'text', [
            'default' => null,
            'null' => true,
        ]);
        $table->addColumn('state_type', 'string', [
            'limit' => 50,
            'default' => 'intermediate',
            'null' => false,
        ]);
        $table->addColumn('status_category', 'string', [
            'limit' => 255,
            'default' => null,
            'null' => true,
        ]);
        $table->addColumn('metadata', 'text', [
            'default' => null,
            'null' => true,
        ]);
        $table->addColumn('position_x', 'integer', [
            'default' => 0,
            'null' => true,
        ]);
        $table->addColumn('position_y', 'integer', [
            'default' => 0,
            'null' => true,
        ]);
        $table->addColumn('on_enter_actions', 'text', [
            'default' => null,
            'null' => true,
        ]);
        $table->addColumn('on_exit_actions', 'text', [
            'default' => null,
            'null' => true,
        ]);
        $table->addColumn('created', 'datetime', [
            'default' => null,
            'null' => false,
        ]);
        $table->addColumn('modified', 'datetime', [
            'default' => null,
            'null' => true,
        ]);
        $table->addColumn('created_by', 'integer', [
            'default' => null,
            'null' => true,
        ]);
        $table->addColumn('modified_by', 'integer', [
            'default' => null,
            'null' => true,
        ]);
        $table->addPrimaryKey(['id']);
        $table->addIndex(['workflow_definition_id', 'slug'], [
            'unique' => true,
            'name' => 'idx_workflow_states_def_slug',
        ]);
        $table->addIndex(['state_type'], [
            'name' => 'idx_workflow_states_state_type',
        ]);
        $table->addIndex(['status_category'], [
            'name' => 'idx_workflow_states_status_category',
        ]);
        $table->create();

        // 3. workflow_transitions
        $table = $this->table('workflow_transitions');
        $table->addColumn('id', 'integer', [
            'autoIncrement' => true,
            'default' => null,
            'limit' => 11,
            'null' => false,
        ]);
        $table->addColumn('workflow_definition_id', 'integer', [
            'null' => false,
        ]);
        $table->addColumn('from_state_id', 'integer', [
            'null' => false,
        ]);
        $table->addColumn('to_state_id', 'integer', [
            'null' => false,
        ]);
        $table->addColumn('name', 'string', [
            'limit' => 255,
            'null' => false,
        ]);
        $table->addColumn('slug', 'string', [
            'limit' => 255,
            'null' => false,
        ]);
        $table->addColumn('label', 'string', [
            'limit' => 255,
            'null' => false,
        ]);
        $table->addColumn('description', 'text', [
            'default' => null,
            'null' => true,
        ]);
        $table->addColumn('priority', 'integer', [
            'default' => 0,
            'null' => false,
        ]);
        $table->addColumn('conditions', 'text', [
            'default' => null,
            'null' => true,
        ]);
        $table->addColumn('actions', 'text', [
            'default' => null,
            'null' => true,
        ]);
        $table->addColumn('is_automatic', 'boolean', [
            'default' => false,
            'null' => false,
        ]);
        $table->addColumn('trigger_type', 'string', [
            'limit' => 50,
            'default' => 'manual',
            'null' => false,
        ]);
        $table->addColumn('trigger_config', 'text', [
            'default' => null,
            'null' => true,
        ]);
        $table->addColumn('created', 'datetime', [
            'default' => null,
            'null' => false,
        ]);
        $table->addColumn('modified', 'datetime', [
            'default' => null,
            'null' => true,
        ]);
        $table->addColumn('created_by', 'integer', [
            'default' => null,
            'null' => true,
        ]);
        $table->addColumn('modified_by', 'integer', [
            'default' => null,
            'null' => true,
        ]);
        $table->addPrimaryKey(['id']);
        $table->addIndex(['workflow_definition_id'], [
            'name' => 'idx_workflow_transitions_def',
        ]);
        $table->addIndex(['from_state_id'], [
            'name' => 'idx_workflow_transitions_from',
        ]);
        $table->addIndex(['to_state_id'], [
            'name' => 'idx_workflow_transitions_to',
        ]);
        $table->create();

        // 4. workflow_instances
        $table = $this->table('workflow_instances');
        $table->addColumn('id', 'integer', [
            'autoIncrement' => true,
            'default' => null,
            'limit' => 11,
            'null' => false,
        ]);
        $table->addColumn('workflow_definition_id', 'integer', [
            'null' => false,
        ]);
        $table->addColumn('entity_type', 'string', [
            'limit' => 255,
            'null' => false,
        ]);
        $table->addColumn('entity_id', 'integer', [
            'null' => false,
        ]);
        $table->addColumn('current_state_id', 'integer', [
            'null' => false,
        ]);
        $table->addColumn('previous_state_id', 'integer', [
            'default' => null,
            'null' => true,
        ]);
        $table->addColumn('context', 'text', [
            'default' => null,
            'null' => true,
        ]);
        $table->addColumn('started_at', 'datetime', [
            'null' => false,
        ]);
        $table->addColumn('completed_at', 'datetime', [
            'default' => null,
            'null' => true,
        ]);
        $table->addColumn('created', 'datetime', [
            'default' => null,
            'null' => false,
        ]);
        $table->addColumn('modified', 'datetime', [
            'default' => null,
            'null' => true,
        ]);
        $table->addColumn('created_by', 'integer', [
            'default' => null,
            'null' => true,
        ]);
        $table->addColumn('modified_by', 'integer', [
            'default' => null,
            'null' => true,
        ]);
        $table->addPrimaryKey(['id']);
        $table->addIndex(['entity_type', 'entity_id'], [
            'name' => 'idx_workflow_instances_entity',
        ]);
        $table->addIndex(['workflow_definition_id'], [
            'name' => 'idx_workflow_instances_def',
        ]);
        $table->addIndex(['current_state_id'], [
            'name' => 'idx_workflow_instances_current_state',
        ]);
        $table->create();

        // 5. workflow_transition_logs (append-only audit log)
        $table = $this->table('workflow_transition_logs');
        $table->addColumn('id', 'integer', [
            'autoIncrement' => true,
            'default' => null,
            'limit' => 11,
            'null' => false,
        ]);
        $table->addColumn('workflow_instance_id', 'integer', [
            'null' => false,
        ]);
        $table->addColumn('from_state_id', 'integer', [
            'default' => null,
            'null' => true,
        ]);
        $table->addColumn('to_state_id', 'integer', [
            'null' => false,
        ]);
        $table->addColumn('transition_id', 'integer', [
            'default' => null,
            'null' => true,
        ]);
        $table->addColumn('triggered_by', 'integer', [
            'default' => null,
            'null' => true,
        ]);
        $table->addColumn('trigger_type', 'string', [
            'limit' => 50,
            'default' => 'manual',
            'null' => false,
        ]);
        $table->addColumn('context_snapshot', 'text', [
            'default' => null,
            'null' => true,
        ]);
        $table->addColumn('notes', 'text', [
            'default' => null,
            'null' => true,
        ]);
        $table->addColumn('created', 'datetime', [
            'null' => false,
        ]);
        $table->addPrimaryKey(['id']);
        $table->addIndex(['workflow_instance_id'], [
            'name' => 'idx_workflow_transition_logs_instance',
        ]);
        $table->addIndex(['created'], [
            'name' => 'idx_workflow_transition_logs_created',
        ]);
        $table->create();

        // 6. workflow_visibility_rules
        $table = $this->table('workflow_visibility_rules');
        $table->addColumn('id', 'integer', [
            'autoIncrement' => true,
            'default' => null,
            'limit' => 11,
            'null' => false,
        ]);
        $table->addColumn('workflow_state_id', 'integer', [
            'null' => false,
        ]);
        $table->addColumn('rule_type', 'string', [
            'limit' => 50,
            'null' => false,
        ]);
        $table->addColumn('target', 'string', [
            'limit' => 255,
            'default' => '*',
            'null' => false,
        ]);
        $table->addColumn('condition', 'text', [
            'default' => null,
            'null' => true,
        ]);
        $table->addColumn('priority', 'integer', [
            'default' => 0,
            'null' => false,
        ]);
        $table->addColumn('created', 'datetime', [
            'default' => null,
            'null' => false,
        ]);
        $table->addColumn('modified', 'datetime', [
            'default' => null,
            'null' => true,
        ]);
        $table->addColumn('created_by', 'integer', [
            'default' => null,
            'null' => true,
        ]);
        $table->addColumn('modified_by', 'integer', [
            'default' => null,
            'null' => true,
        ]);
        $table->addPrimaryKey(['id']);
        $table->addIndex(['workflow_state_id'], [
            'name' => 'idx_workflow_visibility_rules_state',
        ]);
        $table->addIndex(['rule_type'], [
            'name' => 'idx_workflow_visibility_rules_type',
        ]);
        $table->create();

        // 7. workflow_approval_gates
        $table = $this->table('workflow_approval_gates');
        $table->addColumn('id', 'integer', [
            'autoIncrement' => true,
            'default' => null,
            'limit' => 11,
            'null' => false,
        ]);
        $table->addColumn('workflow_state_id', 'integer', [
            'null' => false,
        ]);
        $table->addColumn('approval_type', 'string', [
            'limit' => 50,
            'default' => 'threshold',
            'null' => false,
        ]);
        $table->addColumn('required_count', 'integer', [
            'default' => 1,
            'null' => false,
        ]);
        $table->addColumn('threshold_config', 'text', [
            'default' => null,
            'null' => true,
        ]);
        $table->addColumn('approver_rule', 'text', [
            'default' => null,
            'null' => true,
        ]);
        $table->addColumn('timeout_hours', 'integer', [
            'default' => null,
            'null' => true,
        ]);
        $table->addColumn('timeout_transition_id', 'integer', [
            'default' => null,
            'null' => true,
        ]);
        $table->addColumn('on_satisfied_transition_id', 'integer', [
            'default' => null,
            'null' => true,
        ]);
        $table->addColumn('on_denied_transition_id', 'integer', [
            'default' => null,
            'null' => true,
        ]);
        $table->addColumn('allow_delegation', 'boolean', [
            'default' => false,
            'null' => false,
        ]);
        $table->addColumn('created', 'datetime', [
            'default' => null,
            'null' => false,
        ]);
        $table->addColumn('modified', 'datetime', [
            'default' => null,
            'null' => true,
        ]);
        $table->addColumn('created_by', 'integer', [
            'default' => null,
            'null' => true,
        ]);
        $table->addColumn('modified_by', 'integer', [
            'default' => null,
            'null' => true,
        ]);
        $table->addPrimaryKey(['id']);
        $table->addIndex(['workflow_state_id'], [
            'name' => 'idx_workflow_approval_gates_state',
        ]);
        $table->create();

        // 8. workflow_approvals
        $table = $this->table('workflow_approvals');
        $table->addColumn('id', 'integer', [
            'autoIncrement' => true,
            'default' => null,
            'limit' => 11,
            'null' => false,
        ]);
        $table->addColumn('workflow_instance_id', 'integer', [
            'null' => false,
        ]);
        $table->addColumn('approval_gate_id', 'integer', [
            'null' => false,
        ]);
        $table->addColumn('approver_id', 'integer', [
            'default' => null,
            'null' => true,
        ]);
        $table->addColumn('decision', 'string', [
            'limit' => 50,
            'default' => null,
            'null' => true,
        ]);
        $table->addColumn('notes', 'text', [
            'default' => null,
            'null' => true,
        ]);
        $table->addColumn('approval_order', 'integer', [
            'default' => null,
            'null' => true,
        ]);
        $table->addColumn('delegated_from_id', 'integer', [
            'default' => null,
            'null' => true,
        ]);
        $table->addColumn('token', 'string', [
            'limit' => 255,
            'default' => null,
            'null' => true,
        ]);
        $table->addColumn('requested_at', 'datetime', [
            'default' => null,
            'null' => true,
        ]);
        $table->addColumn('responded_at', 'datetime', [
            'default' => null,
            'null' => true,
        ]);
        $table->addColumn('created', 'datetime', [
            'default' => null,
            'null' => false,
        ]);
        $table->addColumn('modified', 'datetime', [
            'default' => null,
            'null' => true,
        ]);
        $table->addColumn('created_by', 'integer', [
            'default' => null,
            'null' => true,
        ]);
        $table->addColumn('modified_by', 'integer', [
            'default' => null,
            'null' => true,
        ]);
        $table->addPrimaryKey(['id']);
        $table->addIndex(['workflow_instance_id', 'approval_gate_id', 'approver_id'], [
            'unique' => true,
            'name' => 'idx_workflow_approvals_unique',
        ]);
        $table->addIndex(['workflow_instance_id'], [
            'name' => 'idx_workflow_approvals_instance',
        ]);
        $table->addIndex(['approval_gate_id'], [
            'name' => 'idx_workflow_approvals_gate',
        ]);
        $table->addIndex(['token'], [
            'unique' => true,
            'name' => 'idx_workflow_approvals_token',
        ]);
        $table->create();

        // ---- Foreign Keys (added after all tables exist) ----

        // workflow_states → workflow_definitions
        $this->table('workflow_states')
            ->addForeignKey('workflow_definition_id', 'workflow_definitions', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_workflow_states_definition',
            ])
            ->update();

        // workflow_transitions → workflow_definitions, workflow_states
        $this->table('workflow_transitions')
            ->addForeignKey('workflow_definition_id', 'workflow_definitions', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_workflow_transitions_definition',
            ])
            ->addForeignKey('from_state_id', 'workflow_states', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_workflow_transitions_from_state',
            ])
            ->addForeignKey('to_state_id', 'workflow_states', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_workflow_transitions_to_state',
            ])
            ->update();

        // workflow_instances → workflow_definitions, workflow_states
        $this->table('workflow_instances')
            ->addForeignKey('workflow_definition_id', 'workflow_definitions', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_workflow_instances_definition',
            ])
            ->addForeignKey('current_state_id', 'workflow_states', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_workflow_instances_current_state',
            ])
            ->update();

        // workflow_transition_logs → workflow_instances
        $this->table('workflow_transition_logs')
            ->addForeignKey('workflow_instance_id', 'workflow_instances', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_workflow_transition_logs_instance',
            ])
            ->update();

        // workflow_visibility_rules → workflow_states
        $this->table('workflow_visibility_rules')
            ->addForeignKey('workflow_state_id', 'workflow_states', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_workflow_visibility_rules_state',
            ])
            ->update();

        // workflow_approval_gates → workflow_states
        $this->table('workflow_approval_gates')
            ->addForeignKey('workflow_state_id', 'workflow_states', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_workflow_approval_gates_state',
            ])
            ->update();

        // workflow_approvals → workflow_instances, workflow_approval_gates
        $this->table('workflow_approvals')
            ->addForeignKey('workflow_instance_id', 'workflow_instances', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_workflow_approvals_instance',
            ])
            ->addForeignKey('approval_gate_id', 'workflow_approval_gates', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_workflow_approvals_gate',
            ])
            ->update();
    }
}
