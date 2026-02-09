<?php

declare(strict_types=1);

namespace Officers\Services;

use App\Services\WorkflowRegistry\WorkflowActionRegistry;
use App\Services\WorkflowRegistry\WorkflowConditionRegistry;
use App\Services\WorkflowRegistry\WorkflowEntityRegistry;
use App\Services\WorkflowRegistry\WorkflowTriggerRegistry;

/**
 * Registers Officers plugin workflow triggers, actions, conditions, and entities.
 */
class OfficersWorkflowProvider
{
    private const SOURCE = 'Officers';

    /**
     * Register all Officers workflow components with the registries.
     *
     * @return void
     */
    public static function register(): void
    {
        self::registerTriggers();
        self::registerActions();
        self::registerConditions();
        self::registerEntities();
    }

    /**
     * @return void
     */
    private static function registerTriggers(): void
    {
        WorkflowTriggerRegistry::register(self::SOURCE, [
            [
                'event' => 'Officers.HireRequested',
                'label' => 'Officer Hire Requested',
                'description' => 'When a new officer assignment is initiated',
                'payloadSchema' => [
                    'memberId' => ['type' => 'integer', 'label' => 'Member ID'],
                    'officeId' => ['type' => 'integer', 'label' => 'Office ID'],
                    'branchId' => ['type' => 'integer', 'label' => 'Branch ID'],
                    'startOn' => ['type' => 'datetime', 'label' => 'Start Date'],
                    'expiresOn' => ['type' => 'datetime', 'label' => 'Expires On'],
                    'deputyToId' => ['type' => 'integer', 'label' => 'Deputy To Officer ID'],
                ],
            ],
            [
                'event' => 'Officers.Released',
                'label' => 'Officer Released',
                'description' => 'When an officer is released from their position',
                'payloadSchema' => [
                    'officerId' => ['type' => 'integer', 'label' => 'Officer ID'],
                    'memberId' => ['type' => 'integer', 'label' => 'Member ID'],
                    'officeId' => ['type' => 'integer', 'label' => 'Office ID'],
                    'reason' => ['type' => 'string', 'label' => 'Reason'],
                ],
            ],
            [
                'event' => 'Officers.WarrantRequired',
                'label' => 'Officer Warrant Required',
                'description' => 'When an officer hire requires a warrant',
                'payloadSchema' => [
                    'officerId' => ['type' => 'integer', 'label' => 'Officer ID'],
                    'memberId' => ['type' => 'integer', 'label' => 'Member ID'],
                    'officeId' => ['type' => 'integer', 'label' => 'Office ID'],
                ],
            ],
        ]);
    }

    /**
     * @return void
     */
    private static function registerActions(): void
    {
        $actionsClass = OfficerWorkflowActions::class;

        WorkflowActionRegistry::register(self::SOURCE, [
            [
                'action' => 'Officers.CreateOfficerRecord',
                'label' => 'Create Officer Assignment',
                'description' => 'Create an officer record for a member in an office',
                'inputSchema' => [
                    'memberId' => ['type' => 'integer', 'label' => 'Member ID', 'required' => true],
                    'officeId' => ['type' => 'integer', 'label' => 'Office ID', 'required' => true],
                    'branchId' => ['type' => 'integer', 'label' => 'Branch ID', 'required' => true],
                    'startOn' => ['type' => 'datetime', 'label' => 'Start Date'],
                    'expiresOn' => ['type' => 'datetime', 'label' => 'Expires On'],
                ],
                'outputSchema' => [
                    'officerId' => ['type' => 'integer', 'label' => 'Officer ID'],
                ],
                'serviceClass' => $actionsClass,
                'serviceMethod' => 'createOfficerRecord',
                'isAsync' => false,
            ],
            [
                'action' => 'Officers.ReleaseOfficer',
                'label' => 'Release Officer',
                'description' => 'Release an officer from their position',
                'inputSchema' => [
                    'officerId' => ['type' => 'integer', 'label' => 'Officer ID', 'required' => true],
                    'reason' => ['type' => 'string', 'label' => 'Reason'],
                    'expiresOn' => ['type' => 'datetime', 'label' => 'Release Date'],
                ],
                'outputSchema' => [
                    'released' => ['type' => 'boolean'],
                ],
                'serviceClass' => $actionsClass,
                'serviceMethod' => 'releaseOfficer',
                'isAsync' => false,
            ],
            [
                'action' => 'Officers.SendHireNotification',
                'label' => 'Send Hire Notification',
                'description' => 'Send email notification about officer hire',
                'inputSchema' => [
                    'officerId' => ['type' => 'integer', 'label' => 'Officer ID', 'required' => true],
                ],
                'outputSchema' => [
                    'sent' => ['type' => 'boolean'],
                ],
                'serviceClass' => $actionsClass,
                'serviceMethod' => 'sendHireNotification',
                'isAsync' => true,
            ],
        ]);
    }

    /**
     * @return void
     */
    private static function registerConditions(): void
    {
        $conditionsClass = OfficerWorkflowConditions::class;

        WorkflowConditionRegistry::register(self::SOURCE, [
            [
                'condition' => 'Officers.OfficeRequiresWarrant',
                'label' => 'Office Requires Warrant',
                'description' => 'Check if the office requires a warrant for the officer',
                'inputSchema' => [
                    'officeId' => ['type' => 'integer', 'label' => 'Office ID', 'required' => true],
                ],
                'evaluatorClass' => $conditionsClass,
                'evaluatorMethod' => 'officeRequiresWarrant',
            ],
            [
                'condition' => 'Officers.IsOnlyOnePerBranch',
                'label' => 'Office Only One Per Branch',
                'description' => 'Check if this office allows only one officer per branch',
                'inputSchema' => [
                    'officeId' => ['type' => 'integer', 'label' => 'Office ID', 'required' => true],
                ],
                'evaluatorClass' => $conditionsClass,
                'evaluatorMethod' => 'isOnlyOnePerBranch',
            ],
        ]);
    }

    /**
     * @return void
     */
    private static function registerEntities(): void
    {
        WorkflowEntityRegistry::register(self::SOURCE, [
            [
                'entityType' => 'Officers.Officers',
                'label' => 'Officer',
                'description' => 'Officer assignment record',
                'tableClass' => \Officers\Model\Table\OfficersTable::class,
                'fields' => [
                    'id' => ['type' => 'integer', 'label' => 'ID'],
                    'member_id' => ['type' => 'integer', 'label' => 'Member ID'],
                    'office_id' => ['type' => 'integer', 'label' => 'Office ID'],
                    'branch_id' => ['type' => 'integer', 'label' => 'Branch ID'],
                    'status' => ['type' => 'string', 'label' => 'Status'],
                    'start_on' => ['type' => 'datetime', 'label' => 'Start Date'],
                    'expires_on' => ['type' => 'datetime', 'label' => 'Expires On'],
                ],
            ],
            [
                'entityType' => 'Officers.Offices',
                'label' => 'Office',
                'description' => 'Office definition',
                'tableClass' => \Officers\Model\Table\OfficesTable::class,
                'fields' => [
                    'id' => ['type' => 'integer', 'label' => 'ID'],
                    'name' => ['type' => 'string', 'label' => 'Name'],
                    'requires_warrant' => ['type' => 'boolean', 'label' => 'Requires Warrant'],
                    'only_one_per_branch' => ['type' => 'boolean', 'label' => 'Only One Per Branch'],
                    'term_length' => ['type' => 'integer', 'label' => 'Term Length (months)'],
                ],
            ],
        ]);
    }
}
