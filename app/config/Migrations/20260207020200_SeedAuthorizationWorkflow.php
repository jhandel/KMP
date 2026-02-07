<?php

declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Seeds the workflow engine with the Activities Authorization workflow.
 *
 * Models the authorization lifecycle: Pending → Approved/Denied/Retracted/Expired
 * with chain-type approval gate, role granting on approval, and time-based expiration.
 */
class SeedAuthorizationWorkflow extends BaseMigration
{
    public function up(): void
    {
        // 1. Insert workflow definition
        $this->execute(
            "INSERT INTO workflow_definitions (name, slug, description, entity_type, plugin_name, version, is_active, is_default, created, modified)
             VALUES (
                 'Activity Authorization',
                 'activity-authorization',
                 'Manages member activity authorization lifecycle including multi-level approval chains, role granting, time-based expiration, and revocation.',
                 'Authorizations',
                 'Activities',
                 1, 1, 1, NOW(), NOW()
             )"
        );

        // Retrieve the auto-generated definition ID
        $rows = $this->fetchAll(
            "SELECT id FROM workflow_definitions WHERE slug = 'activity-authorization' AND version = 1 LIMIT 1"
        );
        $defId = (int)$rows[0]['id'];

        // 2. Insert states -------------------------------------------------------
        $states = [
            // [name, slug, label, state_type, status_category, description, pos_x, pos_y, on_enter_actions]
            [
                'Pending', 'pending', 'Pending', 'initial', 'Pending',
                'Authorization request awaiting approval from required approvers',
                100, 100, null,
            ],
            [
                'Approved', 'approved', 'Approved', 'intermediate', 'Approved',
                'Authorization approved; role granted with active temporal window',
                350, 100,
                '[{"action":"grant_activity_role","params":{"mode":"grant"}},'
                . '{"action":"send_email","params":{"mailer":"Activities.Activities","method":"notifyRequester",'
                . '"to":"{{entity.member.email_address}}","vars":{"status":"Approved"}}}]',
            ],
            [
                'Denied', 'denied', 'Denied', 'final', 'Denied',
                'Authorization denied by an approver',
                350, 250,
                '[{"action":"grant_activity_role","params":{"mode":"revoke","target_status":"Denied","reason":"Authorization denied"}},'
                . '{"action":"send_email","params":{"mailer":"Activities.Activities","method":"notifyRequester",'
                . '"to":"{{entity.member.email_address}}","vars":{"status":"Denied"}}}]',
            ],
            [
                'Revoked', 'revoked', 'Revoked', 'final', 'Revoked',
                'Previously approved authorization revoked by administrator',
                600, 100,
                '[{"action":"grant_activity_role","params":{"mode":"revoke","target_status":"Revoked","reason":"{{context.reason}}"}},'
                . '{"action":"send_email","params":{"mailer":"Activities.Activities","method":"notifyRequester",'
                . '"to":"{{entity.member.email_address}}","vars":{"status":"Revoked"}}}]',
            ],
            [
                'Expired', 'expired', 'Expired', 'final', 'Expired',
                'Authorization expired based on expires_on date',
                600, 250, null,
            ],
            [
                'Retracted', 'retracted', 'Retracted', 'final', 'Retracted',
                'Authorization request retracted by the requester before approval',
                100, 250, null,
            ],
        ];

        foreach ($states as $s) {
            $name = addslashes($s[0]);
            $slug = $s[1];
            $label = addslashes($s[2]);
            $type = $s[3];
            $cat = addslashes($s[4]);
            $desc = addslashes($s[5]);
            $px = $s[6];
            $py = $s[7];
            $enterActions = $s[8] !== null ? "'" . addslashes($s[8]) . "'" : 'NULL';

            $this->execute(
                "INSERT INTO workflow_states
                    (workflow_definition_id, name, slug, label, description, state_type, status_category, metadata, position_x, position_y, on_enter_actions, on_exit_actions, created, modified)
                 VALUES
                    ({$defId}, '{$name}', '{$slug}', '{$label}', '{$desc}', '{$type}', '{$cat}', '{}', {$px}, {$py}, {$enterActions}, NULL, NOW(), NOW())"
            );
        }

        // Build slug -> id map
        $stateRows = $this->fetchAll(
            "SELECT id, slug FROM workflow_states WHERE workflow_definition_id = {$defId}"
        );
        $sid = [];
        foreach ($stateRows as $r) {
            $sid[$r['slug']] = (int)$r['id'];
        }

        // 3. Insert transitions ---------------------------------------------------
        $transitions = [
            // [from_slug, to_slug, name, slug, label, description, priority, conditions_json, actions_json, is_automatic, trigger_type, trigger_config_json]

            // Pending → Approved (final approval met)
            [
                'pending', 'approved',
                'Approve Authorization', 'approve',
                'Approve', 'Authorization approved after all required approvals are met',
                10,
                '{"type":"approval_gate_met"}',
                null,
                0, 'manual', null,
            ],

            // Pending → Denied (approver denies)
            [
                'pending', 'denied',
                'Deny Authorization', 'deny',
                'Deny', 'Authorization denied by an approver in the chain',
                20,
                '{"type":"permission","permission":"canApproveActivityAuthorization"}',
                null,
                0, 'manual', null,
            ],

            // Pending → Retracted (requester self-service)
            [
                'pending', 'retracted',
                'Retract Authorization', 'retract',
                'Retract', 'Requester retracts their own pending authorization request',
                30,
                '{"type":"ownership","field":"member_id"}',
                null,
                0, 'manual', null,
            ],

            // Pending → Expired (time-based automatic)
            [
                'pending', 'expired',
                'Expire Pending Authorization', 'expire-pending',
                'Expire', 'Pending authorization expired without response',
                40,
                '{"type":"time","field":"created","operator":"<","value":"{{now}}","offset":"-30 days"}',
                null,
                1, 'scheduled', '{"check_field":"created","interval":"daily"}',
            ],

            // Approved → Expired (time-based automatic when expires_on passed)
            [
                'approved', 'expired',
                'Expire Authorization', 'expire-approved',
                'Expire', 'Approved authorization expired based on expires_on date',
                10,
                '{"type":"field","field":"expires_on","operator":"<","value":"{{now}}"}',
                null,
                1, 'scheduled', '{"check_field":"expires_on","interval":"daily"}',
            ],

            // Approved → Revoked (admin action)
            [
                'approved', 'revoked',
                'Revoke Authorization', 'revoke',
                'Revoke', 'Administrator revokes an active authorization',
                20,
                '{"type":"permission","permission":"canRevoke"}',
                null,
                0, 'manual', null,
            ],
        ];

        foreach ($transitions as $t) {
            $fromId = $sid[$t[0]];
            $toId = $sid[$t[1]];
            $tName = addslashes($t[2]);
            $tSlug = $t[3];
            $tLabel = addslashes($t[4]);
            $tDesc = addslashes($t[5]);
            $priority = $t[6];
            $cond = $t[7] !== null ? "'" . addslashes($t[7]) . "'" : 'NULL';
            $actions = $t[8] !== null ? "'" . addslashes($t[8]) . "'" : 'NULL';
            $isAuto = $t[9];
            $trigType = $t[10];
            $trigConfig = $t[11] !== null ? "'" . addslashes($t[11]) . "'" : 'NULL';

            $this->execute(
                "INSERT INTO workflow_transitions
                    (workflow_definition_id, from_state_id, to_state_id, name, slug, label, description, priority, conditions, actions, is_automatic, trigger_type, trigger_config, created, modified)
                 VALUES
                    ({$defId}, {$fromId}, {$toId}, '{$tName}', '{$tSlug}', '{$tLabel}', '{$tDesc}', {$priority}, {$cond}, {$actions}, {$isAuto}, '{$trigType}', {$trigConfig}, NOW(), NOW())"
            );
        }

        // 4. Approval gate on Pending state ---------------------------------------
        // Chain-type approval: sequential multi-level, count varies per activity
        $this->execute(
            "INSERT INTO workflow_approval_gates
                (workflow_state_id, approval_type, required_count, approver_rule, threshold_config, timeout_hours, timeout_transition_id, allow_delegation, created, modified)
             VALUES (
                {$sid['pending']},
                'chain',
                1,
                '" . addslashes('{"type":"activity_config","new_field":"num_required_authorizors","renewal_field":"num_required_renewers","permission":"canApproveActivityAuthorization"}') . "',
                '" . addslashes('{"type":"entity_field","field":"num_required_authorizors","default":1}') . "',
                NULL,
                NULL,
                1,
                NOW(),
                NOW()
             )"
        );

        // 5. Visibility rules -----------------------------------------------------
        // Terminal states require manage permission to view in listings
        $terminalSlugs = ['denied', 'revoked', 'expired', 'retracted'];
        foreach ($terminalSlugs as $slug) {
            $this->execute(
                "INSERT INTO workflow_visibility_rules
                    (workflow_state_id, rule_type, target, `condition`, priority, created, modified)
                 VALUES
                    ({$sid[$slug]}, 'require_permission', '*', '" . addslashes('{"permission":"canViewClosedAuthorizations"}') . "', 10, NOW(), NOW())"
            );
        }
    }

    public function down(): void
    {
        // Cascade deletes handle states, transitions, visibility rules, and approval gates
        $this->execute("DELETE FROM workflow_definitions WHERE slug = 'activity-authorization'");
    }
}
