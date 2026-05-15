<?php
declare(strict_types=1);

namespace App\Services\Tenant;

use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\Utility\Text;
use Closure;

/**
 * Coordinates tenant-scoped operation leases for destructive platform actions.
 */
class TenantOperationLockService
{
    use LocatorAwareTrait;

    private const DEFAULT_LEASE_SECONDS = 900;
    private const STALE_HEARTBEAT_SECONDS = 300;

    /**
     * Acquire tenant lock or throw with operator-facing conflict details.
     *
     * @param int $tenantId Tenant id
     * @param string $operation Operation identifier
     * @param string $owner Owner descriptor (controller/action/actor)
     * @param array<string, mixed> $metadata Optional metadata for lock diagnostics
     * @param int|null $tenantOperationJobId Optional related operation job id
     * @param int|null $leaseSeconds Optional lease TTL
     * @return string Lease token
     */
    public function acquire(
        int $tenantId,
        string $operation,
        string $owner,
        array $metadata = [],
        ?int $tenantOperationJobId = null,
        ?int $leaseSeconds = null,
    ): string {
        $locks = $this->fetchTable('TenantOperationLocks');
        $connection = $locks->getConnection();
        $now = DateTime::now();
        $expiresAt = $now->addSeconds(max(60, (int)($leaseSeconds ?? self::DEFAULT_LEASE_SECONDS)));
        $token = Text::uuid();

        $conflict = null;
        $acquired = false;
        $connection->transactional(function () use (
            $locks,
            $tenantId,
            $operation,
            $owner,
            $metadata,
            $tenantOperationJobId,
            $now,
            $expiresAt,
            $token,
            &$conflict,
            &$acquired,
        ): void {
            $lock = $locks->find()
                ->where(['tenant_id' => $tenantId])
                ->epilog('FOR UPDATE')
                ->first();

            if ($lock === null) {
                $lock = $locks->newEntity([
                    'tenant_id' => $tenantId,
                    'operation' => $operation,
                    'owner' => $owner,
                    'lease_token' => $token,
                    'lease_acquired_at' => $now,
                    'lease_expires_at' => $expiresAt,
                    'heartbeat_at' => $now,
                    'status_message' => sprintf('%s lock acquired by %s', $operation, $owner),
                    'metadata' => $metadata === [] ? null : $metadata,
                    'tenant_operation_job_id' => $tenantOperationJobId,
                    'stale_recovered_at' => null,
                ]);
                $locks->saveOrFail($lock);
                $acquired = true;

                return;
            }

            if ($this->isStale($lock, $now)) {
                $lock = $locks->patchEntity($lock, [
                    'operation' => $operation,
                    'owner' => $owner,
                    'lease_token' => $token,
                    'lease_acquired_at' => $now,
                    'lease_expires_at' => $expiresAt,
                    'heartbeat_at' => $now,
                    'status_message' => sprintf('%s lock recovered by %s from stale owner', $operation, $owner),
                    'metadata' => $metadata === [] ? null : $metadata,
                    'tenant_operation_job_id' => $tenantOperationJobId,
                    'stale_recovered_at' => $now,
                ]);
                $locks->saveOrFail($lock);
                $acquired = true;

                return;
            }

            $conflict = [
                'tenant_id' => $tenantId,
                'operation' => (string)$lock->operation,
                'owner' => (string)$lock->owner,
                'lease_expires_at' => $lock->lease_expires_at,
                'heartbeat_at' => $lock->heartbeat_at,
                'requested_operation' => $operation,
            ];
        });

        if ($acquired) {
            return $token;
        }

        $expiresAtText = '';
        if (is_object($conflict['lease_expires_at'] ?? null) && method_exists($conflict['lease_expires_at'], 'i18nFormat')) {
            $expiresAtText = (string)$conflict['lease_expires_at']->i18nFormat('yyyy-MM-dd HH:mm:ss');
        }
        throw new TenantOperationLockException(
            $expiresAtText === ''
                ? sprintf(
                    'Tenant operation lock is held by %s for "%s". Retry after the active operation completes.',
                    (string)($conflict['owner'] ?? 'another operator'),
                    (string)($conflict['operation'] ?? 'unknown'),
                )
                : sprintf(
                    'Tenant operation lock is held by %s for "%s" until %s. Retry after it completes.',
                    (string)($conflict['owner'] ?? 'another operator'),
                    (string)($conflict['operation'] ?? 'unknown'),
                    $expiresAtText,
                ),
            is_array($conflict) ? $conflict : [],
        );
    }

    /**
     * Refresh lock heartbeat/lease for long-running operations.
     */
    public function heartbeat(int $tenantId, string $token, ?int $leaseSeconds = null): void
    {
        $locks = $this->fetchTable('TenantOperationLocks');
        $lock = $locks->find()
            ->where(['tenant_id' => $tenantId, 'lease_token' => $token])
            ->first();
        if ($lock === null) {
            throw new TenantOperationLockException('Tenant operation lock was lost before heartbeat.');
        }
        $now = DateTime::now();
        $lock = $locks->patchEntity($lock, [
            'heartbeat_at' => $now,
            'lease_expires_at' => $now->addSeconds(max(60, (int)($leaseSeconds ?? self::DEFAULT_LEASE_SECONDS))),
        ]);
        $locks->saveOrFail($lock);
    }

    /**
     * Release tenant lock if owned by the provided lease token.
     */
    public function release(int $tenantId, string $token): void
    {
        $locks = $this->fetchTable('TenantOperationLocks');
        $locks->deleteAll(['tenant_id' => $tenantId, 'lease_token' => $token]);
    }

    /**
     * Run operation under lock and always release lock afterwards.
     *
     * @template T
     * @param Closure():T $operationCallback Callback to execute while lock is held
     * @return T
     */
    public function runWithLock(
        int $tenantId,
        string $operation,
        string $owner,
        Closure $operationCallback,
        array $metadata = [],
        ?int $tenantOperationJobId = null,
        ?int $leaseSeconds = null,
    ): mixed {
        $token = $this->acquire(
            $tenantId,
            $operation,
            $owner,
            $metadata,
            $tenantOperationJobId,
            $leaseSeconds,
        );
        try {
            return $operationCallback();
        } finally {
            $this->release($tenantId, $token);
        }
    }

    /**
     * Determine if a lock can be reclaimed.
     */
    private function isStale(object $lock, DateTime $now): bool
    {
        $leaseExpired = $lock->lease_expires_at instanceof DateTime && $lock->lease_expires_at <= $now;
        if ($leaseExpired) {
            return true;
        }
        if ($lock->heartbeat_at instanceof DateTime) {
            return $lock->heartbeat_at <= $now->subSeconds(self::STALE_HEARTBEAT_SECONDS);
        }

        return false;
    }
}
