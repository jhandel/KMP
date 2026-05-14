<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Model\Entity\Tenant;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * Platform registry table for tenants.
 *
 * Uses the named platform connection so registry lookups never touch tenant
 * application data.
 */
class TenantsTable extends Table
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

        $this->setTable('tenants');
        $this->setDisplayField('display_name');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->hasMany('TenantAliases', [
            'foreignKey' => 'tenant_id',
            'dependent' => true,
            'cascadeCallbacks' => true,
        ]);
        $this->hasMany('TenantDatabaseConfigs', [
            'foreignKey' => 'tenant_id',
            'dependent' => true,
            'cascadeCallbacks' => true,
        ]);
        $this->hasMany('TenantServiceConfigs', [
            'foreignKey' => 'tenant_id',
            'dependent' => true,
            'cascadeCallbacks' => true,
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
            ->scalar('slug')
            ->maxLength('slug', 80)
            ->requirePresence('slug', 'create')
            ->notEmptyString('slug')
            ->regex('slug', '/^[a-z0-9][a-z0-9-]*[a-z0-9]$/', 'Use lowercase letters, numbers, and hyphens.');

        $validator
            ->scalar('display_name')
            ->maxLength('display_name', 255)
            ->requirePresence('display_name', 'create')
            ->notEmptyString('display_name');

        $validator
            ->scalar('status')
            ->maxLength('status', 32)
            ->requirePresence('status', 'create')
            ->notEmptyString('status')
            ->inList('status', [
                Tenant::STATUS_PROVISIONING,
                Tenant::STATUS_ACTIVE,
                Tenant::STATUS_DISABLED,
                Tenant::STATUS_MAINTENANCE,
                Tenant::STATUS_FAILED,
            ]);

        $validator
            ->scalar('schema_version')
            ->maxLength('schema_version', 64)
            ->allowEmptyString('schema_version');

        $validator
            ->scalar('primary_host')
            ->maxLength('primary_host', 255)
            ->allowEmptyString('primary_host');

        $validator
            ->scalar('path_prefix')
            ->maxLength('path_prefix', 128)
            ->allowEmptyString('path_prefix');

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
        $rules->add($rules->isUnique(['slug']), ['errorField' => 'slug']);
        $rules->add($rules->isUnique(['primary_host']), ['errorField' => 'primary_host']);

        return $rules;
    }

    /**
     * Filter to tenants active for request routing.
     *
     * @param \Cake\ORM\Query\SelectQuery $query Query
     * @return \Cake\ORM\Query\SelectQuery
     */
    public function findActive(SelectQuery $query): SelectQuery
    {
        return $query->where(['Tenants.status' => Tenant::STATUS_ACTIVE]);
    }
}
