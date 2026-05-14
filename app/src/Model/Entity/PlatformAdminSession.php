<?php
declare(strict_types=1);

namespace App\Model\Entity;

/**
 * Server-side platform admin session token.
 */
class PlatformAdminSession extends BaseEntity
{
    protected array $_accessible = [
        'platform_admin_id' => true,
        'token_hash' => true,
        'ip_address' => true,
        'user_agent' => true,
        'last_seen_at' => true,
        'expires_at' => true,
        'revoked_at' => true,
    ];

    protected array $_hidden = [
        'token_hash',
    ];
}
