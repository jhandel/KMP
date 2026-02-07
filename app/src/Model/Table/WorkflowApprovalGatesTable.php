<?php

declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\RulesChecker;
use Cake\Validation\Validator;

/**
 * WorkflowApprovalGates Table
 *
 * Manages approval gates on workflow states, defining required approvals,
 * thresholds, and timeout/transition behavior.
 */
class WorkflowApprovalGatesTable extends BaseTable
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('workflow_approval_gates');
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
            ->requirePresence('approval_type', 'create')
            ->notEmptyString('approval_type');

        $validator
            ->integer('required_count')
            ->requirePresence('required_count', 'create')
            ->notEmptyString('required_count');

        $validator
            ->boolean('allow_delegation');

        $validator
            ->integer('timeout_hours')
            ->allowEmptyString('timeout_hours');

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
