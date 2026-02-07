<?php

declare(strict_types=1);

namespace App\Model\Entity;

/**
 * WorkflowVisibilityRule Entity
 *
 * Defines field/section visibility rules for a specific workflow state.
 *
 * @property int $id
 * @property int $workflow_state_id
 * @property string $rule_type
 * @property string $target
 * @property string|null $condition
 * @property int $priority
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime|null $modified
 * @property int|null $created_by
 * @property int|null $modified_by
 *
 * @property \App\Model\Entity\WorkflowState $workflow_state
 */
class WorkflowVisibilityRule extends BaseEntity
{
    protected array $_accessible = [
        '*' => true,
        'id' => false,
    ];

    protected function _getDecodedCondition(): array
    {
        $raw = $this->condition;
        if ($raw === null || $raw === '') {
            return [];
        }

        return json_decode($raw, true) ?? [];
    }
}
