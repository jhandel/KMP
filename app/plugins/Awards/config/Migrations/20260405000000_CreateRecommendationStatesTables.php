<?php

declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Create database tables for recommendation state machine management.
 *
 * Moves statuses, states, field rules, and transitions from YAML config
 * into normalized database tables for kingdom-level customization.
 */
class CreateRecommendationStatesTables extends BaseMigration
{
    public bool $autoId = false;

    public function change(): void
    {
        // 1. Statuses table
        $this->table('awards_recommendation_statuses', ['id' => false])
            ->addColumn('id', 'integer', [
                'autoIncrement' => true,
                'limit' => 11,
                'null' => false,
            ])
            ->addColumn('name', 'string', [
                'limit' => 255,
                'null' => false,
            ])
            ->addColumn('sort_order', 'integer', [
                'default' => 0,
                'null' => false,
            ])
            ->addColumn('created', 'datetime', [
                'null' => false,
            ])
            ->addColumn('modified', 'datetime', [
                'null' => true,
            ])
            ->addColumn('created_by', 'integer', [
                'null' => true,
            ])
            ->addColumn('modified_by', 'integer', [
                'null' => true,
            ])
            ->addColumn('deleted', 'datetime', [
                'null' => true,
            ])
            ->addPrimaryKey(['id'])
            ->addIndex(['name'], ['unique' => true, 'name' => 'idx_rec_statuses_name'])
            ->addIndex(['deleted'], ['name' => 'idx_rec_statuses_deleted'])
            ->create();

        // 2. States table
        $this->table('awards_recommendation_states', ['id' => false])
            ->addColumn('id', 'integer', [
                'autoIncrement' => true,
                'limit' => 11,
                'null' => false,
            ])
            ->addColumn('status_id', 'integer', [
                'limit' => 11,
                'null' => false,
            ])
            ->addColumn('name', 'string', [
                'limit' => 255,
                'null' => false,
            ])
            ->addColumn('sort_order', 'integer', [
                'default' => 0,
                'null' => false,
            ])
            ->addColumn('supports_gathering', 'boolean', [
                'default' => false,
                'null' => false,
            ])
            ->addColumn('is_hidden', 'boolean', [
                'default' => false,
                'null' => false,
            ])
            ->addColumn('created', 'datetime', [
                'null' => false,
            ])
            ->addColumn('modified', 'datetime', [
                'null' => true,
            ])
            ->addColumn('created_by', 'integer', [
                'null' => true,
            ])
            ->addColumn('modified_by', 'integer', [
                'null' => true,
            ])
            ->addColumn('deleted', 'datetime', [
                'null' => true,
            ])
            ->addPrimaryKey(['id'])
            ->addIndex(['name'], ['unique' => true, 'name' => 'idx_rec_states_name'])
            ->addIndex(['deleted'], ['name' => 'idx_rec_states_deleted'])
            ->addForeignKey('status_id', 'awards_recommendation_statuses', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'CASCADE',
            ])
            ->create();

        // 3. State field rules table
        $this->table('awards_recommendation_state_field_rules', ['id' => false])
            ->addColumn('id', 'integer', [
                'autoIncrement' => true,
                'limit' => 11,
                'null' => false,
            ])
            ->addColumn('state_id', 'integer', [
                'limit' => 11,
                'null' => false,
            ])
            ->addColumn('field_target', 'string', [
                'limit' => 255,
                'null' => false,
            ])
            ->addColumn('rule_type', 'string', [
                'limit' => 50,
                'null' => false,
            ])
            ->addColumn('rule_value', 'string', [
                'limit' => 255,
                'null' => true,
            ])
            ->addColumn('created', 'datetime', [
                'null' => false,
            ])
            ->addColumn('modified', 'datetime', [
                'null' => true,
            ])
            ->addColumn('created_by', 'integer', [
                'null' => true,
            ])
            ->addColumn('modified_by', 'integer', [
                'null' => true,
            ])
            ->addPrimaryKey(['id'])
            ->addIndex(['state_id', 'field_target', 'rule_type'], [
                'unique' => true,
                'name' => 'idx_state_field_rule_unique',
            ])
            ->addForeignKey('state_id', 'awards_recommendation_states', 'id', [
                'delete' => 'CASCADE',
                'update' => 'CASCADE',
            ])
            ->create();

        // 4. State transitions table
        $this->table('awards_recommendation_state_transitions', ['id' => false])
            ->addColumn('id', 'integer', [
                'autoIncrement' => true,
                'limit' => 11,
                'null' => false,
            ])
            ->addColumn('from_state_id', 'integer', [
                'limit' => 11,
                'null' => false,
            ])
            ->addColumn('to_state_id', 'integer', [
                'limit' => 11,
                'null' => false,
            ])
            ->addColumn('created', 'datetime', [
                'null' => false,
            ])
            ->addColumn('modified', 'datetime', [
                'null' => true,
            ])
            ->addColumn('created_by', 'integer', [
                'null' => true,
            ])
            ->addColumn('modified_by', 'integer', [
                'null' => true,
            ])
            ->addPrimaryKey(['id'])
            ->addIndex(['from_state_id', 'to_state_id'], [
                'unique' => true,
                'name' => 'idx_state_transition_unique',
            ])
            ->addForeignKey('from_state_id', 'awards_recommendation_states', 'id', [
                'delete' => 'CASCADE',
                'update' => 'CASCADE',
            ])
            ->addForeignKey('to_state_id', 'awards_recommendation_states', 'id', [
                'delete' => 'CASCADE',
                'update' => 'CASCADE',
            ])
            ->create();

        // Seed data
        $this->seedData();
    }

    private function seedData(): void
    {
        $now = date('Y-m-d H:i:s');

        // Statuses
        $statuses = [
            ['name' => 'In Progress', 'sort_order' => 1],
            ['name' => 'Scheduling', 'sort_order' => 2],
            ['name' => 'To Give', 'sort_order' => 3],
            ['name' => 'Closed', 'sort_order' => 4],
        ];

        $statusTable = $this->table('awards_recommendation_statuses');
        foreach ($statuses as $status) {
            $statusTable->insert([
                'name' => $status['name'],
                'sort_order' => $status['sort_order'],
                'created' => $now,
            ]);
        }
        $statusTable->saveData();

        // States - need status IDs from the just-inserted rows
        // We use the sort_order to reliably map status name -> id
        $stateConfigs = [
            // Status sort_order => states
            1 => [ // In Progress
                ['name' => 'Submitted', 'sort_order' => 1, 'supports_gathering' => false, 'is_hidden' => false],
                ['name' => 'In Consideration', 'sort_order' => 2, 'supports_gathering' => false, 'is_hidden' => false],
                ['name' => 'Awaiting Feedback', 'sort_order' => 3, 'supports_gathering' => false, 'is_hidden' => false],
                ['name' => 'Deferred till Later', 'sort_order' => 4, 'supports_gathering' => false, 'is_hidden' => false],
                ['name' => 'King Approved', 'sort_order' => 5, 'supports_gathering' => false, 'is_hidden' => false],
                ['name' => 'Queen Approved', 'sort_order' => 6, 'supports_gathering' => false, 'is_hidden' => false],
            ],
            2 => [ // Scheduling
                ['name' => 'Need to Schedule', 'sort_order' => 1, 'supports_gathering' => true, 'is_hidden' => false],
            ],
            3 => [ // To Give
                ['name' => 'Scheduled', 'sort_order' => 1, 'supports_gathering' => true, 'is_hidden' => false],
                ['name' => 'Announced Not Given', 'sort_order' => 2, 'supports_gathering' => false, 'is_hidden' => false],
            ],
            4 => [ // Closed
                ['name' => 'Given', 'sort_order' => 1, 'supports_gathering' => true, 'is_hidden' => false],
                ['name' => 'No Action', 'sort_order' => 2, 'supports_gathering' => false, 'is_hidden' => true],
            ],
        ];

        // Retrieve status IDs by querying the table
        $statusRows = $this->fetchAll(
            'SELECT id, sort_order FROM awards_recommendation_statuses ORDER BY sort_order'
        );
        $statusIdMap = [];
        foreach ($statusRows as $row) {
            $statusIdMap[(int)$row['sort_order']] = (int)$row['id'];
        }

        $stateTable = $this->table('awards_recommendation_states');
        foreach ($stateConfigs as $statusSortOrder => $states) {
            $statusId = $statusIdMap[$statusSortOrder];
            foreach ($states as $state) {
                $stateTable->insert([
                    'status_id' => $statusId,
                    'name' => $state['name'],
                    'sort_order' => $state['sort_order'],
                    'supports_gathering' => $state['supports_gathering'],
                    'is_hidden' => $state['is_hidden'],
                    'created' => $now,
                ]);
            }
        }
        $stateTable->saveData();

        // Field rules
        $stateRows = $this->fetchAll(
            'SELECT id, name FROM awards_recommendation_states ORDER BY id'
        );
        $stateIdMap = [];
        foreach ($stateRows as $row) {
            $stateIdMap[$row['name']] = (int)$row['id'];
        }

        $fieldRulesConfig = [
            'Need to Schedule' => [
                ['field_target' => 'planToGiveBlockTarget', 'rule_type' => 'Visible', 'rule_value' => null],
                ['field_target' => 'domainTarget', 'rule_type' => 'Disabled', 'rule_value' => null],
                ['field_target' => 'awardTarget', 'rule_type' => 'Disabled', 'rule_value' => null],
                ['field_target' => 'specialtyTarget', 'rule_type' => 'Disabled', 'rule_value' => null],
                ['field_target' => 'scaMemberTarget', 'rule_type' => 'Disabled', 'rule_value' => null],
                ['field_target' => 'branchTarget', 'rule_type' => 'Disabled', 'rule_value' => null],
            ],
            'Scheduled' => [
                ['field_target' => 'planToGiveEventTarget', 'rule_type' => 'Required', 'rule_value' => null],
                ['field_target' => 'planToGiveBlockTarget', 'rule_type' => 'Visible', 'rule_value' => null],
                ['field_target' => 'domainTarget', 'rule_type' => 'Disabled', 'rule_value' => null],
                ['field_target' => 'awardTarget', 'rule_type' => 'Disabled', 'rule_value' => null],
                ['field_target' => 'specialtyTarget', 'rule_type' => 'Disabled', 'rule_value' => null],
                ['field_target' => 'scaMemberTarget', 'rule_type' => 'Disabled', 'rule_value' => null],
                ['field_target' => 'branchTarget', 'rule_type' => 'Disabled', 'rule_value' => null],
            ],
            'Given' => [
                ['field_target' => 'planToGiveEventTarget', 'rule_type' => 'Required', 'rule_value' => null],
                ['field_target' => 'givenDateTarget', 'rule_type' => 'Required', 'rule_value' => null],
                ['field_target' => 'planToGiveBlockTarget', 'rule_type' => 'Visible', 'rule_value' => null],
                ['field_target' => 'givenBlockTarget', 'rule_type' => 'Visible', 'rule_value' => null],
                ['field_target' => 'domainTarget', 'rule_type' => 'Disabled', 'rule_value' => null],
                ['field_target' => 'awardTarget', 'rule_type' => 'Disabled', 'rule_value' => null],
                ['field_target' => 'specialtyTarget', 'rule_type' => 'Disabled', 'rule_value' => null],
                ['field_target' => 'scaMemberTarget', 'rule_type' => 'Disabled', 'rule_value' => null],
                ['field_target' => 'branchTarget', 'rule_type' => 'Disabled', 'rule_value' => null],
                ['field_target' => 'close_reason', 'rule_type' => 'Set', 'rule_value' => 'Given'],
            ],
            'No Action' => [
                ['field_target' => 'closeReasonTarget', 'rule_type' => 'Required', 'rule_value' => null],
                ['field_target' => 'closeReasonBlockTarget', 'rule_type' => 'Visible', 'rule_value' => null],
                ['field_target' => 'closeReasonTarget', 'rule_type' => 'Visible', 'rule_value' => null],
                ['field_target' => 'domainTarget', 'rule_type' => 'Disabled', 'rule_value' => null],
                ['field_target' => 'awardTarget', 'rule_type' => 'Disabled', 'rule_value' => null],
                ['field_target' => 'specialtyTarget', 'rule_type' => 'Disabled', 'rule_value' => null],
                ['field_target' => 'scaMemberTarget', 'rule_type' => 'Disabled', 'rule_value' => null],
                ['field_target' => 'branchTarget', 'rule_type' => 'Disabled', 'rule_value' => null],
                ['field_target' => 'courtAvailabilityTarget', 'rule_type' => 'Disabled', 'rule_value' => null],
                ['field_target' => 'callIntoCourtTarget', 'rule_type' => 'Disabled', 'rule_value' => null],
            ],
        ];

        $rulesTable = $this->table('awards_recommendation_state_field_rules');
        foreach ($fieldRulesConfig as $stateName => $rules) {
            if (!isset($stateIdMap[$stateName])) {
                continue;
            }
            $stateId = $stateIdMap[$stateName];
            foreach ($rules as $rule) {
                $rulesTable->insert([
                    'state_id' => $stateId,
                    'field_target' => $rule['field_target'],
                    'rule_type' => $rule['rule_type'],
                    'rule_value' => $rule['rule_value'],
                    'created' => $now,
                ]);
            }
        }
        $rulesTable->saveData();

        // Transitions: seed all-to-all (every state can transition to every other state)
        $allStateIds = array_values($stateIdMap);
        $transitionsTable = $this->table('awards_recommendation_state_transitions');
        foreach ($allStateIds as $fromId) {
            foreach ($allStateIds as $toId) {
                if ($fromId !== $toId) {
                    $transitionsTable->insert([
                        'from_state_id' => $fromId,
                        'to_state_id' => $toId,
                        'created' => $now,
                    ]);
                }
            }
        }
        $transitionsTable->saveData();
    }
}
