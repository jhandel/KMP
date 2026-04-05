<?php

declare(strict_types=1);

namespace Awards\Services;

use App\Services\WorkflowRegistry\WorkflowActionRegistry;
use App\Services\WorkflowRegistry\WorkflowConditionRegistry;
use App\Services\WorkflowRegistry\WorkflowEntityRegistry;
use App\Services\WorkflowRegistry\WorkflowTriggerRegistry;

/**
 * Registers award recommendation workflow triggers, actions, conditions,
 * and entities with the workflow registries.
 */
class AwardsWorkflowProvider
{
    private const SOURCE = 'Awards';

    /**
     * Register all award workflow components.
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
                'event' => 'Awards.RecommendationSubmitted',
                'label' => 'Recommendation Submitted',
                'description' => 'When a new award recommendation is submitted',
                'payloadSchema' => [
                    'recommendationId' => ['type' => 'integer', 'label' => 'Recommendation ID'],
                    'awardId' => ['type' => 'integer', 'label' => 'Award ID'],
                    'memberId' => ['type' => 'integer', 'label' => 'Member ID'],
                    'requesterId' => ['type' => 'integer', 'label' => 'Requester ID'],
                    'branchId' => ['type' => 'integer', 'label' => 'Branch ID'],
                    'state' => ['type' => 'string', 'label' => 'Initial State'],
                ],
            ],
            [
                'event' => 'Awards.RecommendationStateChanged',
                'label' => 'Recommendation State Changed',
                'description' => 'When a recommendation transitions to a new state',
                'payloadSchema' => [
                    'recommendationId' => ['type' => 'integer', 'label' => 'Recommendation ID'],
                    'previousState' => ['type' => 'string', 'label' => 'Previous State'],
                    'newState' => ['type' => 'string', 'label' => 'New State'],
                    'previousStatus' => ['type' => 'string', 'label' => 'Previous Status'],
                    'newStatus' => ['type' => 'string', 'label' => 'New Status'],
                    'actorId' => ['type' => 'integer', 'label' => 'Actor ID'],
                ],
            ],
            [
                'event' => 'Awards.BulkStateTransition',
                'label' => 'Bulk State Transition',
                'description' => 'When multiple recommendations are transitioned in bulk',
                'payloadSchema' => [
                    'recommendationIds' => ['type' => 'array', 'label' => 'Recommendation IDs'],
                    'targetState' => ['type' => 'string', 'label' => 'Target State'],
                    'actorId' => ['type' => 'integer', 'label' => 'Actor ID'],
                ],
            ],
        ]);
    }

    /**
     * @return void
     */
    private static function registerActions(): void
    {
        $actionsClass = AwardsWorkflowActions::class;

        WorkflowActionRegistry::register(self::SOURCE, [
            [
                'action' => 'Awards.CreateRecommendation',
                'label' => 'Create Recommendation',
                'description' => 'Create a new award recommendation with initial status and state',
                'inputSchema' => [
                    'awardId' => ['type' => 'integer', 'label' => 'Award ID', 'required' => true],
                    'requesterScaName' => ['type' => 'string', 'label' => 'Requester SCA Name', 'required' => true],
                    'memberScaName' => ['type' => 'string', 'label' => 'Member SCA Name', 'required' => true],
                    'contactEmail' => ['type' => 'string', 'label' => 'Contact Email', 'required' => true],
                    'reason' => ['type' => 'string', 'label' => 'Reason', 'required' => true],
                    'requesterId' => ['type' => 'integer', 'label' => 'Requester ID'],
                    'memberId' => ['type' => 'integer', 'label' => 'Member ID'],
                    'branchId' => ['type' => 'integer', 'label' => 'Branch ID'],
                    'status' => ['type' => 'string', 'label' => 'Initial Status'],
                    'state' => ['type' => 'string', 'label' => 'Initial State'],
                ],
                'outputSchema' => [
                    'success' => ['type' => 'boolean', 'label' => 'Creation Successful'],
                    'recommendationId' => ['type' => 'integer', 'label' => 'Recommendation ID'],
                ],
                'serviceClass' => $actionsClass,
                'serviceMethod' => 'createRecommendation',
                'isAsync' => false,
            ],
            [
                'action' => 'Awards.TransitionState',
                'label' => 'Transition Recommendation State',
                'description' => 'Move a recommendation to a new state using the state machine',
                'inputSchema' => [
                    'recommendationId' => ['type' => 'integer', 'label' => 'Recommendation ID', 'required' => true],
                    'targetState' => ['type' => 'string', 'label' => 'Target State', 'required' => true],
                    'actorId' => ['type' => 'integer', 'label' => 'Actor ID'],
                ],
                'outputSchema' => [
                    'success' => ['type' => 'boolean', 'label' => 'Transition Successful'],
                    'previousState' => ['type' => 'string', 'label' => 'Previous State'],
                    'newState' => ['type' => 'string', 'label' => 'New State'],
                    'newStatus' => ['type' => 'string', 'label' => 'New Status'],
                ],
                'serviceClass' => $actionsClass,
                'serviceMethod' => 'transitionState',
                'isAsync' => false,
            ],
            [
                'action' => 'Awards.BulkTransitionState',
                'label' => 'Bulk Transition State',
                'description' => 'Batch state transition for multiple recommendations',
                'inputSchema' => [
                    'recommendationIds' => ['type' => 'array', 'label' => 'Recommendation IDs', 'required' => true],
                    'targetState' => ['type' => 'string', 'label' => 'Target State', 'required' => true],
                    'actorId' => ['type' => 'integer', 'label' => 'Actor ID'],
                    'gatheringId' => ['type' => 'integer', 'label' => 'Gathering ID'],
                    'given' => ['type' => 'string', 'label' => 'Given Date'],
                    'note' => ['type' => 'string', 'label' => 'Note'],
                    'closeReason' => ['type' => 'string', 'label' => 'Close Reason'],
                ],
                'outputSchema' => [
                    'success' => ['type' => 'boolean', 'label' => 'Bulk Transition Successful'],
                    'processedCount' => ['type' => 'integer', 'label' => 'Processed Count'],
                    'targetState' => ['type' => 'string', 'label' => 'Target State'],
                ],
                'serviceClass' => $actionsClass,
                'serviceMethod' => 'bulkTransitionState',
                'isAsync' => false,
            ],
            [
                'action' => 'Awards.ApplyStateRules',
                'label' => 'Apply State Rules',
                'description' => 'Apply field visibility and set rules for a target state',
                'inputSchema' => [
                    'recommendationId' => ['type' => 'integer', 'label' => 'Recommendation ID', 'required' => true],
                    'targetState' => ['type' => 'string', 'label' => 'Target State', 'required' => true],
                ],
                'outputSchema' => [
                    'success' => ['type' => 'boolean', 'label' => 'Rules Applied'],
                    'appliedRules' => ['type' => 'object', 'label' => 'Applied Set Rules'],
                ],
                'serviceClass' => $actionsClass,
                'serviceMethod' => 'applyStateRules',
                'isAsync' => false,
            ],
            [
                'action' => 'Awards.CreateStateLog',
                'label' => 'Create State Log',
                'description' => 'Record a state transition in the audit log',
                'inputSchema' => [
                    'recommendationId' => ['type' => 'integer', 'label' => 'Recommendation ID', 'required' => true],
                    'fromState' => ['type' => 'string', 'label' => 'From State', 'required' => true],
                    'toState' => ['type' => 'string', 'label' => 'To State', 'required' => true],
                    'fromStatus' => ['type' => 'string', 'label' => 'From Status'],
                    'toStatus' => ['type' => 'string', 'label' => 'To Status'],
                    'actorId' => ['type' => 'integer', 'label' => 'Actor ID'],
                ],
                'outputSchema' => [
                    'success' => ['type' => 'boolean', 'label' => 'Log Created'],
                    'logId' => ['type' => 'integer', 'label' => 'Log Entry ID'],
                ],
                'serviceClass' => $actionsClass,
                'serviceMethod' => 'createStateLog',
                'isAsync' => false,
            ],
            [
                'action' => 'Awards.AssignGathering',
                'label' => 'Assign Gathering',
                'description' => 'Link a recommendation to a gathering for presentation',
                'inputSchema' => [
                    'recommendationId' => ['type' => 'integer', 'label' => 'Recommendation ID', 'required' => true],
                    'gatheringId' => ['type' => 'integer', 'label' => 'Gathering ID', 'required' => true],
                ],
                'outputSchema' => [
                    'success' => ['type' => 'boolean', 'label' => 'Assignment Successful'],
                    'recommendationId' => ['type' => 'integer', 'label' => 'Recommendation ID'],
                    'gatheringId' => ['type' => 'integer', 'label' => 'Gathering ID'],
                ],
                'serviceClass' => $actionsClass,
                'serviceMethod' => 'assignGathering',
                'isAsync' => false,
            ],
            [
                'action' => 'Awards.PullCourtPreferences',
                'label' => 'Pull Court Preferences',
                'description' => 'Look up court availability preferences for the recommendation member',
                'inputSchema' => [
                    'recommendationId' => ['type' => 'integer', 'label' => 'Recommendation ID', 'required' => true],
                ],
                'outputSchema' => [
                    'success' => ['type' => 'boolean', 'label' => 'Lookup Successful'],
                    'courtAvailability' => ['type' => 'string', 'label' => 'Court Availability'],
                    'callIntoCourt' => ['type' => 'string', 'label' => 'Call Into Court'],
                ],
                'serviceClass' => $actionsClass,
                'serviceMethod' => 'pullCourtPreferences',
                'isAsync' => false,
            ],
            [
                'action' => 'Awards.NotifyCrownOfRecommendation',
                'label' => 'Notify Crown of Recommendation',
                'description' => 'Send an email to crown with the recommendation details (name, award, reason)',
                'inputSchema' => [
                    'recommendationId' => ['type' => 'integer', 'label' => 'Recommendation ID', 'required' => true],
                    'to' => ['type' => 'string', 'label' => 'Recipient Email', 'required' => true],
                ],
                'outputSchema' => [
                    'sent' => ['type' => 'boolean', 'label' => 'Email Sent'],
                ],
                'serviceClass' => $actionsClass,
                'serviceMethod' => 'notifyCrownOfRecommendation',
                'isAsync' => false,
            ],
        ]);
    }

    /**
     * @return void
     */
    private static function registerConditions(): void
    {
        $conditionsClass = AwardsWorkflowConditions::class;

        WorkflowConditionRegistry::register(self::SOURCE, [
            [
                'condition' => 'Awards.IsValidTransition',
                'label' => 'Is Valid Transition',
                'description' => 'Check if a state transition is allowed per state machine configuration',
                'inputSchema' => [
                    'currentState' => ['type' => 'string', 'label' => 'Current State', 'required' => true],
                    'targetState' => ['type' => 'string', 'label' => 'Target State', 'required' => true],
                ],
                'evaluatorClass' => $conditionsClass,
                'evaluatorMethod' => 'isValidTransition',
            ],
            [
                'condition' => 'Awards.HasRequiredFields',
                'label' => 'Has Required Fields',
                'description' => 'Validate that all required fields for the target state are present',
                'inputSchema' => [
                    'recommendationId' => ['type' => 'integer', 'label' => 'Recommendation ID', 'required' => true],
                    'targetState' => ['type' => 'string', 'label' => 'Target State', 'required' => true],
                ],
                'evaluatorClass' => $conditionsClass,
                'evaluatorMethod' => 'hasRequiredFields',
            ],
            [
                'condition' => 'Awards.RequiresGathering',
                'label' => 'Requires Gathering',
                'description' => 'Check if the target state needs a gathering_id to be set',
                'inputSchema' => [
                    'targetState' => ['type' => 'string', 'label' => 'Target State', 'required' => true],
                ],
                'evaluatorClass' => $conditionsClass,
                'evaluatorMethod' => 'requiresGathering',
            ],
            [
                'condition' => 'Awards.RequiresGivenDate',
                'label' => 'Requires Given Date',
                'description' => 'Check if the target state needs a given date to be set',
                'inputSchema' => [
                    'targetState' => ['type' => 'string', 'label' => 'Target State', 'required' => true],
                ],
                'evaluatorClass' => $conditionsClass,
                'evaluatorMethod' => 'requiresGivenDate',
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
                'entityType' => 'Awards.Recommendations',
                'label' => 'Recommendation',
                'description' => 'Award recommendation with state machine workflow',
                'tableClass' => \Awards\Model\Table\RecommendationsTable::class,
                'fields' => [
                    'id' => ['type' => 'integer', 'label' => 'ID'],
                    'award_id' => ['type' => 'integer', 'label' => 'Award ID'],
                    'member_id' => ['type' => 'integer', 'label' => 'Member ID'],
                    'requester_id' => ['type' => 'integer', 'label' => 'Requester ID'],
                    'branch_id' => ['type' => 'integer', 'label' => 'Branch ID'],
                    'status' => ['type' => 'string', 'label' => 'Status'],
                    'state' => ['type' => 'string', 'label' => 'State'],
                    'state_date' => ['type' => 'datetime', 'label' => 'State Date'],
                    'gathering_id' => ['type' => 'integer', 'label' => 'Gathering ID'],
                    'given' => ['type' => 'date', 'label' => 'Given Date'],
                    'close_reason' => ['type' => 'string', 'label' => 'Close Reason'],
                ],
            ],
        ]);
    }
}
