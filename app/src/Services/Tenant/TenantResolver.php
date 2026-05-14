<?php
declare(strict_types=1);

namespace App\Services\Tenant;

use App\Model\Entity\Tenant;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Resolves the current tenant from host-based routing metadata.
 */
class TenantResolver
{
    /**
     * Constructor.
     *
     * @param \App\Services\Tenant\TenantRegistry $registry Platform tenant registry
     * @param string|null $requiredSchemaVersion Required schema version gate
     */
    public function __construct(
        private readonly TenantRegistry $registry,
        private readonly ?string $requiredSchemaVersion = null,
    ) {
    }

    /**
     * Normalize host casing, ports, URL wrappers, and trailing dots.
     *
     * @param string $host Raw host or URL
     * @return string
     */
    public static function normalizeHost(string $host): string
    {
        return TenantRegistry::normalizeHost($host);
    }

    /**
     * Resolve tenant context from a PSR-7 request host.
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request Request
     * @return \App\Services\Tenant\TenantContext
     */
    public function resolve(ServerRequestInterface $request): TenantContext
    {
        $host = $request->getHeaderLine('Host');
        if ($host === '') {
            $host = $request->getUri()->getHost();
        }

        return $this->resolveHost($host);
    }

    /**
     * Resolve tenant context from a raw host value.
     *
     * @param string $host Raw host
     * @return \App\Services\Tenant\TenantContext
     * @throws \App\Services\Tenant\TenantResolutionException
     */
    public function resolveHost(string $host): TenantContext
    {
        $normalizedHost = self::normalizeHost($host);
        if ($normalizedHost === '') {
            throw new TenantResolutionException(
                TenantResolutionException::EMPTY_HOST,
                'Tenant could not be resolved because the request host is empty.',
            );
        }

        $tenant = $this->registry->findTenantForHost($normalizedHost);
        if ($tenant === null) {
            throw new TenantResolutionException(
                TenantResolutionException::UNKNOWN_TENANT,
                sprintf('No tenant is registered for host "%s".', $normalizedHost),
                $normalizedHost,
            );
        }

        if ($tenant->status !== Tenant::STATUS_ACTIVE) {
            throw new TenantResolutionException(
                TenantResolutionException::INACTIVE_TENANT,
                sprintf('Tenant "%s" is not active.', $tenant->slug),
                $normalizedHost,
                (string)$tenant->slug,
            );
        }

        if (
            $this->requiredSchemaVersion !== null
            && (string)($tenant->schema_version ?? '') !== $this->requiredSchemaVersion
        ) {
            throw new TenantResolutionException(
                TenantResolutionException::SCHEMA_MISMATCH,
                sprintf('Tenant "%s" schema version does not match the required version.', $tenant->slug),
                $normalizedHost,
                (string)$tenant->slug,
            );
        }

        return TenantContext::fromTenant($tenant, $normalizedHost);
    }
}
