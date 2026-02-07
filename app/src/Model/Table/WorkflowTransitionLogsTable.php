<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\RulesChecker;
use Cake\Validation\Validator;

/**
 * WorkflowTransitionLogs Model
 *
 * Immutable audit trail of all state transitions within workflow instances.
 *
 * @property \App\Model\Table\WorkflowInstancesTable&\Cake\ORM\Association\BelongsTo $WorkflowInstances
 * @property \App\Model\Table\WorkflowStatesTable&\Cake\ORM\Association\BelongsTo $FromState
 * @property \App\Model\Table\WorkflowStatesTable&\Cake\ORM\Association\BelongsTo $ToState
 * @property \App\Model\Table\WorkflowTransitionsTable&\Cake\ORM\Association\BelongsTo $WorkflowTransitions
 */
class WorkflowTransitionLogsTable extends BaseTable
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('workflow_transition_logs');
        $this->setDisplayField('id');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp', [
            'events' => [
                'Model.beforeSave' => [
                    'created' => 'new',
                ],
            ],
        ]);

        $this->belongsTo('WorkflowInstances', [
            'foreignKey' => 'workflow_instance_id',
            'joinType' => 'INNER',
        ]);

        $this->belongsTo('FromState', [
            'className' => 'WorkflowStates',
            'foreignKey' => 'from_state_id',
            'joinType' => 'LEFT',
        ]);

        $this->belongsTo('ToState', [
            'className' => 'WorkflowStates',
            'foreignKey' => 'to_state_id',
            'joinType' => 'INNER',
        ]);

        $this->belongsTo('WorkflowTransitions', [
            'foreignKey' => 'transition_id',
            'joinType' => 'LEFT',
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
            ->integer('from_state_id')
            ->allowEmptyString('from_state_id');

        $validator
            ->integer('to_state_id')
            ->requirePresence('to_state_id', 'create')
            ->notEmptyString('to_state_id');

        $validator
            ->integer('transition_id')
            ->allowEmptyString('transition_id');

        $validator
            ->scalar('trigger_type')
            ->maxLength('trigger_type', 50)
            ->requirePresence('trigger_type', 'create')
            ->notEmptyString('trigger_type');

        $validator
            ->integer('triggered_by')
            ->allowEmptyString('triggered_by');

        $validator
            ->scalar('comment')
            ->allowEmptyString('comment');

        return $validator;
    }

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->existsIn('workflow_instance_id', 'WorkflowInstances'));
        $rules->add($rules->existsIn('to_state_id', 'ToState'));

        return $rules;
    }
}
