<?php

declare(strict_types=1);

namespace App\Model\Table;

use App\Model\Entity\WorkflowState;
use Cake\ORM\RulesChecker;
use Cake\Validation\Validator;

/**
 * WorkflowStates Table
 *
 * Manages states within workflow definitions. Each state has a type
 * (initial, intermediate, final) and optional visibility/approval gates.
 */
class WorkflowStatesTable extends BaseTable
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('workflow_states');
        $this->setDisplayField('name');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('WorkflowDefinitions', [
            'foreignKey' => 'workflow_definition_id',
            'joinType' => 'INNER',
        ]);
        $this->hasMany('FromStateTransitions', [
            'className' => 'WorkflowTransitions',
            'foreignKey' => 'from_state_id',
        ]);
        $this->hasMany('ToStateTransitions', [
            'className' => 'WorkflowTransitions',
            'foreignKey' => 'to_state_id',
        ]);
        $this->hasMany('WorkflowVisibilityRules', [
            'foreignKey' => 'workflow_state_id',
            'dependent' => true,
        ]);
        $this->hasMany('WorkflowApprovalGates', [
            'foreignKey' => 'workflow_state_id',
            'dependent' => true,
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->requirePresence('workflow_definition_id', 'create')
            ->notEmptyString('workflow_definition_id');

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
            ->requirePresence('state_type', 'create')
            ->notEmptyString('state_type');

        return $validator;
    }

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add(
            $rules->existsIn(['workflow_definition_id'], 'WorkflowDefinitions'),
        );
        $rules->add(
            $rules->isUnique(['workflow_definition_id', 'slug']),
            'uniqueSlugPerDefinition',
            ['message' => 'This slug already exists within the workflow definition.'],
        );

        return $rules;
    }

    /**
     * Find the initial state for a workflow definition.
     */
    public function findInitialState(int $definitionId): ?WorkflowState
    {
        return $this->find()
            ->where([
                'WorkflowStates.workflow_definition_id' => $definitionId,
                'WorkflowStates.state_type' => 'initial',
            ])
            ->first();
    }
}
