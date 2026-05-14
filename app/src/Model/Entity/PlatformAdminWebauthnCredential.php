<?php
declare(strict_types=1);

namespace App\Model\Entity;

/**
 * Platform admin WebAuthn/passkey credential metadata.
 */
class PlatformAdminWebauthnCredential extends BaseEntity
{
    protected array $_accessible = [
        'platform_admin_id' => true,
        'credential_id' => true,
        'public_key' => true,
        'sign_count' => true,
        'label' => true,
        'last_used_at' => true,
    ];
}
