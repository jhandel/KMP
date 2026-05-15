<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;
use Cake\Validation\Validator;

class TenantOperationLocksTable extends Table
{
    /**
     * Use platform datasource.
     */
    public static function defaultConnectionName(): string
    {
        return 'platform';
    }

    /**
     * Initialize lock table metadata.
     *
     * @param array<string, mixed> $config Config
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->setTable('tenant_operation_locks');
        $this->setPrimaryKey('id');
        $this->addBehavior('Timestamp');
        $this->belongsTo('Tenants', ['foreignKey' => 'tenant_id']);
        $this->belongsTo('TenantOperationJobs', ['foreignKey' => 'tenant_operation_job_id']);
    }

    /**
     * Validate lock payload.
     */
    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->requirePresence('tenant_id', 'create')
            ->integer('tenant_id')
            ->greaterThan('tenant_id', 0);
        $validator
            ->requirePresence('operation', 'create')
            ->scalar('operation')
            ->maxLength('operation', 64)
            ->notEmptyString('operation');
        $validator
            ->requirePresence('owner', 'create')
            ->scalar('owner')
            ->maxLength('owner', 255)
            ->notEmptyString('owner');
        $validator
            ->requirePresence('lease_token', 'create')
            ->scalar('lease_token')
            ->maxLength('lease_token', 255)
            ->notEmptyString('lease_token');
        $validator
            ->allowEmptyString('status_message')
            ->scalar('status_message')
            ->maxLength('status_message', 1024);

        return $validator;
    }
}
