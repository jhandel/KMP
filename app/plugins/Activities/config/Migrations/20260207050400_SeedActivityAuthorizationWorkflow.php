<?php

declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Seeds the Activity Authorization workflow definition.
 *
 * Models the authorization lifecycle from request through an approval
 * chain to active status and eventual expiration, revocation, or retraction.
 */
class SeedActivityAuthorizationWorkflow extends BaseMigration
{
    public function up(): void
    {
        $this->execute(
            "INSERT INTO workflow_definitions (name, slug, description, entity_type, plugin_name, version, is_active, is_default, created, modified)
             VALUES (
                 'Activity Authorization',
                 'activity-authorization',
                 'Workflow for managing activity authorization lifecycle from request through approval chain to activation',
                 'ActivityAuthorizations',
                 'Activities',
                 1, 1, 1, NOW(), NOW()
             )"
        );

        $rows = $this->fetchAll("SELECT id FROM workflow_definitions WHERE slug = 'activity-authorization' AND version = 1 LIMIT 1");
        $defId = (int)$rows[0]['id'];

        // [name, slug, label, state_type, status_category, metadata_json, pos_x, pos_y, on_enter_actions_json]
        $states = [
            ['Requested', 'requested', 'Requested', 'initial', 'Pending', '{}', 100, 100, null],
            ['Pending Approval', 'pending-approval', 'Pending Approval', 'intermediate', 'Pending',
                '{"visible":["approvalChainBlock"]}', 300, 100, null],
            ['Approved', 'approved', 'Approved', 'intermediate', 'Approved',
                '{"disabled":["activity","member"]}', 500, 100,
                '[{"action":"grant_activity_role","params":{}}]'],
            ['Active', 'active', 'Active', 'intermediate', 'Active',
                '{"disabled":["activity","member"]}', 700, 100, null],
            ['Expired', 'expired', 'Expired', 'final', 'Closed',
                '{"disabled":["activity","member"]}', 900, 100, null],
            ['Revoked', 'revoked', 'Revoked', 'final', 'Closed',
                '{"required":["revokeReason"],"visible":["revokeReasonBlock"],"disabled":["activity","member"]}', 900, 250,
                '[{"action":"revoke_activity_role","params":{}}]'],
            ['Denied', 'denied', 'Denied', 'final', 'Closed',
                '{"required":["denyReason"],"visible":["denyReasonBlock"]}', 900, 400, null],
            ['Retracted', 'retracted', 'Retracted', 'final', 'Closed', '{}', 900, 550, null],
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
            [$sid['requested'], $sid['pending-approval'], 'submit-for-approval', 'submit-for-approval', 'Submit for Approval', 0, 'manual', 0],
            [$sid['requested'], $sid['retracted'], 'retract', 'retract', 'Retract Request', 10, 'manual', 0],
            [$sid['pending-approval'], $sid['approved'], 'approve', 'approve', 'Approve', 0, 'manual', 0],
            [$sid['pending-approval'], $sid['denied'], 'deny', 'deny', 'Deny', 10, 'manual', 0],
            [$sid['pending-approval'], $sid['requested'], 'return-to-requested', 'return-to-requested', 'Return to Requested', 5, 'manual', 0],
            [$sid['pending-approval'], $sid['retracted'], 'retract', 'retract', 'Retract', 10, 'manual', 0],
            [$sid['approved'], $sid['active'], 'activate', 'activate', 'Activate', 0, 'manual', 0],
            [$sid['approved'], $sid['revoked'], 'revoke', 'revoke', 'Revoke Before Activation', 10, 'manual', 0],
            [$sid['active'], $sid['expired'], 'expire', 'expire', 'Mark Expired', 0, 'temporal', 1],
            [$sid['active'], $sid['revoked'], 'revoke', 'revoke', 'Revoke', 10, 'manual', 0],
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
        $rows = $this->fetchAll("SELECT id FROM workflow_definitions WHERE slug = 'activity-authorization' LIMIT 1");
        if (!empty($rows)) {
            $defId = (int)$rows[0]['id'];
            $this->execute("DELETE FROM workflow_transitions WHERE workflow_definition_id = {$defId}");
            $this->execute("DELETE FROM workflow_states WHERE workflow_definition_id = {$defId}");
            $this->execute("DELETE FROM workflow_definitions WHERE id = {$defId}");
        }
    }
}
