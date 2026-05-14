<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;

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
        $this->belongsTo('TenantOperationJobs', ['foreignKey' => 'tenant_operation_job_id']);
        $this->belongsTo('PlatformAdmins', ['foreignKey' => 'platform_admin_id']);
    }
}
