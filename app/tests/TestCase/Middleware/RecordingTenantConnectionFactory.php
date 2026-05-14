<?php
declare(strict_types=1);

namespace App\Test\TestCase\Middleware;

use App\Services\Tenant\TenantConnectionFactory;
use App\Services\Tenant\TenantContext;

class RecordingTenantConnectionFactory extends TenantConnectionFactory
{
    public ?TenantContext $configuredContext = null;

    public function configure(TenantContext $context): void
    {
        $this->configuredContext = $context;
    }

    public function resetOrmState(): void
    {
    }
}
