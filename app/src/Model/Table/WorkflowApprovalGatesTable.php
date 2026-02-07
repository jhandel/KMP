<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\RulesChecker;
use Cake\Validation\Validator;

/**
 * WorkflowApprovalGates Model
 *
 * Manages approval gate definitions attached to workflow states.
 *
 * @property \App\Model\Table\WorkflowStatesTable&\Cake\ORM\Association\BelongsTo $WorkflowStates
 * @property \App\Model\Table\WorkflowTransitionsTable&\Cake\ORM\Association\BelongsTo $TimeoutTransition
 * @property \App\Model\Table\WorkflowApprovalsTable&\Cake\ORM\Association\HasMany $WorkflowApprovals
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

        $this->belongsTo('TimeoutTransition', [
            'className' => 'WorkflowTransitions',
            'foreignKey' => 'timeout_transition_id',
            'joinType' => 'LEFT',
        ]);

        $this->hasMany('WorkflowApprovals', [
            'foreignKey' => 'approval_gate_id',
            'dependent' => true,
            'cascadeCallbacks' => true,
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->integer('id')
            ->allowEmptyString('id', null, 'create');

        $validator
            ->integer('workflow_state_id')
            ->requirePresence('workflow_state_id', 'create')
            ->notEmptyString('workflow_state_id');

        $validator
            ->scalar('approval_type')
            ->maxLength('approval_type', 50)
            ->inList('approval_type', \App\Model\Entity\WorkflowApprovalGate::VALID_APPROVAL_TYPES)
            ->requirePresence('approval_type', 'create')
            ->notEmptyString('approval_type');

        $validator
            ->integer('required_count')
            ->requirePresence('required_count', 'create')
            ->notEmptyString('required_count');

        $validator
            ->integer('timeout_transition_id')
            ->allowEmptyString('timeout_transition_id');

        $validator
            ->integer('timeout_hours')
            ->allowEmptyString('timeout_hours');

        return $validator;
    }

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->existsIn('workflow_state_id', 'WorkflowStates'));

        return $rules;
    }
}
