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
     * @var callable(string): void|null
     */
    private $onLookup;

    private int $delayMicroseconds = 0;

    /**
     * @param array<string, \App\Model\Entity\Tenant> $tenantsByHost
     */
    public function __construct(array $tenantsByHost, ?callable $onLookup = null, int $delayMicroseconds = 0)
    {
        $this->tenantsByHost = $tenantsByHost;
        $this->onLookup = $onLookup;
        $this->delayMicroseconds = max(0, $delayMicroseconds);
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
        if ($this->delayMicroseconds > 0) {
            usleep($this->delayMicroseconds);
        }
        if ($this->onLookup !== null) {
            ($this->onLookup)($normalizedHost);
        }

        return $this->tenantsByHost[$normalizedHost] ?? null;
    }
}
