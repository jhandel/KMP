<?php

declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Seeds the Individual Warrant (on-hire) workflow definition.
 *
 * Models the lifecycle of a single warrant from request through
 * approval to activation and eventual expiration or revocation.
 */
class SeedWarrantOnHireWorkflow extends BaseMigration
{
    public function up(): void
    {
        $this->execute(
            "INSERT INTO workflow_definitions (name, slug, description, entity_type, plugin_name, version, is_active, is_default, created, modified)
             VALUES (
                 'Individual Warrant',
                 'warrant-on-hire',
                 'Workflow for managing individual warrant lifecycle from request through approval to activation',
                 'Warrants',
                 'Officers',
                 1, 1, 1, NOW(), NOW()
             )"
        );

        $rows = $this->fetchAll("SELECT id FROM workflow_definitions WHERE slug = 'warrant-on-hire' AND version = 1 LIMIT 1");
        $defId = (int)$rows[0]['id'];

        // [name, slug, label, state_type, status_category, metadata_json, pos_x, pos_y, on_enter_actions_json]
        $states = [
            ['Requested', 'requested', 'Requested', 'initial', 'Pending', '{}', 100, 100, null],
            ['Pending Approval', 'pending-approval', 'Pending Approval', 'intermediate', 'Pending',
                '{"visible":["approvalBlock"]}', 300, 100, null],
            ['Active', 'active', 'Active', 'intermediate', 'Active',
                '{"disabled":["office","member"]}', 500, 100,
                '[{"action":"activate_warrant","params":{}}]'],
            ['Expired', 'expired', 'Expired', 'final', 'Closed',
                '{"disabled":["office","member"]}', 700, 100, null],
            ['Revoked', 'revoked', 'Revoked', 'final', 'Closed',
                '{"required":["revokeReason"],"visible":["revokeReasonBlock"],"disabled":["office","member"]}', 700, 250,
                '[{"action":"deactivate_warrant","params":{"reason":"revoked"}}]'],
            ['Denied', 'denied', 'Denied', 'final', 'Closed',
                '{"required":["denyReason"],"visible":["denyReasonBlock"]}', 700, 400, null],
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
            [$sid['requested'], $sid['pending-approval'], 'submit-for-approval', 'submit-for-approval', 'Submit for Approval', 0],
            [$sid['requested'], $sid['denied'], 'deny', 'deny', 'Deny Request', 10],
            [$sid['pending-approval'], $sid['active'], 'approve', 'approve', 'Approve', 0],
            [$sid['pending-approval'], $sid['denied'], 'deny', 'deny', 'Deny', 10],
            [$sid['pending-approval'], $sid['requested'], 'return-to-requested', 'return-to-requested', 'Return to Requested', 5],
            [$sid['active'], $sid['expired'], 'expire', 'expire', 'Mark Expired', 0],
            [$sid['active'], $sid['revoked'], 'revoke', 'revoke', 'Revoke', 10],
        ];

        foreach ($transitions as $t) {
            $label = addslashes($t[4]);
            $isAuto = ($t[3] === 'expire') ? 1 : 0;
            $trigger = ($t[3] === 'expire') ? 'temporal' : 'manual';
            $this->execute(
                "INSERT INTO workflow_transitions
                    (workflow_definition_id, from_state_id, to_state_id, name, slug, label, priority, trigger_type, is_automatic, created, modified)
                 VALUES
                    ({$defId}, {$t[0]}, {$t[1]}, '{$t[2]}', '{$t[3]}', '{$label}', {$t[5]}, '{$trigger}', {$isAuto}, NOW(), NOW())"
            );
        }
    }

    public function down(): void
    {
        $rows = $this->fetchAll("SELECT id FROM workflow_definitions WHERE slug = 'warrant-on-hire' LIMIT 1");
        if (!empty($rows)) {
            $defId = (int)$rows[0]['id'];
            $this->execute("DELETE FROM workflow_transitions WHERE workflow_definition_id = {$defId}");
            $this->execute("DELETE FROM workflow_states WHERE workflow_definition_id = {$defId}");
            $this->execute("DELETE FROM workflow_definitions WHERE id = {$defId}");
        }
    }
}
