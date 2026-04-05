<?php

declare(strict_types=1);

namespace Awards\Model\Entity;

use App\Model\Entity\BaseEntity;

/**
 * RecommendationStatus Entity - High-level workflow category.
 *
 * @property int $id
 * @property string $name
 * @property int $sort_order
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime|null $modified
 * @property int|null $created_by
 * @property int|null $modified_by
 * @property \Cake\I18n\DateTime|null $deleted
 *
 * @property \Awards\Model\Entity\RecommendationState[] $recommendation_states
 */
class RecommendationStatus extends BaseEntity
{
    /**
     * @var array<string, bool>
     */
    protected array $_accessible = [
        'name' => true,
        'sort_order' => true,
        'created' => true,
        'modified' => true,
        'created_by' => true,
        'modified_by' => true,
        'deleted' => true,
    ];
}
