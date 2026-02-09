<?php

declare(strict_types=1);

use Migrations\AbstractMigration;

/**
 * Seed default workflow definitions and their initial published versions.
 *
 * Creates the warrant-roster approval workflow.
 */
class SeedWorkflowDefinitions extends AbstractMigration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $name = 'Warrant Roster Approval';
        $slug = 'warrant-roster';
        $desc = 'Default workflow for warrant roster approval process';
        $triggerConfig = addslashes(json_encode(['event' => 'Warrants.RosterCreated']));
        $entityType = 'Warrants';
        $definition = addslashes(json_encode($this->getWarrantRosterDefinition()));

        $this->execute(
            "INSERT INTO workflow_definitions (name, slug, description, trigger_type, trigger_config, entity_type, is_active, current_version_id, created_by, modified_by, created, modified) " .
            "VALUES ('{$name}', '{$slug}', '{$desc}', 'event', '{$triggerConfig}', '{$entityType}', 1, NULL, 1, 1, '{$now}', '{$now}')"
        );

        $this->execute(
            "INSERT INTO workflow_versions (workflow_definition_id, version_number, definition, canvas_layout, status, published_at, published_by, created_by, created, modified) " .
            "VALUES ((SELECT id FROM workflow_definitions WHERE slug = '{$slug}'), 1, '{$definition}', '{}', 'published', '{$now}', 1, 1, '{$now}', '{$now}')"
        );

        $this->execute(
            "UPDATE workflow_definitions SET current_version_id = (" .
            "SELECT wv.id FROM workflow_versions wv " .
            "INNER JOIN workflow_definitions wd ON wv.workflow_definition_id = wd.id " .
            "WHERE wd.slug = '{$slug}' AND wv.version_number = 1" .
            ") WHERE slug = '{$slug}'"
        );
    }

    public function down(): void
    {
        $this->execute("UPDATE workflow_definitions SET current_version_id = NULL WHERE slug = 'warrant-roster'");
        $this->execute("DELETE FROM workflow_versions WHERE workflow_definition_id IN (SELECT id FROM workflow_definitions WHERE slug = 'warrant-roster')");
        $this->execute("DELETE FROM workflow_definitions WHERE slug = 'warrant-roster'");
    }

    private function getWarrantRosterDefinition(): array
    {
        return [
            'nodes' => [
                'trigger-1' => [
                    'type' => 'trigger',
                    'label' => 'Warrant Roster Created',
                    'config' => [
                        'event' => 'Warrants.RosterCreated',
                        'entityIdField' => 'rosterId',
                        'inputMapping' => [
                            'rosterId' => '$.event.rosterId',
                            'rosterName' => '$.event.rosterName',
                            'approvalsRequired' => '$.event.approvalsRequired',
                        ],
                    ],
                    'position' => ['x' => 50, 'y' => 200],
                    'outputs' => [
                        ['port' => 'next', 'target' => 'approval-1'],
                    ],
                ],
                'approval-1' => [
                    'type' => 'approval',
                    'label' => 'Warrant Roster Approval',
                    'config' => [
                        'approverType' => 'policy',
                        'permission' => 'Can Approve Warrant Rosters',
                        'policyClass' => 'App\\Policy\\WarrantRosterPolicy',
                        'policyAction' => 'canApprove',
                        'entityTable' => 'WarrantRosters',
                        'entityIdKey' => 'trigger.rosterId',
                        'requiredCount' => ['type' => 'app_setting', 'key' => 'Warrant.RosterApprovalsRequired'],
                        'parallel' => true,
                        'deadline' => '14d',
                        'allowComments' => true,
                    ],
                    'position' => ['x' => 350, 'y' => 200],
                    'outputs' => [
                        ['port' => 'approved', 'target' => 'action-activate'],
                        ['port' => 'rejected', 'target' => 'action-decline'],
                    ],
                ],
                'action-activate' => [
                    'type' => 'action',
                    'label' => 'Activate Warrants',
                    'config' => [
                        'action' => 'Warrants.ActivateWarrants',
                        'params' => ['rosterId' => '$.trigger.rosterId'],
                    ],
                    'position' => ['x' => 650, 'y' => 100],
                    'outputs' => [
                        ['port' => 'next', 'target' => 'action-notify-approved'],
                    ],
                ],
                'action-notify-approved' => [
                    'type' => 'action',
                    'label' => 'Notify Warrant Issued',
                    'config' => [
                        'action' => 'Warrants.NotifyWarrantIssued',
                        'params' => [
                            'rosterId' => '$.trigger.rosterId',
                        ],
                    ],
                    'position' => ['x' => 950, 'y' => 100],
                    'outputs' => [
                        ['port' => 'next', 'target' => 'end-1'],
                    ],
                ],
                'action-decline' => [
                    'type' => 'action',
                    'label' => 'Decline Warrants',
                    'config' => [
                        'action' => 'Warrants.DeclineRoster',
                        'params' => [
                            'rosterId' => '$.trigger.rosterId',
                            'reason' => '$.approval-1.rejectionComment',
                        ],
                    ],
                    'position' => ['x' => 650, 'y' => 300],
                    'outputs' => [
                        ['port' => 'next', 'target' => 'end-1'],
                    ],
                ],
                'end-1' => [
                    'type' => 'end',
                    'label' => 'Complete',
                    'config' => (object)[],
                    'position' => ['x' => 1200, 'y' => 200],
                    'outputs' => [],
                ],
            ],
        ];
    }
}
