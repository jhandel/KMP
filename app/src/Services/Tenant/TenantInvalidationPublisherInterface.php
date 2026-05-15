<?php
declare(strict_types=1);

namespace App\Services\Tenant;

interface TenantInvalidationPublisherInterface
{
    /**
     * Publish a tenant runtime invalidation event.
     *
     * @param int $tenantId Tenant id (0 = global registry scope)
     * @param int $version Monotonic invalidation version
     * @param string $changeType Change category
     * @param array<string, mixed> $context Additional metadata
     * @return void
     */
    public function publish(int $tenantId, int $version, string $changeType, array $context = []): void;
}
