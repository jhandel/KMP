<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\RulesChecker;
use Cake\Validation\Validator;

class WorkflowStatesTable extends BaseTable
{
    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->setTable('workflow_states');
        $this->setDisplayField('label');
        $this->setPrimaryKey('id');

        $this->belongsTo('WorkflowDefinitions', [
            'foreignKey' => 'workflow_definition_id',
            'joinType' => 'INNER',
        ]);

        $this->hasMany('OutgoingTransitions', [
            'className' => 'WorkflowTransitions',
            'foreignKey' => 'from_state_id',
        ]);

        $this->hasMany('IncomingTransitions', [
            'className' => 'WorkflowTransitions',
            'foreignKey' => 'to_state_id',
        ]);

        $this->hasMany('WorkflowVisibilityRules', [
            'foreignKey' => 'workflow_state_id',
            'dependent' => true,
            'cascadeCallbacks' => true,
        ]);

        $this->hasMany('WorkflowApprovalGates', [
            'foreignKey' => 'workflow_state_id',
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
            ->scalar('state_type')
            ->maxLength('state_type', 50)
            ->inList('state_type', \App\Model\Entity\WorkflowState::VALID_STATE_TYPES)
            ->notEmptyString('state_type');

        $validator
            ->scalar('status_category')
            ->maxLength('status_category', 255)
            ->allowEmptyString('status_category');

        return $validator;
    }

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->existsIn('workflow_definition_id', 'WorkflowDefinitions'));
        $rules->add($rules->isUnique(['workflow_definition_id', 'slug'], 'This slug already exists in this workflow.'));
        return $rules;
    }

    /**
     * Find the initial state for a workflow definition.
     */
    public function findInitialState(int $workflowDefinitionId): ?\App\Model\Entity\WorkflowState
    {
        return $this->find()
            ->where([
                'workflow_definition_id' => $workflowDefinitionId,
                'state_type' => \App\Model\Entity\WorkflowState::TYPE_INITIAL,
            ])
            ->first();
    }

    /**
     * Find all states for a workflow definition grouped by status category.
     */
    public function findByStatusCategory(int $workflowDefinitionId): array
    {
        $states = $this->find()
            ->where(['workflow_definition_id' => $workflowDefinitionId])
            ->orderBy(['status_category' => 'ASC', 'name' => 'ASC'])
            ->all();

        $grouped = [];
        foreach ($states as $state) {
            $category = $state->status_category ?? 'Uncategorized';
            $grouped[$category][] = $state;
        }
        return $grouped;
    }
}
