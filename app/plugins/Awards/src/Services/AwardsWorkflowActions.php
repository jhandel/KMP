<?php

declare(strict_types=1);

namespace Awards\Services;

use App\Services\WorkflowEngine\StateMachine\StateMachineHandler;
use App\Services\WorkflowEngine\WorkflowContextAwareTrait;
use Awards\Model\Entity\Recommendation;
use Cake\I18n\DateTime;
use Cake\Log\Log;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\ORM\Table;
use DateTimeZone;

/**
 * Workflow action implementations for award recommendation operations.
 *
 * Provides state machine transitions, bulk updates, and gathering assignment
 * for the Awards plugin workflow engine integration.
 *
 * @property \Awards\Model\Table\RecommendationsTable $Recommendations
 * @property \Awards\Model\Table\RecommendationsStatesLogsTable $RecommendationsStatesLogs
 */
class AwardsWorkflowActions
{
    use LocatorAwareTrait;
    use WorkflowContextAwareTrait;

    private Table $recommendationsTable;
    private Table $stateLogsTable;
    private StateMachineHandler $stateMachineHandler;

    public function __construct(?StateMachineHandler $stateMachineHandler = null)
    {
        $this->recommendationsTable = $this->fetchTable('Awards.Recommendations');
        $this->stateLogsTable = $this->fetchTable('Awards.RecommendationsStatesLogs');
        $this->stateMachineHandler = $stateMachineHandler ?? new StateMachineHandler();
    }

    /**
     * Create a new recommendation with initial status and state.
     *
     * @param array $context Current workflow context
     * @param array $config Config with awardId, requesterScaName, memberScaName, contactEmail, reason, and optional fields
     * @return array Output with success, recommendationId
     */
    public function createRecommendation(array $context, array $config): array
    {
        try {
            $data = [];
            $fieldMap = [
                'awardId' => 'award_id',
                'requesterId' => 'requester_id',
                'memberId' => 'member_id',
                'branchId' => 'branch_id',
                'requesterScaName' => 'requester_sca_name',
                'memberScaName' => 'member_sca_name',
                'contactEmail' => 'contact_email',
                'contactNumber' => 'contact_number',
                'reason' => 'reason',
                'specialty' => 'specialty',
                'callIntoCourt' => 'call_into_court',
                'courtAvailability' => 'court_availability',
                'personToNotify' => 'person_to_notify',
            ];

            foreach ($fieldMap as $configKey => $entityField) {
                if (isset($config[$configKey])) {
                    $data[$entityField] = $this->resolveValue($config[$configKey], $context);
                }
            }

            // Set initial state from config or default to first available state
            $statuses = Recommendation::getStatuses();
            $firstStatus = array_key_first($statuses);
            $firstState = $statuses[$firstStatus][0] ?? '';

            $data['status'] = (string)$this->resolveValue($config['status'] ?? $firstStatus, $context);
            $data['state'] = (string)$this->resolveValue($config['state'] ?? $firstState, $context);
            $data['state_date'] = DateTime::now();

            $entity = $this->recommendationsTable->newEntity($data, ['validate' => 'default']);
            $saved = $this->recommendationsTable->save($entity);

            if (!$saved) {
                $errors = $entity->getErrors();
                Log::warning('Workflow CreateRecommendation validation failed: ' . json_encode($errors));
                return ['success' => false, 'error' => 'Validation failed', 'recommendationId' => null];
            }

            return ['success' => true, 'data' => ['recommendationId' => $saved->id]];
        } catch (\Throwable $e) {
            Log::error('Workflow CreateRecommendation failed: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage(), 'recommendationId' => null];
        }
    }

    /**
     * Transition a recommendation to a new state using the state machine handler.
     *
     * @param array $context Current workflow context
     * @param array $config Config with recommendationId, targetState, actorId
     * @return array Output with success, previousState, newState, newStatus
     */
    public function transitionState(array $context, array $config): array
    {
        try {
            $recommendationId = (int)$this->resolveValue($config['recommendationId'], $context);
            $targetState = (string)$this->resolveValue($config['targetState'], $context);
            $actorId = $this->resolveValue($config['actorId'] ?? null, $context);

            $recommendation = $this->recommendationsTable->get($recommendationId);
            $currentState = (string)$recommendation->state;

            $smConfig = $this->buildStateMachineConfig();

            $entityData = $recommendation->toArray();
            $result = $this->stateMachineHandler->executeTransition(
                $entityData,
                $currentState,
                $targetState,
                $smConfig,
            );

            if (!$result['success']) {
                return [
                    'success' => false,
                    'error' => $result['error'] ?? 'Transition failed',
                    'missingFields' => $result['missingFields'] ?? [],
                ];
            }

            // Apply the transition via the entity setter (handles status, state_date, and rules)
            $recommendation->state = $targetState;
            if ($actorId) {
                $recommendation->modified_by = (int)$actorId;
            }

            // Apply any additional set-rule fields from the state machine result
            $updatedData = $result['entityData'] ?? [];
            $skipFields = ['state', 'status', 'state_date'];
            foreach ($updatedData as $field => $value) {
                if (!in_array($field, $skipFields, true) && $recommendation->isAccessible($field)) {
                    $recommendation->set($field, $value);
                }
            }

            $saved = $this->recommendationsTable->save($recommendation);
            if (!$saved) {
                return ['success' => false, 'error' => 'Failed to save recommendation'];
            }

            return [
                'success' => true,
                'data' => [
                    'previousState' => $currentState,
                    'newState' => $targetState,
                    'newStatus' => $recommendation->status,
                    'recommendationId' => $recommendationId,
                ],
            ];
        } catch (\Throwable $e) {
            Log::error('Workflow TransitionState failed: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Batch state transition for multiple recommendations.
     *
     * @param array $context Current workflow context
     * @param array $config Config with recommendationIds (array), targetState, actorId, note, gatheringId, given, closeReason
     * @return array Output with success, results per entity
     */
    public function bulkTransitionState(array $context, array $config): array
    {
        try {
            $ids = $this->resolveValue($config['recommendationIds'], $context);
            if (!is_array($ids)) {
                return ['success' => false, 'error' => 'recommendationIds must be an array'];
            }

            $targetState = (string)$this->resolveValue($config['targetState'], $context);
            $actorId = (int)$this->resolveValue($config['actorId'] ?? 0, $context);
            $gatheringId = $this->resolveValue($config['gatheringId'] ?? null, $context);
            $given = $this->resolveValue($config['given'] ?? null, $context);
            $note = $this->resolveValue($config['note'] ?? null, $context);
            $closeReason = $this->resolveValue($config['closeReason'] ?? null, $context);

            $stateService = new RecommendationStateService();
            $bulkData = [
                'ids' => $ids,
                'newState' => $targetState,
                'gathering_id' => $gatheringId,
                'given' => $given,
                'note' => $note,
                'close_reason' => $closeReason,
            ];

            $result = $stateService->bulkUpdateStates(
                $this->recommendationsTable,
                $bulkData,
                $actorId,
            );

            return [
                'success' => $result,
                'data' => [
                    'processedCount' => count($ids),
                    'targetState' => $targetState,
                ],
            ];
        } catch (\Throwable $e) {
            Log::error('Workflow BulkTransitionState failed: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Apply field set rules for a target state without performing the transition.
     *
     * @param array $context Current workflow context
     * @param array $config Config with recommendationId, targetState
     * @return array Output with success, modified entity data
     */
    public function applyStateRules(array $context, array $config): array
    {
        try {
            $recommendationId = (int)$this->resolveValue($config['recommendationId'], $context);
            $targetState = (string)$this->resolveValue($config['targetState'], $context);

            $recommendation = $this->recommendationsTable->get($recommendationId);
            $entityData = $recommendation->toArray();

            $smConfig = $this->buildStateMachineConfig();
            $stateRules = $smConfig['stateRules'][$targetState] ?? [];

            $modifiedData = $this->stateMachineHandler->applySetRules($entityData, $stateRules);

            // Apply changes to entity
            foreach ($modifiedData as $field => $value) {
                if ($recommendation->isAccessible($field)) {
                    $recommendation->set($field, $value);
                }
            }

            $saved = $this->recommendationsTable->save($recommendation);
            if (!$saved) {
                return ['success' => false, 'error' => 'Failed to save recommendation after applying rules'];
            }

            return [
                'success' => true,
                'data' => [
                    'recommendationId' => $recommendationId,
                    'appliedRules' => $stateRules['set'] ?? [],
                ],
            ];
        } catch (\Throwable $e) {
            Log::error('Workflow ApplyStateRules failed: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Record a state transition in the audit log.
     *
     * @param array $context Current workflow context
     * @param array $config Config with recommendationId, fromState, toState, fromStatus, toStatus, actorId
     * @return array Output with success, logId
     */
    public function createStateLog(array $context, array $config): array
    {
        try {
            $recommendationId = (int)$this->resolveValue($config['recommendationId'], $context);
            $fromState = (string)$this->resolveValue($config['fromState'], $context);
            $toState = (string)$this->resolveValue($config['toState'], $context);
            $fromStatus = (string)$this->resolveValue($config['fromStatus'] ?? 'Unknown', $context);
            $toStatus = (string)$this->resolveValue($config['toStatus'] ?? 'Unknown', $context);
            $actorId = $this->resolveValue($config['actorId'] ?? null, $context);

            $log = $this->stateLogsTable->newEmptyEntity();
            $log->recommendation_id = $recommendationId;
            $log->from_state = $fromState;
            $log->to_state = $toState;
            $log->from_status = $fromStatus;
            $log->to_status = $toStatus;
            $log->created_by = $actorId ? (int)$actorId : null;

            $saved = $this->stateLogsTable->save($log);
            if (!$saved) {
                return ['success' => false, 'error' => 'Failed to save state log'];
            }

            return [
                'success' => true,
                'data' => ['logId' => $saved->id],
            ];
        } catch (\Throwable $e) {
            Log::error('Workflow CreateStateLog failed: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Assign a gathering to a recommendation.
     *
     * @param array $context Current workflow context
     * @param array $config Config with recommendationId, gatheringId
     * @return array Output with success
     */
    public function assignGathering(array $context, array $config): array
    {
        try {
            $recommendationId = (int)$this->resolveValue($config['recommendationId'], $context);
            $gatheringId = (int)$this->resolveValue($config['gatheringId'], $context);

            $recommendation = $this->recommendationsTable->get($recommendationId);

            if (!Recommendation::supportsGatheringAssignmentForState((string)$recommendation->state)) {
                return [
                    'success' => false,
                    'error' => "State '{$recommendation->state}' does not support gathering assignment",
                ];
            }

            $recommendation->gathering_id = $gatheringId;
            $saved = $this->recommendationsTable->save($recommendation);

            if (!$saved) {
                return ['success' => false, 'error' => 'Failed to save gathering assignment'];
            }

            return [
                'success' => true,
                'data' => [
                    'recommendationId' => $recommendationId,
                    'gatheringId' => $gatheringId,
                ],
            ];
        } catch (\Throwable $e) {
            Log::error('Workflow AssignGathering failed: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Look up court availability preferences for the recommendation's member.
     *
     * @param array $context Current workflow context
     * @param array $config Config with recommendationId
     * @return array Output with success, courtAvailability
     */
    public function pullCourtPreferences(array $context, array $config): array
    {
        try {
            $recommendationId = (int)$this->resolveValue($config['recommendationId'], $context);

            $recommendation = $this->recommendationsTable->get($recommendationId);

            return [
                'success' => true,
                'data' => [
                    'recommendationId' => $recommendationId,
                    'courtAvailability' => $recommendation->court_availability,
                    'callIntoCourt' => $recommendation->call_into_court,
                ],
            ];
        } catch (\Throwable $e) {
            Log::error('Workflow PullCourtPreferences failed: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Build the state machine config from the database-backed recommendation configuration.
     *
     * @return array State machine config with transitions, statuses, stateRules, stateField, statusField
     */
    private function buildStateMachineConfig(): array
    {
        $statuses = Recommendation::getStatuses();
        $allStates = Recommendation::getStates();

        // Build transitions from database
        $transitions = [];
        foreach ($allStates as $state) {
            $transitions[$state] = Recommendation::getValidTransitionsFrom($state);
        }

        $stateRulesRaw = Recommendation::getStateRules();

        // Normalize rule keys to match StateMachineHandler expectations
        $normalizedRules = [];
        foreach ($stateRulesRaw as $state => $rules) {
            $normalized = [];
            if (isset($rules['Set'])) {
                $normalized['set'] = $rules['Set'];
            }
            if (isset($rules['Required'])) {
                $normalized['required'] = $rules['Required'];
            }
            $normalizedRules[$state] = $normalized;
        }

        return [
            'transitions' => $transitions,
            'statuses' => $statuses,
            'stateRules' => $normalizedRules,
            'stateField' => 'state',
            'statusField' => 'status',
        ];
    }
}
