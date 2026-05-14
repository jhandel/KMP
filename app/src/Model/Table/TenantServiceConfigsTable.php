<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * Platform registry table for tenant runtime service configuration.
 */
class TenantServiceConfigsTable extends Table
{
    /**
     * Use the platform registry datasource.
     *
     * @return string
     */
    public static function defaultConnectionName(): string
    {
        return 'platform';
    }

    /**
     * Initialize table metadata and associations.
     *
     * @param array<string, mixed> $config Configuration
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('tenant_service_configs');
        $this->setDisplayField('service_name');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('Tenants', [
            'foreignKey' => 'tenant_id',
            'joinType' => 'INNER',
        ]);
    }

    /**
     * Default validation rules.
     *
     * @param \Cake\Validation\Validator $validator Validator
     * @return \Cake\Validation\Validator
     */
    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->integer('tenant_id')
            ->requirePresence('tenant_id', 'create')
            ->notEmptyString('tenant_id');

        $validator
            ->scalar('service_name')
            ->maxLength('service_name', 64)
            ->requirePresence('service_name', 'create')
            ->notEmptyString('service_name')
            ->inList('service_name', ['email', 'storage']);

        $validator
            ->scalar('config_key')
            ->maxLength('config_key', 64)
            ->requirePresence('config_key', 'create')
            ->notEmptyString('config_key');

        $validator
            ->scalar('adapter')
            ->maxLength('adapter', 64)
            ->allowEmptyString('adapter');

        $validator
            ->scalar('secret_reference')
            ->maxLength('secret_reference', 512)
            ->allowEmptyString('secret_reference');

        $validator
            ->allowEmptyString('metadata');

        $validator
            ->boolean('is_active')
            ->notEmptyString('is_active');

        return $validator;
    }

    /**
     * Application integrity rules.
     *
     * @param \Cake\ORM\RulesChecker $rules Rules checker
     * @return \Cake\ORM\RulesChecker
     */
    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->existsIn(['tenant_id'], 'Tenants'), ['errorField' => 'tenant_id']);
        $rules->add($rules->isUnique(['tenant_id', 'service_name', 'config_key']), ['errorField' => 'config_key']);

        return $rules;
    }
}
