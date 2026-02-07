<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\RulesChecker;
use Cake\Validation\Validator;

/**
 * WorkflowApprovals Model
 *
 * Tracks individual approval decisions for workflow approval gates.
 *
 * @property \App\Model\Table\WorkflowInstancesTable&\Cake\ORM\Association\BelongsTo $WorkflowInstances
 * @property \App\Model\Table\WorkflowApprovalGatesTable&\Cake\ORM\Association\BelongsTo $WorkflowApprovalGates
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
            ->integer('id')
            ->allowEmptyString('id', null, 'create');

        $validator
            ->integer('workflow_instance_id')
            ->requirePresence('workflow_instance_id', 'create')
            ->notEmptyString('workflow_instance_id');

        $validator
            ->integer('approval_gate_id')
            ->requirePresence('approval_gate_id', 'create')
            ->notEmptyString('approval_gate_id');

        $validator
            ->integer('approver_id')
            ->allowEmptyString('approver_id');

        $validator
            ->scalar('token')
            ->maxLength('token', 255)
            ->allowEmptyString('token');

        $validator
            ->scalar('decision')
            ->maxLength('decision', 50)
            ->inList('decision', \App\Model\Entity\WorkflowApproval::VALID_DECISIONS)
            ->allowEmptyString('decision');

        $validator
            ->scalar('comment')
            ->allowEmptyString('comment');

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
        $rules->add($rules->existsIn('workflow_instance_id', 'WorkflowInstances'));
        $rules->add($rules->existsIn('approval_gate_id', 'WorkflowApprovalGates'));
        $rules->add($rules->isUnique(['token'], 'This token is already in use.'));

        return $rules;
    }
}
