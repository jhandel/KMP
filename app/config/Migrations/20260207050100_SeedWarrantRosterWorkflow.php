<?php

declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Seeds the Warrant Roster workflow definition.
 *
 * Models the Ansteorra warrant roster lifecycle from draft through
 * review and approval to issuance or rejection.
 */
class SeedWarrantRosterWorkflow extends BaseMigration
{
    public function up(): void
    {
        $this->execute(
            "INSERT INTO workflow_definitions (name, slug, description, entity_type, plugin_name, version, is_active, is_default, created, modified)
             VALUES (
                 'Warrant Roster',
                 'warrant-roster',
                 'Workflow for managing warrant roster lifecycle from draft through review to issuance',
                 'WarrantRosters',
                 'Officers',
                 1, 1, 1, NOW(), NOW()
             )"
        );

        $rows = $this->fetchAll("SELECT id FROM workflow_definitions WHERE slug = 'warrant-roster' AND version = 1 LIMIT 1");
        $defId = (int)$rows[0]['id'];

        // [name, slug, label, state_type, status_category, metadata_json, pos_x, pos_y]
        $states = [
            ['Draft', 'draft', 'Draft', 'initial', 'In Progress', '{}', 100, 100],
            ['Submitted', 'submitted', 'Submitted', 'intermediate', 'In Progress', '{}', 250, 100],
            ['Under Review', 'under-review', 'Under Review', 'intermediate', 'In Progress', '{}', 400, 100],
            ['Partially Approved', 'partially-approved', 'Partially Approved', 'intermediate', 'In Progress',
                '{"visible":["approvalDetails"]}', 550, 100],
            ['Approved', 'approved', 'Approved', 'intermediate', 'Approved',
                '{"disabled":["rosterItems"]}', 700, 100],
            ['Issued', 'issued', 'Issued', 'final', 'Closed',
                '{"disabled":["rosterItems"]}', 850, 100],
            ['Rejected', 'rejected', 'Rejected', 'final', 'Closed',
                '{"required":["rejectionReason"],"visible":["rejectionBlock"]}', 850, 250],
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

            $this->execute(
                "INSERT INTO workflow_states
                    (workflow_definition_id, name, slug, label, description, state_type, status_category, metadata, position_x, position_y, on_enter_actions, on_exit_actions, created, modified)
                 VALUES
                    ({$defId}, '{$name}', '{$slug}', '{$label}', NULL, '{$type}', '{$cat}', '{$meta}', {$px}, {$py}, NULL, NULL, NOW(), NOW())"
            );
        }

        $stateRows = $this->fetchAll("SELECT id, slug FROM workflow_states WHERE workflow_definition_id = {$defId}");
        $sid = [];
        foreach ($stateRows as $r) {
            $sid[$r['slug']] = (int)$r['id'];
        }

        // Transitions: linear flow with rejection from review states
        $transitions = [
            [$sid['draft'], $sid['submitted'], 'submit', 'submit', 'Submit Roster', 0],
            [$sid['submitted'], $sid['under-review'], 'start-review', 'start-review', 'Start Review', 0],
            [$sid['submitted'], $sid['draft'], 'return-to-draft', 'return-to-draft', 'Return to Draft', 5],
            [$sid['under-review'], $sid['partially-approved'], 'partial-approve', 'partial-approve', 'Partially Approve', 0],
            [$sid['under-review'], $sid['approved'], 'approve', 'approve', 'Approve', 0],
            [$sid['under-review'], $sid['rejected'], 'reject', 'reject', 'Reject', 10],
            [$sid['under-review'], $sid['submitted'], 'return-to-submitted', 'return-to-submitted', 'Return for Revision', 5],
            [$sid['partially-approved'], $sid['approved'], 'approve', 'approve', 'Approve Remaining', 0],
            [$sid['partially-approved'], $sid['rejected'], 'reject', 'reject', 'Reject', 10],
            [$sid['approved'], $sid['issued'], 'issue', 'issue', 'Issue Warrants', 0],
        ];

        foreach ($transitions as $t) {
            $label = addslashes($t[4]);
            $this->execute(
                "INSERT INTO workflow_transitions
                    (workflow_definition_id, from_state_id, to_state_id, name, slug, label, priority, trigger_type, is_automatic, created, modified)
                 VALUES
                    ({$defId}, {$t[0]}, {$t[1]}, '{$t[2]}', '{$t[3]}', '{$label}', {$t[5]}, 'manual', 0, NOW(), NOW())"
            );
        }
    }

    public function down(): void
    {
        $rows = $this->fetchAll("SELECT id FROM workflow_definitions WHERE slug = 'warrant-roster' LIMIT 1");
        if (!empty($rows)) {
            $defId = (int)$rows[0]['id'];
            $this->execute("DELETE FROM workflow_transitions WHERE workflow_definition_id = {$defId}");
            $this->execute("DELETE FROM workflow_states WHERE workflow_definition_id = {$defId}");
            $this->execute("DELETE FROM workflow_definitions WHERE id = {$defId}");
        }
    }
}
