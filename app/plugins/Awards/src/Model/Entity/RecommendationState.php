<?php

declare(strict_types=1);

namespace Awards\Model\Entity;

use App\Model\Entity\BaseEntity;

/**
 * RecommendationState Entity - Specific workflow position within a status category.
 *
 * @property int $id
 * @property int $status_id
 * @property string $name
 * @property int $sort_order
 * @property bool $supports_gathering
 * @property bool $is_hidden
 * @property bool $is_system
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime|null $modified
 * @property int|null $created_by
 * @property int|null $modified_by
 * @property \Cake\I18n\DateTime|null $deleted
 *
 * @property \Awards\Model\Entity\RecommendationStatus $recommendation_status
 * @property \Awards\Model\Entity\RecommendationStateFieldRule[] $recommendation_state_field_rules
 * @property \Awards\Model\Entity\RecommendationStateTransition[] $outgoing_transitions
 * @property \Awards\Model\Entity\RecommendationStateTransition[] $incoming_transitions
 */
class RecommendationState extends BaseEntity
{
    /**
     * @var array<string, bool>
     */
    protected array $_accessible = [
        'status_id' => true,
        'name' => true,
        'sort_order' => true,
        'supports_gathering' => true,
        'is_hidden' => true,
        'is_system' => true,
        'created' => true,
        'modified' => true,
        'created_by' => true,
        'modified_by' => true,
        'deleted' => true,
    ];
}
