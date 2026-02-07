<?php

declare(strict_types=1);

namespace App\Services\WorkflowEngine;

use Cake\ORM\TableRegistry;
use Cake\Log\Log;

/**
 * Evaluates visibility and editability rules for workflow instances.
 *
 * Uses priority-ordered rules from workflow_visibility_rules table.
 * Defaults to allow when no rules are defined (backward compatibility).
 */
class DefaultVisibilityEvaluator implements VisibilityEvaluatorInterface
{
    protected RuleEvaluatorInterface $ruleEvaluator;

    public function __construct()
    {
    }

    /**
     * Set the rule evaluator (called by WorkflowEngine after construction).
     */
    public function setRuleEvaluator(RuleEvaluatorInterface $ruleEvaluator): void
    {
        $this->ruleEvaluator = $ruleEvaluator;
    }

    /**
     * Get or create the rule evaluator lazily.
     */
    protected function getRuleEvaluator(): RuleEvaluatorInterface
    {
        if (!isset($this->ruleEvaluator)) {
            $this->ruleEvaluator = new DefaultRuleEvaluator();
        }
        return $this->ruleEvaluator;
    }

    /**
     * @inheritDoc
     */
    public function canViewEntity(int $instanceId, ?int $userId = null): bool
    {
        return $this->evaluateRule('can_view_entity', $this->getStateIdForInstance($instanceId), $userId, [
            'instance_id' => $instanceId,
        ]);
    }

    /**
     * @inheritDoc
     */
    public function canEditEntity(int $instanceId, ?int $userId = null): bool
    {
        return $this->evaluateRule('can_edit_entity', $this->getStateIdForInstance($instanceId), $userId, [
            'instance_id' => $instanceId,
        ]);
    }

    /**
     * @inheritDoc
     */
    public function getVisibleFields(int $instanceId, ?int $userId = null): array
    {
        return $this->getFieldsForRule('can_view_field', $instanceId, $userId);
    }

    /**
     * @inheritDoc
     */
    public function getEditableFields(int $instanceId, ?int $userId = null): array
    {
        return $this->getFieldsForRule('can_edit_field', $instanceId, $userId);
    }

    /**
     * @inheritDoc
     */
    public function evaluateRule(string $ruleType, int $stateId, ?int $userId = null, array $context = []): bool
    {
        $rulesTable = TableRegistry::getTableLocator()->get('WorkflowVisibilityRules');

        $rules = $rulesTable->find()
            ->where([
                'workflow_state_id' => $stateId,
                'rule_type' => $ruleType,
                'target' => '*',
            ])
            ->orderBy(['priority' => 'DESC'])
            ->all();

        if ($rules->isEmpty()) {
            return true;
        }

        $evalContext = $this->buildUserContext($userId, $context);

        // First matching rule wins (priority order)
        foreach ($rules as $rule) {
            $condition = $rule->decoded_condition ?? [];
            if (empty($condition)) {
                return true;
            }
            if ($this->getRuleEvaluator()->evaluate($condition, $evalContext)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get fields that pass a specific rule type for a user.
     */
    protected function getFieldsForRule(string $ruleType, int $instanceId, ?int $userId): array
    {
        $stateId = $this->getStateIdForInstance($instanceId);
        $rulesTable = TableRegistry::getTableLocator()->get('WorkflowVisibilityRules');

        $rules = $rulesTable->find()
            ->where([
                'workflow_state_id' => $stateId,
                'rule_type' => $ruleType,
                'target !=' => '*',
            ])
            ->orderBy(['priority' => 'DESC'])
            ->all();

        if ($rules->isEmpty()) {
            return ['*'];
        }

        $evalContext = $this->buildUserContext($userId, ['instance_id' => $instanceId]);
        $fields = [];

        foreach ($rules as $rule) {
            $condition = $rule->decoded_condition ?? [];
            if (empty($condition) || $this->getRuleEvaluator()->evaluate($condition, $evalContext)) {
                $fields[] = $rule->target;
            }
        }

        return array_unique($fields);
    }

    /**
     * Get the current state ID for a workflow instance.
     */
    protected function getStateIdForInstance(int $instanceId): int
    {
        $instancesTable = TableRegistry::getTableLocator()->get('WorkflowInstances');
        $instance = $instancesTable->get($instanceId);
        return $instance->current_state_id;
    }

    /**
     * Build context array with user permissions, roles, and entity data.
     */
    protected function buildUserContext(?int $userId, array $additionalContext = []): array
    {
        $context = array_merge([
            'user_id' => $userId,
            'user_permissions' => [],
            'user_roles' => [],
        ], $additionalContext);

        if ($userId) {
            try {
                $memberPermissionsTable = TableRegistry::getTableLocator()->get('MemberPermissions');
                $permissions = $memberPermissionsTable->find()
                    ->where(['member_id' => $userId, 'is_allowed' => true])
                    ->contain(['Permissions'])
                    ->all();
                $context['user_permissions'] = array_map(
                    fn($mp) => $mp->permission->name ?? '',
                    $permissions->toArray()
                );
            } catch (\Exception $e) {
                Log::warning("VisibilityEvaluator: Could not load permissions: {$e->getMessage()}");
            }

            try {
                $memberRolesTable = TableRegistry::getTableLocator()->get('MemberRoles');
                $roles = $memberRolesTable->find()
                    ->where(['member_id' => $userId])
                    ->contain(['Roles'])
                    ->all();
                $context['user_roles'] = array_map(
                    fn($mr) => $mr->role->name ?? '',
                    $roles->toArray()
                );
            } catch (\Exception $e) {
                Log::warning("VisibilityEvaluator: Could not load roles: {$e->getMessage()}");
            }
        }

        if (isset($additionalContext['instance_id'])) {
            try {
                $instancesTable = TableRegistry::getTableLocator()->get('WorkflowInstances');
                $instance = $instancesTable->get($additionalContext['instance_id']);
                $entityTable = TableRegistry::getTableLocator()->get($instance->entity_type);
                $entity = $entityTable->get($instance->entity_id);
                $context['entity'] = $entity->toArray();
                $context['entity_type'] = $instance->entity_type;
                $context['instance'] = $instance->toArray();
            } catch (\Exception $e) {
                Log::warning("VisibilityEvaluator: Could not load entity: {$e->getMessage()}");
            }
        }

        return $context;
    }
}
