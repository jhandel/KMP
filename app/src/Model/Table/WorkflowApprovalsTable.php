<?php

declare(strict_types=1);

namespace App\Model\Table;

use App\Model\Entity\WorkflowApproval;
use App\Services\WorkflowEngine\DefaultWorkflowApprovalManager;
use Cake\Log\Log;
use Cake\ORM\RulesChecker;
use Cake\Validation\Validator;

/**
 * WorkflowApprovals Model
 *
 * @property \App\Model\Table\WorkflowInstancesTable&\Cake\ORM\Association\BelongsTo $WorkflowInstances
 * @property \App\Model\Table\WorkflowExecutionLogsTable&\Cake\ORM\Association\BelongsTo $WorkflowExecutionLogs
 * @property \App\Model\Table\WorkflowApprovalResponsesTable&\Cake\ORM\Association\HasMany $WorkflowApprovalResponses
 *
 * @method \App\Model\Entity\WorkflowApproval newEmptyEntity()
 * @method \App\Model\Entity\WorkflowApproval newEntity(array $data, array $options = [])
 * @method \App\Model\Entity\WorkflowApproval patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 */
class WorkflowApprovalsTable extends BaseTable
{
    /**
     * Initialize method.
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('workflow_approvals');
        $this->setDisplayField('status');
        $this->setPrimaryKey('id');

        $this->belongsTo('WorkflowInstances', [
            'foreignKey' => 'workflow_instance_id',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('WorkflowExecutionLogs', [
            'foreignKey' => 'execution_log_id',
            'joinType' => 'INNER',
        ]);
        $this->hasMany('WorkflowApprovalResponses', [
            'foreignKey' => 'workflow_approval_id',
            'dependent' => true,
        ]);

        $this->addBehavior('Timestamp');

        // MariaDB stores JSON as longtext; explicitly map JSON columns
        $this->getSchema()->setColumnType('approver_config', 'json');
        $this->getSchema()->setColumnType('escalation_config', 'json');
    }

    /**
     * Default validation rules.
     */
    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->integer('workflow_instance_id')
            ->requirePresence('workflow_instance_id', 'create')
            ->notEmptyString('workflow_instance_id');

        $validator
            ->scalar('node_id')
            ->maxLength('node_id', 100)
            ->requirePresence('node_id', 'create')
            ->notEmptyString('node_id');

        $validator
            ->integer('execution_log_id')
            ->requirePresence('execution_log_id', 'create')
            ->notEmptyString('execution_log_id');

        $validator
            ->scalar('approver_type')
            ->requirePresence('approver_type', 'create')
            ->notEmptyString('approver_type')
            ->inList('approver_type', [
                WorkflowApproval::APPROVER_TYPE_PERMISSION,
                WorkflowApproval::APPROVER_TYPE_ROLE,
                WorkflowApproval::APPROVER_TYPE_MEMBER,
                WorkflowApproval::APPROVER_TYPE_DYNAMIC,
                WorkflowApproval::APPROVER_TYPE_POLICY,
            ]);

        $validator
            ->integer('required_count')
            ->notEmptyString('required_count');

        $validator
            ->integer('approved_count')
            ->notEmptyString('approved_count');

        $validator
            ->integer('rejected_count')
            ->notEmptyString('rejected_count');

        $validator
            ->scalar('status')
            ->requirePresence('status', 'create')
            ->notEmptyString('status')
            ->inList('status', [
                WorkflowApproval::STATUS_PENDING,
                WorkflowApproval::STATUS_APPROVED,
                WorkflowApproval::STATUS_REJECTED,
                WorkflowApproval::STATUS_EXPIRED,
                WorkflowApproval::STATUS_CANCELLED,
            ]);

        $validator
            ->boolean('allow_parallel')
            ->notEmptyString('allow_parallel');

        $validator
            ->dateTime('deadline')
            ->allowEmptyDateTime('deadline');

        return $validator;
    }

    /**
     * Build rules for referential integrity.
     */
    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->existsIn(['workflow_instance_id'], 'WorkflowInstances'), [
            'errorField' => 'workflow_instance_id',
        ]);
        $rules->add($rules->existsIn(['execution_log_id'], 'WorkflowExecutionLogs'), [
            'errorField' => 'execution_log_id',
        ]);

        return $rules;
    }

    /**
     * Get the count of pending approvals for a specific member.
     *
     * @param int $memberId The member ID to check
     * @return int Count of pending approvals
     */
    public static function getPendingApprovalCountForMember(int $memberId): int
    {
        try {
            $manager = new DefaultWorkflowApprovalManager();
            return count($manager->getPendingApprovalsForMember($memberId));
        } catch (\Exception $e) {
            Log::error("Error counting pending approvals for member {$memberId}: {$e->getMessage()}");
            return 0;
        }
    }
}
