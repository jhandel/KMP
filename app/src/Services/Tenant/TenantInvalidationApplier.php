<?php
declare(strict_types=1);

namespace App\Services\Tenant;

use App\Services\Platform\PlatformSecretService;

/**
 * Applies tenant runtime invalidations in-process when versions advance.
 */
class TenantInvalidationApplier
{
    /**
     * @var array<int, int>
     */
    private static array $appliedVersions = [];

    /**
     * @param \App\Services\Tenant\TenantInvalidationService|null $invalidationService Invalidation version store
     * @param \App\Services\Tenant\TenantConnectionFactory|null $connectionFactory Connection/ORM reset helper
     * @param \App\Services\Tenant\TenantRuntimeConfigService|null $runtimeConfigService Runtime config reset helper
     * @param \App\Services\Platform\PlatformSecretService|null $secretService Managed secret cache service
     */
    public function __construct(
        private readonly ?TenantInvalidationService $invalidationService = null,
        private readonly ?TenantConnectionFactory $connectionFactory = null,
        private readonly ?TenantRuntimeConfigService $runtimeConfigService = null,
        private readonly ?PlatformSecretService $secretService = null,
    ) {
    }

    /**
     * Refresh local tenant runtime state if a newer version exists.
     *
     * @param \App\Services\Tenant\TenantContext $context Tenant context
     * @return void
     */
    public function apply(TenantContext $context): void
    {
        $version = ($this->invalidationService ?? new TenantInvalidationService())->effectiveVersion($context->id);
        $lastApplied = self::$appliedVersions[$context->id] ?? null;
        if ($lastApplied === null) {
            self::$appliedVersions[$context->id] = $version;

            return;
        }
        if ($version <= $lastApplied) {
            return;
        }

        ($this->secretService ?? new PlatformSecretService())->clearCache();
        ($this->runtimeConfigService ?? new TenantRuntimeConfigService())->reset();
        ($this->connectionFactory ?? new TenantConnectionFactory())->resetOrmState();
        self::$appliedVersions[$context->id] = $version;
    }

    /**
     * Reset process-local applied versions.
     *
     * @return void
     */
    public static function clearAppliedVersions(): void
    {
        self::$appliedVersions = [];
    }
}
