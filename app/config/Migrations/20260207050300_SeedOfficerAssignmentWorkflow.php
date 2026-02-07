<?php

declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Seeds the Officer Assignment workflow definition.
 *
 * Models the officer lifecycle from nomination through approval
 * to active service and eventual release or replacement.
 */
class SeedOfficerAssignmentWorkflow extends BaseMigration
{
    public function up(): void
    {
        $this->execute(
            "INSERT INTO workflow_definitions (name, slug, description, entity_type, plugin_name, version, is_active, is_default, created, modified)
             VALUES (
                 'Officer Assignment',
                 'officer-assignment',
                 'Workflow for managing officer assignment lifecycle from nomination through approval to release',
                 'OfficerAssignments',
                 'Officers',
                 1, 1, 1, NOW(), NOW()
             )"
        );

        $rows = $this->fetchAll("SELECT id FROM workflow_definitions WHERE slug = 'officer-assignment' AND version = 1 LIMIT 1");
        $defId = (int)$rows[0]['id'];

        // [name, slug, label, state_type, status_category, metadata_json, pos_x, pos_y, on_enter_actions_json]
        $states = [
            ['Nominated', 'nominated', 'Nominated', 'initial', 'Pending', '{}', 100, 100, null],
            ['Pending Approval', 'pending-approval', 'Pending Approval', 'intermediate', 'Pending',
                '{"visible":["approvalBlock"]}', 300, 100, null],
            ['Active', 'active', 'Active', 'intermediate', 'Active',
                '{"disabled":["office","member","branch"]}', 500, 100,
                '[{"action":"request_warrant","params":{}}]'],
            ['Expired', 'expired', 'Expired', 'final', 'Closed',
                '{"disabled":["office","member","branch"]}', 700, 100, null],
            ['Released', 'released', 'Released', 'final', 'Closed',
                '{"disabled":["office","member","branch"]}', 700, 250,
                '[{"action":"release_warrant","params":{}}]'],
            ['Replaced', 'replaced', 'Replaced', 'final', 'Closed',
                '{"disabled":["office","member","branch"]}', 700, 400, null],
            ['Cancelled', 'cancelled', 'Cancelled', 'final', 'Closed', '{}', 700, 550, null],
        ];

        foreach ($states as $s) {
            $name = addslashes($s[0]);
            $slug = $s[1];
            $label = addslashes($s[2]);
            $type = $s[3];
            $cat = addslashes($s[4]);
            $meta = addslashes($s[5]);
            $px = $s[6];
            $py = $s[7];
            $enterActions = $s[8] !== null ? "'" . addslashes($s[8]) . "'" : 'NULL';

            $this->execute(
                "INSERT INTO workflow_states
                    (workflow_definition_id, name, slug, label, description, state_type, status_category, metadata, position_x, position_y, on_enter_actions, on_exit_actions, created, modified)
                 VALUES
                    ({$defId}, '{$name}', '{$slug}', '{$label}', NULL, '{$type}', '{$cat}', '{$meta}', {$px}, {$py}, {$enterActions}, NULL, NOW(), NOW())"
            );
        }

        $stateRows = $this->fetchAll("SELECT id, slug FROM workflow_states WHERE workflow_definition_id = {$defId}");
        $sid = [];
        foreach ($stateRows as $r) {
            $sid[$r['slug']] = (int)$r['id'];
        }

        $transitions = [
            [$sid['nominated'], $sid['pending-approval'], 'submit-for-approval', 'submit-for-approval', 'Submit for Approval', 0, 'manual', 0],
            [$sid['nominated'], $sid['cancelled'], 'cancel', 'cancel', 'Cancel Nomination', 10, 'manual', 0],
            [$sid['pending-approval'], $sid['active'], 'approve', 'approve', 'Approve', 0, 'manual', 0],
            [$sid['pending-approval'], $sid['nominated'], 'return-to-nominated', 'return-to-nominated', 'Return to Nominated', 5, 'manual', 0],
            [$sid['pending-approval'], $sid['cancelled'], 'cancel', 'cancel', 'Cancel', 10, 'manual', 0],
            [$sid['active'], $sid['released'], 'release', 'release', 'Release', 0, 'manual', 0],
            [$sid['active'], $sid['replaced'], 'replace', 'replace', 'Replace', 0, 'manual', 0],
            [$sid['active'], $sid['expired'], 'expire', 'expire', 'Mark Expired', 0, 'temporal', 1],
        ];

        foreach ($transitions as $t) {
            $label = addslashes($t[4]);
            $this->execute(
                "INSERT INTO workflow_transitions
                    (workflow_definition_id, from_state_id, to_state_id, name, slug, label, priority, trigger_type, is_automatic, created, modified)
                 VALUES
                    ({$defId}, {$t[0]}, {$t[1]}, '{$t[2]}', '{$t[3]}', '{$label}', {$t[5]}, '{$t[6]}', {$t[7]}, NOW(), NOW())"
            );
        }
    }

    public function down(): void
    {
        $rows = $this->fetchAll("SELECT id FROM workflow_definitions WHERE slug = 'officer-assignment' LIMIT 1");
        if (!empty($rows)) {
            $defId = (int)$rows[0]['id'];
            $this->execute("DELETE FROM workflow_transitions WHERE workflow_definition_id = {$defId}");
            $this->execute("DELETE FROM workflow_states WHERE workflow_definition_id = {$defId}");
            $this->execute("DELETE FROM workflow_definitions WHERE id = {$defId}");
        }
    }
}
