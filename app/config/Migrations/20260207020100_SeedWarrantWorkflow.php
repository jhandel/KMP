<?php

declare(strict_types=1);

use Migrations\AbstractMigration;

/**
 * Seeds the workflow engine with Warrant and Warrant Roster workflow definitions.
 *
 * Two separate workflows model the linked but independent lifecycles:
 * - warrant-roster: batch approval workflow (Pending → Approved/Declined)
 * - warrant: individual warrant lifecycle (Pending → Current → terminal states)
 */
class SeedWarrantWorkflow extends AbstractMigration
{
    public function up(): void
    {
        // ---------------------------------------------------------------
        // 1. Warrant Roster workflow
        // ---------------------------------------------------------------
        $this->execute(
            "INSERT INTO workflow_definitions (name, slug, description, entity_type, plugin_name, version, is_active, is_default, created, modified)
             VALUES (
                'Warrant Roster Approval',
                'warrant-roster',
                'Multi-signer batch approval workflow for warrant rosters. Requires configurable number of approvals before warrants are activated.',
                'WarrantRosters',
                NULL,
                1,
                1,
                1,
                NOW(),
                NOW()
             )"
        );

        $this->execute("SET @wr_def_id = LAST_INSERT_ID()");

        // Warrant Roster states
        $this->execute(
            "INSERT INTO workflow_states (workflow_definition_id, name, slug, label, description, state_type, status_category, metadata, position_x, position_y, created, modified)
             VALUES
                (@wr_def_id, 'Pending', 'pending', 'Pending', 'Awaiting required approvals from authorized signers', 'start', 'Pending', NULL, 0, 0, NOW(), NOW()),
                (@wr_def_id, 'Approved', 'approved', 'Approved', 'All required approvals obtained; warrants ready for activation', 'end', 'Approved', NULL, 200, 0, NOW(), NOW()),
                (@wr_def_id, 'Declined', 'declined', 'Declined', 'At least one signer declined; roster workflow terminated', 'end', 'Declined', NULL, 200, 100, NOW(), NOW())"
        );

        // Capture state IDs for transitions
        $this->execute("SET @wr_pending_id  = (SELECT id FROM workflow_states WHERE workflow_definition_id = @wr_def_id AND slug = 'pending')");
        $this->execute("SET @wr_approved_id = (SELECT id FROM workflow_states WHERE workflow_definition_id = @wr_def_id AND slug = 'approved')");
        $this->execute("SET @wr_declined_id = (SELECT id FROM workflow_states WHERE workflow_definition_id = @wr_def_id AND slug = 'declined')");

        // Warrant Roster transitions
        $this->execute(
            "INSERT INTO workflow_transitions (workflow_definition_id, from_state_id, to_state_id, name, slug, label, description, priority, conditions, actions, is_automatic, trigger_type, trigger_config, created, modified)
             VALUES
                (@wr_def_id, @wr_pending_id, @wr_approved_id, 'Approve Roster', 'approve-roster', 'Approve', 'Approve the warrant roster after required approvals are met', 10,
                 '{\"type\":\"permission\",\"permission\":\"warrant.rosters.approve\"}',
                 '{\"on_complete\":[{\"action\":\"activate_warrant\",\"params\":{}}]}',
                 0, 'manual', NULL, NOW(), NOW()),
                (@wr_def_id, @wr_pending_id, @wr_declined_id, 'Decline Roster', 'decline-roster', 'Decline', 'Decline the warrant roster; cancels all pending warrants', 20,
                 '{\"type\":\"permission\",\"permission\":\"warrant.rosters.decline\"}',
                 '{\"on_complete\":[{\"action\":\"cancel_warrant\",\"params\":{\"reason\":\"Warrant Roster Declined\"}}]}',
                 0, 'manual', NULL, NOW(), NOW())"
        );

        // Approval gate on Pending state (threshold-based, reads count from app setting)
        $this->execute(
            "INSERT INTO workflow_approval_gates (workflow_state_id, approval_type, required_count, approver_rule, timeout_hours, allow_delegation, created, modified)
             VALUES (
                @wr_pending_id,
                'threshold',
                2,
                '{\"type\":\"setting\",\"key\":\"Warrant.RosterApprovalsRequired\",\"default\":2,\"permission\":\"warrant.rosters.approve\"}',
                NULL,
                0,
                NOW(),
                NOW()
             )"
        );

        // ---------------------------------------------------------------
        // 2. Warrant workflow
        // ---------------------------------------------------------------
        $this->execute(
            "INSERT INTO workflow_definitions (name, slug, description, entity_type, plugin_name, version, is_active, is_default, created, modified)
             VALUES (
                'Warrant Lifecycle',
                'warrant',
                'Individual warrant lifecycle from pending through current to terminal states. Supports time-based expiration and manual deactivation.',
                'Warrants',
                NULL,
                1,
                1,
                1,
                NOW(),
                NOW()
             )"
        );

        $this->execute("SET @w_def_id = LAST_INSERT_ID()");

        // Warrant states
        $this->execute(
            "INSERT INTO workflow_states (workflow_definition_id, name, slug, label, description, state_type, status_category, metadata, position_x, position_y, on_enter_actions, on_exit_actions, created, modified)
             VALUES
                (@w_def_id, 'Pending',     'pending',     'Pending',     'Warrant awaiting roster approval',                        'start',        'Pending',     NULL, 0,   100, NULL, NULL, NOW(), NOW()),
                (@w_def_id, 'Current',     'current',     'Current',     'Warrant is active and granting permissions',              'intermediate', 'Current',     NULL, 200, 100,
                 '[{\"action\":\"set_field\",\"params\":{\"field\":\"approved_date\",\"value\":\"{{now}}\"}}]', NULL, NOW(), NOW()),
                (@w_def_id, 'Upcoming',    'upcoming',    'Upcoming',    'Warrant approved but start date is in the future',        'intermediate', 'Upcoming',    NULL, 200, 0,   NULL, NULL, NOW(), NOW()),
                (@w_def_id, 'Expired',     'expired',     'Expired',     'Warrant expired based on expires_on date',                'end',          'Expired',     NULL, 400, 0,   NULL, NULL, NOW(), NOW()),
                (@w_def_id, 'Deactivated', 'deactivated', 'Deactivated', 'Warrant manually deactivated by administrator',           'end',          'Deactivated', NULL, 400, 50,  NULL, NULL, NOW(), NOW()),
                (@w_def_id, 'Cancelled',   'cancelled',   'Cancelled',   'Warrant cancelled before activation (roster declined)',    'end',          'Cancelled',   NULL, 400, 100, NULL, NULL, NOW(), NOW()),
                (@w_def_id, 'Declined',    'declined',    'Declined',    'Warrant individually declined',                           'end',          'Declined',    NULL, 400, 150, NULL, NULL, NOW(), NOW()),
                (@w_def_id, 'Replaced',    'replaced',    'Replaced',    'Warrant superseded by a newer warrant for the same role',  'end',          'Replaced',    NULL, 400, 200, NULL, NULL, NOW(), NOW()),
                (@w_def_id, 'Released',    'released',    'Released',    'Warrant voluntarily released by the holder',               'end',          'Released',    NULL, 400, 250, NULL, NULL, NOW(), NOW())"
        );

        // Capture warrant state IDs
        $this->execute("SET @w_pending_id     = (SELECT id FROM workflow_states WHERE workflow_definition_id = @w_def_id AND slug = 'pending')");
        $this->execute("SET @w_current_id     = (SELECT id FROM workflow_states WHERE workflow_definition_id = @w_def_id AND slug = 'current')");
        $this->execute("SET @w_upcoming_id    = (SELECT id FROM workflow_states WHERE workflow_definition_id = @w_def_id AND slug = 'upcoming')");
        $this->execute("SET @w_expired_id     = (SELECT id FROM workflow_states WHERE workflow_definition_id = @w_def_id AND slug = 'expired')");
        $this->execute("SET @w_deactivated_id = (SELECT id FROM workflow_states WHERE workflow_definition_id = @w_def_id AND slug = 'deactivated')");
        $this->execute("SET @w_cancelled_id   = (SELECT id FROM workflow_states WHERE workflow_definition_id = @w_def_id AND slug = 'cancelled')");
        $this->execute("SET @w_declined_id    = (SELECT id FROM workflow_states WHERE workflow_definition_id = @w_def_id AND slug = 'declined')");
        $this->execute("SET @w_replaced_id    = (SELECT id FROM workflow_states WHERE workflow_definition_id = @w_def_id AND slug = 'replaced')");
        $this->execute("SET @w_released_id    = (SELECT id FROM workflow_states WHERE workflow_definition_id = @w_def_id AND slug = 'released')");

        // Warrant transitions
        $this->execute(
            "INSERT INTO workflow_transitions (workflow_definition_id, from_state_id, to_state_id, name, slug, label, description, priority, conditions, actions, is_automatic, trigger_type, trigger_config, created, modified)
             VALUES
                -- Activation: Pending → Current (roster approved, start_on <= now)
                (@w_def_id, @w_pending_id, @w_current_id, 'Activate Warrant', 'activate', 'Activate', 'Roster approved and start date reached; activate the warrant', 10,
                 '{\"type\":\"composite\",\"operator\":\"and\",\"conditions\":[{\"type\":\"field\",\"field\":\"start_on\",\"operator\":\"<=\",\"value\":\"{{now}}\"},{\"type\":\"roster_approved\"}]}',
                 '[{\"action\":\"activate_warrant\",\"params\":{}}]',
                 1, 'automatic', '{\"trigger\":\"on_roster_approval\"}', NOW(), NOW()),

                -- Activation future: Pending → Upcoming (roster approved, start_on > now)
                (@w_def_id, @w_pending_id, @w_upcoming_id, 'Schedule Warrant', 'schedule', 'Schedule', 'Roster approved but start date is in the future', 20,
                 '{\"type\":\"composite\",\"operator\":\"and\",\"conditions\":[{\"type\":\"field\",\"field\":\"start_on\",\"operator\":\">\",\"value\":\"{{now}}\"},{\"type\":\"roster_approved\"}]}',
                 NULL,
                 1, 'automatic', '{\"trigger\":\"on_roster_approval\"}', NOW(), NOW()),

                -- Upcoming → Current (start date reached)
                (@w_def_id, @w_upcoming_id, @w_current_id, 'Start Warrant', 'start', 'Start', 'Start date reached; activate upcoming warrant', 10,
                 '{\"type\":\"field\",\"field\":\"start_on\",\"operator\":\"<=\",\"value\":\"{{now}}\"}',
                 '[{\"action\":\"activate_warrant\",\"params\":{}}]',
                 1, 'scheduled', '{\"check_field\":\"start_on\",\"interval\":\"daily\"}', NOW(), NOW()),

                -- Expiration: Current → Expired (time-based)
                (@w_def_id, @w_current_id, @w_expired_id, 'Expire Warrant', 'expire', 'Expire', 'Warrant expired based on expires_on date', 10,
                 '{\"type\":\"field\",\"field\":\"expires_on\",\"operator\":\"<\",\"value\":\"{{now}}\"}',
                 NULL,
                 1, 'scheduled', '{\"check_field\":\"expires_on\",\"interval\":\"daily\"}', NOW(), NOW()),

                -- Manual deactivation: Current → Deactivated
                (@w_def_id, @w_current_id, @w_deactivated_id, 'Deactivate Warrant', 'deactivate', 'Deactivate', 'Manually deactivate an active warrant', 20,
                 '{\"type\":\"permission\",\"permission\":\"warrant.warrants.deactivate\"}',
                 '[{\"action\":\"cancel_warrant\",\"params\":{\"reason\":\"{{context.reason}}\"}}]',
                 0, 'manual', NULL, NOW(), NOW()),

                -- Release: Current → Released
                (@w_def_id, @w_current_id, @w_released_id, 'Release Warrant', 'release', 'Release', 'Voluntarily release an active warrant', 30,
                 NULL,
                 '[{\"action\":\"cancel_warrant\",\"params\":{\"reason\":\"Voluntarily released\"}}]',
                 0, 'manual', NULL, NOW(), NOW()),

                -- Cancel pending: Pending → Cancelled (roster declined)
                (@w_def_id, @w_pending_id, @w_cancelled_id, 'Cancel Warrant', 'cancel', 'Cancel', 'Cancel a pending warrant when roster is declined', 30,
                 '{\"type\":\"roster_declined\"}',
                 '[{\"action\":\"cancel_warrant\",\"params\":{\"reason\":\"Warrant Roster Declined\"}}]',
                 1, 'automatic', '{\"trigger\":\"on_roster_decline\"}', NOW(), NOW()),

                -- Decline individual: Pending → Declined
                (@w_def_id, @w_pending_id, @w_declined_id, 'Decline Warrant', 'decline', 'Decline', 'Individually decline a pending warrant', 40,
                 '{\"type\":\"permission\",\"permission\":\"warrant.warrants.declineWarrantInRoster\"}',
                 '[{\"action\":\"cancel_warrant\",\"params\":{\"reason\":\"{{context.reason}}\"}}]',
                 0, 'manual', NULL, NOW(), NOW()),

                -- Replace: Current → Replaced (new warrant for same role)
                (@w_def_id, @w_current_id, @w_replaced_id, 'Replace Warrant', 'replace', 'Replace', 'Superseded by a newer warrant for the same role', 40,
                 '{\"type\":\"new_warrant_approved\"}',
                 NULL,
                 1, 'automatic', '{\"trigger\":\"on_replacement\"}', NOW(), NOW())"
        );
    }

    public function down(): void
    {
        // Cascade deletes handle states, transitions, gates via foreign keys
        $this->execute("DELETE FROM workflow_definitions WHERE slug IN ('warrant-roster', 'warrant')");
    }
}
