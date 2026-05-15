<?php
declare(strict_types=1);

namespace App\Model\Entity;

/**
 * Records retained boundary metadata after audit history purge.
 */
class PlatformAuditRetentionAnchor extends BaseEntity
{
    protected array $_accessible = [
        'purged_before_event_id' => true,
        'purged_before_event_hash' => true,
        'next_event_id' => true,
        'next_event_previous_hash' => true,
        'archived_path' => true,
        'archive_sha256' => true,
        'archive_event_count' => true,
        'metadata' => true,
    ];
}

