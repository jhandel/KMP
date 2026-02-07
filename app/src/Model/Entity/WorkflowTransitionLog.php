<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * WorkflowTransitionLog Entity
 *
 * Immutable audit record of a state transition within a workflow instance.
 *
 * @property int $id
 * @property int $workflow_instance_id
 * @property int|null $from_state_id
 * @property int $to_state_id
 * @property int|null $transition_id
 * @property string $trigger_type
 * @property int|null $triggered_by
 * @property string|null $context_snapshot
 * @property string|null $comment
 * @property \Cake\I18n\DateTime $created
 *
 * @property \App\Model\Entity\WorkflowInstance $workflow_instance
 * @property \App\Model\Entity\WorkflowState|null $from_state
 * @property \App\Model\Entity\WorkflowState $to_state
 * @property \App\Model\Entity\WorkflowTransition|null $workflow_transition
 */
class WorkflowTransitionLog extends Entity
{
    protected array $_accessible = [
        '*' => true,
        'id' => false,
    ];

    /**
     * Decode context_snapshot JSON to array.
     */
    protected function _getDecodedContextSnapshot(): array
    {
        $snapshot = $this->context_snapshot;
        if (empty($snapshot)) {
            return [];
        }
        if (is_string($snapshot)) {
            return json_decode($snapshot, true) ?? [];
        }
        return (array)$snapshot;
    }
}
