<?php
declare(strict_types=1);

namespace App\Model\Entity;

/**
 * Approval marker for high-risk tenant operation jobs.
 */
class TenantOperationApproval extends BaseEntity
{
    protected array $_accessible = [
        'tenant_operation_job_id' => true,
        'platform_admin_id' => true,
        'approval_type' => true,
        'approved_at' => true,
    ];
}
