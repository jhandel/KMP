<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * Platform registry table for tenant database connection metadata.
 */
class TenantDatabaseConfigsTable extends Table
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

        $this->setTable('tenant_database_configs');
        $this->setDisplayField('database_name');
        $this->setPrimaryKey('id');
        if ($this->getSchema()->hasColumn('metadata')) {
            $this->getSchema()->setColumnType('metadata', 'json');
        }

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
            ->scalar('connection_role')
            ->maxLength('connection_role', 16)
            ->requirePresence('connection_role', 'create')
            ->notEmptyString('connection_role');

        $validator
            ->scalar('driver')
            ->maxLength('driver', 255)
            ->requirePresence('driver', 'create')
            ->notEmptyString('driver');

        $validator
            ->scalar('host')
            ->maxLength('host', 255)
            ->requirePresence('host', 'create')
            ->notEmptyString('host');

        $validator
            ->integer('port')
            ->allowEmptyString('port');

        $validator
            ->scalar('database_name')
            ->maxLength('database_name', 255)
            ->requirePresence('database_name', 'create')
            ->notEmptyString('database_name');

        $validator
            ->scalar('username')
            ->maxLength('username', 255)
            ->allowEmptyString('username');

        $validator
            ->scalar('secret_reference')
            ->maxLength('secret_reference', 512)
            ->allowEmptyString('secret_reference');

        $validator
            ->scalar('encrypted_dsn')
            ->allowEmptyString('encrypted_dsn');

        $validator
            ->boolean('read_enabled')
            ->notEmptyString('read_enabled');

        $validator
            ->boolean('write_enabled')
            ->notEmptyString('write_enabled');

        $validator
            ->boolean('is_active')
            ->notEmptyString('is_active');

        $validator
            ->allowEmptyString('metadata');

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
        $rules->add($rules->isUnique(['tenant_id', 'connection_role']), ['errorField' => 'connection_role']);

        return $rules;
    }
}
