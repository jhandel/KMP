<?php
declare(strict_types=1);

namespace App\Model\Entity;

/**
 * One-time platform admin recovery/MFA code.
 */
class PlatformAdminRecoveryCode extends BaseEntity
{
    protected array $_accessible = [
        'platform_admin_id' => true,
        'code_hash' => true,
        'used_at' => true,
    ];

    protected array $_hidden = [
        'code_hash',
    ];
}
