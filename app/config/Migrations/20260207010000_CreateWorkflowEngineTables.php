<?php

use Migrations\BaseMigration;

class CreateWorkflowEngineTables extends BaseMigration
{
    public bool $autoId = false;

    public function change(): void
    {
        // 1. workflow_definitions
        $this->table("workflow_definitions", ["id" => false, "primary_key" => ["id"]])
            ->addColumn("id", "integer", [
                "autoIncrement" => true,
                "default" => null,
                "limit" => 11,
                "null" => false,
            ])
            ->addColumn("name", "string", [
                "default" => null,
                "limit" => 255,
                "null" => false,
            ])
            ->addColumn("slug", "string", [
                "default" => null,
                "limit" => 255,
                "null" => false,
            ])
            ->addColumn("description", "text", [
                "default" => null,
                "null" => true,
            ])
            ->addColumn("entity_type", "string", [
                "default" => null,
                "limit" => 255,
                "null" => false,
            ])
            ->addColumn("plugin_name", "string", [
                "default" => null,
                "limit" => 255,
                "null" => true,
            ])
            ->addColumn("version", "integer", [
                "default" => 1,
                "limit" => 11,
                "null" => false,
            ])
            ->addColumn("is_active", "boolean", [
                "default" => true,
                "null" => false,
            ])
            ->addColumn("is_default", "boolean", [
                "default" => false,
                "null" => false,
            ])
            ->addColumn("created", "datetime", [
                "default" => null,
                "null" => true,
            ])
            ->addColumn("modified", "datetime", [
                "default" => null,
                "null" => true,
            ])
            ->addColumn("created_by", "integer", [
                "default" => null,
                "limit" => 11,
                "null" => true,
            ])
            ->addColumn("modified_by", "integer", [
                "default" => null,
                "limit" => 11,
                "null" => true,
            ])
            ->addIndex(["slug", "version"], ["unique" => true])
            ->addIndex(["entity_type"])
            ->create();

        // 2. workflow_states
        $this->table("workflow_states", ["id" => false, "primary_key" => ["id"]])
            ->addColumn("id", "integer", [
                "autoIncrement" => true,
                "default" => null,
                "limit" => 11,
                "null" => false,
            ])
            ->addColumn("workflow_definition_id", "integer", [
                "default" => null,
                "limit" => 11,
                "null" => false,
            ])
            ->addColumn("name", "string", [
                "default" => null,
                "limit" => 255,
                "null" => false,
            ])
            ->addColumn("slug", "string", [
                "default" => null,
                "limit" => 255,
                "null" => false,
            ])
            ->addColumn("label", "string", [
                "default" => null,
                "limit" => 255,
                "null" => false,
            ])
            ->addColumn("description", "text", [
                "default" => null,
                "null" => true,
            ])
            ->addColumn("state_type", "string", [
                "default" => "intermediate",
                "limit" => 50,
                "null" => false,
            ])
            ->addColumn("status_category", "string", [
                "default" => null,
                "limit" => 255,
                "null" => true,
            ])
            ->addColumn("metadata", "text", [
                "default" => null,
                "null" => true,
            ])
            ->addColumn("position_x", "integer", [
                "default" => 0,
                "limit" => 11,
                "null" => true,
            ])
            ->addColumn("position_y", "integer", [
                "default" => 0,
                "limit" => 11,
                "null" => true,
            ])
            ->addColumn("on_enter_actions", "text", [
                "default" => null,
                "null" => true,
            ])
            ->addColumn("on_exit_actions", "text", [
                "default" => null,
                "null" => true,
            ])
            ->addColumn("created", "datetime", [
                "default" => null,
                "null" => true,
            ])
            ->addColumn("modified", "datetime", [
                "default" => null,
                "null" => true,
            ])
            ->addColumn("created_by", "integer", [
                "default" => null,
                "limit" => 11,
                "null" => true,
            ])
            ->addColumn("modified_by", "integer", [
                "default" => null,
                "limit" => 11,
                "null" => true,
            ])
            ->addIndex(["workflow_definition_id", "slug"], ["unique" => true])
            ->addIndex(["state_type"])
            ->addIndex(["status_category"])
            ->create();

        // 3. workflow_transitions
        $this->table("workflow_transitions", ["id" => false, "primary_key" => ["id"]])
            ->addColumn("id", "integer", [
                "autoIncrement" => true,
                "default" => null,
                "limit" => 11,
                "null" => false,
            ])
            ->addColumn("workflow_definition_id", "integer", [
                "default" => null,
                "limit" => 11,
                "null" => false,
            ])
            ->addColumn("from_state_id", "integer", [
                "default" => null,
                "limit" => 11,
                "null" => false,
            ])
            ->addColumn("to_state_id", "integer", [
                "default" => null,
                "limit" => 11,
                "null" => false,
            ])
            ->addColumn("name", "string", [
                "default" => null,
                "limit" => 255,
                "null" => false,
            ])
            ->addColumn("slug", "string", [
                "default" => null,
                "limit" => 255,
                "null" => false,
            ])
            ->addColumn("label", "string", [
                "default" => null,
                "limit" => 255,
                "null" => false,
            ])
            ->addColumn("description", "text", [
                "default" => null,
                "null" => true,
            ])
            ->addColumn("priority", "integer", [
                "default" => 0,
                "limit" => 11,
                "null" => false,
            ])
            ->addColumn("conditions", "text", [
                "default" => null,
                "null" => true,
            ])
            ->addColumn("actions", "text", [
                "default" => null,
                "null" => true,
            ])
            ->addColumn("is_automatic", "boolean", [
                "default" => false,
                "null" => false,
            ])
            ->addColumn("trigger_type", "string", [
                "default" => "manual",
                "limit" => 50,
                "null" => false,
            ])
            ->addColumn("trigger_config", "text", [
                "default" => null,
                "null" => true,
            ])
            ->addColumn("created", "datetime", [
                "default" => null,
                "null" => true,
            ])
            ->addColumn("modified", "datetime", [
                "default" => null,
                "null" => true,
            ])
            ->addColumn("created_by", "integer", [
                "default" => null,
                "limit" => 11,
                "null" => true,
            ])
            ->addColumn("modified_by", "integer", [
                "default" => null,
                "limit" => 11,
                "null" => true,
            ])
            ->addIndex(["workflow_definition_id", "slug"], ["unique" => true])
            ->addIndex(["from_state_id"])
            ->addIndex(["to_state_id"])
            ->addIndex(["trigger_type"])
            ->create();

        // 4. workflow_instances
        $this->table("workflow_instances", ["id" => false, "primary_key" => ["id"]])
            ->addColumn("id", "integer", [
                "autoIncrement" => true,
                "default" => null,
                "limit" => 11,
                "null" => false,
            ])
            ->addColumn("workflow_definition_id", "integer", [
                "default" => null,
                "limit" => 11,
                "null" => false,
            ])
            ->addColumn("entity_type", "string", [
                "default" => null,
                "limit" => 255,
                "null" => false,
            ])
            ->addColumn("entity_id", "integer", [
                "default" => null,
                "limit" => 11,
                "null" => false,
            ])
            ->addColumn("current_state_id", "integer", [
                "default" => null,
                "limit" => 11,
                "null" => false,
            ])
            ->addColumn("previous_state_id", "integer", [
                "default" => null,
                "limit" => 11,
                "null" => true,
            ])
            ->addColumn("context", "text", [
                "default" => null,
                "null" => true,
            ])
            ->addColumn("started_at", "datetime", [
                "default" => null,
                "null" => true,
            ])
            ->addColumn("completed_at", "datetime", [
                "default" => null,
                "null" => true,
            ])
            ->addColumn("created_by", "integer", [
                "default" => null,
                "limit" => 11,
                "null" => true,
            ])
            ->addColumn("created", "datetime", [
                "default" => null,
                "null" => true,
            ])
            ->addColumn("modified", "datetime", [
                "default" => null,
                "null" => true,
            ])
            ->addColumn("modified_by", "integer", [
                "default" => null,
                "limit" => 11,
                "null" => true,
            ])
            ->addIndex(["entity_type", "entity_id"], ["unique" => true])
            ->addIndex(["workflow_definition_id"])
            ->addIndex(["current_state_id"])
            ->create();

        // 5. workflow_transition_logs
        $this->table("workflow_transition_logs", ["id" => false, "primary_key" => ["id"]])
            ->addColumn("id", "integer", [
                "autoIncrement" => true,
                "default" => null,
                "limit" => 11,
                "null" => false,
            ])
            ->addColumn("workflow_instance_id", "integer", [
                "default" => null,
                "limit" => 11,
                "null" => false,
            ])
            ->addColumn("from_state_id", "integer", [
                "default" => null,
                "limit" => 11,
                "null" => true,
            ])
            ->addColumn("to_state_id", "integer", [
                "default" => null,
                "limit" => 11,
                "null" => false,
            ])
            ->addColumn("transition_id", "integer", [
                "default" => null,
                "limit" => 11,
                "null" => true,
            ])
            ->addColumn("triggered_by", "integer", [
                "default" => null,
                "limit" => 11,
                "null" => true,
            ])
            ->addColumn("trigger_type", "string", [
                "default" => "manual",
                "limit" => 50,
                "null" => false,
            ])
            ->addColumn("context_snapshot", "text", [
                "default" => null,
                "null" => true,
            ])
            ->addColumn("notes", "text", [
                "default" => null,
                "null" => true,
            ])
            ->addColumn("created", "datetime", [
                "default" => null,
                "null" => false,
            ])
            ->addIndex(["workflow_instance_id"])
            ->addIndex(["created"])
            ->create();

        // 6. workflow_visibility_rules
        $this->table("workflow_visibility_rules", ["id" => false, "primary_key" => ["id"]])
            ->addColumn("id", "integer", [
                "autoIncrement" => true,
                "default" => null,
                "limit" => 11,
                "null" => false,
            ])
            ->addColumn("workflow_state_id", "integer", [
                "default" => null,
                "limit" => 11,
                "null" => false,
            ])
            ->addColumn("rule_type", "string", [
                "default" => null,
                "limit" => 50,
                "null" => false,
            ])
            ->addColumn("target", "string", [
                "default" => "*",
                "limit" => 255,
                "null" => false,
            ])
            ->addColumn("condition", "text", [
                "default" => null,
                "null" => true,
            ])
            ->addColumn("priority", "integer", [
                "default" => 0,
                "limit" => 11,
                "null" => false,
            ])
            ->addColumn("created", "datetime", [
                "default" => null,
                "null" => true,
            ])
            ->addColumn("modified", "datetime", [
                "default" => null,
                "null" => true,
            ])
            ->addColumn("created_by", "integer", [
                "default" => null,
                "limit" => 11,
                "null" => true,
            ])
            ->addColumn("modified_by", "integer", [
                "default" => null,
                "limit" => 11,
                "null" => true,
            ])
            ->addIndex(["workflow_state_id"])
            ->addIndex(["rule_type"])
            ->create();

        // 7. workflow_approval_gates
        $this->table("workflow_approval_gates", ["id" => false, "primary_key" => ["id"]])
            ->addColumn("id", "integer", [
                "autoIncrement" => true,
                "default" => null,
                "limit" => 11,
                "null" => false,
            ])
            ->addColumn("workflow_state_id", "integer", [
                "default" => null,
                "limit" => 11,
                "null" => false,
            ])
            ->addColumn("approval_type", "string", [
                "default" => "threshold",
                "limit" => 50,
                "null" => false,
            ])
            ->addColumn("required_count", "integer", [
                "default" => 1,
                "limit" => 11,
                "null" => false,
            ])
            ->addColumn("approver_rule", "text", [
                "default" => null,
                "null" => true,
            ])
            ->addColumn("timeout_hours", "integer", [
                "default" => null,
                "limit" => 11,
                "null" => true,
            ])
            ->addColumn("timeout_transition_id", "integer", [
                "default" => null,
                "limit" => 11,
                "null" => true,
            ])
            ->addColumn("allow_delegation", "boolean", [
                "default" => false,
                "null" => false,
            ])
            ->addColumn("created", "datetime", [
                "default" => null,
                "null" => true,
            ])
            ->addColumn("modified", "datetime", [
                "default" => null,
                "null" => true,
            ])
            ->addColumn("created_by", "integer", [
                "default" => null,
                "limit" => 11,
                "null" => true,
            ])
            ->addColumn("modified_by", "integer", [
                "default" => null,
                "limit" => 11,
                "null" => true,
            ])
            ->addIndex(["workflow_state_id"])
            ->create();

        // 8. workflow_approvals
        $this->table("workflow_approvals", ["id" => false, "primary_key" => ["id"]])
            ->addColumn("id", "integer", [
                "autoIncrement" => true,
                "default" => null,
                "limit" => 11,
                "null" => false,
            ])
            ->addColumn("workflow_instance_id", "integer", [
                "default" => null,
                "limit" => 11,
                "null" => false,
            ])
            ->addColumn("approval_gate_id", "integer", [
                "default" => null,
                "limit" => 11,
                "null" => false,
            ])
            ->addColumn("approver_id", "integer", [
                "default" => null,
                "limit" => 11,
                "null" => true,
            ])
            ->addColumn("decision", "string", [
                "default" => null,
                "limit" => 50,
                "null" => true,
            ])
            ->addColumn("notes", "text", [
                "default" => null,
                "null" => true,
            ])
            ->addColumn("token", "string", [
                "default" => null,
                "limit" => 255,
                "null" => true,
            ])
            ->addColumn("requested_at", "datetime", [
                "default" => null,
                "null" => true,
            ])
            ->addColumn("responded_at", "datetime", [
                "default" => null,
                "null" => true,
            ])
            ->addColumn("created", "datetime", [
                "default" => null,
                "null" => true,
            ])
            ->addColumn("modified", "datetime", [
                "default" => null,
                "null" => true,
            ])
            ->addColumn("created_by", "integer", [
                "default" => null,
                "limit" => 11,
                "null" => true,
            ])
            ->addColumn("modified_by", "integer", [
                "default" => null,
                "limit" => 11,
                "null" => true,
            ])
            ->addIndex(["workflow_instance_id"])
            ->addIndex(["approval_gate_id"])
            ->addIndex(["token"], ["unique" => true])
            ->create();

        // Foreign keys
        $this->table("workflow_states")
            ->addForeignKey("workflow_definition_id", "workflow_definitions", "id", [
                "update" => "NO_ACTION",
                "delete" => "CASCADE",
            ])
            ->update();

        $this->table("workflow_transitions")
            ->addForeignKey("workflow_definition_id", "workflow_definitions", "id", [
                "update" => "NO_ACTION",
                "delete" => "CASCADE",
            ])
            ->addForeignKey("from_state_id", "workflow_states", "id", [
                "update" => "NO_ACTION",
                "delete" => "CASCADE",
            ])
            ->addForeignKey("to_state_id", "workflow_states", "id", [
                "update" => "NO_ACTION",
                "delete" => "CASCADE",
            ])
            ->update();

        $this->table("workflow_instances")
            ->addForeignKey("workflow_definition_id", "workflow_definitions", "id", [
                "update" => "NO_ACTION",
                "delete" => "CASCADE",
            ])
            ->addForeignKey("current_state_id", "workflow_states", "id", [
                "update" => "NO_ACTION",
                "delete" => "CASCADE",
            ])
            ->update();

        $this->table("workflow_transition_logs")
            ->addForeignKey("workflow_instance_id", "workflow_instances", "id", [
                "update" => "NO_ACTION",
                "delete" => "CASCADE",
            ])
            ->update();

        $this->table("workflow_visibility_rules")
            ->addForeignKey("workflow_state_id", "workflow_states", "id", [
                "update" => "NO_ACTION",
                "delete" => "CASCADE",
            ])
            ->update();

        $this->table("workflow_approval_gates")
            ->addForeignKey("workflow_state_id", "workflow_states", "id", [
                "update" => "NO_ACTION",
                "delete" => "CASCADE",
            ])
            ->update();

        $this->table("workflow_approvals")
            ->addForeignKey("workflow_instance_id", "workflow_instances", "id", [
                "update" => "NO_ACTION",
                "delete" => "CASCADE",
            ])
            ->addForeignKey("approval_gate_id", "workflow_approval_gates", "id", [
                "update" => "NO_ACTION",
                "delete" => "CASCADE",
            ])
            ->update();
    }
}
