<?php

declare(strict_types=1);

namespace App\Model\Entity;

/**
 * WorkflowTransitionLog Entity
 *
 * Append-only audit log recording each state transition for a workflow instance.
 *
 * @property int $id
 * @property int $workflow_instance_id
 * @property int|null $from_state_id
 * @property int $to_state_id
 * @property int|null $transition_id
 * @property int|null $triggered_by
 * @property string $trigger_type
 * @property string|null $context_snapshot
 * @property string|null $notes
 * @property \Cake\I18n\DateTime $created
 *
 * @property \App\Model\Entity\WorkflowInstance $workflow_instance
 */
class WorkflowTransitionLog extends BaseEntity
{
    protected array $_accessible = [
        '*' => true,
        'id' => false,
    ];

    protected function _getDecodedContextSnapshot(): array
    {
        $raw = $this->context_snapshot;
        if ($raw === null || $raw === '') {
            return [];
        }

        return json_decode($raw, true) ?? [];
    }
}
