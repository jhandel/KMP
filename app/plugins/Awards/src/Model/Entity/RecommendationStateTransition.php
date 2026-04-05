<?php

declare(strict_types=1);

namespace Awards\Model\Entity;

use App\Model\Entity\BaseEntity;

/**
 * RecommendationStateTransition Entity - Valid state-to-state transition.
 *
 * @property int $id
 * @property int $from_state_id
 * @property int $to_state_id
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime|null $modified
 * @property int|null $created_by
 * @property int|null $modified_by
 *
 * @property \Awards\Model\Entity\RecommendationState $from_state
 * @property \Awards\Model\Entity\RecommendationState $to_state
 */
class RecommendationStateTransition extends BaseEntity
{
    /**
     * @var array<string, bool>
     */
    protected array $_accessible = [
        'from_state_id' => true,
        'to_state_id' => true,
        'created' => true,
        'modified' => true,
        'created_by' => true,
        'modified_by' => true,
    ];
}
