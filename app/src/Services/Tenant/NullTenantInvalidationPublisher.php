<?php
declare(strict_types=1);

namespace App\Services\Tenant;

/**
 * No-op publisher; extension point for Azure Service Bus topic publishing.
 */
class NullTenantInvalidationPublisher implements TenantInvalidationPublisherInterface
{
    /**
     * @param int $tenantId Tenant id
     * @param int $version Version
     * @param string $changeType Change type
     * @param array<string, mixed> $context Metadata
     * @return void
     */
    public function publish(int $tenantId, int $version, string $changeType, array $context = []): void
    {
    }
}
