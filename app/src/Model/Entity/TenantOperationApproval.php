<?php
declare(strict_types=1);

namespace App\Model\Entity;

/**
 * Approval marker for high-risk tenant operation jobs.
 */
class TenantOperationApproval extends BaseEntity
{
    public const DECISION_APPROVED = 'approved';
    public const DECISION_REJECTED = 'rejected';

    protected array $_accessible = [
        'tenant_operation_job_id' => true,
        'platform_admin_id' => true,
        'approval_type' => true,
        'decision' => true,
        'decision_note' => true,
        'decided_at' => true,
        'approved_at' => true,
    ];
}
