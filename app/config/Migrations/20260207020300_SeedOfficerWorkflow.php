<?php

declare(strict_types=1);

use Migrations\BaseMigration;

class SeedOfficerWorkflow extends BaseMigration
{
    /**
     * Seed the officer-assignment workflow definition with states, transitions, and actions.
     *
     * States mirror ActiveWindowBaseEntity constants: Upcoming, Current, Expired, Released, Replaced.
     * Transitions encode both automatic (time-based) and manual (admin-initiated) flows.
     */
    public function up(): void
    {
        // 1. Workflow definition
        $this->execute("
            INSERT INTO workflow_definitions (name, slug, description, entity_type, plugin_name, version, is_active, is_default, created, modified)
            VALUES (
                'Officer Assignment',
                'officer-assignment',
                'Manages the lifecycle of officer assignments including temporal status transitions, warrant integration, role grants, and notification processing.',
                'Officers.Officers',
                'Officers',
                1,
                1,
                1,
                NOW(),
                NOW()
            )
        ");

        // 2. States
        // Upcoming (initial) — assignment starts in the future
        $this->execute("
            INSERT INTO workflow_states (workflow_definition_id, name, slug, label, description, state_type, status_category, metadata, on_enter_actions, on_exit_actions, created, modified)
            VALUES (
                (SELECT id FROM workflow_definitions WHERE slug = 'officer-assignment'),
                'Upcoming',
                'upcoming',
                'Upcoming',
                'Officer assignment is scheduled but start_on is in the future.',
                'initial',
                'Upcoming',
                NULL,
                NULL,
                NULL,
                NOW(),
                NOW()
            )
        ");

        // Current (intermediate) — active assignment
        $this->execute("
            INSERT INTO workflow_states (workflow_definition_id, name, slug, label, description, state_type, status_category, metadata, on_enter_actions, on_exit_actions, created, modified)
            VALUES (
                (SELECT id FROM workflow_definitions WHERE slug = 'officer-assignment'),
                'Current',
                'current',
                'Current',
                'Officer assignment is active. Member holds the office.',
                'intermediate',
                'Current',
                NULL,
                " . $this->quoteValue(json_encode([
                    [
                        'type' => 'assign_officer_role',
                        'params' => [
                            'operation' => 'grant',
                            'officer_id' => '{{entity.id}}',
                            'member_id' => '{{entity.member_id}}',
                            'role_id' => '{{entity.office.grants_role_id}}',
                            'branch_id' => '{{entity.branch_id}}',
                        ],
                    ],
                    [
                        'type' => 'request_warrant',
                        'condition' => ['field' => 'entity.office.requires_warrant', 'operator' => '==', 'value' => true],
                        'params' => [
                            'officer_id' => '{{entity.id}}',
                            'member_id' => '{{entity.member_id}}',
                            'office_id' => '{{entity.office_id}}',
                            'branch_id' => '{{entity.branch_id}}',
                            'approver_id' => '{{triggered_by}}',
                        ],
                    ],
                    [
                        'type' => 'send_email',
                        'params' => [
                            'mailer' => 'Officers.Officers',
                            'method' => 'notifyOfHire',
                            'to' => '{{entity.member.email_address}}',
                            'vars' => [
                                'memberScaName' => '{{entity.member.sca_name}}',
                                'officeName' => '{{entity.office.name}}',
                                'branchName' => '{{entity.branch.name}}',
                                'hireDate' => '{{entity.start_on}}',
                                'endDate' => '{{entity.expires_on}}',
                                'requiresWarrant' => '{{entity.office.requires_warrant}}',
                            ],
                        ],
                    ],
                ])) . ",
                NULL,
                NOW(),
                NOW()
            )
        ");

        // Expired (terminal) — time-based expiration
        $this->execute("
            INSERT INTO workflow_states (workflow_definition_id, name, slug, label, description, state_type, status_category, metadata, on_enter_actions, on_exit_actions, created, modified)
            VALUES (
                (SELECT id FROM workflow_definitions WHERE slug = 'officer-assignment'),
                'Expired',
                'expired',
                'Expired',
                'Officer assignment expired because expires_on has passed.',
                'terminal',
                'Expired',
                NULL,
                " . $this->quoteValue(json_encode([
                    [
                        'type' => 'assign_officer_role',
                        'params' => [
                            'operation' => 'revoke',
                            'officer_id' => '{{entity.id}}',
                            'member_id' => '{{entity.member_id}}',
                            'role_id' => '{{entity.office.grants_role_id}}',
                            'branch_id' => '{{entity.branch_id}}',
                        ],
                    ],
                    [
                        'type' => 'send_email',
                        'params' => [
                            'mailer' => 'Officers.Officers',
                            'method' => 'notifyOfRelease',
                            'to' => '{{entity.member.email_address}}',
                            'vars' => [
                                'memberScaName' => '{{entity.member.sca_name}}',
                                'officeName' => '{{entity.office.name}}',
                                'branchName' => '{{entity.branch.name}}',
                                'reason' => 'Term expired',
                                'releaseDate' => '{{entity.expires_on}}',
                            ],
                        ],
                    ],
                ])) . ",
                NULL,
                NOW(),
                NOW()
            )
        ");

        // Released (terminal) — manual admin release
        $this->execute("
            INSERT INTO workflow_states (workflow_definition_id, name, slug, label, description, state_type, status_category, metadata, on_enter_actions, on_exit_actions, created, modified)
            VALUES (
                (SELECT id FROM workflow_definitions WHERE slug = 'officer-assignment'),
                'Released',
                'released',
                'Released',
                'Officer was manually released from their assignment by an administrator.',
                'terminal',
                'Released',
                NULL,
                " . $this->quoteValue(json_encode([
                    [
                        'type' => 'assign_officer_role',
                        'params' => [
                            'operation' => 'revoke',
                            'officer_id' => '{{entity.id}}',
                            'member_id' => '{{entity.member_id}}',
                            'role_id' => '{{entity.office.grants_role_id}}',
                            'branch_id' => '{{entity.branch_id}}',
                        ],
                    ],
                    [
                        'type' => 'send_email',
                        'params' => [
                            'mailer' => 'Officers.Officers',
                            'method' => 'notifyOfRelease',
                            'to' => '{{entity.member.email_address}}',
                            'vars' => [
                                'memberScaName' => '{{entity.member.sca_name}}',
                                'officeName' => '{{entity.office.name}}',
                                'branchName' => '{{entity.branch.name}}',
                                'reason' => '{{transition.reason}}',
                                'releaseDate' => '{{transition.revoked_on}}',
                            ],
                        ],
                    ],
                ])) . ",
                NULL,
                NOW(),
                NOW()
            )
        ");

        // Replaced (terminal) — replaced by new officer
        $this->execute("
            INSERT INTO workflow_states (workflow_definition_id, name, slug, label, description, state_type, status_category, metadata, on_enter_actions, on_exit_actions, created, modified)
            VALUES (
                (SELECT id FROM workflow_definitions WHERE slug = 'officer-assignment'),
                'Replaced',
                'replaced',
                'Replaced',
                'Officer was replaced by a new officer assigned to the same office in a single-holder branch.',
                'terminal',
                'Replaced',
                NULL,
                " . $this->quoteValue(json_encode([
                    [
                        'type' => 'assign_officer_role',
                        'params' => [
                            'operation' => 'revoke',
                            'officer_id' => '{{entity.id}}',
                            'member_id' => '{{entity.member_id}}',
                            'role_id' => '{{entity.office.grants_role_id}}',
                            'branch_id' => '{{entity.branch_id}}',
                        ],
                    ],
                    [
                        'type' => 'send_email',
                        'params' => [
                            'mailer' => 'Officers.Officers',
                            'method' => 'notifyOfRelease',
                            'to' => '{{entity.member.email_address}}',
                            'vars' => [
                                'memberScaName' => '{{entity.member.sca_name}}',
                                'officeName' => '{{entity.office.name}}',
                                'branchName' => '{{entity.branch.name}}',
                                'reason' => 'Replaced by new officer',
                                'releaseDate' => '{{transition.revoked_on}}',
                            ],
                        ],
                    ],
                ])) . ",
                NULL,
                NOW(),
                NOW()
            )
        ");

        // 3. Transitions

        // Upcoming → Current (automatic: when start_on <= now)
        $this->execute("
            INSERT INTO workflow_transitions (workflow_definition_id, from_state_id, to_state_id, name, slug, label, description, priority, conditions, actions, is_automatic, trigger_type, trigger_config, created, modified)
            VALUES (
                (SELECT id FROM workflow_definitions WHERE slug = 'officer-assignment'),
                (SELECT id FROM workflow_states WHERE slug = 'upcoming' AND workflow_definition_id = (SELECT id FROM workflow_definitions WHERE slug = 'officer-assignment')),
                (SELECT id FROM workflow_states WHERE slug = 'current' AND workflow_definition_id = (SELECT id FROM workflow_definitions WHERE slug = 'officer-assignment')),
                'Activate',
                'activate',
                'Activate',
                'Automatically transitions officer to Current when start_on date is reached.',
                10,
                " . $this->quoteValue(json_encode([
                    ['type' => 'time', 'field' => 'entity.start_on', 'operator' => '<=', 'value' => '{{now}}'],
                ])) . ",
                NULL,
                1,
                'scheduled',
                " . $this->quoteValue(json_encode([
                    'schedule' => 'daily',
                    'description' => 'Check if start_on <= now to activate upcoming officers',
                ])) . ",
                NOW(),
                NOW()
            )
        ");

        // Current → Expired (automatic: when expires_on <= now)
        $this->execute("
            INSERT INTO workflow_transitions (workflow_definition_id, from_state_id, to_state_id, name, slug, label, description, priority, conditions, actions, is_automatic, trigger_type, trigger_config, created, modified)
            VALUES (
                (SELECT id FROM workflow_definitions WHERE slug = 'officer-assignment'),
                (SELECT id FROM workflow_states WHERE slug = 'current' AND workflow_definition_id = (SELECT id FROM workflow_definitions WHERE slug = 'officer-assignment')),
                (SELECT id FROM workflow_states WHERE slug = 'expired' AND workflow_definition_id = (SELECT id FROM workflow_definitions WHERE slug = 'officer-assignment')),
                'Expire',
                'expire',
                'Expire',
                'Automatically transitions officer to Expired when expires_on date is reached.',
                10,
                " . $this->quoteValue(json_encode([
                    ['type' => 'time', 'field' => 'entity.expires_on', 'operator' => '<=', 'value' => '{{now}}'],
                    ['type' => 'field', 'field' => 'entity.expires_on', 'operator' => '!=', 'value' => null],
                ])) . ",
                NULL,
                1,
                'scheduled',
                " . $this->quoteValue(json_encode([
                    'schedule' => 'daily',
                    'description' => 'Check if expires_on <= now to expire current officers',
                ])) . ",
                NOW(),
                NOW()
            )
        ");

        // Current → Released (manual: admin action with canRelease permission)
        $this->execute("
            INSERT INTO workflow_transitions (workflow_definition_id, from_state_id, to_state_id, name, slug, label, description, priority, conditions, actions, is_automatic, trigger_type, trigger_config, created, modified)
            VALUES (
                (SELECT id FROM workflow_definitions WHERE slug = 'officer-assignment'),
                (SELECT id FROM workflow_states WHERE slug = 'current' AND workflow_definition_id = (SELECT id FROM workflow_definitions WHERE slug = 'officer-assignment')),
                (SELECT id FROM workflow_states WHERE slug = 'released' AND workflow_definition_id = (SELECT id FROM workflow_definitions WHERE slug = 'officer-assignment')),
                'Release',
                'release',
                'Release',
                'Administrator manually releases an officer from their current assignment.',
                0,
                " . $this->quoteValue(json_encode([
                    ['type' => 'permission', 'policy' => 'Officers.Officer', 'action' => 'canRelease'],
                ])) . ",
                NULL,
                0,
                'manual',
                NULL,
                NOW(),
                NOW()
            )
        ");

        // Current → Replaced (automatic: when new officer assigned to same office with only_one_per_branch)
        $this->execute("
            INSERT INTO workflow_transitions (workflow_definition_id, from_state_id, to_state_id, name, slug, label, description, priority, conditions, actions, is_automatic, trigger_type, trigger_config, created, modified)
            VALUES (
                (SELECT id FROM workflow_definitions WHERE slug = 'officer-assignment'),
                (SELECT id FROM workflow_states WHERE slug = 'current' AND workflow_definition_id = (SELECT id FROM workflow_definitions WHERE slug = 'officer-assignment')),
                (SELECT id FROM workflow_states WHERE slug = 'replaced' AND workflow_definition_id = (SELECT id FROM workflow_definitions WHERE slug = 'officer-assignment')),
                'Replace',
                'replace',
                'Replace',
                'Automatically triggered when a new officer is assigned to the same office in a branch with only_one_per_branch enabled.',
                5,
                " . $this->quoteValue(json_encode([
                    ['type' => 'field', 'field' => 'entity.office.only_one_per_branch', 'operator' => '==', 'value' => true],
                ])) . ",
                NULL,
                1,
                'event',
                " . $this->quoteValue(json_encode([
                    'event' => 'Officers.Officers.assigned',
                    'description' => 'Fires when a new officer is assigned to the same office+branch',
                ])) . ",
                NOW(),
                NOW()
            )
        ");

        // Upcoming → Released (manual: admin cancels before start)
        $this->execute("
            INSERT INTO workflow_transitions (workflow_definition_id, from_state_id, to_state_id, name, slug, label, description, priority, conditions, actions, is_automatic, trigger_type, trigger_config, created, modified)
            VALUES (
                (SELECT id FROM workflow_definitions WHERE slug = 'officer-assignment'),
                (SELECT id FROM workflow_states WHERE slug = 'upcoming' AND workflow_definition_id = (SELECT id FROM workflow_definitions WHERE slug = 'officer-assignment')),
                (SELECT id FROM workflow_states WHERE slug = 'released' AND workflow_definition_id = (SELECT id FROM workflow_definitions WHERE slug = 'officer-assignment')),
                'Cancel',
                'cancel',
                'Cancel',
                'Administrator cancels an upcoming officer assignment before the start date.',
                0,
                " . $this->quoteValue(json_encode([
                    ['type' => 'permission', 'policy' => 'Officers.Officer', 'action' => 'canRelease'],
                ])) . ",
                NULL,
                0,
                'manual',
                NULL,
                NOW(),
                NOW()
            )
        ");
    }

    /**
     * Remove the officer-assignment workflow and all related data (cascades via FK).
     */
    public function down(): void
    {
        $this->execute("DELETE FROM workflow_definitions WHERE slug = 'officer-assignment'");
    }

    /**
     * Quote a string value for safe inclusion in SQL.
     */
    private function quoteValue(?string $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        return "'" . addslashes($value) . "'";
    }
}
