<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * WorkflowVisibilityRule Entity
 *
 * Defines visibility and editability rules that apply when an entity is in a given state.
 *
 * @property int $id
 * @property int $workflow_state_id
 * @property string $rule_type
 * @property string $target
 * @property string|null $condition
 * @property \Cake\I18n\DateTime|null $created
 * @property \Cake\I18n\DateTime|null $modified
 *
 * @property \App\Model\Entity\WorkflowState $workflow_state
 */
class WorkflowVisibilityRule extends Entity
{
    public const RULE_CAN_VIEW_ENTITY = 'can_view_entity';
    public const RULE_CAN_EDIT_ENTITY = 'can_edit_entity';
    public const RULE_CAN_VIEW_FIELD = 'can_view_field';
    public const RULE_CAN_EDIT_FIELD = 'can_edit_field';

    public const VALID_RULE_TYPES = [
        self::RULE_CAN_VIEW_ENTITY,
        self::RULE_CAN_EDIT_ENTITY,
        self::RULE_CAN_VIEW_FIELD,
        self::RULE_CAN_EDIT_FIELD,
    ];

    protected array $_accessible = [
        '*' => true,
        'id' => false,
    ];

    /**
     * Decode condition JSON to array.
     */
    protected function _getDecodedCondition(): array
    {
        $condition = $this->condition;
        if (empty($condition)) {
            return [];
        }
        if (is_string($condition)) {
            return json_decode($condition, true) ?? [];
        }
        return (array)$condition;
    }
}
