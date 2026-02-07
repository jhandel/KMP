<?php

declare(strict_types=1);

namespace App\Model\Table;

use App\Model\Entity\WorkflowDefinition;
use Cake\ORM\RulesChecker;
use Cake\Validation\Validator;

/**
 * WorkflowDefinitions Table
 *
 * Manages versioned workflow blueprints. Each definition describes
 * the states, transitions, and rules for a particular entity type.
 */
class WorkflowDefinitionsTable extends BaseTable
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('workflow_definitions');
        $this->setDisplayField('name');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->hasMany('WorkflowStates', [
            'foreignKey' => 'workflow_definition_id',
            'dependent' => true,
        ]);
        $this->hasMany('WorkflowTransitions', [
            'foreignKey' => 'workflow_definition_id',
            'dependent' => true,
        ]);
        $this->hasMany('WorkflowInstances', [
            'foreignKey' => 'workflow_definition_id',
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->requirePresence('name', 'create')
            ->notEmptyString('name');

        $validator
            ->requirePresence('slug', 'create')
            ->notEmptyString('slug');

        $validator
            ->requirePresence('entity_type', 'create')
            ->notEmptyString('entity_type');

        $validator
            ->integer('version')
            ->requirePresence('version', 'create')
            ->notEmptyString('version');

        $validator
            ->boolean('is_active');

        $validator
            ->boolean('is_default');

        return $validator;
    }

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add(
            $rules->isUnique(['slug', 'version']),
            'uniqueSlugVersion',
            ['message' => 'This slug and version combination already exists.'],
        );

        return $rules;
    }

    /**
     * Find the latest active workflow definition by slug.
     */
    public function findBySlug(string $slug): ?WorkflowDefinition
    {
        return $this->find()
            ->where(['slug' => $slug, 'is_active' => true])
            ->orderBy(['version' => 'DESC'])
            ->first();
    }
}
