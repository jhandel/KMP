<?php

declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Add "Linked - Closed" system state under "Closed" status.
 *
 * When a group head moves to a Closed status (Given, No Action, etc.),
 * its linked children should also appear closed in reports. This state
 * mirrors "Linked" but sits under the "Closed" status.
 */
class AddLinkedClosedState extends BaseMigration
{
    public bool $autoId = false;

    public function change(): void
    {
        $now = date('Y-m-d H:i:s');

        // Find the "Closed" status
        $statusRows = $this->fetchAll(
            "SELECT id FROM awards_recommendation_statuses WHERE name = 'Closed' LIMIT 1"
        );
        if (empty($statusRows)) {
            return;
        }
        $closedStatusId = (int)$statusRows[0]['id'];

        // Insert the "Linked - Closed" state
        $this->table('awards_recommendation_states')->insert([
            'status_id' => $closedStatusId,
            'name' => 'Linked - Closed',
            'sort_order' => 99,
            'supports_gathering' => false,
            'is_hidden' => true,
            'is_system' => true,
            'created' => $now,
        ])->saveData();

        // Get the newly inserted state ID
        $linkedClosedRows = $this->fetchAll(
            "SELECT id FROM awards_recommendation_states WHERE name = 'Linked - Closed' LIMIT 1"
        );
        if (empty($linkedClosedRows)) {
            return;
        }
        $linkedClosedStateId = (int)$linkedClosedRows[0]['id'];

        // Add field rules: all editable fields set to Disabled (same as Linked)
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
                'state_id' => $linkedClosedStateId,
                'field_target' => $field,
                'rule_type' => 'Disabled',
                'rule_value' => null,
                'created' => $now,
            ]);
        }
        $rulesTable->saveData();

        // Add transitions: all existing states <-> Linked - Closed (bidirectional)
        $allStateRows = $this->fetchAll(
            "SELECT id FROM awards_recommendation_states WHERE id != {$linkedClosedStateId}"
        );

        $transitionsTable = $this->table('awards_recommendation_state_transitions');
        foreach ($allStateRows as $row) {
            $otherStateId = (int)$row['id'];
            $transitionsTable->insert([
                'from_state_id' => $otherStateId,
                'to_state_id' => $linkedClosedStateId,
                'created' => $now,
            ]);
            $transitionsTable->insert([
                'from_state_id' => $linkedClosedStateId,
                'to_state_id' => $otherStateId,
                'created' => $now,
            ]);
        }
        $transitionsTable->saveData();
    }
}
