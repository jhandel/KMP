<?php
declare(strict_types=1);

namespace App\Test\TestCase\Services\Tenant;

use App\Model\Entity\Tenant;
use App\Services\Tenant\TenantRegistry;

class FakeTenantRegistry extends TenantRegistry
{
    /**
     * @var array<string, \App\Model\Entity\Tenant>
     */
    private array $tenantsByHost;

    /**
     * @var array<int, string>
     */
    public array $requestedHosts = [];

    /**
     * @param array<string, \App\Model\Entity\Tenant> $tenantsByHost
     */
    public function __construct(array $tenantsByHost)
    {
        $this->tenantsByHost = $tenantsByHost;
    }

    /**
     * Return a fake tenant for the normalized host.
     *
     * @param string $normalizedHost Normalized host
     * @return \App\Model\Entity\Tenant|null
     */
    public function findTenantForHost(string $normalizedHost): ?Tenant
    {
        $this->requestedHosts[] = $normalizedHost;

        return $this->tenantsByHost[$normalizedHost] ?? null;
    }
}
