<?php
declare(strict_types=1);

namespace App\Model\Entity;

/**
 * Encrypted platform-managed secret.
 */
class PlatformSecret extends BaseEntity
{
    protected array $_accessible = [
        'name' => true,
        'encrypted_value' => true,
        'key_version' => true,
        'description' => true,
        'created_by_platform_admin_id' => true,
        'created' => true,
        'modified' => true,
    ];

    protected array $_hidden = [
        'encrypted_value',
    ];
}
