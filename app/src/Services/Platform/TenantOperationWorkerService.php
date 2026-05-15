<?php
declare(strict_types=1);

namespace App\Services\Platform;

use App\Model\Entity\PlatformAdmin;
use App\Model\Entity\Tenant;
use App\Model\Entity\TenantOperationJob;
use App\Services\BackupService;
use App\Services\BackupStorageService;
use App\Services\Telemetry\TenantMetrics;
use App\Services\Tenant\TenantConnectionFactory;
use App\Services\Tenant\TenantContext;
use App\Services\Tenant\TenantContextAccessor;
use App\Services\Tenant\TenantInvalidationService;
use App\Services\Tenant\TenantMigrationService;
use App\Services\Tenant\TenantOperationLockService;
use App\Services\Tenant\TenantProvisioningService;
use Cake\Datasource\ConnectionManager;
use Cake\Datasource\EntityInterface;
use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\Utility\Text;
use ReflectionClass;
use RuntimeException;
use Throwable;

/**
 * Durable worker loop for tenant_operation_jobs.
 */
class TenantOperationWorkerService
{
    use LocatorAwareTrait;

    public const DEFAULT_MAX_ATTEMPTS = 3;
    private const SUPPORTED_OPERATIONS = [
        'tenant_status',
        'tenant_migrate',
        'tenant_doctor',
        'tenant_create',
        'tenant_backup',
        'tenant_restore_cutover',
        'tenant_rotate_db_secret',
    ];

    /**
     * @var callable|null
     */
    private $operationExecutor;
    /**
     * @var callable|null
     */
    private $restoreMigrationExecutor;
    /**
     * @var callable|null
     */
    private $restoreImportExecutor;
    /**
     * @var callable|null
     */
    private $tenantInvalidationPublisher;
    /**
     * @var callable|null
     */
    private $restoreIntegrityChecker;

    /**
     * @param \App\Services\Tenant\TenantProvisioningService|null $tenantProvisioningService Tenant provisioning service
     * @param callable|null $operationExecutor Optional custom operation executor for tests
     * @param int $leaseTtlSeconds Lease duration in seconds
     * @param string|null $workerId Worker identity
     * @param \App\Services\Platform\TenantDatabaseSecretRotationService|null $tenantDatabaseSecretRotationService Secret rotation service
     * @param callable|null $restoreMigrationExecutor Optional restore migration executor for tests
     * @param callable|null $restoreImportExecutor Optional restore import executor for tests
     * @param callable|null $tenantInvalidationPublisher Optional invalidation publisher for tests
     * @param callable|null $restoreIntegrityChecker Optional restore integrity checker for tests
     */
    public function __construct(
        private readonly ?TenantProvisioningService $tenantProvisioningService = null,
        ?callable $operationExecutor = null,
        private readonly int $leaseTtlSeconds = 300,
        private readonly ?string $workerId = null,
        private readonly ?TenantDatabaseSecretRotationService $tenantDatabaseSecretRotationService = null,
        ?callable $restoreMigrationExecutor = null,
        ?callable $restoreImportExecutor = null,
        ?callable $tenantInvalidationPublisher = null,
        ?callable $restoreIntegrityChecker = null,
    ) {
        $this->operationExecutor = $operationExecutor;
        $this->restoreMigrationExecutor = $restoreMigrationExecutor;
        $this->restoreImportExecutor = $restoreImportExecutor;
        $this->tenantInvalidationPublisher = $tenantInvalidationPublisher;
        $this->restoreIntegrityChecker = $restoreIntegrityChecker;
    }

    /**
     * Process up to $limit available jobs.
     *
     * @param int $limit Maximum jobs to run in this pass
     * @return int Processed job count
     */
    public function runNextBatch(int $limit = 1): int
    {
        $processed = 0;
        $limit = max(1, $limit);
        TenantMetrics::observeOperationQueueDepth($this->runnableQueueDepth());
        for ($i = 0; $i < $limit; $i++) {
            $job = $this->acquireNextJob();
            if (!$job instanceof TenantOperationJob) {
                break;
            }
            $this->executeJob($job);
            $processed++;
        }

        return $processed;
    }

    /**
     * Claim the next runnable job with an execution lease.
     *
     * @return \App\Model\Entity\TenantOperationJob|null
     */
    private function acquireNextJob(): ?TenantOperationJob
    {
        $jobs = $this->fetchTable('TenantOperationJobs');
        $now = DateTime::now();
        $query = $jobs->find()
            ->where([
                'state IN' => [
                    TenantOperationJob::STATUS_QUEUED,
                    TenantOperationJob::STATUS_APPROVED,
                    TenantOperationJob::STATUS_RUNNING,
                ],
            ]);
        if (!is_callable($this->operationExecutor)) {
            $query->where(['operation IN' => self::SUPPORTED_OPERATIONS]);
        }
        $this->applyApprovalReadinessConstraint($query);
        $candidate = $query
            ->andWhere(function ($exp) use ($now) {
                return $exp->or([
                    ['state !=' => TenantOperationJob::STATUS_RUNNING],
                    ['lease_expires_at IS' => null],
                    ['lease_expires_at <=' => $now],
                ]);
            })
            ->orderByAsc('created')
            ->first();

        if (!$candidate instanceof TenantOperationJob) {
            return null;
        }

        $leaseToken = Text::uuid();
        $leaseExpiresAt = $now->addSeconds(max(30, $this->leaseTtlSeconds));
        $updates = [
            'state' => TenantOperationJob::STATUS_RUNNING,
            'status' => TenantOperationJob::STATUS_RUNNING,
            'lease_owner' => $this->workerId(),
            'lease_token' => $leaseToken,
            'lease_acquired_at' => $now,
            'lease_expires_at' => $leaseExpiresAt,
            'heartbeat_at' => $now,
            'status_message' => 'Starting operation',
        ];
        if ($candidate->started_at === null) {
            $updates['started_at'] = $now;
        }
        $conditions = [
            'id' => (int)$candidate->id,
            'state' => (string)$candidate->state,
        ];
        if ((string)$candidate->state === TenantOperationJob::STATUS_RUNNING) {
            $conditions['OR'] = [
                ['lease_expires_at IS' => null],
                ['lease_expires_at <=' => $now],
            ];
        }
        $updated = $jobs->updateAll($updates, $conditions);
        if ($updated < 1) {
            return null;
        }

        return $jobs->get((int)$candidate->id);
    }

    /**
     * Execute a claimed job and record terminal/retry state.
     *
     * @param \App\Model\Entity\TenantOperationJob $job Claimed job
     * @return void
     */
    private function executeJob(TenantOperationJob $job): void
    {
        $startedAt = hrtime(true);
        $leaseToken = (string)$job->lease_token;
        $jobId = (int)$job->id;
        $outcome = 'failed';
        if ($leaseToken === '') {
            TenantMetrics::observeOperationDuration(
                (string)$job->operation,
                (hrtime(true) - $startedAt) / 1_000_000,
                'failed',
            );
            TenantMetrics::incrementOperationOutcome((string)$job->operation, 'failed');
            TenantMetrics::incrementTenantHealthSignal('operation_failed');

            return;
        }
        if ($this->isCancellationRequested($jobId)) {
            $this->markCancelled($jobId, $leaseToken, 'Cancelled before execution started.');
            TenantMetrics::observeOperationDuration(
                (string)$job->operation,
                (hrtime(true) - $startedAt) / 1_000_000,
                'cancelled',
            );
            TenantMetrics::incrementOperationOutcome((string)$job->operation, 'cancelled');
            TenantMetrics::incrementTenantHealthSignal('operation_cancelled');

            return;
        }

        $progress = $this->normalizeProgress($job->progress_json);
        $attemptCount = (int)($progress['attempt_count'] ?? 0) + 1;
        $maxAttempts = max(
            1,
            (int)($job->input['max_attempts'] ?? $progress['max_attempts'] ?? self::DEFAULT_MAX_ATTEMPTS),
        );
        $progress['attempt_count'] = $attemptCount;
        $progress['max_attempts'] = $maxAttempts;
        $progress['updated_at'] = DateTime::now()->toIso8601String();
        $this->persistLeaseUpdate(
            $jobId,
            $leaseToken,
            [
                'status_message' => sprintf('Running attempt %d/%d', $attemptCount, $maxAttempts),
                'progress_json' => $progress,
                'heartbeat_at' => DateTime::now(),
                'lease_expires_at' => DateTime::now()->addSeconds(max(30, $this->leaseTtlSeconds)),
            ],
        );

        try {
            $result = $this->executeOperation(
                $job,
                function (
                    string $phase,
                    string $message,
                    ?int $percent = null,
                    ?string $checkpoint = null,
                ) use (
                    $jobId,
                    $leaseToken,
                    $progress,
                ): void {
                    $progressPayload = $progress;
                    $progressPayload['phase'] = $phase;
                    $progressPayload['message'] = $message;
                    $progressPayload['updated_at'] = DateTime::now()->toIso8601String();
                    if ($checkpoint !== null && $checkpoint !== '') {
                        $progressPayload['checkpoint'] = $checkpoint;
                    }
                    $fields = [
                        'status_message' => $message,
                        'progress_json' => $progressPayload,
                        'heartbeat_at' => DateTime::now(),
                        'lease_expires_at' => DateTime::now()->addSeconds(max(30, $this->leaseTtlSeconds)),
                    ];
                    if ($percent !== null) {
                        $fields['progress_percent'] = max(0, min(100, $percent));
                    }
                    $this->persistLeaseUpdate($jobId, $leaseToken, $fields);
                    if ($this->isCancellationRequested($jobId)) {
                        throw new TenantOperationPermanentException('Cancelled while executing.');
                    }
                },
            );
            $this->markCompleted($jobId, $leaseToken, $result, $progress);
            $outcome = 'success';
        } catch (Throwable $e) {
            $this->handleFailure($job, $leaseToken, $e, $attemptCount, $maxAttempts, $progress);
            $state = $this->jobState($jobId);
            if ($state === TenantOperationJob::STATUS_CANCELLED) {
                $outcome = 'cancelled';
            }
        } finally {
            $elapsedMilliseconds = (hrtime(true) - $startedAt) / 1_000_000;
            TenantMetrics::observeOperationDuration((string)$job->operation, $elapsedMilliseconds, $outcome);
            TenantMetrics::incrementOperationOutcome((string)$job->operation, $outcome);
            TenantMetrics::incrementTenantHealthSignal(match ($outcome) {
                'success' => 'operation_completed',
                'cancelled' => 'operation_cancelled',
                default => 'operation_failed',
            });
            $this->clearTenantRuntimeContext();
        }
    }

    /**
     * Execute operation by type.
     *
     * @param \App\Model\Entity\TenantOperationJob $job Job
     * @param callable $progress Progress callback
     * @return array<string, mixed>
     */
    private function executeOperation(TenantOperationJob $job, callable $progress): array
    {
        if (is_callable($this->operationExecutor)) {
            /** @var callable $executor */
            $executor = $this->operationExecutor;

            return (array)$executor($job, $progress);
        }

        $service = $this->tenantProvisioningService ?? new TenantProvisioningService();
        $operation = (string)$job->operation;
        $input = is_array($job->input ?? null) ? $job->input : [];
        $this->assertGatewayCommandAllowed($job, $operation, $input);

        return match ($operation) {
            'tenant_status' => $this->runTenantStatusOperation($job, $service, $progress),
            'tenant_migrate' => $this->runTenantMigrateOperation($job, $service, $progress),
            'tenant_doctor' => $this->runTenantDoctorOperation($job, $service, $progress),
            'tenant_create' => $this->runTenantCreateOperation($job, $input, $service, $progress),
            'tenant_backup' => $this->runTenantBackupOperation($job, $progress),
            'tenant_restore_cutover' => $this->runTenantRestoreCutoverOperation($job, $service, $progress),
            'tenant_rotate_db_secret' => $this->runTenantRotateDbSecretOperation($job, $progress),
            default => throw new TenantOperationPermanentException(
                sprintf('Unsupported tenant operation "%s".', $operation),
            ),
        };
    }

    /**
     * Execute queued tenant database secret-reference rotation operation.
     *
     * @param \App\Model\Entity\TenantOperationJob $job Job
     * @param callable $progress Progress callback
     * @return array<string, mixed>
     */
    private function runTenantRotateDbSecretOperation(TenantOperationJob $job, callable $progress): array
    {
        $tenant = $this->resolveTenant($job);
        $input = is_array($job->input ?? null) ? $job->input : [];

        return (new TenantOperationLockService())->runWithLock(
            (int)$tenant->id,
            'tenant_rotate_db_secret',
            'tenant-operation-worker',
            function () use ($tenant, $input, $progress): array {
                $rotationService = $this->tenantDatabaseSecretRotationService
                    ?? new TenantDatabaseSecretRotationService();

                return $rotationService->rotate($tenant, $input, $progress);
            },
            ['slug' => (string)$tenant->slug],
            (int)$job->id,
            1200,
        );
    }

    /**
     * Validate gateway metadata against the canonical command catalog.
     *
     * @param \App\Model\Entity\TenantOperationJob $job Job
     * @param string $operation Operation name
     * @param array<string, mixed> $input Job input
     * @return void
     */
    private function assertGatewayCommandAllowed(TenantOperationJob $job, string $operation, array $input): void
    {
        $gateway = is_array($input['gateway'] ?? null) ? $input['gateway'] : null;
        if ($gateway === null) {
            return;
        }

        $targetMode = trim((string)($gateway['tenant_target_mode'] ?? ''));
        if ($targetMode === '') {
            throw new TenantOperationPermanentException(sprintf(
                'Gateway command validation failed for operation "%s" (job %d): missing gateway.tenant_target_mode.',
                $operation,
                (int)$job->id,
            ));
        }

        $parameters = is_array($gateway['parameters'] ?? null) ? $gateway['parameters'] : [];
        try {
            $normalizedParameters = TenantOperationCommandCatalog::validateGatewayRequest(
                operation: $operation,
                targetMode: $targetMode,
                parameters: $parameters,
            );
            TenantOperationCommandCatalog::validateIdempotencyScope(
                operation: $operation,
                idempotencyScope: (string)$job->idempotency_scope,
            );
        } catch (RuntimeException $e) {
            throw new TenantOperationPermanentException(sprintf(
                'Gateway command validation failed for operation "%s" (job %d): %s',
                $operation,
                (int)$job->id,
                $e->getMessage(),
            ));
        }

        foreach ($normalizedParameters as $key => $value) {
            if (!array_key_exists($key, $input)) {
                if ($value !== null) {
                    throw new TenantOperationPermanentException(sprintf(
                        'Gateway command validation failed for operation "%s" (job %d): input.%s is missing.',
                        $operation,
                        (int)$job->id,
                        $key,
                    ));
                }

                continue;
            }
            $inputValue = $input[$key];
            if ($inputValue !== $value) {
                throw new TenantOperationPermanentException(sprintf(
                    'Gateway command validation failed for operation "%s" (job %d): '
                    . 'input.%s does not match approved parameters.',
                    $operation,
                    (int)$job->id,
                    $key,
                ));
            }
        }
    }

    /**
     * @param \App\Model\Entity\TenantOperationJob $job Job
     * @param \App\Services\Tenant\TenantProvisioningService $service Provisioning service
     * @param callable $progress Progress callback
     * @return array<string, mixed>
     */
    private function runTenantStatusOperation(
        TenantOperationJob $job,
        TenantProvisioningService $service,
        callable $progress,
    ): array {
        $tenant = $this->resolveTenant($job);
        $input = is_array($job->input ?? null) ? $job->input : [];
        $status = trim((string)($input['status'] ?? ''));
        if ($status === '') {
            throw new TenantOperationPermanentException('tenant_status operation requires input.status.');
        }
        $progress('tenant-status', 'Updating tenant status', 35, 'tenant-status:update');
        $updated = $service->setStatus((string)$tenant->slug, $status);
        $progress('tenant-status', 'Tenant status updated', 90, 'tenant-status:done');

        return [
            'tenant_id' => (int)$updated->id,
            'slug' => (string)$updated->slug,
            'status' => (string)$updated->status,
        ];
    }

    /**
     * @param \App\Model\Entity\TenantOperationJob $job Job
     * @param \App\Services\Tenant\TenantProvisioningService $service Provisioning service
     * @param callable $progress Progress callback
     * @return array<string, mixed>
     */
    private function runTenantMigrateOperation(
        TenantOperationJob $job,
        TenantProvisioningService $service,
        callable $progress,
    ): array {
        $tenant = $this->resolveTenant($job);
        $input = is_array($job->input ?? null) ? $job->input : [];
        $plugin = isset($input['plugin']) ? trim((string)$input['plugin']) : null;
        $plugin = $plugin === '' ? null : $plugin;
        $schemaBefore = (string)($tenant->schema_version ?? ($input['schema_before'] ?? ''));
        $migrationScope = trim((string)($input['migration_scope'] ?? 'tenant_schema'));
        $migrationScopes = is_array($input['migration_scopes'] ?? null) ? $input['migration_scopes'] : [];
        if ($migrationScopes === []) {
            $migrationScopes = ['core', 'plugins'];
        }
        $startedAt = DateTime::now();
        $startedAtIso = $startedAt->toIso8601String();
        $startedAtMillis = microtime(true);
        $progress('tenant-migrate', 'Running tenant migrations', 40, 'tenant-migrate:start');
        $schemaVersion = $service->migrateTenant($tenant, $plugin);
        $completedAt = DateTime::now();
        $durationMs = (int)round((microtime(true) - $startedAtMillis) * 1000);
        $progress('tenant-migrate', 'Tenant migrations completed', 90, 'tenant-migrate:done');

        return [
            'tenant_id' => (int)$tenant->id,
            'slug' => (string)$tenant->slug,
            'schema_before' => $schemaBefore,
            'schema_after' => $schemaVersion,
            'schema_version' => $schemaVersion,
            'plugin' => $plugin,
            'migration_scope' => $migrationScope,
            'migration_scopes' => $migrationScopes,
            'started_at' => $startedAtIso,
            'completed_at' => $completedAt->toIso8601String(),
            'duration_ms' => $durationMs,
            'duration_seconds' => round($durationMs / 1000, 3),
            'parent_operation_id' => isset($input['parent_operation_id']) ? (int)$input['parent_operation_id'] : null,
            'parent_tenant_operation_job_id' => isset($input['parent_tenant_operation_job_id'])
                ? (int)$input['parent_tenant_operation_job_id']
                : (isset($input['parent_operation_id']) ? (int)$input['parent_operation_id'] : null),
        ];
    }

    /**
     * @param \App\Model\Entity\TenantOperationJob $job Job
     * @param \App\Services\Tenant\TenantProvisioningService $service Provisioning service
     * @param callable $progress Progress callback
     * @return array<string, mixed>
     */
    private function runTenantDoctorOperation(
        TenantOperationJob $job,
        TenantProvisioningService $service,
        callable $progress,
    ): array {
        $tenant = $this->resolveTenant($job);
        $progress('tenant-doctor', 'Running tenant doctor checks', 40, 'tenant-doctor:start');
        $checks = $service->doctor($tenant);
        $progress('tenant-doctor', 'Tenant doctor checks complete', 90, 'tenant-doctor:done');

        return [
            'tenant_id' => (int)$tenant->id,
            'slug' => (string)$tenant->slug,
            'checks' => $checks,
        ];
    }

    /**
     * @param array<string, mixed> $input Operation input payload
     * @param \App\Services\Tenant\TenantProvisioningService $service Provisioning service
     * @param callable $progress Progress callback
     * @return array<string, mixed>
     */
    private function runTenantCreateOperation(
        TenantOperationJob $job,
        array $input,
        TenantProvisioningService $service,
        callable $progress,
    ): array {
        $payload = is_array($input['tenant'] ?? null) ? (array)$input['tenant'] : $input;
        $slug = trim((string)($payload['slug'] ?? ''));
        if ($slug === '') {
            throw new TenantOperationPermanentException('tenant_create operation requires tenant slug.');
        }
        if ((bool)($payload['create_database'] ?? false)) {
            $progress('tenant-create', 'Creating tenant database', 20, 'tenant-create:create-db');
            $service->createPhysicalDatabase($payload);
        }
        $progress('tenant-create', 'Saving tenant metadata', 45, 'tenant-create:metadata');
        $tenant = $service->createOrUpdateTenant($payload);
        if ((bool)($payload['migrate'] ?? false)) {
            $progress('tenant-create', 'Running tenant migrations', 70, 'tenant-create:migrate');
            $service->migrateTenant($tenant);
            $tenant = $service->getTenant((string)$tenant->slug);
        }
        if ((bool)($payload['activate'] ?? false)) {
            $progress('tenant-create', 'Activating tenant', 85, 'tenant-create:activate');
            $tenant = $service->setStatus((string)$tenant->slug, Tenant::STATUS_ACTIVE);
        }
        $secretChanges = $this->storeTenantSecretsFromPayload($job, $tenant, $payload);
        if ($secretChanges !== []) {
            $progress('tenant-create', 'Stored managed tenant secrets', 92, 'tenant-create:secrets');
            (new TenantInvalidationService())->bumpTenant((int)$tenant->id, 'tenant_secret_rotated', [
                'secrets' => $secretChanges,
            ]);
        }
        $progress('tenant-create', 'Tenant create/update completed', 95, 'tenant-create:done');

        return [
            'tenant_id' => (int)$tenant->id,
            'slug' => (string)$tenant->slug,
            'status' => (string)$tenant->status,
            'secrets' => $secretChanges,
        ];
    }

    /**
     * Execute queued backup operation.
     *
     * @param \App\Model\Entity\TenantOperationJob $job Job
     * @param callable $progress Progress callback
     * @return array<string, mixed>
     */
    private function runTenantBackupOperation(TenantOperationJob $job, callable $progress): array
    {
        $tenant = $this->resolveTenant($job);
        $input = is_array($job->input ?? null) ? $job->input : [];
        $key = (string)($input['backup_key'] ?? '');
        if ($key === '') {
            throw new TenantOperationPermanentException('tenant_backup operation requires input.backup_key.');
        }
        $progress('tenant-backup', 'Preparing tenant backup', 25, 'tenant-backup:start');

        return (new TenantOperationLockService())->runWithLock(
            (int)$tenant->id,
            'tenant_backup',
            'tenant-operation-worker',
            function () use ($tenant, $key, $progress): array {
                $this->configureTenant($tenant);
                $storage = new BackupStorageService();
                $backupService = new BackupService();
                $backupsTable = $this->fetchTable('Backups');
                $filename = $storage->buildBackupFilename();
                $backup = $backupsTable->newEntity([
                    'filename' => $filename,
                    'storage_type' => $storage->getAdapterType(),
                    'status' => 'running',
                ]);
                $backupsTable->saveOrFail($backup);
                $progress('tenant-backup', 'Exporting tenant backup', 55, 'tenant-backup:export');
                try {
                    $result = $backupService->export($key);
                    $storage->write($filename, (string)$result['data']);
                    $backup->size_bytes = $result['meta']['size_bytes'];
                    $backup->table_count = $result['meta']['table_count'];
                    $backup->row_count = $result['meta']['row_count'];
                    $backup->status = 'completed';
                    $backupsTable->saveOrFail($backup);
                    $progress('tenant-backup', 'Backup stored successfully', 92, 'tenant-backup:done');

                    return [
                        'tenant_id' => (int)$tenant->id,
                        'slug' => (string)$tenant->slug,
                        'backup_id' => (int)$backup->id,
                        'filename' => $filename,
                        'row_count' => (int)$backup->row_count,
                    ];
                } catch (Throwable $e) {
                    $backup->status = 'failed';
                    $backup->notes = substr($e->getMessage(), 0, 500);
                    $backupsTable->save($backup);
                    throw $e;
                }
            },
            ['slug' => (string)$tenant->slug],
            null,
            1800,
        );
    }

    /**
     * Execute queued restore and cutover operation.
     *
     * @param \App\Model\Entity\TenantOperationJob $job Job
     * @param \App\Services\Tenant\TenantProvisioningService $service Provisioning service
     * @param callable $progress Progress callback
     * @return array<string, mixed>
     */
    private function runTenantRestoreCutoverOperation(
        TenantOperationJob $job,
        TenantProvisioningService $service,
        callable $progress,
    ): array {
        $tenant = $this->resolveTenant($job);
        $input = is_array($job->input ?? null) ? $job->input : [];
        $newDatabase = trim((string)($input['new_database_name'] ?? ''));
        if ($newDatabase === '' || !preg_match('/^[A-Za-z0-9_]+$/', $newDatabase)) {
            throw new TenantOperationPermanentException(
                'tenant_restore_cutover requires a simple alphanumeric input.new_database_name.',
            );
        }
        $restoreKey = (string)($input['restore_key'] ?? '');
        if ($restoreKey === '') {
            throw new TenantOperationPermanentException('tenant_restore_cutover requires input.restore_key.');
        }
        $backupFileBase64 = (string)($input['backup_file_base64'] ?? '');
        if ($backupFileBase64 === '') {
            throw new TenantOperationPermanentException('tenant_restore_cutover requires input.backup_file_base64.');
        }
        $backupBytes = base64_decode($backupFileBase64, true);
        if (!is_string($backupBytes) || $backupBytes === '') {
            throw new TenantOperationPermanentException('Unable to decode restore backup payload.');
        }
        $primaryConfig = $this->primaryDatabaseConfig($tenant);
        if ($newDatabase === (string)$primaryConfig->database_name) {
            throw new TenantOperationPermanentException(
                'Restore database must be different from the current tenant database.',
            );
        }
        $this->assertRestoreTargetDatabaseIsSafe(
            $tenant,
            $newDatabase,
            (string)$primaryConfig->database_name,
            (int)$job->id,
        );
        $progress('tenant-restore', 'Preparing restore cutover', 20, 'tenant-restore:start');

        return (new TenantOperationLockService())->runWithLock(
            (int)$tenant->id,
            'tenant_restore_cutover',
            'tenant-operation-worker',
            function () use (
                $tenant,
                $service,
                $primaryConfig,
                $newDatabase,
                $backupBytes,
                $restoreKey,
                $progress,
            ): array {
                $statusBeforeRestore = (string)$tenant->status;
                $oldPrimaryConfig = $this->databaseConfigSnapshot($primaryConfig);
                $newPrimaryConfig = $oldPrimaryConfig;
                $newPrimaryConfig['database_name'] = $newDatabase;
                $tenant = $this->updateTenantStatusForLockedOperation(
                    $tenant,
                    Tenant::STATUS_DRAINING,
                    'tenant_restore_drain_started',
                    ['previous_status' => $statusBeforeRestore],
                );
                try {
                    $progress('tenant-restore', 'Creating restore database', 35, 'tenant-restore:create-db');
                    $service->createPhysicalDatabase([
                        'database_name' => $newDatabase,
                        'driver' => $primaryConfig->driver,
                    ]);
                    $this->configureRestoreTenant($tenant, $newDatabase);
                    $progress('tenant-restore', 'Running tenant migrations for restore', 50, 'tenant-restore:migrate');
                    if (is_callable($this->restoreMigrationExecutor)) {
                        ($this->restoreMigrationExecutor)();
                    } else {
                        (new TenantMigrationService())->migrate('tenant');
                    }
                    $progress('tenant-restore', 'Importing backup payload', 75, 'tenant-restore:import');
                    $result = is_callable($this->restoreImportExecutor)
                        ? (array)($this->restoreImportExecutor)($backupBytes, $restoreKey)
                        : (new BackupService())->import($backupBytes, $restoreKey);
                    if (is_callable($this->restoreIntegrityChecker)) {
                        ($this->restoreIntegrityChecker)($newDatabase, $result);
                    } else {
                        $this->assertRestoreIntegrity($newDatabase, $result);
                    }
                    $tenant = $this->refreshTenant((int)$tenant->id);
                    $this->assertTenantDraining($tenant);
                    $progress('tenant-restore', 'Applying cutover to restored database', 86, 'tenant-restore:cutover');

                    $rolledBack = false;
                    try {
                        $primaryConfig->database_name = $newDatabase;
                        $this->fetchTable('TenantDatabaseConfigs')->saveOrFail($primaryConfig);
                        $this->bumpTenantInvalidation((int)$tenant->id, 'tenant_restore_cutover', [
                            'databaseName' => $newDatabase,
                            'previousDatabaseName' => (string)$oldPrimaryConfig['database_name'],
                            'source' => 'tenant-operation-worker',
                        ]);
                        $progress('tenant-restore', 'Restore cutover complete', 92, 'tenant-restore:done');

                        return [
                            'tenant_id' => (int)$tenant->id,
                            'slug' => (string)$tenant->slug,
                            'new_database_name' => $newDatabase,
                            'previous_database_name' => (string)$oldPrimaryConfig['database_name'],
                            'row_count' => (int)($result['row_count'] ?? 0),
                            'table_count' => (int)($result['table_count'] ?? 0),
                            'constraints_not_valid' => (int)($result['constraints_not_valid'] ?? 0),
                            'cutover_state' => [
                                'previous_primary_config' => $oldPrimaryConfig,
                                'new_primary_config' => $newPrimaryConfig,
                                'rollback_applied' => false,
                            ],
                        ];
                    } catch (Throwable $cutoverException) {
                        $this->restorePrimaryDatabaseConfig($primaryConfig, $oldPrimaryConfig);
                        $rolledBack = true;
                        $this->bumpTenantInvalidation(
                            (int)$tenant->id,
                            'tenant_restore_cutover_rollback',
                            [
                                'databaseName' => (string)$oldPrimaryConfig['database_name'],
                                'failedCutoverDatabaseName' => $newDatabase,
                                'source' => 'tenant-operation-worker',
                            ],
                        );
                        $progress(
                            'tenant-restore',
                            'Cutover failed; restored previous primary database config.',
                            88,
                            'tenant-restore:rollback',
                        );
                        throw new TenantOperationPermanentException(sprintf(
                            'Restore cutover failed and rolled back to "%s": %s',
                            (string)$oldPrimaryConfig['database_name'],
                            $cutoverException->getMessage(),
                        ), 0, $cutoverException);
                    } finally {
                        if ($rolledBack) {
                            $this->clearTenantRuntimeContext();
                        }
                    }
                } catch (Throwable $restoreException) {
                    if ($restoreException instanceof TenantOperationPermanentException) {
                        throw $restoreException;
                    }
                    throw new TenantOperationPermanentException($restoreException->getMessage(), 0, $restoreException);
                } finally {
                    $this->updateTenantStatusForLockedOperation(
                        $tenant,
                        $statusBeforeRestore,
                        'tenant_restore_drain_released',
                        ['restored_status' => $statusBeforeRestore],
                    );
                }
            },
            ['slug' => (string)$tenant->slug],
            null,
            3600,
        );
    }

    /**
     * Reject unsafe restore database names that collide with tenant config state.
     */
    private function assertRestoreTargetDatabaseIsSafe(
        Tenant $tenant,
        string $newDatabase,
        string $currentDatabase,
        ?int $currentJobId = null,
    ): void {
        if (strcasecmp($newDatabase, $currentDatabase) === 0) {
            throw new TenantOperationPermanentException(
                'Restore database must be different from the current tenant database.',
            );
        }

        $knownConfigs = $this->fetchTable('TenantDatabaseConfigs')->find()
            ->select(['tenant_id', 'database_name'])
            ->all();
        foreach ($knownConfigs as $knownConfig) {
            if (strcasecmp((string)$knownConfig->database_name, $newDatabase) !== 0) {
                continue;
            }
            $ownerSlug = $this->tenantSlugForId((int)$knownConfig->tenant_id);
            throw new TenantOperationPermanentException(sprintf(
                'Restore target database "%s" already exists in tenant config%s. Pick a unique restore database name.',
                $newDatabase,
                $ownerSlug === null ? '' : sprintf(' for "%s"', $ownerSlug),
            ));
        }

        $lookbackStart = DateTime::now()->subDays(30);
        $recentJobs = $this->fetchTable('TenantOperationJobs')->find()
            ->select(['id', 'tenant_id', 'input', 'created'])
            ->where([
                'operation' => 'tenant_restore_cutover',
                'created >=' => $lookbackStart,
            ])
            ->orderByDesc('created')
            ->limit(200)
            ->all();
        foreach ($recentJobs as $recentJob) {
            if ($currentJobId !== null && (int)$recentJob->id === $currentJobId) {
                continue;
            }
            $input = $this->normalizeJobInput($recentJob->input ?? []);
            $recentTarget = trim((string)($input['new_database_name'] ?? ''));
            if ($recentTarget === '' || strcasecmp($recentTarget, $newDatabase) !== 0) {
                continue;
            }
            $ownerSlug = $this->tenantSlugForId((int)($recentJob->tenant_id ?? 0));
            $createdAt = $recentJob->created instanceof DateTime
                ? (string)$recentJob->created->i18nFormat('yyyy-MM-dd HH:mm:ss')
                : 'recently';
            throw new TenantOperationPermanentException(sprintf(
                'Restore target database "%s" was already used by%s on %s. Choose a fresh restore target.',
                $newDatabase,
                $ownerSlug === null ? ' another tenant operation' : sprintf(' "%s"', $ownerSlug),
                $createdAt,
            ));
        }
    }

    /**
     * Validate restored database before config cutover.
     *
     * @param array<string, mixed> $importResult
     */
    private function assertRestoreIntegrity(string $newDatabase, array $importResult): void
    {
        $connection = ConnectionManager::get('tenant');
        $connection->execute('SELECT 1')->fetch('assoc');

        $settings = $this->fetchTable('AppSettings');
        $missing = [];
        foreach (['KMP.KingdomName', 'KMP.ShortSiteTitle', 'KMP.LongSiteTitle'] as $name) {
            if (!$settings->exists(['name' => $name])) {
                $missing[] = $name;
            }
        }
        if ($missing !== []) {
            throw new TenantOperationPermanentException(sprintf(
                'Restore integrity check failed for "%s": missing app settings (%s).',
                $newDatabase,
                implode(', ', $missing),
            ));
        }

        $notValid = (int)($importResult['constraints_not_valid'] ?? 0);
        if ($notValid > 0) {
            throw new TenantOperationPermanentException(sprintf(
                'Restore integrity check failed: %d foreign key constraints are NOT VALID after import.',
                $notValid,
            ));
        }
    }

    /**
     * Ensure tenant remains in drain mode until cutover completes.
     */
    private function assertTenantDraining(Tenant $tenant): void
    {
        if ((string)$tenant->status !== Tenant::STATUS_DRAINING) {
            throw new TenantOperationPermanentException(sprintf(
                'Tenant "%s" must stay in drain mode during restore cutover.',
                (string)$tenant->slug,
            ));
        }
    }

    /**
     * @param mixed $input
     * @return array<string, mixed>
     */
    private function normalizeJobInput(mixed $input): array
    {
        if (is_array($input)) {
            return $input;
        }
        if (is_string($input) && $input !== '') {
            $decoded = json_decode($input, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    /**
     * Capture a stable rollback snapshot for primary DB config.
     *
     * @param object $config Database config entity
     * @return array<string, mixed>
     */
    private function databaseConfigSnapshot(object $config): array
    {
        return [
            'id' => (int)$config->id,
            'tenant_id' => (int)$config->tenant_id,
            'connection_role' => (string)$config->connection_role,
            'driver' => (string)$config->driver,
            'host' => (string)$config->host,
            'port' => $config->port === null ? null : (int)$config->port,
            'database_name' => (string)$config->database_name,
            'username' => $config->username === null ? null : (string)$config->username,
            'secret_reference' => $config->secret_reference === null ? null : (string)$config->secret_reference,
            'encrypted_dsn' => $config->encrypted_dsn === null ? null : (string)$config->encrypted_dsn,
            'read_enabled' => (bool)$config->read_enabled,
            'write_enabled' => (bool)$config->write_enabled,
            'is_active' => (bool)$config->is_active,
            'metadata' => is_array($config->metadata ?? null) ? $config->metadata : [],
        ];
    }

    /**
     * Roll back primary config after a failed cutover.
     *
     * @param object $primaryConfig Primary config entity
     * @param array<string, mixed> $snapshot Original state
     */
    private function restorePrimaryDatabaseConfig(object $primaryConfig, array $snapshot): void
    {
        foreach ($snapshot as $field => $value) {
            $primaryConfig->{$field} = $value;
        }
        $this->fetchTable('TenantDatabaseConfigs')->saveOrFail($primaryConfig);
    }

    /**
     * @param int $tenantId Tenant id
     * @return \App\Model\Entity\Tenant
     */
    private function refreshTenant(int $tenantId): Tenant
    {
        return $this->fetchTable('Tenants')->get(
            $tenantId,
            contain: ['TenantAliases', 'TenantDatabaseConfigs', 'TenantServiceConfigs'],
        );
    }

    /**
     * Resolve tenant slug from id for operator-facing messages.
     */
    private function tenantSlugForId(int $tenantId): ?string
    {
        if ($tenantId < 1) {
            return null;
        }
        $tenant = $this->fetchTable('Tenants')->find()
            ->select(['id', 'slug'])
            ->where(['id' => $tenantId])
            ->first();

        return $tenant instanceof Tenant ? (string)$tenant->slug : null;
    }

    /**
     * Resolve a tenant from tenant_id or input slug.
     *
     * @param \App\Model\Entity\TenantOperationJob $job Job
     * @return \App\Model\Entity\Tenant
     */
    private function resolveTenant(TenantOperationJob $job): Tenant
    {
        $tenantId = (int)($job->tenant_id ?? 0);
        if ($tenantId > 0) {
            return $this->fetchTable('Tenants')->get(
                $tenantId,
                contain: ['TenantAliases', 'TenantDatabaseConfigs', 'TenantServiceConfigs'],
            );
        }
        $input = is_array($job->input ?? null) ? $job->input : [];
        $slug = trim((string)($input['tenant_slug'] ?? $input['slug'] ?? ''));
        if ($slug === '') {
            throw new TenantOperationPermanentException('Tenant operation requires tenant_id or input tenant_slug.');
        }
        $tenant = $this->fetchTable('Tenants')->find()
            ->where(['slug' => $slug])
            ->contain(['TenantAliases', 'TenantDatabaseConfigs', 'TenantServiceConfigs'])
            ->first();
        if (!$tenant instanceof Tenant) {
            throw new TenantOperationPermanentException(sprintf('Tenant "%s" was not found.', $slug));
        }

        return $tenant;
    }

    /**
     * Configure tenant datasource for worker operations.
     *
     * @param \App\Model\Entity\Tenant $tenant Tenant
     * @return void
     */
    private function configureTenant(Tenant $tenant): void
    {
        (new TenantConnectionFactory())->configure(TenantContext::fromTenant(
            $tenant,
            (string)($tenant->primary_host ?? $tenant->slug),
        ));
    }

    /**
     * Configure tenant datasource against a temporary restore database.
     *
     * @param \App\Model\Entity\Tenant $tenant Tenant
     * @param string $databaseName Restore database name
     * @return void
     */
    private function configureRestoreTenant(Tenant $tenant, string $databaseName): void
    {
        $context = TenantContext::fromTenant($tenant, (string)($tenant->primary_host ?? $tenant->slug));
        $configs = $context->databaseConfigs;
        foreach ($configs as &$config) {
            if (($config['connectionRole'] ?? 'primary') === 'primary') {
                $config['databaseName'] = $databaseName;
            }
        }
        unset($config);
        (new TenantConnectionFactory())->configure(new TenantContext(
            $context->id,
            $context->slug,
            $context->displayName,
            $context->status,
            $context->schemaVersion,
            $context->primaryHost,
            $context->resolvedHost,
            $configs,
            $context->serviceConfigs,
        ));
    }

    /**
     * @param \App\Model\Entity\Tenant $tenant Tenant
     * @return \Cake\Datasource\EntityInterface
     */
    private function primaryDatabaseConfig(Tenant $tenant): EntityInterface
    {
        foreach ((array)($tenant->tenant_database_configs ?? []) as $config) {
            if ((string)$config->connection_role === 'primary' && (bool)$config->is_active) {
                return $config;
            }
        }

        throw new TenantOperationPermanentException('Tenant does not have an active primary database config.');
    }

    /**
     * @param \App\Model\Entity\TenantOperationJob $job Job
     * @param \App\Model\Entity\Tenant $tenant Tenant
     * @param array<string, mixed> $payload Tenant create payload
     * @return array<int, string>
     */
    private function storeTenantSecretsFromPayload(TenantOperationJob $job, Tenant $tenant, array $payload): array
    {
        $adminId = (int)($job->platform_admin_id ?? 0);
        if ($adminId < 1) {
            return [];
        }
        $admin = $this->fetchTable('PlatformAdmins')->find()
            ->where(['id' => $adminId])
            ->first();
        if (!$admin instanceof PlatformAdmin) {
            return [];
        }

        $secretService = new PlatformSecretService();
        $changed = [];
        $tenantId = (int)$tenant->id;

        $databaseSecret = (string)($payload['database_secret_value'] ?? '');
        if ($databaseSecret !== '') {
            $reference = $secretService->storeSecret(
                sprintf('tenant/%d/database/primary', $tenantId),
                $databaseSecret,
                sprintf('Primary database password for tenant %s', $tenant->slug),
                $admin,
            );
            $this->fetchTable('TenantDatabaseConfigs')->updateAll(
                ['secret_reference' => $reference],
                ['tenant_id' => $tenantId, 'connection_role' => 'primary'],
            );
            $changed[] = 'database';
        }

        $emailSecret = (string)($payload['email_secret_value'] ?? '');
        if ($emailSecret !== '') {
            $reference = $secretService->storeSecret(
                sprintf('tenant/%d/email/default', $tenantId),
                $emailSecret,
                sprintf('Email password for tenant %s', $tenant->slug),
                $admin,
            );
            $this->upsertTenantServiceSecretReference($tenant, 'email', null, $reference);
            $changed[] = 'email';
        }

        $storageSecret = (string)($payload['storage_secret_value'] ?? '');
        if ($storageSecret !== '') {
            $reference = $secretService->storeSecret(
                sprintf('tenant/%d/storage/default', $tenantId),
                $storageSecret,
                sprintf('Storage secret for tenant %s', $tenant->slug),
                $admin,
            );
            $this->upsertTenantServiceSecretReference(
                $tenant,
                'storage',
                isset($payload['storage_adapter']) ? (string)$payload['storage_adapter'] : '',
                $reference,
            );
            $changed[] = 'storage';
        }

        return $changed;
    }

    /**
     * Ensure a tenant service config exists and points at a managed secret.
     *
     * @param \App\Model\Entity\Tenant $tenant Tenant
     * @param string $serviceName Service key
     * @param string|null $adapter Adapter
     * @param string $reference Secret reference
     * @return void
     */
    private function upsertTenantServiceSecretReference(
        Tenant $tenant,
        string $serviceName,
        ?string $adapter,
        string $reference,
    ): void {
        $configs = $this->fetchTable('TenantServiceConfigs');
        $config = $configs->find()->where([
            'tenant_id' => $tenant->id,
            'service_name' => $serviceName,
            'config_key' => 'default',
        ])->first();
        $payload = [
            'tenant_id' => $tenant->id,
            'service_name' => $serviceName,
            'config_key' => 'default',
            'secret_reference' => $reference,
            'is_active' => true,
        ];
        if ($adapter !== null && $adapter !== '') {
            $payload['adapter'] = $adapter;
        }
        if ($config === null) {
            $payload['metadata'] = '{}';
        }
        $config = $config === null ? $configs->newEntity($payload) : $configs->patchEntity($config, $payload);
        $configs->saveOrFail($config);
    }

    /**
     * Update tenant status as part of a locked background operation.
     *
     * @param \App\Model\Entity\Tenant $tenant Tenant
     * @param string $status New status
     * @param string $reason Invalidation reason
     * @param array<string, mixed> $context Additional metadata
     * @return \App\Model\Entity\Tenant
     */
    private function updateTenantStatusForLockedOperation(
        Tenant $tenant,
        string $status,
        string $reason,
        array $context = [],
    ): Tenant {
        if ((string)$tenant->status === $status) {
            return $tenant;
        }
        $tenant->status = $status;
        $this->fetchTable('Tenants')->saveOrFail($tenant);
        $this->bumpTenantInvalidation((int)$tenant->id, $reason, $context + [
            'status' => $status,
            'source' => 'tenant-operation-worker',
        ]);

        return $tenant;
    }

    /**
     * Publish tenant invalidation reason/context.
     *
     * @param int $tenantId Tenant id
     * @param string $reason Invalidation reason
     * @param array<string, mixed> $context Invalidation metadata
     * @return int
     */
    private function bumpTenantInvalidation(int $tenantId, string $reason, array $context = []): int
    {
        if (is_callable($this->tenantInvalidationPublisher)) {
            return (int)($this->tenantInvalidationPublisher)($tenantId, $reason, $context);
        }

        return (new TenantInvalidationService())->bumpTenant($tenantId, $reason, $context);
    }

    /**
     * Handle non-terminal and terminal failures.
     *
     * @param \App\Model\Entity\TenantOperationJob $job Job
     * @param string $leaseToken Current lease token
     * @param \Throwable $exception Execution exception
     * @param int $attemptCount Attempt count
     * @param int $maxAttempts Max attempt limit
     * @param array<string, mixed> $progress Progress payload
     * @return void
     */
    private function handleFailure(
        TenantOperationJob $job,
        string $leaseToken,
        Throwable $exception,
        int $attemptCount,
        int $maxAttempts,
        array $progress,
    ): void {
        if ($this->isCancellationRequested((int)$job->id)) {
            $this->markCancelled((int)$job->id, $leaseToken, 'Cancelled while executing operation.');

            return;
        }
        $error = [
            'code' => strtolower((new ReflectionClass($exception))->getShortName()),
            'message' => $exception->getMessage(),
            'retryable' => !($exception instanceof TenantOperationPermanentException),
            'category' => $exception instanceof TenantOperationPermanentException ? 'validation' : 'transient',
            'details' => $this->errorDetails($job, $progress),
            'occurred_at' => DateTime::now()->toIso8601String(),
        ];
        $progress['attempt_count'] = $attemptCount;
        $progress['max_attempts'] = $maxAttempts;
        $progress['last_error_at'] = $error['occurred_at'];
        $progress['last_error_code'] = $error['code'];
        $retryable = (bool)$error['retryable'];
        if ($retryable && $attemptCount < $maxAttempts) {
            $this->persistLeaseUpdate((int)$job->id, $leaseToken, [
                'state' => TenantOperationJob::STATUS_APPROVED,
                'status' => TenantOperationJob::STATUS_APPROVED,
                'status_message' => sprintf(
                    'Attempt %d/%d failed; operation queued for retry.',
                    $attemptCount,
                    $maxAttempts,
                ),
                'progress_json' => $progress,
                'error_json' => $error,
                'error_message' => $exception->getMessage(),
                'lease_owner' => null,
                'lease_token' => null,
                'lease_acquired_at' => null,
                'lease_expires_at' => null,
                'heartbeat_at' => null,
            ]);

            return;
        }
        $this->persistLeaseUpdate((int)$job->id, $leaseToken, [
            'state' => TenantOperationJob::STATUS_FAILED,
            'status' => TenantOperationJob::STATUS_FAILED,
            'status_message' => sprintf(
                'Operation failed after %d attempt(s).',
                $attemptCount,
            ),
            'progress_json' => $progress,
            'error_json' => $error,
            'error_message' => $exception->getMessage(),
            'completed_at' => DateTime::now(),
            'lease_owner' => null,
            'lease_token' => null,
            'lease_acquired_at' => null,
            'lease_expires_at' => null,
            'heartbeat_at' => null,
        ]);
    }

    /**
     * Persist successful completion.
     *
     * @param int $jobId Job id
     * @param string $leaseToken Current lease token
     * @param array<string, mixed> $result Operation result
     * @param array<string, mixed> $progress Progress payload
     * @return void
     */
    private function markCompleted(int $jobId, string $leaseToken, array $result, array $progress): void
    {
        $progress['phase'] = 'completed';
        $progress['message'] = 'Operation completed';
        $progress['updated_at'] = DateTime::now()->toIso8601String();
        $this->persistLeaseUpdate($jobId, $leaseToken, [
            'state' => TenantOperationJob::STATUS_COMPLETED,
            'status' => TenantOperationJob::STATUS_COMPLETED,
            'progress_percent' => 100,
            'status_message' => 'Operation completed successfully.',
            'progress_json' => $progress,
            'result_json' => $result,
            'result' => $result,
            'completed_at' => DateTime::now(),
            'lease_owner' => null,
            'lease_token' => null,
            'lease_acquired_at' => null,
            'lease_expires_at' => null,
            'heartbeat_at' => null,
        ]);
    }

    /**
     * Persist cancellation.
     *
     * @param int $jobId Job id
     * @param string $leaseToken Current lease token
     * @param string $message Status message
     * @return void
     */
    private function markCancelled(int $jobId, string $leaseToken, string $message): void
    {
        $this->persistLeaseUpdate($jobId, $leaseToken, [
            'state' => TenantOperationJob::STATUS_CANCELLED,
            'status' => TenantOperationJob::STATUS_CANCELLED,
            'status_message' => $message,
            'completed_at' => DateTime::now(),
            'lease_owner' => null,
            'lease_token' => null,
            'lease_acquired_at' => null,
            'lease_expires_at' => null,
            'heartbeat_at' => null,
        ]);
    }

    /**
     * Persist update guarded by lease token ownership.
     *
     * @param int $jobId Job id
     * @param string $leaseToken Lease token
     * @param array<string, mixed> $fields Field updates
     * @return void
     */
    private function persistLeaseUpdate(int $jobId, string $leaseToken, array $fields): void
    {
        $fields = $this->encodeJsonFieldUpdates($fields);
        $updated = $this->fetchTable('TenantOperationJobs')->updateAll($fields, [
            'id' => $jobId,
            'lease_token' => $leaseToken,
            'state IN' => [
                TenantOperationJob::STATUS_RUNNING,
                TenantOperationJob::STATUS_APPROVED,
                TenantOperationJob::STATUS_FAILED,
                TenantOperationJob::STATUS_COMPLETED,
                TenantOperationJob::STATUS_CANCELLED,
            ],
        ]);
        if ($updated < 1) {
            throw new TenantOperationPermanentException('Failed to persist operation update due to lease conflict.');
        }
    }

    /**
     * updateAll() bypasses ORM JSON marshalling, so encode JSON payload fields explicitly.
     *
     * @param array<string, mixed> $fields Field updates
     * @return array<string, mixed>
     */
    private function encodeJsonFieldUpdates(array $fields): array
    {
        foreach (['input', 'result', 'progress_json', 'result_json', 'error_json', 'approval_policy_json'] as $field) {
            if (!array_key_exists($field, $fields) || !is_array($fields[$field])) {
                continue;
            }
            $fields[$field] = json_encode($fields[$field], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        }

        return $fields;
    }

    /**
     * @param int $jobId Job id
     * @return bool
     */
    private function isCancellationRequested(int $jobId): bool
    {
        $job = $this->fetchTable('TenantOperationJobs')->find()
            ->select(['id', 'cancelled_at', 'state'])
            ->where(['id' => $jobId])
            ->first();
        if (!$job instanceof TenantOperationJob) {
            return true;
        }

        return $job->cancelled_at !== null || (string)$job->state === TenantOperationJob::STATUS_CANCELLED;
    }

    /**
     * @param mixed $payload Stored progress payload
     * @return array<string, mixed>
     */
    private function normalizeProgress(mixed $payload): array
    {
        return is_array($payload) ? $payload : [];
    }

    /**
     * @param \App\Model\Entity\TenantOperationJob $job Job
     * @param array<string, mixed> $progress Progress payload
     * @return array<string, mixed>
     */
    private function errorDetails(TenantOperationJob $job, array $progress): array
    {
        $details = ['operation' => (string)$job->operation];
        if ($job->tenant_id !== null) {
            $details['tenant_id'] = (int)$job->tenant_id;
        }
        if (!empty($progress['phase'])) {
            $details['phase'] = (string)$progress['phase'];
        }
        $input = is_array($job->input ?? null) ? $job->input : [];
        if ((string)$job->operation === 'tenant_migrate') {
            if (isset($input['tenant_slug']) && trim((string)$input['tenant_slug']) !== '') {
                $details['tenant_slug'] = trim((string)$input['tenant_slug']);
            }
            if (isset($input['schema_before']) && trim((string)$input['schema_before']) !== '') {
                $details['schema_before'] = trim((string)$input['schema_before']);
            }
            if (isset($input['target_schema_version']) && trim((string)$input['target_schema_version']) !== '') {
                $details['target_schema_version'] = trim((string)$input['target_schema_version']);
            }
            if (isset($input['parent_operation_id'])) {
                $details['parent_operation_id'] = (int)$input['parent_operation_id'];
            }
            if (isset($input['parent_tenant_operation_job_id'])) {
                $details['parent_tenant_operation_job_id'] = (int)$input['parent_tenant_operation_job_id'];
            }
            if (isset($input['migration_scope']) && trim((string)$input['migration_scope']) !== '') {
                $details['migration_scope'] = trim((string)$input['migration_scope']);
            }
            if (isset($input['migration_scopes']) && is_array($input['migration_scopes'])) {
                $details['migration_scopes'] = $input['migration_scopes'];
            }
            $tenant = $this->fetchTable('Tenants')->find()
                ->select(['schema_version'])
                ->where(['id' => (int)$job->tenant_id])
                ->first();
            if ($tenant instanceof Tenant && (string)($tenant->schema_version ?? '') !== '') {
                $details['schema_after'] = (string)$tenant->schema_version;
            }
        }

        return $details;
    }

    /**
     * @return string
     */
    private function workerId(): string
    {
        if ($this->workerId !== null && $this->workerId !== '') {
            return $this->workerId;
        }
        $host = gethostname();
        if (!is_string($host) || $host === '') {
            $host = 'worker';
        }

        return sprintf('%s:%d', $host, getmypid());
    }

    /**
     * Clear tenant context after each execution.
     *
     * @return void
     */
    private function clearTenantRuntimeContext(): void
    {
        TenantContextAccessor::set(null);
        TenantContext::clearCurrent();
        (new TenantConnectionFactory())->resetOrmState();
    }

    /**
     * Count jobs currently eligible for worker pickup.
     */
    private function runnableQueueDepth(): int
    {
        $query = $this->fetchTable('TenantOperationJobs')->find()
            ->where([
                'state IN' => [
                    TenantOperationJob::STATUS_QUEUED,
                    TenantOperationJob::STATUS_APPROVED,
                    TenantOperationJob::STATUS_RUNNING,
                ],
            ]);
        if (!is_callable($this->operationExecutor)) {
            $query->where(['operation IN' => self::SUPPORTED_OPERATIONS]);
        }
        $this->applyApprovalReadinessConstraint($query);

        return (int)$query->count();
    }

    /**
     * Restrict approved jobs to those that have satisfied approval requirements.
     *
     * @param \Cake\ORM\Query\SelectQuery $query Query
     * @return void
     */
    private function applyApprovalReadinessConstraint($query): void
    {
        $query->andWhere(function ($exp, $q) {
            $approvalThresholdMet = $exp->or([
                ['approvals_required IS' => null],
                ['approvals_required <=' => 0],
                $exp->gte('approvals_received', $q->identifier('approvals_required')),
            ]);

            return $exp->or([
                ['state NOT IN' => [
                    TenantOperationJob::STATUS_QUEUED,
                    TenantOperationJob::STATUS_APPROVED,
                ]],
                [
                    'AND' => [
                        ['approval_rejected_at IS' => null],
                        $approvalThresholdMet,
                    ],
                ],
            ]);
        });
    }

    /**
     * Fetch current state for a specific operation job.
     */
    private function jobState(int $jobId): string
    {
        $job = $this->fetchTable('TenantOperationJobs')->find()
            ->select(['id', 'state'])
            ->where(['id' => $jobId])
            ->first();

        return $job instanceof TenantOperationJob
            ? (string)$job->state
            : TenantOperationJob::STATUS_FAILED;
    }
}
