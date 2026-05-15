<?php
declare(strict_types=1);

namespace App\Services\Platform;

use App\Model\Entity\Tenant;
use App\Services\Tenant\TenantConnectionFactory;
use App\Services\Tenant\TenantContext;
use App\Services\Tenant\TenantContextAccessor;
use App\Services\Tenant\TenantInvalidationService;
use App\Services\Tenant\TenantRuntimeConfigService;
use Cake\Datasource\ConnectionManager;
use Cake\ORM\Locator\LocatorAwareTrait;
use Throwable;

/**
 * Executes staged tenant database secret-reference rotation with rollback safety.
 */
class TenantDatabaseSecretRotationService
{
    use LocatorAwareTrait;

    /**
     * @param callable|null $connectionVerifier Optional connection verification callback
     */
    public function __construct(
        private readonly mixed $connectionVerifier = null,
    ) {
    }

    /**
     * Rotate a tenant primary database secret reference.
     *
     * @param \App\Model\Entity\Tenant $tenant Tenant
     * @param array<string, mixed> $input Operation input payload
     * @param callable $progress Progress callback
     * @return array<string, mixed>
     */
    public function rotate(Tenant $tenant, array $input, callable $progress): array
    {
        $newReference = trim((string)($input['new_secret_reference'] ?? ''));
        if ($newReference === '') {
            throw new TenantOperationPermanentException(
                'tenant_rotate_db_secret requires input.new_secret_reference.',
            );
        }
        if (!str_starts_with($newReference, 'managed:') && !str_starts_with($newReference, 'env:')) {
            throw new TenantOperationPermanentException(
                'tenant_rotate_db_secret new_secret_reference must start with managed: or env:.',
            );
        }

        $progress('tenant-secret-rotation', 'Preparing database secret rotation', 20, 'tenant-secret-rotation:prepare');

        $databaseConfigs = $this->fetchTable('TenantDatabaseConfigs');
        $primaryConfig = $databaseConfigs->find()
            ->where([
                'tenant_id' => (int)$tenant->id,
                'connection_role' => 'primary',
                'is_active' => true,
            ])
            ->first();
        if ($primaryConfig === null) {
            throw new TenantOperationPermanentException('Tenant does not have an active primary database config.');
        }

        $previousReference = (string)($primaryConfig->secret_reference ?? '');
        if ($previousReference !== '' && hash_equals($previousReference, $newReference)) {
            $progress('tenant-secret-rotation', 'Database secret reference already active', 95, 'tenant-secret-rotation:noop');

            return [
                'tenant_id' => (int)$tenant->id,
                'slug' => (string)$tenant->slug,
                'previous_secret_reference' => $previousReference,
                'new_secret_reference' => $newReference,
                'rotated' => false,
                'rolled_back' => false,
            ];
        }

        $updatedReference = false;

        try {
            $primaryConfig->secret_reference = $newReference;
            $databaseConfigs->saveOrFail($primaryConfig);
            $updatedReference = true;
            $progress('tenant-secret-rotation', 'Updated tenant database secret reference', 45, 'tenant-secret-rotation:update-reference');

            $this->clearRuntimeState();
            $progress('tenant-secret-rotation', 'Invalidated local secret and connection caches', 70, 'tenant-secret-rotation:invalidate');

            $reloadedTenant = $this->fetchTable('Tenants')->get((int)$tenant->id, contain: ['TenantDatabaseConfigs']);
            $this->verifyTenantConnection($reloadedTenant);
            $progress('tenant-secret-rotation', 'Verified tenant DB connectivity with rotated secret', 90, 'tenant-secret-rotation:verify');

            $invalidationVersion = (new TenantInvalidationService())->bumpTenant((int)$tenant->id, 'tenant_secret_rotated', [
                'secrets' => ['database'],
                'source' => 'tenant-operation-worker',
                'operation' => 'tenant_rotate_db_secret',
            ]);

            return [
                'tenant_id' => (int)$tenant->id,
                'slug' => (string)$tenant->slug,
                'previous_secret_reference' => $previousReference,
                'new_secret_reference' => $newReference,
                'rotated' => true,
                'rolled_back' => false,
                'invalidation_version' => $invalidationVersion,
            ];
        } catch (Throwable $exception) {
            if ($updatedReference) {
                $this->rollbackReference(
                    tenant: $tenant,
                    primaryConfigId: (int)$primaryConfig->id,
                    previousReference: $previousReference,
                    attemptedReference: $newReference,
                    reason: $exception,
                );
            }

            throw $exception;
        }
    }

    /**
     * @param \App\Model\Entity\Tenant $tenant Tenant
     * @return void
     */
    private function verifyTenantConnection(Tenant $tenant): void
    {
        if (is_callable($this->connectionVerifier)) {
            $verifier = $this->connectionVerifier;
            $verifier($tenant);

            return;
        }

        (new TenantConnectionFactory())->configure(TenantContext::fromTenant(
            $tenant,
            (string)($tenant->primary_host ?? $tenant->slug),
        ));
        ConnectionManager::get('tenant')->execute('SELECT 1');
    }

    /**
     * @param \App\Model\Entity\Tenant $tenant Tenant
     * @param int $primaryConfigId Primary config id
     * @param string $previousReference Previous secret reference
     * @param string $attemptedReference Attempted new reference
     * @param \Throwable $reason Failure cause
     * @return void
     */
    private function rollbackReference(
        Tenant $tenant,
        int $primaryConfigId,
        string $previousReference,
        string $attemptedReference,
        Throwable $reason,
    ): void {
        try {
            $this->fetchTable('TenantDatabaseConfigs')->updateAll(
                ['secret_reference' => $previousReference === '' ? null : $previousReference],
                ['id' => $primaryConfigId],
            );
            $this->clearRuntimeState();
            (new TenantInvalidationService())->bumpTenant((int)$tenant->id, 'tenant_secret_rotation_rollback', [
                'secrets' => ['database'],
                'source' => 'tenant-operation-worker',
                'operation' => 'tenant_rotate_db_secret',
                'attempted_secret_reference' => $attemptedReference,
                'rolled_back_to_reference' => $previousReference,
                'reason' => get_class($reason),
            ]);
        } catch (Throwable $rollbackError) {
            throw new TenantOperationPermanentException(sprintf(
                'Database secret rotation rollback failed: %s',
                $rollbackError->getMessage(),
            ));
        }
    }

    /**
     * @return void
     */
    private function clearRuntimeState(): void
    {
        (new PlatformSecretService())->clearCache();
        (new TenantRuntimeConfigService())->reset();
        TenantContextAccessor::set(null);
        TenantContext::clearCurrent();
        (new TenantConnectionFactory())->resetOrmState();
    }
}
