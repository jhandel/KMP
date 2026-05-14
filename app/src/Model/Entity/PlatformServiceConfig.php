<?php
declare(strict_types=1);

namespace App\Model\Entity;

/**
 * Platform runtime service configuration, such as admin email transport.
 */
class PlatformServiceConfig extends BaseEntity
{
    protected array $_accessible = [
        'service_name' => true,
        'config_key' => true,
        'adapter' => true,
        'secret_reference' => true,
        'metadata' => true,
        'is_active' => true,
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
