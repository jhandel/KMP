<?php

declare(strict_types=1);

namespace Awards\Model\Table;

use Cake\ORM\RulesChecker;
use Cake\Validation\Validator;
use App\Model\Table\BaseTable;

/**
 * RecommendationStatuses Table - Manages recommendation status categories.
 *
 * @property \Awards\Model\Table\RecommendationStatesTable&\Cake\ORM\Association\HasMany $RecommendationStates
 *
 * @method \Awards\Model\Entity\RecommendationStatus newEmptyEntity()
 * @method \Awards\Model\Entity\RecommendationStatus newEntity(array $data, array $options = [])
 * @method \Awards\Model\Entity\RecommendationStatus get(mixed $primaryKey, array|string $finder = 'all', \Psr\SimpleCache\CacheInterface|string|null $cache = null, \Closure|string|null $cacheKey = null, mixed ...$args)
 * @method \Awards\Model\Entity\RecommendationStatus patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method \Awards\Model\Entity\RecommendationStatus|false save(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method \Awards\Model\Entity\RecommendationStatus saveOrFail(\Cake\Datasource\EntityInterface $entity, array $options = [])
 *
 * @mixin \Cake\ORM\Behavior\TimestampBehavior
 * @mixin \Muffin\Footprint\Model\Behavior\FootprintBehavior
 * @mixin \Muffin\Trash\Model\Behavior\TrashBehavior
 */
class RecommendationStatusesTable extends BaseTable
{
    /**
     * @param array<string, mixed> $config Configuration options
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('awards_recommendation_statuses');
        $this->setDisplayField('name');
        $this->setPrimaryKey('id');

        $this->hasMany('RecommendationStates', [
            'foreignKey' => 'status_id',
            'className' => 'Awards.RecommendationStates',
            'dependent' => false,
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
            ->integer('sort_order')
            ->requirePresence('sort_order', 'create')
            ->notEmptyString('sort_order');

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

        return $rules;
    }
}
