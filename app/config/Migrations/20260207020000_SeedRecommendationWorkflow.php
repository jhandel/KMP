<?php

declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Seeds the workflow engine tables with the Award Recommendations workflow.
 *
 * Maps states, transitions, visibility rules, and approval gates from
 * the Awards plugin RecommendationStatuses/RecommendationStateRules config.
 */
class SeedRecommendationWorkflow extends BaseMigration
{
    public function up(): void
    {
        // 1. Insert workflow definition
        $this->execute(
            "INSERT INTO workflow_definitions (name, slug, description, entity_type, plugin_name, version, is_active, is_default, created, modified)
             VALUES (
                 'Award Recommendations',
                 'award-recommendations',
                 'Workflow for managing award recommendation lifecycle from submission through scheduling to closure',
                 'AwardsRecommendations',
                 'Awards',
                 1, 1, 1, NOW(), NOW()
             )"
        );

        // Retrieve the auto-generated definition ID
        $rows = $this->fetchAll(
            "SELECT id FROM workflow_definitions WHERE slug = 'award-recommendations' AND version = 1 LIMIT 1"
        );
        $defId = (int)$rows[0]['id'];

        // 2. Insert states -------------------------------------------------------
        // Status category: In Progress (visual editor: left column)
        $states = [
            // [name, slug, label, state_type, status_category, metadata_json, pos_x, pos_y, on_enter_actions]
            ['Submitted', 'submitted', 'Submitted', 'initial', 'In Progress', '{}', 100, 100, null],
            ['In Consideration', 'in-consideration', 'In Consideration', 'intermediate', 'In Progress', '{}', 100, 200, null],
            ['Awaiting Feedback', 'awaiting-feedback', 'Awaiting Feedback', 'intermediate', 'In Progress', '{}', 100, 300, null],
            ['Deferred till Later', 'deferred-till-later', 'Deferred till Later', 'intermediate', 'In Progress', '{}', 100, 400, null],
            ['King Approved', 'king-approved', 'King Approved', 'intermediate', 'In Progress', '{}', 100, 500, null],
            ['Queen Approved', 'queen-approved', 'Queen Approved', 'intermediate', 'In Progress', '{}', 100, 600, null],

            // Status category: Scheduling (visual editor: center-left)
            ['Need to Schedule', 'need-to-schedule', 'Need to Schedule', 'intermediate', 'Scheduling',
                '{"visible":["planToGiveBlockTarget"],"disabled":["domainTarget","awardTarget","specialtyTarget","scaMemberTarget","branchTarget"]}',
                350, 100, null],

            // Status category: To Give (visual editor: center-right)
            ['Scheduled', 'scheduled', 'Scheduled', 'intermediate', 'To Give',
                '{"required":["planToGiveEventTarget"],"visible":["planToGiveBlockTarget"],"disabled":["domainTarget","awardTarget","specialtyTarget","scaMemberTarget","branchTarget"]}',
                600, 100, null],
            ['Announced Not Given', 'announced-not-given', 'Announced Not Given', 'intermediate', 'To Give', '{}', 600, 200, null],

            // Status category: Closed (visual editor: right column)
            ['Given', 'given', 'Given', 'final', 'Closed',
                '{"required":["planToGiveEventTarget","givenDateTarget"],"visible":["planToGiveBlockTarget","givenBlockTarget"],"disabled":["domainTarget","awardTarget","specialtyTarget","scaMemberTarget","branchTarget"],"set":{"close_reason":"Given"}}',
                850, 100,
                '[{"action":"set_field","params":{"field":"close_reason","value":"Given"}},{"action":"log_recommendation_state","params":{}}]'],
            ['No Action', 'no-action', 'No Action', 'final', 'Closed',
                '{"required":["closeReasonTarget"],"visible":["closeReasonBlockTarget","closeReasonTarget"],"disabled":["domainTarget","awardTarget","specialtyTarget","scaMemberTarget","branchTarget","courtAvailabilityTarget","callIntoCourtTarget"]}',
                850, 200,
                '[{"action":"log_recommendation_state","params":{}}]'],
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

        // Build slug -> id map
        $stateRows = $this->fetchAll(
            "SELECT id, slug FROM workflow_states WHERE workflow_definition_id = {$defId}"
        );
        $sid = [];
        foreach ($stateRows as $r) {
            $sid[$r['slug']] = (int)$r['id'];
        }

        // 3. Insert transitions ---------------------------------------------------
        // Transitions model the allowed state changes.
        // Within "In Progress" states can move freely among each other.
        $inProgressSlugs = ['submitted', 'in-consideration', 'awaiting-feedback', 'deferred-till-later', 'king-approved', 'queen-approved'];

        $transitionCounter = 0;
        // Intra-"In Progress" transitions (any -> any within the group)
        foreach ($inProgressSlugs as $from) {
            foreach ($inProgressSlugs as $to) {
                if ($from === $to) {
                    continue;
                }
                $transitionCounter++;
                $fromId = $sid[$from];
                $toId = $sid[$to];
                $fromLabel = str_replace('-', ' ', ucwords($from, '-'));
                $toLabel = str_replace('-', ' ', ucwords($to, '-'));
                $tName = addslashes("{$fromLabel} → {$toLabel}");
                $tSlug = "{$from}-to-{$to}";
                $tLabel = addslashes("Move to {$toLabel}");
                $cond = addslashes('{"type":"permission","permission":"canUpdateStates"}');
                $actions = addslashes('[{"action":"log_recommendation_state","params":{}}]');
                $this->execute(
                    "INSERT INTO workflow_transitions
                        (workflow_definition_id, from_state_id, to_state_id, name, slug, label, description, priority, conditions, actions, is_automatic, trigger_type, trigger_config, created, modified)
                     VALUES
                        ({$defId}, {$fromId}, {$toId}, '{$tName}', '{$tSlug}', '{$tLabel}', NULL, {$transitionCounter}, '{$cond}', '{$actions}', 0, 'manual', NULL, NOW(), NOW())"
                );
            }
        }

        // Forward transitions from In Progress states → Need to Schedule
        foreach ($inProgressSlugs as $from) {
            $transitionCounter++;
            $fromId = $sid[$from];
            $toId = $sid['need-to-schedule'];
            $fromLabel = str_replace('-', ' ', ucwords($from, '-'));
            $tName = addslashes("{$fromLabel} → Need to Schedule");
            $tSlug = "{$from}-to-need-to-schedule";
            $cond = addslashes('{"type":"permission","permission":"canUpdateStates"}');
            $actions = addslashes('[{"action":"log_recommendation_state","params":{}}]');
            $this->execute(
                "INSERT INTO workflow_transitions
                    (workflow_definition_id, from_state_id, to_state_id, name, slug, label, description, priority, conditions, actions, is_automatic, trigger_type, trigger_config, created, modified)
                 VALUES
                    ({$defId}, {$fromId}, {$toId}, '{$tName}', '{$tSlug}', 'Move to Need to Schedule', NULL, {$transitionCounter}, '{$cond}', '{$actions}', 0, 'manual', NULL, NOW(), NOW())"
            );
        }

        // Need to Schedule → Scheduled
        $transitionCounter++;
        $this->execute(
            "INSERT INTO workflow_transitions
                (workflow_definition_id, from_state_id, to_state_id, name, slug, label, description, priority, conditions, actions, is_automatic, trigger_type, trigger_config, created, modified)
             VALUES
                ({$defId}, {$sid['need-to-schedule']}, {$sid['scheduled']}, 'Need to Schedule → Scheduled', 'need-to-schedule-to-scheduled', 'Schedule', NULL, {$transitionCounter},
                 '" . addslashes('{"type":"permission","permission":"canUpdateStates"}') . "',
                 '" . addslashes('[{"action":"log_recommendation_state","params":{}}]') . "',
                 0, 'manual', NULL, NOW(), NOW())"
        );

        // Scheduled → Announced Not Given
        $transitionCounter++;
        $this->execute(
            "INSERT INTO workflow_transitions
                (workflow_definition_id, from_state_id, to_state_id, name, slug, label, description, priority, conditions, actions, is_automatic, trigger_type, trigger_config, created, modified)
             VALUES
                ({$defId}, {$sid['scheduled']}, {$sid['announced-not-given']}, 'Scheduled → Announced Not Given', 'scheduled-to-announced-not-given', 'Mark Announced Not Given', NULL, {$transitionCounter},
                 '" . addslashes('{"type":"permission","permission":"canUpdateStates"}') . "',
                 '" . addslashes('[{"action":"log_recommendation_state","params":{}}]') . "',
                 0, 'manual', NULL, NOW(), NOW())"
        );

        // Announced Not Given → Scheduled (reschedule)
        $transitionCounter++;
        $this->execute(
            "INSERT INTO workflow_transitions
                (workflow_definition_id, from_state_id, to_state_id, name, slug, label, description, priority, conditions, actions, is_automatic, trigger_type, trigger_config, created, modified)
             VALUES
                ({$defId}, {$sid['announced-not-given']}, {$sid['scheduled']}, 'Announced Not Given → Scheduled', 'announced-not-given-to-scheduled', 'Reschedule', NULL, {$transitionCounter},
                 '" . addslashes('{"type":"permission","permission":"canUpdateStates"}') . "',
                 '" . addslashes('[{"action":"log_recommendation_state","params":{}}]') . "',
                 0, 'manual', NULL, NOW(), NOW())"
        );

        // Need to Schedule → back to In Progress states (return to consideration)
        foreach ($inProgressSlugs as $to) {
            $transitionCounter++;
            $toId = $sid[$to];
            $toLabel = str_replace('-', ' ', ucwords($to, '-'));
            $tName = addslashes("Need to Schedule → {$toLabel}");
            $tSlug = "need-to-schedule-to-{$to}";
            $tLabel = addslashes("Return to {$toLabel}");
            $cond = addslashes('{"type":"permission","permission":"canUpdateStates"}');
            $actions = addslashes('[{"action":"log_recommendation_state","params":{}}]');
            $this->execute(
                "INSERT INTO workflow_transitions
                    (workflow_definition_id, from_state_id, to_state_id, name, slug, label, description, priority, conditions, actions, is_automatic, trigger_type, trigger_config, created, modified)
                 VALUES
                    ({$defId}, {$sid['need-to-schedule']}, {$toId}, '{$tName}', '{$tSlug}', '{$tLabel}', NULL, {$transitionCounter}, '{$cond}', '{$actions}', 0, 'manual', NULL, NOW(), NOW())"
            );
        }

        // Scheduled / Announced Not Given → Given
        foreach (['scheduled', 'announced-not-given'] as $from) {
            $transitionCounter++;
            $fromId = $sid[$from];
            $fromLabel = str_replace('-', ' ', ucwords($from, '-'));
            $tName = addslashes("{$fromLabel} → Given");
            $tSlug = "{$from}-to-given";
            $cond = addslashes('{"type":"permission","permission":"canUpdateStates"}');
            $actions = addslashes('[{"action":"set_field","params":{"field":"close_reason","value":"Given"}},{"action":"log_recommendation_state","params":{}}]');
            $this->execute(
                "INSERT INTO workflow_transitions
                    (workflow_definition_id, from_state_id, to_state_id, name, slug, label, description, priority, conditions, actions, is_automatic, trigger_type, trigger_config, created, modified)
                 VALUES
                    ({$defId}, {$fromId}, {$sid['given']}, '{$tName}', '{$tSlug}', 'Mark as Given', NULL, {$transitionCounter}, '{$cond}', '{$actions}', 0, 'manual', NULL, NOW(), NOW())"
            );
        }

        // Any non-closed state → No Action
        $nonClosedSlugs = array_merge($inProgressSlugs, ['need-to-schedule', 'scheduled', 'announced-not-given']);
        foreach ($nonClosedSlugs as $from) {
            $transitionCounter++;
            $fromId = $sid[$from];
            $fromLabel = str_replace('-', ' ', ucwords($from, '-'));
            $tName = addslashes("{$fromLabel} → No Action");
            $tSlug = "{$from}-to-no-action";
            $cond = addslashes('{"type":"permission","permission":"canUpdateStates"}');
            $actions = addslashes('[{"action":"log_recommendation_state","params":{}}]');
            $this->execute(
                "INSERT INTO workflow_transitions
                    (workflow_definition_id, from_state_id, to_state_id, name, slug, label, description, priority, conditions, actions, is_automatic, trigger_type, trigger_config, created, modified)
                 VALUES
                    ({$defId}, {$fromId}, {$sid['no-action']}, '{$tName}', '{$tSlug}', 'Close - No Action', NULL, {$transitionCounter}, '{$cond}', '{$actions}', 0, 'manual', NULL, NOW(), NOW())"
            );
        }

        // 4. Visibility rules -----------------------------------------------------
        // "No Action" state requires canViewHidden permission to see
        $this->execute(
            "INSERT INTO workflow_visibility_rules
                (workflow_state_id, rule_type, target, `condition`, priority, created, modified)
             VALUES
                ({$sid['no-action']}, 'require_permission', '*', '" . addslashes('{"permission":"canViewHidden"}') . "', 10, NOW(), NOW())"
        );

        // 5. Approval gates -------------------------------------------------------
        // King Approved and Queen Approved states have approval gates
        $this->execute(
            "INSERT INTO workflow_approval_gates
                (workflow_state_id, approval_type, required_count, approver_rule, timeout_hours, timeout_transition_id, allow_delegation, created, modified)
             VALUES
                ({$sid['king-approved']}, 'threshold', 1, '" . addslashes('{"type":"permission","permission":"canApproveLevel"}') . "', NULL, NULL, 0, NOW(), NOW())"
        );
        $this->execute(
            "INSERT INTO workflow_approval_gates
                (workflow_state_id, approval_type, required_count, approver_rule, timeout_hours, timeout_transition_id, allow_delegation, created, modified)
             VALUES
                ({$sid['queen-approved']}, 'threshold', 1, '" . addslashes('{"type":"permission","permission":"canApproveLevel"}') . "', NULL, NULL, 0, NOW(), NOW())"
        );
    }

    public function down(): void
    {
        // Cascade deletes handle states, transitions, visibility rules, and approval gates
        $this->execute("DELETE FROM workflow_definitions WHERE slug = 'award-recommendations'");
    }
}
