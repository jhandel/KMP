<?php
declare(strict_types=1);

namespace App\Model\Entity;

/**
 * Short-lived platform admin email verification code.
 */
class PlatformAdminEmailCode extends BaseEntity
{
    protected array $_accessible = [
        'platform_admin_id' => true,
        'purpose' => true,
        'code_hash' => true,
        'expires_at' => true,
        'attempts' => true,
        'max_attempts' => true,
        'ip_address' => true,
        'user_agent' => true,
        'used_at' => true,
    ];

    protected array $_hidden = [
        'code_hash',
    ];
}
