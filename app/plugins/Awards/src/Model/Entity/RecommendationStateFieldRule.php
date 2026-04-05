<?php

declare(strict_types=1);

namespace Awards\Model\Entity;

use App\Model\Entity\BaseEntity;

/**
 * RecommendationStateFieldRule Entity - Field visibility/requirement rules per state.
 *
 * @property int $id
 * @property int $state_id
 * @property string $field_target
 * @property string $rule_type
 * @property string|null $rule_value
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime|null $modified
 * @property int|null $created_by
 * @property int|null $modified_by
 *
 * @property \Awards\Model\Entity\RecommendationState $recommendation_state
 */
class RecommendationStateFieldRule extends BaseEntity
{
    /**
     * @var array<string, bool>
     */
    protected array $_accessible = [
        'state_id' => true,
        'field_target' => true,
        'rule_type' => true,
        'rule_value' => true,
        'created' => true,
        'modified' => true,
        'created_by' => true,
        'modified_by' => true,
    ];
}
