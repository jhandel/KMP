<?php

declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\RulesChecker;
use Cake\Validation\Validator;

/**
 * WorkflowDefinitions Model
 *
 * Manages versioned workflow definitions that control entity lifecycle states and transitions.
 *
 * @property \App\Model\Table\WorkflowStatesTable&\Cake\ORM\Association\HasMany $WorkflowStates
 * @property \App\Model\Table\WorkflowTransitionsTable&\Cake\ORM\Association\HasMany $WorkflowTransitions
 * @property \App\Model\Table\WorkflowInstancesTable&\Cake\ORM\Association\HasMany $WorkflowInstances
 *
 * @method \App\Model\Entity\WorkflowDefinition get($primaryKey, array $options = [])
 * @method \App\Model\Entity\WorkflowDefinition newEntity(array $data = [], array $options = [])
 * @method \App\Model\Entity\WorkflowDefinition[] newEntities(array $data, array $options = [])
 * @method \App\Model\Entity\WorkflowDefinition|bool save(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method \App\Model\Entity\WorkflowDefinition patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method \App\Model\Entity\WorkflowDefinition[] patchEntities(iterable $entities, array $data, array $options = [])
 * @method \App\Model\Entity\WorkflowDefinition findOrCreate($search, ?callable $callback = null, array $options = [])
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
        $this->addBehavior('Muffin/Footprint.Footprint');

        $this->hasMany('WorkflowStates', [
            'foreignKey' => 'workflow_definition_id',
            'dependent' => true,
            'cascadeCallbacks' => true,
        ]);
        $this->hasMany('WorkflowTransitions', [
            'foreignKey' => 'workflow_definition_id',
            'dependent' => true,
            'cascadeCallbacks' => true,
        ]);
        $this->hasMany('WorkflowInstances', [
            'foreignKey' => 'workflow_definition_id',
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->integer('id')
            ->allowEmptyString('id', null, 'create');

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
            ->scalar('entity_type')
            ->maxLength('entity_type', 255)
            ->requirePresence('entity_type', 'create')
            ->notEmptyString('entity_type');

        $validator
            ->scalar('plugin_name')
            ->maxLength('plugin_name', 255)
            ->allowEmptyString('plugin_name');

        $validator
            ->integer('version')
            ->notEmptyString('version');

        $validator
            ->boolean('is_active');

        $validator
            ->boolean('is_default');

        return $validator;
    }

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->isUnique(['slug', 'version'], 'This slug and version combination already exists.'));

        return $rules;
    }

    /**
     * Find the active default workflow definition for an entity type.
     */
    public function findActiveForEntityType(string $entityType): ?\App\Model\Entity\WorkflowDefinition
    {
        return $this->find()
            ->where([
                'entity_type' => $entityType,
                'is_active' => true,
                'is_default' => true,
            ])
            ->orderByDesc('version')
            ->first();
    }

    /**
     * Find workflow definition by slug (latest active version).
     */
    public function findBySlug(string $slug): ?\App\Model\Entity\WorkflowDefinition
    {
        return $this->find()
            ->where([
                'slug' => $slug,
                'is_active' => true,
            ])
            ->orderByDesc('version')
            ->first();
    }
}
