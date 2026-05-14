<?php
declare(strict_types=1);

namespace App\Model\Entity;

/**
 * Platform admin identity stored in the global platform datastore.
 *
 * @property int $id
 * @property string $email
 * @property string $display_name
 * @property string $password_hash
 * @property string $status
 * @property int $failed_attempts
 * @property \Cake\I18n\DateTime|null $locked_until
 */
class PlatformAdmin extends BaseEntity
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISABLED = 'disabled';
    public const STATUS_LOCKED = 'locked';

    protected array $_accessible = [
        'email' => true,
        'display_name' => true,
        'password_hash' => true,
        'status' => true,
        'require_password_change' => true,
        'failed_attempts' => true,
        'locked_until' => true,
        'last_login_at' => true,
    ];

    protected array $_hidden = [
        'password_hash',
    ];

    /**
     * Whether this platform admin may authenticate.
     */
    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
