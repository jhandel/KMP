<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Model\Entity\TenantOperationApproval;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class TenantOperationApprovalsTable extends Table
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
        $this->setTable('tenant_operation_approvals');
        $this->setPrimaryKey('id');
        $this->addBehavior('Timestamp');
        $this->belongsTo('TenantOperationJobs', ['foreignKey' => 'tenant_operation_job_id']);
        $this->belongsTo('PlatformAdmins', ['foreignKey' => 'platform_admin_id']);
    }

    /**
     * @param \Cake\Validation\Validator $validator Validator
     * @return \Cake\Validation\Validator
     */
    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->allowEmptyString('approval_type')
            ->scalar('approval_type')
            ->maxLength('approval_type', 32);
        $validator
            ->allowEmptyString('decision')
            ->scalar('decision')
            ->maxLength('decision', 32)
            ->inList('decision', [
                TenantOperationApproval::DECISION_APPROVED,
                TenantOperationApproval::DECISION_REJECTED,
            ]);
        $validator
            ->allowEmptyString('decision_note')
            ->scalar('decision_note')
            ->maxLength('decision_note', 1024);

        return $validator;
    }
}
