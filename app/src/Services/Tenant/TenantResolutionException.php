<?php
declare(strict_types=1);

namespace App\Services\Tenant;

use RuntimeException;

class TenantResolutionException extends RuntimeException
{
    public const EMPTY_HOST = 'empty_host';
    public const UNKNOWN_TENANT = 'unknown_tenant';
    public const INACTIVE_TENANT = 'inactive_tenant';
    public const DRAINING_TENANT = 'draining_tenant';
    public const SCHEMA_MISMATCH = 'schema_mismatch';

    /**
     * Constructor.
     *
     * @param string $reason Machine-readable reason
     * @param string $message Human-readable message
     * @param string|null $host Normalized host
     * @param string|null $tenantSlug Tenant slug when known
     */
    public function __construct(
        private readonly string $reason,
        string $message,
        private readonly ?string $host = null,
        private readonly ?string $tenantSlug = null,
    ) {
        parent::__construct($message);
    }

    /**
     * Get the machine-readable failure reason.
     *
     * @return string
     */
    public function getReason(): string
    {
        return $this->reason;
    }

    /**
     * Get the normalized host involved in resolution.
     *
     * @return string|null
     */
    public function getHost(): ?string
    {
        return $this->host;
    }

    /**
     * Get the tenant slug when resolution reached a tenant.
     *
     * @return string|null
     */
    public function getTenantSlug(): ?string
    {
        return $this->tenantSlug;
    }
}
