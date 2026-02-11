<?php

declare(strict_types=1);

namespace App\Services\WorkflowEngine\Providers;

use App\Services\WorkflowRegistry\WorkflowActionRegistry;
use App\Services\WorkflowRegistry\WorkflowTriggerRegistry;

/**
 * Registers warrant workflow triggers and actions with the workflow registries.
 */
class WarrantWorkflowProvider
{
    private const SOURCE = 'Warrants';

    /**
     * Register all warrant workflow components.
     *
     * @return void
     */
    public static function register(): void
    {
        self::registerTriggers();
        self::registerActions();
    }

    /**
     * @return void
     */
    private static function registerTriggers(): void
    {
        WorkflowTriggerRegistry::register(self::SOURCE, [
            [
                'event' => 'Warrants.RosterCreated',
                'label' => 'Warrant Roster Created',
                'description' => 'When a new warrant roster is submitted for approval',
                'payloadSchema' => [
                    'rosterId' => ['type' => 'integer', 'label' => 'Roster ID'],
                    'rosterName' => ['type' => 'string', 'label' => 'Roster Name'],
                    'approvalsRequired' => ['type' => 'integer', 'label' => 'Approvals Required'],
                ],
            ],
            [
                'event' => 'Warrants.Approved',
                'label' => 'Warrant Approved',
                'description' => 'When a warrant roster receives all required approvals',
                'payloadSchema' => [
                    'rosterId' => ['type' => 'integer', 'label' => 'Roster ID'],
                ],
            ],
            [
                'event' => 'Warrants.Declined',
                'label' => 'Warrant Declined',
                'description' => 'When a warrant roster is declined',
                'payloadSchema' => [
                    'rosterId' => ['type' => 'integer', 'label' => 'Roster ID'],
                    'reason' => ['type' => 'string', 'label' => 'Decline Reason'],
                ],
            ],
        ]);
    }

    /**
     * @return void
     */
    private static function registerActions(): void
    {
        $actionsClass = WarrantWorkflowActions::class;

        WorkflowActionRegistry::register(self::SOURCE, [
            [
                'action' => 'Warrants.CreateWarrantRoster',
                'label' => 'Create Warrant Roster',
                'description' => 'Create a warrant roster for approval',
                'inputSchema' => [
                    'name' => ['type' => 'string', 'label' => 'Roster Name', 'required' => true, 'description' => 'Display name for the warrant roster'],
                    'description' => ['type' => 'string', 'label' => 'Description', 'default' => ''],
                    'entityType' => ['type' => 'string', 'label' => 'Entity Type', 'required' => true, 'description' => 'CakePHP table alias for the warranted entity'],
                    'entityId' => ['type' => 'integer', 'label' => 'Entity ID', 'required' => true, 'description' => 'Primary key of the warranted entity'],
                    'memberId' => ['type' => 'integer', 'label' => 'Member ID', 'required' => true, 'description' => 'The member receiving the warrant'],
                    'startOn' => ['type' => 'datetime', 'label' => 'Start Date', 'required' => true],
                    'expiresOn' => ['type' => 'datetime', 'label' => 'Expires On'],
                    'memberRoleId' => ['type' => 'integer', 'label' => 'Member Role ID'],
                ],
                'outputSchema' => [
                    'rosterId' => ['type' => 'integer', 'label' => 'Roster ID'],
                ],
                'serviceClass' => $actionsClass,
                'serviceMethod' => 'createWarrantRoster',
                'isAsync' => false,
            ],
            [
                'action' => 'Warrants.ActivateWarrants',
                'label' => 'Activate Warrants',
                'description' => 'Activate all warrants in an approved roster',
                'inputSchema' => [
                    'rosterId' => ['type' => 'integer', 'label' => 'Roster ID', 'required' => true, 'description' => 'The ID of the warrant roster to activate'],
                    'approverId' => ['type' => 'integer', 'label' => 'Approver ID', 'required' => true, 'description' => 'Member ID of the approver'],
                ],
                'outputSchema' => [
                    'activated' => ['type' => 'boolean', 'label' => 'Activation Successful'],
                    'count' => ['type' => 'integer', 'label' => 'Warrants Activated'],
                ],
                'serviceClass' => $actionsClass,
                'serviceMethod' => 'activateWarrants',
                'isAsync' => false,
            ],
            [
                'action' => 'Warrants.CreateDirectWarrant',
                'label' => 'Create Direct Warrant',
                'description' => 'Create and immediately activate a warrant (no roster)',
                'inputSchema' => [
                    'name' => ['type' => 'string', 'label' => 'Warrant Name', 'required' => true],
                    'memberId' => ['type' => 'integer', 'label' => 'Member ID', 'required' => true],
                    'entityType' => ['type' => 'string', 'label' => 'Entity Type', 'required' => true],
                    'entityId' => ['type' => 'integer', 'label' => 'Entity ID', 'required' => true],
                    'startOn' => ['type' => 'datetime', 'label' => 'Start Date', 'required' => true],
                    'expiresOn' => ['type' => 'datetime', 'label' => 'Expires On'],
                    'memberRoleId' => ['type' => 'integer', 'label' => 'Member Role ID'],
                ],
                'outputSchema' => [
                    'warrantId' => ['type' => 'integer', 'label' => 'Warrant ID'],
                ],
                'serviceClass' => $actionsClass,
                'serviceMethod' => 'createDirectWarrant',
                'isAsync' => false,
            ],
            [
                'action' => 'Warrants.DeclineRoster',
                'label' => 'Decline Warrant Roster',
                'description' => 'Decline a warrant roster and cancel its warrants',
                'inputSchema' => [
                    'rosterId' => ['type' => 'integer', 'label' => 'Roster ID', 'required' => true, 'description' => 'The ID of the warrant roster to decline'],
                    'reason' => ['type' => 'string', 'label' => 'Decline Reason', 'required' => true, 'description' => 'Reason for declining the warrant roster'],
                    'rejecterId' => ['type' => 'integer', 'label' => 'Rejecter ID', 'required' => true, 'description' => 'Member ID of the person declining'],
                ],
                'outputSchema' => [
                    'declined' => ['type' => 'boolean', 'label' => 'Decline Successful'],
                ],
                'serviceClass' => $actionsClass,
                'serviceMethod' => 'declineRoster',
                'isAsync' => false,
            ],
            [
                'action' => 'Warrants.NotifyWarrantIssued',
                'label' => 'Notify Warrant Issued',
                'description' => 'Send warrant-issued notification emails to each member in the roster',
                'inputSchema' => [
                    'rosterId' => ['type' => 'integer', 'label' => 'Roster ID', 'required' => true, 'description' => 'The ID of the warrant roster to notify about'],
                ],
                'outputSchema' => [
                    'emailsSent' => ['type' => 'integer', 'label' => 'Emails Sent'],
                ],
                'serviceClass' => $actionsClass,
                'serviceMethod' => 'notifyWarrantIssued',
                'isAsync' => false,
            ],
        ]);
    }
}
