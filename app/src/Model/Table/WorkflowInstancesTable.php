<?php

declare(strict_types=1);

namespace App\Model\Table;

use App\Model\Entity\WorkflowInstance;
use Cake\ORM\RulesChecker;
use Cake\Validation\Validator;

/**
 * WorkflowInstances Table
 *
 * Manages running workflow instances bound to specific entities.
 * Tracks current/previous state and workflow context.
 */
class WorkflowInstancesTable extends BaseTable
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('workflow_instances');
        $this->setDisplayField('id');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('WorkflowDefinitions', [
            'foreignKey' => 'workflow_definition_id',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('CurrentState', [
            'className' => 'WorkflowStates',
            'foreignKey' => 'current_state_id',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('PreviousState', [
            'className' => 'WorkflowStates',
            'foreignKey' => 'previous_state_id',
        ]);
        $this->hasMany('WorkflowTransitionLogs', [
            'foreignKey' => 'workflow_instance_id',
            'dependent' => true,
        ]);
        $this->hasMany('WorkflowApprovals', [
            'foreignKey' => 'workflow_instance_id',
            'dependent' => true,
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->requirePresence('workflow_definition_id', 'create')
            ->notEmptyString('workflow_definition_id');

        $validator
            ->requirePresence('entity_type', 'create')
            ->notEmptyString('entity_type');

        $validator
            ->requirePresence('entity_id', 'create')
            ->notEmptyString('entity_id');

        $validator
            ->requirePresence('current_state_id', 'create')
            ->notEmptyString('current_state_id');

        $validator
            ->dateTime('started_at')
            ->requirePresence('started_at', 'create')
            ->notEmptyDateTime('started_at');

        $validator
            ->dateTime('completed_at')
            ->allowEmptyDateTime('completed_at');

        return $validator;
    }

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add(
            $rules->existsIn(['workflow_definition_id'], 'WorkflowDefinitions'),
        );
        $rules->add(
            $rules->existsIn(['current_state_id'], 'CurrentState'),
        );

        return $rules;
    }

    /**
     * Find the active (non-completed) workflow instance for a given entity.
     */
    public function findActiveForEntity(string $entityType, int $entityId): ?WorkflowInstance
    {
        return $this->find()
            ->where([
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'status' => 'active',
            ])
            ->first();
    }
}
