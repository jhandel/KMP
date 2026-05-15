<?php
declare(strict_types=1);

namespace App\Model\Entity;

/**
 * Platform tenant registry record.
 *
 * @property int $id
 * @property string $slug
 * @property string $display_name
 * @property string $status
 * @property string|null $schema_version
 * @property string|null $primary_host
 * @property string|null $path_prefix
 * @property \App\Model\Entity\TenantAlias[] $tenant_aliases
 * @property \App\Model\Entity\TenantDatabaseConfig[] $tenant_database_configs
 * @property \App\Model\Entity\TenantServiceConfig[] $tenant_service_configs
 */
class Tenant extends BaseEntity
{
    public const STATUS_PROVISIONING = 'provisioning';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_DRAINING = 'draining';
    public const STATUS_DISABLED = 'disabled';
    public const STATUS_MAINTENANCE = 'maintenance';
    public const STATUS_FAILED = 'failed';

    protected array $_accessible = [
        'slug' => true,
        'display_name' => true,
        'status' => true,
        'schema_version' => true,
        'primary_host' => true,
        'path_prefix' => true,
        'tenant_aliases' => true,
        'tenant_database_configs' => true,
        'tenant_service_configs' => true,
    ];

    /**
     * Whether the tenant can serve normal application traffic.
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Whether the tenant is in drain mode.
     *
     * @return bool
     */
    public function isDraining(): bool
    {
        return $this->status === self::STATUS_DRAINING;
    }
}
