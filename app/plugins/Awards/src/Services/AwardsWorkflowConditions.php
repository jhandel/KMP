<?php

declare(strict_types=1);

namespace Awards\Services;

use App\Services\WorkflowEngine\StateMachine\StateMachineHandler;
use App\Services\WorkflowEngine\WorkflowContextAwareTrait;
use Awards\Model\Entity\Recommendation;
use Cake\Log\Log;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\ORM\Table;

/**
 * Workflow condition evaluators for the Awards plugin.
 *
 * Each method accepts workflow context and config, returns bool.
 */
class AwardsWorkflowConditions
{
    use LocatorAwareTrait;
    use WorkflowContextAwareTrait;

    private Table $recommendationsTable;
    private StateMachineHandler $stateMachineHandler;

    public function __construct(?StateMachineHandler $stateMachineHandler = null)
    {
        $this->recommendationsTable = $this->fetchTable('Awards.Recommendations');
        $this->stateMachineHandler = $stateMachineHandler ?? new StateMachineHandler();
    }

    /**
     * Check if a state transition is allowed per the state machine configuration.
     *
     * @param array $context Current workflow context
     * @param array $config Config with currentState, targetState
     * @return bool
     */
    public function isValidTransition(array $context, array $config): bool
    {
        try {
            $currentState = $this->resolveValue($config['currentState'] ?? null, $context);
            $targetState = $this->resolveValue($config['targetState'] ?? null, $context);

            if (empty($currentState) || empty($targetState)) {
                return false;
            }

            $allStates = Recommendation::getStates();

            // Both states must be valid
            if (!in_array((string)$currentState, $allStates, true) || !in_array((string)$targetState, $allStates, true)) {
                return false;
            }

            // Build transitions from configuration
            $transitions = [];
            foreach ($allStates as $state) {
                $otherStates = array_filter($allStates, fn($s) => $s !== $state);
                $transitions[$state] = array_values($otherStates);
            }

            $smConfig = ['transitions' => $transitions];

            return $this->stateMachineHandler->validateTransition(
                (string)$currentState,
                (string)$targetState,
                $smConfig,
            );
        } catch (\Throwable $e) {
            Log::error('Condition IsValidTransition failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Validate that all required fields for a target state are present on the entity.
     *
     * @param array $context Current workflow context
     * @param array $config Config with recommendationId, targetState
     * @return bool
     */
    public function hasRequiredFields(array $context, array $config): bool
    {
        try {
            $recommendationId = $this->resolveValue($config['recommendationId'] ?? null, $context);
            $targetState = $this->resolveValue($config['targetState'] ?? null, $context);

            if (empty($recommendationId) || empty($targetState)) {
                return false;
            }

            $recommendation = $this->recommendationsTable->get((int)$recommendationId);
            $entityData = $recommendation->toArray();

            $stateRulesRaw = \App\KMP\StaticHelpers::getAppSetting('Awards.RecommendationStateRules');
            if (is_string($stateRulesRaw)) {
                $stateRulesRaw = yaml_parse($stateRulesRaw);
            }
            $rules = is_array($stateRulesRaw) ? ($stateRulesRaw[(string)$targetState] ?? []) : [];

            $requiredFields = $rules['Required'] ?? $rules['required'] ?? [];
            if (empty($requiredFields)) {
                return true;
            }

            $missing = $this->stateMachineHandler->validateRequiredFields($entityData, $requiredFields);

            return empty($missing);
        } catch (\Throwable $e) {
            Log::error('Condition HasRequiredFields failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if the target state requires a gathering_id to be set.
     *
     * @param array $context Current workflow context
     * @param array $config Config with targetState
     * @return bool True if the target state requires a gathering assignment
     */
    public function requiresGathering(array $context, array $config): bool
    {
        try {
            $targetState = $this->resolveValue($config['targetState'] ?? null, $context);

            if (empty($targetState)) {
                return false;
            }

            return Recommendation::supportsGatheringAssignmentForState((string)$targetState);
        } catch (\Throwable $e) {
            Log::error('Condition RequiresGathering failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if the target state requires a given date.
     *
     * @param array $context Current workflow context
     * @param array $config Config with targetState
     * @return bool True if the target state requires a given date
     */
    public function requiresGivenDate(array $context, array $config): bool
    {
        try {
            $targetState = $this->resolveValue($config['targetState'] ?? null, $context);

            if (empty($targetState)) {
                return false;
            }

            $stateRulesRaw = \App\KMP\StaticHelpers::getAppSetting('Awards.RecommendationStateRules');
            if (is_string($stateRulesRaw)) {
                $stateRulesRaw = yaml_parse($stateRulesRaw);
            }
            $rules = is_array($stateRulesRaw) ? ($stateRulesRaw[(string)$targetState] ?? []) : [];
            $requiredFields = $rules['Required'] ?? $rules['required'] ?? [];

            return in_array('given', $requiredFields, true);
        } catch (\Throwable $e) {
            Log::error('Condition RequiresGivenDate failed: ' . $e->getMessage());
            return false;
        }
    }
}
