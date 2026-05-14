<?php
declare(strict_types=1);

namespace App\Model\Entity;

/**
 * Platform-held runtime service configuration metadata for a tenant.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $service_name
 * @property string $config_key
 * @property string|null $adapter
 * @property string|null $secret_reference
 * @property array|null $metadata
 * @property bool $is_active
 * @property \App\Model\Entity\Tenant $tenant
 */
class TenantServiceConfig extends BaseEntity
{
    protected array $_accessible = [
        'tenant_id' => true,
        'service_name' => true,
        'config_key' => true,
        'adapter' => true,
        'secret_reference' => true,
        'metadata' => true,
        'is_active' => true,
        'tenant' => true,
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
