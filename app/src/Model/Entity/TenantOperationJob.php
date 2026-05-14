<?php
declare(strict_types=1);

namespace App\Model\Entity;

/**
 * Platform job record for tenant operations.
 */
class TenantOperationJob extends BaseEntity
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    protected array $_accessible = [
        'tenant_id' => true,
        'platform_admin_id' => true,
        'operation' => true,
        'status' => true,
        'input' => true,
        'result' => true,
        'error_message' => true,
        'webauthn_assertion_id' => true,
        'started_at' => true,
        'completed_at' => true,
        'cancelled_at' => true,
    ];
}
