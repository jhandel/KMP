<?php
declare(strict_types=1);

namespace App\Model\Entity;

/**
 * Platform-held database connection metadata for a tenant.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $connection_role
 * @property string $driver
 * @property string $host
 * @property int|null $port
 * @property string $database_name
 * @property string|null $username
 * @property string|null $secret_reference
 * @property string|null $encrypted_dsn
 * @property bool $read_enabled
 * @property bool $write_enabled
 * @property bool $is_active
 * @property array|null $metadata
 * @property \App\Model\Entity\Tenant $tenant
 */
class TenantDatabaseConfig extends BaseEntity
{
    protected array $_accessible = [
        'tenant_id' => true,
        'connection_role' => true,
        'driver' => true,
        'host' => true,
        'port' => true,
        'database_name' => true,
        'username' => true,
        'secret_reference' => true,
        'encrypted_dsn' => true,
        'read_enabled' => true,
        'write_enabled' => true,
        'is_active' => true,
        'metadata' => true,
        'tenant' => true,
    ];

    protected array $_hidden = [
        'encrypted_dsn',
    ];

    /**
     * Encode metadata arrays for JSON-capable platform datastores.
     *
     * @param array<string, mixed>|string|null $metadata Metadata value
     * @return string|null
     */
    protected function _setMetadata(array|string|null $metadata): ?string
    {
        if ($metadata === null || $metadata === '') {
            return null;
        }
        if (is_string($metadata)) {
            return $metadata;
        }

        return json_encode($metadata, JSON_THROW_ON_ERROR);
    }
}
