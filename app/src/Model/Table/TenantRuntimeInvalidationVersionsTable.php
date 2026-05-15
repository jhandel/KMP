<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * Monotonic tenant runtime invalidation versions stored in platform DB.
 */
class TenantRuntimeInvalidationVersionsTable extends Table
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
     * @param array<string, mixed> $config Table config
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('tenant_runtime_invalidation_versions');
        $this->setPrimaryKey('tenant_id');
        $this->addBehavior('Timestamp');
    }

    /**
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
            ->nonNegativeInteger('version')
            ->requirePresence('version', 'create')
            ->notEmptyString('version');

        $validator
            ->scalar('last_change_type')
            ->maxLength('last_change_type', 64)
            ->allowEmptyString('last_change_type');

        return $validator;
    }
}
