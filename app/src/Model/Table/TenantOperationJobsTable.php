<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Model\Entity\TenantOperationJob;
use Cake\Event\EventInterface;
use Cake\ORM\Table;
use Cake\Validation\Validator;
use ArrayObject;

class TenantOperationJobsTable extends Table
{
    /**
     * Use platform datasource.
     *
     * @return string
     */
    public static function defaultConnectionName(): string
    {
        return 'platform';
    }

    /**
     * Initialize table metadata.
     *
     * @param array<string, mixed> $config Config
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->setTable('tenant_operation_jobs');
        $this->setPrimaryKey('id');
        $schema = $this->getSchema();
        foreach (['input', 'result', 'progress_json', 'result_json', 'error_json', 'approval_policy_json'] as $column) {
            if ($schema->hasColumn($column)) {
                $schema->setColumnType($column, 'json');
            }
        }
        $this->addBehavior('Timestamp');
        $this->belongsTo('Tenants', ['foreignKey' => 'tenant_id']);
        $this->belongsTo('PlatformAdmins', ['foreignKey' => 'platform_admin_id']);
        $this->belongsTo('ParentTenantOperationJobs', [
            'className' => 'TenantOperationJobs',
            'foreignKey' => 'parent_tenant_operation_job_id',
        ]);
        $this->hasMany('ChildTenantOperationJobs', [
            'className' => 'TenantOperationJobs',
            'foreignKey' => 'parent_tenant_operation_job_id',
        ]);
        $this->hasMany('TenantOperationApprovals', ['foreignKey' => 'tenant_operation_job_id']);
    }

    /**
     * Validate job lifecycle and orchestration metadata.
     *
     * @param \Cake\Validation\Validator $validator Validator instance
     * @return \Cake\Validation\Validator
     */
    public function validationDefault(Validator $validator): Validator
    {
        $states = [
            TenantOperationJob::STATUS_QUEUED,
            TenantOperationJob::STATUS_APPROVAL_REQUIRED,
            TenantOperationJob::STATUS_APPROVED,
            TenantOperationJob::STATUS_RUNNING,
            TenantOperationJob::STATUS_HOLD,
            TenantOperationJob::STATUS_BLOCKED,
            TenantOperationJob::STATUS_COMPLETED,
            TenantOperationJob::STATUS_FAILED,
            TenantOperationJob::STATUS_CANCELLED,
        ];
        $validator
            ->allowEmptyString('parent_tenant_operation_job_id')
            ->integer('parent_tenant_operation_job_id');
        $validator
            ->requirePresence('operation', 'create')
            ->scalar('operation')
            ->maxLength('operation', 64)
            ->notEmptyString('operation');
        $validator
            ->allowEmptyString('status')
            ->scalar('status')
            ->maxLength('status', 32)
            ->inList('status', $states);
        $validator
            ->allowEmptyString('state')
            ->scalar('state')
            ->maxLength('state', 32)
            ->inList('state', $states);
        $validator
            ->allowEmptyString('idempotency_scope')
            ->scalar('idempotency_scope')
            ->maxLength('idempotency_scope', 32);
        $validator
            ->allowEmptyString('idempotency_key')
            ->scalar('idempotency_key')
            ->maxLength('idempotency_key', 255);
        $validator
            ->allowEmptyString('approvals_required')
            ->integer('approvals_required')
            ->greaterThanOrEqual('approvals_required', 0);
        $validator
            ->allowEmptyString('approvals_received')
            ->integer('approvals_received')
            ->greaterThanOrEqual('approvals_received', 0);
        $validator
            ->allowEmptyString('approval_rejection_reason')
            ->scalar('approval_rejection_reason')
            ->maxLength('approval_rejection_reason', 1024);
        $validator
            ->allowEmptyString('lease_owner')
            ->scalar('lease_owner')
            ->maxLength('lease_owner', 255);
        $validator
            ->allowEmptyString('lease_token')
            ->scalar('lease_token')
            ->maxLength('lease_token', 255);
        $validator
            ->allowEmptyString('status_message')
            ->scalar('status_message')
            ->maxLength('status_message', 1024);
        $validator
            ->allowEmptyString('operation_correlation_id')
            ->scalar('operation_correlation_id')
            ->maxLength('operation_correlation_id', 128);
        $validator
            ->allowEmptyString('operation_image')
            ->scalar('operation_image')
            ->maxLength('operation_image', 255);
        $validator
            ->allowEmptyString('operation_version')
            ->scalar('operation_version')
            ->maxLength('operation_version', 64);
        $validator
            ->allowEmptyString('progress_percent')
            ->integer('progress_percent')
            ->greaterThanOrEqual('progress_percent', 0)
            ->lessThanOrEqual('progress_percent', 100);

        return $validator;
    }

    /**
     * Keep status/state synchronized for backward compatibility.
     *
     * @param \Cake\Event\EventInterface $event Event
     * @param \ArrayObject $data Marshalled data
     * @param \ArrayObject $options Options
     * @return void
     */
    public function beforeMarshal(EventInterface $event, ArrayObject $data, ArrayObject $options): void
    {
        $state = isset($data['state']) ? (string)$data['state'] : '';
        $status = isset($data['status']) ? (string)$data['status'] : '';
        if ($state === '' && $status !== '') {
            $data['state'] = $status;
        } elseif ($status === '' && $state !== '') {
            $data['status'] = $state;
        }
    }
}
