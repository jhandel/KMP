<?php

declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Seeds the Award Recommendations workflow definition.
 *
 * Maps the 11-state recommendation lifecycle from the Awards plugin
 * into the workflow engine tables.
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

        $rows = $this->fetchAll("SELECT id FROM workflow_definitions WHERE slug = 'award-recommendations' AND version = 1 LIMIT 1");
        $defId = (int)$rows[0]['id'];

        // 2. Insert states
        // [name, slug, label, state_type, status_category, metadata_json, pos_x, pos_y, on_enter_actions_json]
        $states = [
            ['Submitted', 'submitted', 'Submitted', 'initial', 'In Progress', '{}', 100, 100, null],
            ['In Consideration', 'in-consideration', 'In Consideration', 'intermediate', 'In Progress', '{}', 100, 200, null],
            ['Awaiting Feedback', 'awaiting-feedback', 'Awaiting Feedback', 'intermediate', 'In Progress', '{}', 100, 300, null],
            ['Deferred till Later', 'deferred', 'Deferred till Later', 'intermediate', 'In Progress', '{}', 100, 400, null],
            ['King Approved', 'king-approved', 'King Approved', 'intermediate', 'In Progress', '{}', 100, 500, null],
            ['Queen Approved', 'queen-approved', 'Queen Approved', 'intermediate', 'In Progress', '{}', 100, 600, null],
            ['Need to Schedule', 'need-to-schedule', 'Need to Schedule', 'intermediate', 'Scheduling',
                '{"visible":["planToGiveBlock"],"disabled":["domain","award","member","branch"]}', 350, 100, null],
            ['Scheduled', 'scheduled', 'Scheduled', 'intermediate', 'To Give',
                '{"required":["planToGiveEvent"],"visible":["planToGiveBlock"],"disabled":["domain","award","member","branch"]}', 600, 100, null],
            ['Announced Not Given', 'announced-not-given', 'Announced Not Given', 'intermediate', 'To Give', '{}', 600, 200, null],
            ['Given', 'given', 'Given', 'final', 'Closed',
                '{"required":["planToGiveEvent","givenDate"],"visible":["planToGiveBlock","givenBlock"],"disabled":["domain","award","member","branch"],"set":{"close_reason":"Given"}}',
                850, 100,
                '[{"action":"set_field","params":{"field":"close_reason","value":"Given"}},{"action":"log_recommendation_state","params":{}}]'],
            ['No Action', 'no-action', 'No Action', 'final', 'Closed',
                '{"required":["closeReason"],"visible":["closeReasonBlock","closeReasonTarget"],"disabled":["domain","award","member","branch","courtAvailability","callIntoCourt"]}',
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

        // Build state ID map
        $stateRows = $this->fetchAll("SELECT id, slug FROM workflow_states WHERE workflow_definition_id = {$defId}");
        $sid = [];
        foreach ($stateRows as $r) {
            $sid[$r['slug']] = (int)$r['id'];
        }

        // 3. Insert transitions
        $inProgressSlugs = ['submitted', 'in-consideration', 'awaiting-feedback', 'deferred', 'king-approved', 'queen-approved'];

        // Free movement within "In Progress" states
        $transitionOrder = 0;
        foreach ($inProgressSlugs as $from) {
            foreach ($inProgressSlugs as $to) {
                if ($from === $to) {
                    continue;
                }
                $tName = "to-{$to}";
                $tLabel = addslashes("Move to " . str_replace('-', ' ', ucfirst($to)));
                $this->execute(
                    "INSERT INTO workflow_transitions
                        (workflow_definition_id, from_state_id, to_state_id, name, slug, label, priority, trigger_type, is_automatic, created, modified)
                     VALUES
                        ({$defId}, {$sid[$from]}, {$sid[$to]}, '{$tName}', '{$tName}', '{$tLabel}', {$transitionOrder}, 'manual', 0, NOW(), NOW())"
                );
            }
        }

        // In Progress → Need to Schedule
        foreach ($inProgressSlugs as $from) {
            $this->execute(
                "INSERT INTO workflow_transitions
                    (workflow_definition_id, from_state_id, to_state_id, name, slug, label, priority, trigger_type, is_automatic, created, modified)
                 VALUES
                    ({$defId}, {$sid[$from]}, {$sid['need-to-schedule']}, 'schedule', 'schedule', 'Schedule', 0, 'manual', 0, NOW(), NOW())"
            );
        }

        // Need to Schedule → Scheduled
        $this->execute(
            "INSERT INTO workflow_transitions
                (workflow_definition_id, from_state_id, to_state_id, name, slug, label, priority, trigger_type, is_automatic, created, modified)
             VALUES
                ({$defId}, {$sid['need-to-schedule']}, {$sid['scheduled']}, 'mark-scheduled', 'mark-scheduled', 'Mark Scheduled', 0, 'manual', 0, NOW(), NOW())"
        );

        // Scheduled → Given, Announced Not Given, back to Need to Schedule
        $this->execute("INSERT INTO workflow_transitions (workflow_definition_id, from_state_id, to_state_id, name, slug, label, priority, trigger_type, is_automatic, created, modified) VALUES ({$defId}, {$sid['scheduled']}, {$sid['given']}, 'mark-given', 'mark-given', 'Mark as Given', 0, 'manual', 0, NOW(), NOW())");
        $this->execute("INSERT INTO workflow_transitions (workflow_definition_id, from_state_id, to_state_id, name, slug, label, priority, trigger_type, is_automatic, created, modified) VALUES ({$defId}, {$sid['scheduled']}, {$sid['announced-not-given']}, 'announce-not-given', 'announce-not-given', 'Announced Not Given', 0, 'manual', 0, NOW(), NOW())");
        $this->execute("INSERT INTO workflow_transitions (workflow_definition_id, from_state_id, to_state_id, name, slug, label, priority, trigger_type, is_automatic, created, modified) VALUES ({$defId}, {$sid['scheduled']}, {$sid['need-to-schedule']}, 'reschedule', 'reschedule', 'Reschedule', 0, 'manual', 0, NOW(), NOW())");

        // Announced Not Given → Scheduled, Given
        $this->execute("INSERT INTO workflow_transitions (workflow_definition_id, from_state_id, to_state_id, name, slug, label, priority, trigger_type, is_automatic, created, modified) VALUES ({$defId}, {$sid['announced-not-given']}, {$sid['scheduled']}, 'mark-scheduled', 'mark-scheduled', 'Reschedule', 0, 'manual', 0, NOW(), NOW())");
        $this->execute("INSERT INTO workflow_transitions (workflow_definition_id, from_state_id, to_state_id, name, slug, label, priority, trigger_type, is_automatic, created, modified) VALUES ({$defId}, {$sid['announced-not-given']}, {$sid['given']}, 'mark-given', 'mark-given', 'Mark as Given', 0, 'manual', 0, NOW(), NOW())");

        // Any non-final → No Action
        $nonFinalSlugs = array_merge($inProgressSlugs, ['need-to-schedule', 'scheduled', 'announced-not-given']);
        foreach ($nonFinalSlugs as $from) {
            $this->execute(
                "INSERT INTO workflow_transitions
                    (workflow_definition_id, from_state_id, to_state_id, name, slug, label, priority, trigger_type, is_automatic, created, modified)
                 VALUES
                    ({$defId}, {$sid[$from]}, {$sid['no-action']}, 'close-no-action', 'close-no-action', 'Close - No Action', 10, 'manual', 0, NOW(), NOW())"
            );
        }
    }

    public function down(): void
    {
        $rows = $this->fetchAll("SELECT id FROM workflow_definitions WHERE slug = 'award-recommendations' LIMIT 1");
        if (!empty($rows)) {
            $defId = (int)$rows[0]['id'];
            $this->execute("DELETE FROM workflow_transitions WHERE workflow_definition_id = {$defId}");
            $this->execute("DELETE FROM workflow_states WHERE workflow_definition_id = {$defId}");
            $this->execute("DELETE FROM workflow_definitions WHERE id = {$defId}");
        }
    }
}
