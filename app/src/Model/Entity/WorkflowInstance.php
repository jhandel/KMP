<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * WorkflowInstance Entity
 *
 * Represents an active workflow instance tracking an entity through its lifecycle.
 *
 * @property int $id
 * @property int $workflow_definition_id
 * @property string $entity_type
 * @property int $entity_id
 * @property int $current_state_id
 * @property int|null $previous_state_id
 * @property string|null $context
 * @property \Cake\I18n\DateTime|null $completed_at
 * @property \Cake\I18n\DateTime|null $created
 * @property \Cake\I18n\DateTime|null $modified
 *
 * @property \App\Model\Entity\WorkflowDefinition $workflow_definition
 * @property \App\Model\Entity\WorkflowState $current_state
 * @property \App\Model\Entity\WorkflowState|null $previous_state
 * @property \App\Model\Entity\WorkflowTransitionLog[] $workflow_transition_logs
 * @property \App\Model\Entity\WorkflowApproval[] $workflow_approvals
 */
class WorkflowInstance extends Entity
{
    protected array $_accessible = [
        '*' => true,
        'id' => false,
    ];

    /**
     * Decode context JSON to array.
     */
    protected function _getDecodedContext(): array
    {
        $context = $this->context;
        if (empty($context)) {
            return [];
        }
        if (is_string($context)) {
            return json_decode($context, true) ?? [];
        }
        return (array)$context;
    }

    /**
     * Whether this instance has completed.
     */
    protected function _getIsCompleted(): bool
    {
        return $this->completed_at !== null;
    }
}
