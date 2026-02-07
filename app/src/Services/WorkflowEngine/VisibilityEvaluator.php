<?php

declare(strict_types=1);

namespace App\Services\WorkflowEngine;

use Cake\Log\Log;
use Cake\ORM\TableRegistry;

/**
 * Evaluates field/entity visibility and editability rules per workflow state.
 *
 * Rules are stored in workflow_visibility_rules and evaluated with priority ordering.
 * Defaults to allow when no rules defined (backward compatibility).
 */
class VisibilityEvaluator
{
    private RuleEvaluator $ruleEvaluator;

    public function __construct(?RuleEvaluator $ruleEvaluator = null)
    {
        $this->ruleEvaluator = $ruleEvaluator ?? new RuleEvaluator();
    }

    /**
     * Check if entity is viewable in the given state.
     */
    public function canViewEntity(int $stateId, array $context): bool
    {
        return $this->evaluateRule('can_view_entity', $stateId, '*', $context);
    }

    /**
     * Check if entity is editable in the given state.
     */
    public function canEditEntity(int $stateId, array $context): bool
    {
        return $this->evaluateRule('can_edit_entity', $stateId, '*', $context);
    }

    /**
     * Get list of visible fields for a state.
     */
    public function getVisibleFields(int $stateId, array $context): array
    {
        return $this->getFieldsForRule('can_view_field', $stateId, $context);
    }

    /**
     * Get list of editable fields for a state.
     */
    public function getEditableFields(int $stateId, array $context): array
    {
        return $this->getFieldsForRule('can_edit_field', $stateId, $context);
    }

    /**
     * Get complete visibility profile for a state (entity + field level).
     */
    public function getVisibilityProfile(int $stateId, array $context): array
    {
        return [
            'can_view_entity' => $this->canViewEntity($stateId, $context),
            'can_edit_entity' => $this->canEditEntity($stateId, $context),
            'visible_fields' => $this->getVisibleFields($stateId, $context),
            'editable_fields' => $this->getEditableFields($stateId, $context),
        ];
    }

    /**
     * Evaluate a specific rule type for a target.
     * Returns true (allowed) by default if no rules match.
     */
    private function evaluateRule(string $ruleType, int $stateId, string $target, array $context): bool
    {
        try {
            $rulesTable = TableRegistry::getTableLocator()->get('WorkflowVisibilityRules');
            $rules = $rulesTable->find()
                ->where([
                    'workflow_state_id' => $stateId,
                    'rule_type' => $ruleType,
                    'target IN' => [$target, '*'],
                ])
                ->orderBy(['priority' => 'ASC'])
                ->all();

            if ($rules->isEmpty()) {
                return true; // Default: allow
            }

            // First matching rule wins
            foreach ($rules as $rule) {
                $condition = json_decode($rule->condition ?? '{}', true);
                if (empty($condition)) {
                    // No condition = unconditional rule
                    return true;
                }
                if ($this->ruleEvaluator->evaluate($condition, $context)) {
                    return true;
                }
            }

            return false; // No rules matched
        } catch (\Exception $e) {
            Log::warning('VisibilityEvaluator: ' . $e->getMessage());

            return true; // Fail open for visibility
        }
    }

    /**
     * Get fields that pass a specific rule type.
     */
    private function getFieldsForRule(string $ruleType, int $stateId, array $context): array
    {
        try {
            $rulesTable = TableRegistry::getTableLocator()->get('WorkflowVisibilityRules');
            $rules = $rulesTable->find()
                ->where([
                    'workflow_state_id' => $stateId,
                    'rule_type' => $ruleType,
                ])
                ->orderBy(['priority' => 'ASC'])
                ->all();

            if ($rules->isEmpty()) {
                return ['*']; // Default: all fields
            }

            $fields = [];
            foreach ($rules as $rule) {
                $condition = json_decode($rule->condition ?? '{}', true);
                $passes = empty($condition) || $this->ruleEvaluator->evaluate($condition, $context);
                if ($passes) {
                    $fields[] = $rule->target;
                }
            }

            return $fields;
        } catch (\Exception $e) {
            Log::warning('VisibilityEvaluator: ' . $e->getMessage());

            return ['*'];
        }
    }
}
