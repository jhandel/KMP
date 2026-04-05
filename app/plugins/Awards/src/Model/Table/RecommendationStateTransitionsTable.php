<?php

declare(strict_types=1);

namespace Awards\Model\Table;

use Cake\ORM\RulesChecker;
use Cake\Validation\Validator;
use App\Model\Table\BaseTable;

/**
 * RecommendationStateTransitions Table - Valid state-to-state transitions.
 *
 * @property \Awards\Model\Table\RecommendationStatesTable&\Cake\ORM\Association\BelongsTo $FromStates
 * @property \Awards\Model\Table\RecommendationStatesTable&\Cake\ORM\Association\BelongsTo $ToStates
 *
 * @method \Awards\Model\Entity\RecommendationStateTransition newEmptyEntity()
 * @method \Awards\Model\Entity\RecommendationStateTransition newEntity(array $data, array $options = [])
 * @method \Awards\Model\Entity\RecommendationStateTransition get(mixed $primaryKey, array|string $finder = 'all', \Psr\SimpleCache\CacheInterface|string|null $cache = null, \Closure|string|null $cacheKey = null, mixed ...$args)
 * @method \Awards\Model\Entity\RecommendationStateTransition patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method \Awards\Model\Entity\RecommendationStateTransition|false save(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method \Awards\Model\Entity\RecommendationStateTransition saveOrFail(\Cake\Datasource\EntityInterface $entity, array $options = [])
 *
 * @mixin \Cake\ORM\Behavior\TimestampBehavior
 * @mixin \Muffin\Footprint\Model\Behavior\FootprintBehavior
 */
class RecommendationStateTransitionsTable extends BaseTable
{
    /**
     * @param array<string, mixed> $config Configuration options
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('awards_recommendation_state_transitions');
        $this->setPrimaryKey('id');

        $this->belongsTo('FromStates', [
            'foreignKey' => 'from_state_id',
            'joinType' => 'INNER',
            'className' => 'Awards.RecommendationStates',
        ]);

        $this->belongsTo('ToStates', [
            'foreignKey' => 'to_state_id',
            'joinType' => 'INNER',
            'className' => 'Awards.RecommendationStates',
        ]);

        $this->addBehavior('Timestamp');
        $this->addBehavior('Muffin/Footprint.Footprint');
    }

    /**
     * Default validation rules.
     *
     * @param \Cake\Validation\Validator $validator Validator instance
     * @return \Cake\Validation\Validator
     */
    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->integer('from_state_id')
            ->requirePresence('from_state_id', 'create')
            ->notEmptyString('from_state_id');

        $validator
            ->integer('to_state_id')
            ->requirePresence('to_state_id', 'create')
            ->notEmptyString('to_state_id');

        return $validator;
    }

    /**
     * @param \Cake\ORM\RulesChecker $rules The rules object
     * @return \Cake\ORM\RulesChecker
     */
    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->existsIn(['from_state_id'], 'FromStates'), [
            'errorField' => 'from_state_id',
            'message' => 'Invalid from state.',
        ]);
        $rules->add($rules->existsIn(['to_state_id'], 'ToStates'), [
            'errorField' => 'to_state_id',
            'message' => 'Invalid to state.',
        ]);
        $rules->add($rules->isUnique(['from_state_id', 'to_state_id']), [
            'errorField' => 'to_state_id',
            'message' => 'This transition already exists.',
        ]);

        return $rules;
    }
}
