<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\RulesChecker;
use Cake\Validation\Validator;

/**
 * WorkflowVisibilityRules Model
 *
 * Manages per-state visibility and editability rules for workflow-governed entities.
 *
 * @property \App\Model\Table\WorkflowStatesTable&\Cake\ORM\Association\BelongsTo $WorkflowStates
 */
class WorkflowVisibilityRulesTable extends BaseTable
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('workflow_visibility_rules');
        $this->setDisplayField('id');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('WorkflowStates', [
            'foreignKey' => 'workflow_state_id',
            'joinType' => 'INNER',
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->integer('id')
            ->allowEmptyString('id', null, 'create');

        $validator
            ->integer('workflow_state_id')
            ->requirePresence('workflow_state_id', 'create')
            ->notEmptyString('workflow_state_id');

        $validator
            ->scalar('rule_type')
            ->maxLength('rule_type', 50)
            ->inList('rule_type', \App\Model\Entity\WorkflowVisibilityRule::VALID_RULE_TYPES)
            ->requirePresence('rule_type', 'create')
            ->notEmptyString('rule_type');

        $validator
            ->scalar('target')
            ->maxLength('target', 255)
            ->requirePresence('target', 'create')
            ->notEmptyString('target');

        return $validator;
    }

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->existsIn('workflow_state_id', 'WorkflowStates'));

        return $rules;
    }
}
