<?php

declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\RulesChecker;
use Cake\Validation\Validator;

/**
 * WorkflowTransitions Table
 *
 * Manages transitions between workflow states. Transitions may be manual
 * or automatic, with conditions, actions, and trigger configuration.
 */
class WorkflowTransitionsTable extends BaseTable
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('workflow_transitions');
        $this->setDisplayField('name');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

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
            ->requirePresence('workflow_definition_id', 'create')
            ->notEmptyString('workflow_definition_id');

        $validator
            ->requirePresence('from_state_id', 'create')
            ->notEmptyString('from_state_id');

        $validator
            ->requirePresence('to_state_id', 'create')
            ->notEmptyString('to_state_id');

        $validator
            ->requirePresence('name', 'create')
            ->notEmptyString('name');

        $validator
            ->requirePresence('slug', 'create')
            ->notEmptyString('slug');

        $validator
            ->requirePresence('label', 'create')
            ->notEmptyString('label');

        $validator
            ->integer('priority');

        $validator
            ->boolean('is_automatic');

        $validator
            ->requirePresence('trigger_type', 'create')
            ->notEmptyString('trigger_type');

        return $validator;
    }

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add(
            $rules->existsIn(['workflow_definition_id'], 'WorkflowDefinitions'),
        );
        $rules->add(
            $rules->existsIn(['from_state_id'], 'FromState'),
        );
        $rules->add(
            $rules->existsIn(['to_state_id'], 'ToState'),
        );

        return $rules;
    }
}
