<?php

declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Add recommendation grouping support and system state protection.
 *
 * - Adds recommendation_group_id (self-referential FK) to awards_recommendations
 *   for grouping multiple recommendations into a single working unit.
 * - Adds is_system flag to awards_recommendation_states to protect system states
 *   from user edit/delete.
 * - Seeds the "Linked" system state with field rules and transitions.
 */
class AddRecommendationGrouping extends BaseMigration
{
    public bool $autoId = false;

    public function change(): void
    {
        // 1. Add recommendation_group_id to awards_recommendations
        $this->table('awards_recommendations')
            ->addColumn('recommendation_group_id', 'integer', [
                'null' => true,
                'default' => null,
                'limit' => 11,
                'after' => 'id',
            ])
            ->addIndex(['recommendation_group_id'], [
                'name' => 'idx_rec_group_id',
            ])
            ->addForeignKey('recommendation_group_id', 'awards_recommendations', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'CASCADE',
                'constraint' => 'fk_rec_group_id',
            ])
            ->update();

        // 2. Add is_system flag to awards_recommendation_states
        $this->table('awards_recommendation_states')
            ->addColumn('is_system', 'boolean', [
                'default' => false,
                'null' => false,
                'after' => 'is_hidden',
            ])
            ->update();

        // 3. Seed the "Linked" state
        $this->seedLinkedState();
    }

    private function seedLinkedState(): void
    {
        $now = date('Y-m-d H:i:s');

        // Find the "In Progress" status ID
        $statusRows = $this->fetchAll(
            "SELECT id FROM awards_recommendation_statuses WHERE name = 'In Progress' LIMIT 1"
        );
        if (empty($statusRows)) {
            return;
        }
        $inProgressStatusId = (int)$statusRows[0]['id'];

        // Insert the "Linked" state
        $this->table('awards_recommendation_states')->insert([
            'status_id' => $inProgressStatusId,
            'name' => 'Linked',
            'sort_order' => 99,
            'supports_gathering' => false,
            'is_hidden' => true,
            'is_system' => true,
            'created' => $now,
        ])->saveData();

        // Get the newly inserted state ID
        $linkedRows = $this->fetchAll(
            "SELECT id FROM awards_recommendation_states WHERE name = 'Linked' LIMIT 1"
        );
        if (empty($linkedRows)) {
            return;
        }
        $linkedStateId = (int)$linkedRows[0]['id'];

        // Add field rules: all editable fields set to Disabled
        $disabledFields = [
            'domainTarget',
            'awardTarget',
            'specialtyTarget',
            'scaMemberTarget',
            'branchTarget',
            'courtAvailabilityTarget',
            'callIntoCourtTarget',
            'reasonTarget',
            'contactEmailTarget',
            'contactNumberTarget',
            'personToNotifyTarget',
        ];

        $rulesTable = $this->table('awards_recommendation_state_field_rules');
        foreach ($disabledFields as $field) {
            $rulesTable->insert([
                'state_id' => $linkedStateId,
                'field_target' => $field,
                'rule_type' => 'Disabled',
                'rule_value' => null,
                'created' => $now,
            ]);
        }
        $rulesTable->saveData();

        // Add transitions: all existing states <-> Linked (bidirectional)
        $allStateRows = $this->fetchAll(
            "SELECT id FROM awards_recommendation_states WHERE id != {$linkedStateId}"
        );

        $transitionsTable = $this->table('awards_recommendation_state_transitions');
        foreach ($allStateRows as $row) {
            $otherStateId = (int)$row['id'];
            // Other → Linked
            $transitionsTable->insert([
                'from_state_id' => $otherStateId,
                'to_state_id' => $linkedStateId,
                'created' => $now,
            ]);
            // Linked → Other
            $transitionsTable->insert([
                'from_state_id' => $linkedStateId,
                'to_state_id' => $otherStateId,
                'created' => $now,
            ]);
        }
        $transitionsTable->saveData();
    }
}
