<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\Validation\Validator;

/**
 * WorkflowInstances Model
 *
 * Tracks active workflow instances binding entities to their current workflow state.
 *
 * @property \App\Model\Table\WorkflowDefinitionsTable&\Cake\ORM\Association\BelongsTo $WorkflowDefinitions
 * @property \App\Model\Table\WorkflowStatesTable&\Cake\ORM\Association\BelongsTo $CurrentState
 * @property \App\Model\Table\WorkflowStatesTable&\Cake\ORM\Association\BelongsTo $PreviousState
 * @property \App\Model\Table\WorkflowTransitionLogsTable&\Cake\ORM\Association\HasMany $WorkflowTransitionLogs
 * @property \App\Model\Table\WorkflowApprovalsTable&\Cake\ORM\Association\HasMany $WorkflowApprovals
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
            'joinType' => 'LEFT',
        ]);

        $this->hasMany('WorkflowTransitionLogs', [
            'foreignKey' => 'workflow_instance_id',
            'dependent' => true,
            'cascadeCallbacks' => true,
        ]);

        $this->hasMany('WorkflowApprovals', [
            'foreignKey' => 'workflow_instance_id',
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
            ->integer('workflow_definition_id')
            ->requirePresence('workflow_definition_id', 'create')
            ->notEmptyString('workflow_definition_id');

        $validator
            ->scalar('entity_type')
            ->maxLength('entity_type', 255)
            ->requirePresence('entity_type', 'create')
            ->notEmptyString('entity_type');

        $validator
            ->integer('entity_id')
            ->requirePresence('entity_id', 'create')
            ->notEmptyString('entity_id');

        $validator
            ->integer('current_state_id')
            ->requirePresence('current_state_id', 'create')
            ->notEmptyString('current_state_id');

        $validator
            ->integer('previous_state_id')
            ->allowEmptyString('previous_state_id');

        $validator
            ->dateTime('completed_at')
            ->allowEmptyDateTime('completed_at');

        return $validator;
    }

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->existsIn('workflow_definition_id', 'WorkflowDefinitions'));
        $rules->add($rules->existsIn('current_state_id', 'CurrentState'));

        // Only one active (non-completed) instance per entity
        $rules->add(
            function ($entity) {
                if ($entity->completed_at !== null) {
                    return true;
                }
                $conditions = [
                    'entity_type' => $entity->entity_type,
                    'entity_id' => $entity->entity_id,
                    'completed_at IS' => null,
                ];
                if (!$entity->isNew()) {
                    $conditions['id !='] = $entity->id;
                }
                return !$this->exists($conditions);
            },
            'uniqueActiveInstance',
            [
                'errorField' => 'entity_id',
                'message' => 'An active workflow instance already exists for this entity.',
            ]
        );

        return $rules;
    }

    /**
     * Find the active workflow instance for a given entity.
     */
    public function findForEntity(string $entityType, int $entityId): SelectQuery
    {
        return $this->find()
            ->where([
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'completed_at IS' => null,
            ])
            ->contain(['CurrentState', 'WorkflowDefinitions']);
    }
}
