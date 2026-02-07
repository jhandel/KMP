<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * WorkflowApprovalGate Entity
 *
 * Defines an approval requirement on a workflow state, specifying how many and
 * what type of approvals are needed before the workflow can advance.
 *
 * @property int $id
 * @property int $workflow_state_id
 * @property string $approval_type
 * @property int $required_count
 * @property string|null $approver_rule
 * @property int|null $timeout_transition_id
 * @property int|null $timeout_hours
 * @property \Cake\I18n\DateTime|null $created
 * @property \Cake\I18n\DateTime|null $modified
 *
 * @property \App\Model\Entity\WorkflowState $workflow_state
 * @property \App\Model\Entity\WorkflowTransition|null $timeout_transition
 * @property \App\Model\Entity\WorkflowApproval[] $workflow_approvals
 */
class WorkflowApprovalGate extends Entity
{
    public const TYPE_THRESHOLD = 'threshold';
    public const TYPE_UNANIMOUS = 'unanimous';
    public const TYPE_ANY_ONE = 'any_one';
    public const TYPE_CHAIN = 'chain';

    public const VALID_APPROVAL_TYPES = [
        self::TYPE_THRESHOLD,
        self::TYPE_UNANIMOUS,
        self::TYPE_ANY_ONE,
        self::TYPE_CHAIN,
    ];

    protected array $_accessible = [
        '*' => true,
        'id' => false,
    ];

    /**
     * Decode approver_rule JSON to array.
     */
    protected function _getDecodedApproverRule(): array
    {
        $rule = $this->approver_rule;
        if (empty($rule)) {
            return [];
        }
        if (is_string($rule)) {
            return json_decode($rule, true) ?? [];
        }
        return (array)$rule;
    }
}
