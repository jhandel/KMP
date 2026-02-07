<?php

declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\RulesChecker;
use Cake\Validation\Validator;

/**
 * WorkflowApprovals Table
 *
 * Tracks individual approval decisions for workflow instance approval gates.
 */
class WorkflowApprovalsTable extends BaseTable
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('workflow_approvals');
        $this->setDisplayField('id');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('WorkflowInstances', [
            'foreignKey' => 'workflow_instance_id',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('WorkflowApprovalGates', [
            'foreignKey' => 'approval_gate_id',
            'joinType' => 'INNER',
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->requirePresence('workflow_instance_id', 'create')
            ->notEmptyString('workflow_instance_id');

        $validator
            ->requirePresence('approval_gate_id', 'create')
            ->notEmptyString('approval_gate_id');

        $validator
            ->dateTime('requested_at')
            ->allowEmptyDateTime('requested_at');

        $validator
            ->dateTime('responded_at')
            ->allowEmptyDateTime('responded_at');

        return $validator;
    }

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add(
            $rules->existsIn(['workflow_instance_id'], 'WorkflowInstances'),
        );
        $rules->add(
            $rules->existsIn(['approval_gate_id'], 'WorkflowApprovalGates'),
        );

        return $rules;
    }
}
