<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;

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
        $this->addBehavior('Timestamp');
        $this->belongsTo('Tenants', ['foreignKey' => 'tenant_id']);
        $this->belongsTo('PlatformAdmins', ['foreignKey' => 'platform_admin_id']);
        $this->hasMany('TenantOperationApprovals', ['foreignKey' => 'tenant_operation_job_id']);
    }
}
