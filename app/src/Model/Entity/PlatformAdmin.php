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
 * @property string $role
 * @property int $failed_attempts
 * @property \Cake\I18n\DateTime|null $locked_until
 */
class PlatformAdmin extends BaseEntity
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISABLED = 'disabled';
    public const STATUS_LOCKED = 'locked';
    public const ROLE_VIEWER = 'viewer';
    public const ROLE_OPERATOR = 'operator';
    public const ROLE_PROVISIONER = 'provisioner';
    public const ROLE_SECURITY_ADMIN = 'security_admin';
    public const ROLE_BREAK_GLASS = 'break_glass';

    public const CAPABILITY_VIEW_DASHBOARD = 'view_dashboard';
    public const CAPABILITY_OPERATE_TENANTS = 'operate_tenants';
    public const CAPABILITY_PROVISION_TENANTS = 'provision_tenants';
    public const CAPABILITY_MANAGE_SECRETS = 'manage_secrets';
    public const CAPABILITY_MANAGE_RECOVERY = 'manage_recovery';

    protected array $_accessible = [
        'email' => true,
        'display_name' => true,
        'password_hash' => true,
        'status' => true,
        'role' => true,
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

    /**
     * Determine whether this platform admin can perform a capability.
     */
    public function hasCapability(string $capability): bool
    {
        $role = (string)($this->role ?? self::ROLE_BREAK_GLASS);

        $matrix = [
            self::ROLE_VIEWER => [
                self::CAPABILITY_VIEW_DASHBOARD,
            ],
            self::ROLE_OPERATOR => [
                self::CAPABILITY_VIEW_DASHBOARD,
                self::CAPABILITY_OPERATE_TENANTS,
            ],
            self::ROLE_PROVISIONER => [
                self::CAPABILITY_VIEW_DASHBOARD,
                self::CAPABILITY_OPERATE_TENANTS,
                self::CAPABILITY_PROVISION_TENANTS,
            ],
            self::ROLE_SECURITY_ADMIN => [
                self::CAPABILITY_VIEW_DASHBOARD,
                self::CAPABILITY_MANAGE_SECRETS,
                self::CAPABILITY_MANAGE_RECOVERY,
            ],
            self::ROLE_BREAK_GLASS => [
                self::CAPABILITY_VIEW_DASHBOARD,
                self::CAPABILITY_OPERATE_TENANTS,
                self::CAPABILITY_PROVISION_TENANTS,
                self::CAPABILITY_MANAGE_SECRETS,
                self::CAPABILITY_MANAGE_RECOVERY,
            ],
        ];

        return in_array($capability, $matrix[$role] ?? [], true);
    }
}
