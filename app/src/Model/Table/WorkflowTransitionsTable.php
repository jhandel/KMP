<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\RulesChecker;
use Cake\Validation\Validator;

class WorkflowTransitionsTable extends BaseTable
{
    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->setTable('workflow_transitions');
        $this->setDisplayField('label');
        $this->setPrimaryKey('id');

        $this->belongsTo('WorkflowDefinitions', [
            'foreignKey' => 'workflow_definition_id',
            'joinType' => 'INNER',
        ]);

        $this->belongsTo('FromState', [
            'className' => 'WorkflowStates',
            'foreignKey' => 'from_state_id',
            'joinType' => 'INNER',
        ]);

        $this->belongsTo('ToState', [
            'className' => 'WorkflowStates',
            'foreignKey' => 'to_state_id',
            'joinType' => 'INNER',
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
            ->integer('from_state_id')
            ->requirePresence('from_state_id', 'create')
            ->notEmptyString('from_state_id');

        $validator
            ->integer('to_state_id')
            ->requirePresence('to_state_id', 'create')
            ->notEmptyString('to_state_id');

        $validator
            ->scalar('name')
            ->maxLength('name', 255)
            ->requirePresence('name', 'create')
            ->notEmptyString('name');

        $validator
            ->scalar('slug')
            ->maxLength('slug', 255)
            ->requirePresence('slug', 'create')
            ->notEmptyString('slug');

        $validator
            ->scalar('label')
            ->maxLength('label', 255)
            ->requirePresence('label', 'create')
            ->notEmptyString('label');

        $validator
            ->integer('priority')
            ->notEmptyString('priority');

        $validator
            ->boolean('is_automatic');

        $validator
            ->scalar('trigger_type')
            ->maxLength('trigger_type', 50)
            ->inList('trigger_type', \App\Model\Entity\WorkflowTransition::VALID_TRIGGER_TYPES)
            ->notEmptyString('trigger_type');

        return $validator;
    }

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->existsIn('workflow_definition_id', 'WorkflowDefinitions'));
        $rules->add($rules->existsIn('from_state_id', 'FromState'));
        $rules->add($rules->existsIn('to_state_id', 'ToState'));
        $rules->add($rules->isUnique(['workflow_definition_id', 'slug'], 'This slug already exists in this workflow.'));
        return $rules;
    }

    /**
     * Find all transitions from a given state, ordered by priority.
     */
    public function findFromState(int $stateId): \Cake\ORM\Query\SelectQuery
    {
        return $this->find()
            ->where(['from_state_id' => $stateId])
            ->orderBy(['priority' => 'ASC'])
            ->contain(['ToState']);
    }

    /**
     * Find all automatic transitions for a workflow definition.
     */
    public function findAutomatic(int $workflowDefinitionId): \Cake\ORM\Query\SelectQuery
    {
        return $this->find()
            ->where([
                'workflow_definition_id' => $workflowDefinitionId,
                'is_automatic' => true,
            ])
            ->contain(['FromState', 'ToState'])
            ->orderBy(['priority' => 'ASC']);
    }

    /**
     * Find scheduled transitions that need processing.
     */
    public function findScheduled(): \Cake\ORM\Query\SelectQuery
    {
        return $this->find()
            ->where([
                'trigger_type' => \App\Model\Entity\WorkflowTransition::TRIGGER_SCHEDULED,
            ])
            ->contain(['FromState', 'ToState', 'WorkflowDefinitions']);
    }
}
