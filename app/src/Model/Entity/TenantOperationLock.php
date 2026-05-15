<?php
declare(strict_types=1);

namespace App\Model\Entity;

/**
 * Platform lock record used to serialize destructive tenant operations.
 */
class TenantOperationLock extends BaseEntity
{
    protected array $_accessible = [
        'tenant_id' => true,
        'operation' => true,
        'owner' => true,
        'lease_token' => true,
        'lease_acquired_at' => true,
        'lease_expires_at' => true,
        'heartbeat_at' => true,
        'status_message' => true,
        'metadata' => true,
        'tenant_operation_job_id' => true,
        'stale_recovered_at' => true,
    ];
}
