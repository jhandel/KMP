<?php

declare(strict_types=1);

namespace App\Model\Entity;

/**
 * WorkflowApprovalGate Entity
 *
 * Defines an approval gate on a workflow state, specifying required approvals,
 * thresholds, approver rules, and timeout/transition behavior.
 *
 * @property int $id
 * @property int $workflow_state_id
 * @property string $approval_type
 * @property int $required_count
 * @property string|null $threshold_config
 * @property string|null $approver_rule
 * @property int|null $timeout_hours
 * @property int|null $timeout_transition_id
 * @property int|null $on_satisfied_transition_id
 * @property int|null $on_denied_transition_id
 * @property bool $allow_delegation
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime|null $modified
 * @property int|null $created_by
 * @property int|null $modified_by
 *
 * @property \App\Model\Entity\WorkflowState $workflow_state
 */
class WorkflowApprovalGate extends BaseEntity
{
    protected array $_accessible = [
        '*' => true,
        'id' => false,
    ];

    protected function _getDecodedThresholdConfig(): array
    {
        $raw = $this->threshold_config;
        if ($raw === null || $raw === '') {
            return [];
        }

        return json_decode($raw, true) ?? [];
    }

    protected function _getDecodedApproverRule(): array
    {
        $raw = $this->approver_rule;
        if ($raw === null || $raw === '') {
            return [];
        }

        return json_decode($raw, true) ?? [];
    }
}
