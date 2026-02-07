<?php

declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\RulesChecker;
use Cake\Validation\Validator;

/**
 * WorkflowVisibilityRules Table
 *
 * Manages field/section visibility rules tied to specific workflow states.
 */
class WorkflowVisibilityRulesTable extends BaseTable
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('workflow_visibility_rules');
        $this->setDisplayField('id');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('WorkflowStates', [
            'foreignKey' => 'workflow_state_id',
            'joinType' => 'INNER',
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->requirePresence('workflow_state_id', 'create')
            ->notEmptyString('workflow_state_id');

        $validator
            ->requirePresence('rule_type', 'create')
            ->notEmptyString('rule_type');

        $validator
            ->requirePresence('target', 'create')
            ->notEmptyString('target');

        $validator
            ->integer('priority');

        return $validator;
    }

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add(
            $rules->existsIn(['workflow_state_id'], 'WorkflowStates'),
        );

        return $rules;
    }
}
