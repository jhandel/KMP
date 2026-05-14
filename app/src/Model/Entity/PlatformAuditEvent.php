<?php
declare(strict_types=1);

namespace App\Model\Entity;

/**
 * Append-only platform audit event.
 */
class PlatformAuditEvent extends BaseEntity
{
    protected array $_accessible = [
        'platform_admin_id' => true,
        'tenant_id' => true,
        'event_type' => true,
        'severity' => true,
        'action' => true,
        'result' => true,
        'subject_type' => true,
        'subject_id' => true,
        'request_id' => true,
        'ip_address' => true,
        'user_agent' => true,
        'metadata' => true,
        'previous_hash' => true,
        'event_hash' => true,
    ];
}
