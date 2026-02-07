<?php

declare(strict_types=1);

namespace App\Model\Entity;

/**
 * WorkflowInstance Entity
 *
 * Represents a running workflow for a specific entity. Tracks the current
 * and previous state, context data, and completion status.
 *
 * @property int $id
 * @property int $workflow_definition_id
 * @property string $entity_type
 * @property int $entity_id
 * @property int $current_state_id
 * @property int|null $previous_state_id
 * @property string|null $context
 * @property \Cake\I18n\DateTime $started_at
 * @property \Cake\I18n\DateTime|null $completed_at
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime|null $modified
 * @property int|null $created_by
 * @property int|null $modified_by
 *
 * @property \App\Model\Entity\WorkflowDefinition $workflow_definition
 * @property \App\Model\Entity\WorkflowState $current_state
 * @property \App\Model\Entity\WorkflowState|null $previous_state
 * @property \App\Model\Entity\WorkflowTransitionLog[] $workflow_transition_logs
 * @property \App\Model\Entity\WorkflowApproval[] $workflow_approvals
 */
class WorkflowInstance extends BaseEntity
{
    protected array $_accessible = [
        '*' => true,
        'id' => false,
    ];

    protected function _getDecodedContext(): array
    {
        $raw = $this->context;
        if ($raw === null || $raw === '') {
            return [];
        }

        return json_decode($raw, true) ?? [];
    }
}
