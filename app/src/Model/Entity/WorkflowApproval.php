<?php

declare(strict_types=1);

namespace App\Model\Entity;

/**
 * WorkflowApproval Entity
 *
 * Tracks an individual approval decision for a workflow instance's approval gate.
 *
 * @property int $id
 * @property int $workflow_instance_id
 * @property int $approval_gate_id
 * @property int|null $approver_id
 * @property string|null $decision
 * @property string|null $notes
 * @property int|null $approval_order
 * @property int|null $delegated_from_id
 * @property string|null $token
 * @property \Cake\I18n\DateTime|null $requested_at
 * @property \Cake\I18n\DateTime|null $responded_at
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime|null $modified
 * @property int|null $created_by
 * @property int|null $modified_by
 *
 * @property \App\Model\Entity\WorkflowInstance $workflow_instance
 * @property \App\Model\Entity\WorkflowApprovalGate $workflow_approval_gate
 */
class WorkflowApproval extends BaseEntity
{
    protected array $_accessible = [
        '*' => true,
        'id' => false,
    ];
}
