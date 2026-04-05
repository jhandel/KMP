<?php

declare(strict_types=1);

namespace Awards\Model\Table;

use Cake\ORM\RulesChecker;
use Cake\Validation\Validator;
use App\Model\Table\BaseTable;

/**
 * RecommendationStates Table - Manages recommendation workflow states.
 *
 * @property \Awards\Model\Table\RecommendationStatusesTable&\Cake\ORM\Association\BelongsTo $RecommendationStatuses
 * @property \Awards\Model\Table\RecommendationStateFieldRulesTable&\Cake\ORM\Association\HasMany $RecommendationStateFieldRules
 * @property \Awards\Model\Table\RecommendationStateTransitionsTable&\Cake\ORM\Association\HasMany $OutgoingTransitions
 * @property \Awards\Model\Table\RecommendationStateTransitionsTable&\Cake\ORM\Association\HasMany $IncomingTransitions
 *
 * @method \Awards\Model\Entity\RecommendationState newEmptyEntity()
 * @method \Awards\Model\Entity\RecommendationState newEntity(array $data, array $options = [])
 * @method \Awards\Model\Entity\RecommendationState get(mixed $primaryKey, array|string $finder = 'all', \Psr\SimpleCache\CacheInterface|string|null $cache = null, \Closure|string|null $cacheKey = null, mixed ...$args)
 * @method \Awards\Model\Entity\RecommendationState patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method \Awards\Model\Entity\RecommendationState|false save(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method \Awards\Model\Entity\RecommendationState saveOrFail(\Cake\Datasource\EntityInterface $entity, array $options = [])
 *
 * @mixin \Cake\ORM\Behavior\TimestampBehavior
 * @mixin \Muffin\Footprint\Model\Behavior\FootprintBehavior
 * @mixin \Muffin\Trash\Model\Behavior\TrashBehavior
 */
class RecommendationStatesTable extends BaseTable
{
    /**
     * @param array<string, mixed> $config Configuration options
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('awards_recommendation_states');
        $this->setDisplayField('name');
        $this->setPrimaryKey('id');

        $this->belongsTo('RecommendationStatuses', [
            'foreignKey' => 'status_id',
            'joinType' => 'INNER',
            'className' => 'Awards.RecommendationStatuses',
        ]);

        $this->hasMany('RecommendationStateFieldRules', [
            'foreignKey' => 'state_id',
            'className' => 'Awards.RecommendationStateFieldRules',
            'dependent' => true,
            'cascadeCallbacks' => true,
        ]);

        $this->hasMany('OutgoingTransitions', [
            'foreignKey' => 'from_state_id',
            'className' => 'Awards.RecommendationStateTransitions',
            'dependent' => true,
            'cascadeCallbacks' => true,
        ]);

        $this->hasMany('IncomingTransitions', [
            'foreignKey' => 'to_state_id',
            'className' => 'Awards.RecommendationStateTransitions',
            'dependent' => true,
            'cascadeCallbacks' => true,
        ]);

        $this->addBehavior('Timestamp');
        $this->addBehavior('Muffin/Footprint.Footprint');
        $this->addBehavior('Muffin/Trash.Trash');
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
            ->scalar('name')
            ->maxLength('name', 255)
            ->requirePresence('name', 'create')
            ->notEmptyString('name')
            ->add('name', 'unique', ['rule' => 'validateUnique', 'provider' => 'table']);

        $validator
            ->integer('status_id')
            ->requirePresence('status_id', 'create')
            ->notEmptyString('status_id');

        $validator
            ->integer('sort_order')
            ->requirePresence('sort_order', 'create')
            ->notEmptyString('sort_order');

        $validator
            ->boolean('supports_gathering')
            ->notEmptyString('supports_gathering');

        $validator
            ->boolean('is_hidden')
            ->notEmptyString('is_hidden');

        $validator
            ->boolean('is_system')
            ->notEmptyString('is_system');

        $validator
            ->integer('created_by')
            ->allowEmptyString('created_by');

        $validator
            ->integer('modified_by')
            ->allowEmptyString('modified_by');

        $validator
            ->dateTime('deleted')
            ->allowEmptyDateTime('deleted');

        return $validator;
    }

    /**
     * @param \Cake\ORM\RulesChecker $rules The rules object
     * @return \Cake\ORM\RulesChecker
     */
    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->isUnique(['name']), ['errorField' => 'name']);
        $rules->add($rules->existsIn(['status_id'], 'RecommendationStatuses'), [
            'errorField' => 'status_id',
            'message' => 'Invalid status.',
        ]);

        // Prevent editing system states
        $rules->addUpdate(function ($entity) {
            if ($entity->getOriginal('is_system') && $entity->isDirty()) {
                return false;
            }
            return true;
        }, 'systemStateProtection', [
            'errorField' => 'is_system',
            'message' => 'System states cannot be modified.',
        ]);

        // Prevent deleting system states
        $rules->addDelete(function ($entity) {
            return !$entity->is_system;
        }, 'systemStateDeleteProtection', [
            'errorField' => 'is_system',
            'message' => 'System states cannot be deleted.',
        ]);

        return $rules;
    }
}
