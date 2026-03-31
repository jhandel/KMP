<?php

declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\RulesChecker;
use Cake\Validation\Validator;

/**
 * WorkflowSchedules Model
 *
 * Tracks last/next run times for scheduled workflow definitions.
 *
 * @property \App\Model\Table\WorkflowDefinitionsTable&\Cake\ORM\Association\BelongsTo $WorkflowDefinitions
 *
 * @method \App\Model\Entity\WorkflowSchedule newEmptyEntity()
 * @method \App\Model\Entity\WorkflowSchedule newEntity(array $data, array $options = [])
 * @method \App\Model\Entity\WorkflowSchedule patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method \App\Model\Entity\WorkflowSchedule get(mixed $primaryKey, array|string $finder = 'all', \Psr\SimpleCache\CacheInterface|string|null $cache = null, \Closure|string|null $cacheKey = null, mixed ...$args)
 * @method \App\Model\Entity\WorkflowSchedule findOrCreate($search, ?callable $callback = null, array $options = [])
 * @method \App\Model\Entity\WorkflowSchedule saveOrFail(\Cake\Datasource\EntityInterface $entity, array $options = [])
 */
class WorkflowSchedulesTable extends BaseTable
{
    /**
     * @param array $config Configuration options
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('workflow_schedules');
        $this->setDisplayField('id');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('WorkflowDefinitions', [
            'foreignKey' => 'workflow_definition_id',
            'joinType' => 'INNER',
        ]);
    }

    /**
     * @param \Cake\Validation\Validator $validator Validator instance
     * @return \Cake\Validation\Validator
     */
    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->integer('workflow_definition_id')
            ->requirePresence('workflow_definition_id', 'create')
            ->notEmptyString('workflow_definition_id');

        $validator
            ->dateTime('last_run_at')
            ->allowEmptyDateTime('last_run_at');

        $validator
            ->dateTime('next_run_at')
            ->allowEmptyDateTime('next_run_at');

        $validator
            ->boolean('is_enabled')
            ->notEmptyString('is_enabled');

        return $validator;
    }

    /**
     * @param \Cake\ORM\RulesChecker $rules Rules checker instance
     * @return \Cake\ORM\RulesChecker
     */
    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->existsIn(['workflow_definition_id'], 'WorkflowDefinitions'));
        $rules->add($rules->isUnique(['workflow_definition_id']));

        return $rules;
    }
}
