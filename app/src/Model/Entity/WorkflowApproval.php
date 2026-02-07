<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * WorkflowApproval Entity
 *
 * Tracks an individual approval decision within a workflow approval gate.
 *
 * @property int $id
 * @property int $workflow_instance_id
 * @property int $approval_gate_id
 * @property int|null $approver_id
 * @property string|null $token
 * @property string|null $decision
 * @property string|null $comment
 * @property \Cake\I18n\DateTime|null $requested_at
 * @property \Cake\I18n\DateTime|null $responded_at
 * @property \Cake\I18n\DateTime|null $created
 * @property \Cake\I18n\DateTime|null $modified
 *
 * @property \App\Model\Entity\WorkflowInstance $workflow_instance
 * @property \App\Model\Entity\WorkflowApprovalGate $workflow_approval_gate
 */
class WorkflowApproval extends Entity
{
    public const DECISION_APPROVED = 'approved';
    public const DECISION_DENIED = 'denied';
    public const DECISION_ABSTAINED = 'abstained';

    public const VALID_DECISIONS = [
        self::DECISION_APPROVED,
        self::DECISION_DENIED,
        self::DECISION_ABSTAINED,
    ];

    protected array $_accessible = [
        '*' => true,
        'id' => false,
    ];

    /**
     * Whether this approval has been responded to.
     */
    protected function _getIsResponded(): bool
    {
        return $this->responded_at !== null;
    }

    /**
     * Whether this approval was approved.
     */
    protected function _getIsApproved(): bool
    {
        return $this->decision === self::DECISION_APPROVED;
    }
}
