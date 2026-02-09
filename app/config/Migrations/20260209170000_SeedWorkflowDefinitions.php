<?php

declare(strict_types=1);

use Migrations\AbstractMigration;

/**
 * Seed default workflow definitions and their initial published versions.
 *
 * Creates officer-hire, warrant-roster, and direct-warrant workflows.
 */
class SeedWorkflowDefinitions extends AbstractMigration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $workflows = [
            [
                'name' => 'Officer Hiring',
                'slug' => 'officer-hire',
                'description' => 'Default workflow for officer hiring process',
                'trigger_config' => json_encode(['event' => 'Officers.HireRequested']),
                'entity_type' => 'Officers.Officers',
                'definition' => json_encode($this->getOfficerHireDefinition()),
            ],
            [
                'name' => 'Warrant Roster Approval',
                'slug' => 'warrant-roster',
                'description' => 'Default workflow for warrant roster approval process',
                'trigger_config' => json_encode(['event' => 'Warrants.RosterCreated']),
                'entity_type' => 'Warrants',
                'definition' => json_encode($this->getWarrantRosterDefinition()),
            ],
            [
                'name' => 'Direct Warrant',
                'slug' => 'direct-warrant',
                'description' => 'Creates a warrant directly upon officer hire approval without a roster',
                'trigger_config' => json_encode(['event' => 'Officers.WarrantRequired']),
                'entity_type' => 'Officers.Officers',
                'definition' => json_encode($this->getDirectWarrantDefinition()),
            ],
        ];

        foreach ($workflows as $wf) {
            $name = addslashes($wf['name']);
            $slug = addslashes($wf['slug']);
            $desc = addslashes($wf['description']);
            $triggerConfig = addslashes($wf['trigger_config']);
            $entityType = addslashes($wf['entity_type']);
            $definition = addslashes($wf['definition']);

            $isActive = ($slug === 'warrant-roster') ? 1 : 0;

            $this->execute(
                "INSERT INTO workflow_definitions (name, slug, description, trigger_type, trigger_config, entity_type, is_active, current_version_id, created_by, modified_by, created, modified) " .
                "VALUES ('{$name}', '{$slug}', '{$desc}', 'event', '{$triggerConfig}', '{$entityType}', {$isActive}, NULL, 1, 1, '{$now}', '{$now}')"
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
    }

    public function down(): void
    {
        $this->execute("UPDATE workflow_definitions SET current_version_id = NULL WHERE slug IN ('officer-hire', 'warrant-roster', 'direct-warrant')");
        $this->execute("DELETE FROM workflow_versions WHERE workflow_definition_id IN (SELECT id FROM workflow_definitions WHERE slug IN ('officer-hire', 'warrant-roster', 'direct-warrant'))");
        $this->execute("DELETE FROM workflow_definitions WHERE slug IN ('officer-hire', 'warrant-roster', 'direct-warrant')");
    }

    private function getOfficerHireDefinition(): array
    {
        return [
            'nodes' => [
                'trigger-1' => [
                    'type' => 'trigger',
                    'label' => 'Officer Hire Requested',
                    'config' => [
                        'event' => 'Officers.HireRequested',
                        'inputMapping' => [
                            'memberId' => '$.event.memberId',
                            'officeId' => '$.event.officeId',
                            'branchId' => '$.event.branchId',
                            'startOn' => '$.event.startOn',
                            'expiresOn' => '$.event.expiresOn',
                            'deputyToId' => '$.event.deputyToId',
                        ],
                    ],
                    'position' => ['x' => 50, 'y' => 200],
                    'outputs' => [
                        ['port' => 'next', 'target' => 'condition-warrant'],
                    ],
                ],
                'condition-warrant' => [
                    'type' => 'condition',
                    'label' => 'Office Requires Warrant?',
                    'config' => [
                        'evaluator' => 'Officers.OfficeRequiresWarrant',
                        'params' => ['officeId' => '$.trigger.officeId'],
                    ],
                    'position' => ['x' => 300, 'y' => 200],
                    'outputs' => [
                        ['port' => 'true', 'target' => 'action-create-officer-warrant', 'label' => 'Yes'],
                        ['port' => 'false', 'target' => 'action-create-officer-no-warrant', 'label' => 'No'],
                    ],
                ],
                'action-create-officer-warrant' => [
                    'type' => 'action',
                    'label' => 'Create Officer (Warrant Path)',
                    'config' => [
                        'action' => 'Officers.CreateOfficerRecord',
                        'params' => [
                            'memberId' => '$.trigger.memberId',
                            'officeId' => '$.trigger.officeId',
                            'branchId' => '$.trigger.branchId',
                            'startOn' => '$.trigger.startOn',
                            'expiresOn' => '$.trigger.expiresOn',
                        ],
                    ],
                    'position' => ['x' => 550, 'y' => 100],
                    'outputs' => [
                        ['port' => 'next', 'target' => 'action-request-warrant'],
                    ],
                ],
                'action-create-officer-no-warrant' => [
                    'type' => 'action',
                    'label' => 'Create Officer (No Warrant)',
                    'config' => [
                        'action' => 'Officers.CreateOfficerRecord',
                        'params' => [
                            'memberId' => '$.trigger.memberId',
                            'officeId' => '$.trigger.officeId',
                            'branchId' => '$.trigger.branchId',
                            'startOn' => '$.trigger.startOn',
                            'expiresOn' => '$.trigger.expiresOn',
                        ],
                    ],
                    'position' => ['x' => 550, 'y' => 300],
                    'outputs' => [
                        ['port' => 'next', 'target' => 'action-notify'],
                    ],
                ],
                'action-request-warrant' => [
                    'type' => 'action',
                    'label' => 'Request Warrant',
                    'config' => [
                        'action' => 'Warrants.CreateWarrantRoster',
                        'params' => [
                            'memberId' => '$.trigger.memberId',
                            'officeId' => '$.trigger.officeId',
                            'branchId' => '$.trigger.branchId',
                            'officerId' => '$.action-create-officer-warrant.officerId',
                        ],
                    ],
                    'position' => ['x' => 800, 'y' => 100],
                    'outputs' => [
                        ['port' => 'next', 'target' => 'action-notify'],
                    ],
                ],
                'action-notify' => [
                    'type' => 'action',
                    'label' => 'Send Hire Notification',
                    'config' => [
                        'action' => 'Officers.SendHireNotification',
                        'params' => [
                            'officerId' => '$.action-create-officer-warrant.officerId',
                        ],
                    ],
                    'position' => ['x' => 1050, 'y' => 200],
                    'outputs' => [
                        ['port' => 'next', 'target' => 'end-1'],
                    ],
                ],
                'end-1' => [
                    'type' => 'end',
                    'label' => 'Complete',
                    'config' => (object)[],
                    'position' => ['x' => 1300, 'y' => 200],
                    'outputs' => [],
                ],
            ],
        ];
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
                    'label' => 'Notify Approved',
                    'config' => [
                        'action' => 'Core.SendEmail',
                        'params' => [
                            'template' => 'warrant_approved',
                            'subject' => 'Warrant Roster Approved',
                            'toEntityField' => '$.trigger.rosterId',
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
                        ['port' => 'next', 'target' => 'action-notify-declined'],
                    ],
                ],
                'action-notify-declined' => [
                    'type' => 'action',
                    'label' => 'Notify Declined',
                    'config' => [
                        'action' => 'Core.SendEmail',
                        'params' => [
                            'template' => 'warrant_declined',
                            'subject' => 'Warrant Roster Declined',
                        ],
                    ],
                    'position' => ['x' => 950, 'y' => 300],
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

    private function getDirectWarrantDefinition(): array
    {
        return [
            'nodes' => [
                'trigger-1' => [
                    'type' => 'trigger',
                    'label' => 'Officer Warrant Required',
                    'config' => [
                        'event' => 'Officers.WarrantRequired',
                        'inputMapping' => [
                            'officerId' => '$.event.officerId',
                            'memberId' => '$.event.memberId',
                            'officeId' => '$.event.officeId',
                        ],
                    ],
                    'position' => ['x' => 50, 'y' => 200],
                    'outputs' => [
                        ['port' => 'next', 'target' => 'approval-1'],
                    ],
                ],
                'approval-1' => [
                    'type' => 'approval',
                    'label' => 'Direct Warrant Approval',
                    'config' => [
                        'approverType' => 'permission',
                        'permission' => 'canApproveDirectWarrants',
                        'requiredCount' => 1,
                        'parallel' => false,
                        'deadline' => '7d',
                    ],
                    'position' => ['x' => 350, 'y' => 200],
                    'outputs' => [
                        ['port' => 'approved', 'target' => 'action-create-warrant'],
                        ['port' => 'rejected', 'target' => 'action-notify-rejected'],
                    ],
                ],
                'action-create-warrant' => [
                    'type' => 'action',
                    'label' => 'Create Direct Warrant',
                    'config' => [
                        'action' => 'Warrants.CreateDirectWarrant',
                        'params' => [
                            'memberId' => '$.trigger.memberId',
                            'officeId' => '$.trigger.officeId',
                            'officerId' => '$.trigger.officerId',
                        ],
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
                        'action' => 'Core.SendEmail',
                        'params' => [
                            'template' => 'direct_warrant_approved',
                            'subject' => 'Your Warrant Has Been Issued',
                        ],
                    ],
                    'position' => ['x' => 950, 'y' => 100],
                    'outputs' => [
                        ['port' => 'next', 'target' => 'end-1'],
                    ],
                ],
                'action-notify-rejected' => [
                    'type' => 'action',
                    'label' => 'Notify Warrant Rejected',
                    'config' => [
                        'action' => 'Core.SendEmail',
                        'params' => [
                            'template' => 'direct_warrant_rejected',
                            'subject' => 'Warrant Request Declined',
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
