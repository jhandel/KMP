<?php
declare(strict_types=1);

namespace App\Test\TestCase\Services\Platform;

use App\Model\Entity\Tenant;
use App\Model\Entity\TenantOperationJob;
use App\Services\Platform\TenantDatabaseSecretRotationService;
use App\Services\Platform\TenantOperationWorkerService;
use App\Services\Telemetry\TenantMetrics;
use App\Services\Tenant\TenantContext;
use App\Services\Tenant\TenantProvisioningService;
use Cake\Datasource\ConnectionManager;
use Cake\I18n\DateTime;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;
use Migrations\Migrations;
use ReflectionMethod;
use RuntimeException;

class TenantOperationWorkerServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (new Migrations())->migrate([
            'connection' => 'test',
            'source' => 'PlatformMigrations',
        ]);
        $this->truncatePlatformTables();
        TenantMetrics::reset();
    }

    protected function tearDown(): void
    {
        TenantContext::clearCurrent();
        TenantMetrics::reset();
        parent::tearDown();
    }

    public function testRunNextBatchCompletesTenantStatusOperation(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createPlatformAdmin();
        $job = $this->createJob([
            'tenant_id' => (int)$tenant->id,
            'platform_admin_id' => (int)$admin->id,
            'operation' => 'tenant_status',
            'state' => TenantOperationJob::STATUS_APPROVED,
            'status' => TenantOperationJob::STATUS_APPROVED,
            'input' => ['status' => Tenant::STATUS_MAINTENANCE],
        ]);

        $service = new TenantOperationWorkerService(workerId: 'test-worker');
        $this->assertSame(1, $service->runNextBatch());

        $updatedJob = $this->getTableLocator()->get('TenantOperationJobs')->get((int)$job->id);
        $updatedTenant = $this->getTableLocator()->get('Tenants')->get((int)$tenant->id);
        $this->assertSame(TenantOperationJob::STATUS_COMPLETED, $updatedJob->state);
        $this->assertSame(100, (int)$updatedJob->progress_percent);
        $this->assertSame(Tenant::STATUS_MAINTENANCE, (string)$updatedTenant->status);
        $this->assertIsArray($updatedJob->result_json);
        $this->assertSame(Tenant::STATUS_MAINTENANCE, (string)$updatedJob->result_json['status']);
    }

    public function testRunNextBatchTransitionsQueuedToRunningToCompleted(): void
    {
        $admin = $this->createPlatformAdmin();
        $job = $this->createJob([
            'platform_admin_id' => (int)$admin->id,
            'operation' => 'custom_queued_to_completed',
            'state' => TenantOperationJob::STATUS_QUEUED,
            'status' => TenantOperationJob::STATUS_QUEUED,
            'input' => ['max_attempts' => 1],
        ]);

        $observedRunningState = false;
        $service = new TenantOperationWorkerService(
            operationExecutor: function (TenantOperationJob $claimedJob) use (&$observedRunningState): array {
                $observedRunningState = $claimedJob->state === TenantOperationJob::STATUS_RUNNING
                    && (string)$claimedJob->lease_token !== '';

                return ['executed' => true];
            },
            workerId: 'queued-worker',
        );

        $this->assertSame(1, $service->runNextBatch());
        $updated = $this->getTableLocator()->get('TenantOperationJobs')->get((int)$job->id);

        $this->assertTrue($observedRunningState);
        $this->assertSame(TenantOperationJob::STATUS_COMPLETED, (string)$updated->state);
        $this->assertNotNull($updated->started_at);
        $this->assertNotNull($updated->completed_at);
        $this->assertNull($updated->lease_token);
        $this->assertSame(100, (int)$updated->progress_percent);
        $snapshot = TenantMetrics::snapshot();
        $this->assertSame(1.0, (float)($snapshot['gauges']['kmp_tenant_operation_queue_depth']['queue=runnable'] ?? 0.0));
        $this->assertSame(
            1,
            (int)($snapshot['counters']['kmp_tenant_operation_outcome_total']['operation=custom_queued_to_completed,outcome=success'] ?? 0),
        );
        $this->assertSame(
            1,
            (int)($snapshot['histograms']['kmp_tenant_operation_duration_ms']['operation=custom_queued_to_completed,outcome=success']['count'] ?? 0),
        );
        $this->assertSame(
            1,
            (int)($snapshot['counters']['kmp_tenant_health_signal_total']['signal=operation_completed'] ?? 0),
        );
    }

    public function testRunNextBatchSupportsTenantDrainStatusOperation(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createPlatformAdmin();
        $job = $this->createJob([
            'tenant_id' => (int)$tenant->id,
            'platform_admin_id' => (int)$admin->id,
            'operation' => 'tenant_status',
            'state' => TenantOperationJob::STATUS_APPROVED,
            'status' => TenantOperationJob::STATUS_APPROVED,
            'input' => ['status' => Tenant::STATUS_DRAINING],
        ]);

        $service = new TenantOperationWorkerService(workerId: 'test-worker');
        $this->assertSame(1, $service->runNextBatch());

        $updatedJob = $this->getTableLocator()->get('TenantOperationJobs')->get((int)$job->id);
        $updatedTenant = $this->getTableLocator()->get('Tenants')->get((int)$tenant->id);
        $this->assertSame(TenantOperationJob::STATUS_COMPLETED, $updatedJob->state);
        $this->assertSame(Tenant::STATUS_DRAINING, (string)$updatedTenant->status);
        $this->assertSame(Tenant::STATUS_DRAINING, (string)$updatedJob->result_json['status']);
    }

    public function testRunNextBatchBlocksExecutionUntilApprovalThresholdIsMet(): void
    {
        $admin = $this->createPlatformAdmin();
        $job = $this->createJob([
            'tenant_id' => null,
            'platform_admin_id' => (int)$admin->id,
            'operation' => 'custom_sensitive',
            'state' => TenantOperationJob::STATUS_APPROVED,
            'status' => TenantOperationJob::STATUS_APPROVED,
            'approvals_required' => 2,
            'approvals_received' => 1,
            'approval_policy_json' => [
                'mode' => 'two_person',
                'required_approvals' => 2,
                'require_distinct_approvers' => true,
                'require_requester_separation' => true,
            ],
        ]);

        $executed = false;
        $service = new TenantOperationWorkerService(
            operationExecutor: function () use (&$executed): array {
                $executed = true;

                return ['executed' => true];
            },
            workerId: 'approval-gating-worker',
        );

        $this->assertSame(0, $service->runNextBatch());
        $this->assertFalse($executed);

        $jobs = $this->getTableLocator()->get('TenantOperationJobs');
        $blocked = $jobs->get((int)$job->id);
        $this->assertSame(TenantOperationJob::STATUS_APPROVED, (string)$blocked->state);

        $blocked->approvals_received = 2;
        $jobs->saveOrFail($blocked);

        $this->assertSame(1, $service->runNextBatch());
        $this->assertTrue($executed);

        $completed = $jobs->get((int)$job->id);
        $this->assertSame(TenantOperationJob::STATUS_COMPLETED, (string)$completed->state);
    }

    public function testRunNextBatchRetriesThenFailsOperation(): void
    {
        $admin = $this->createPlatformAdmin();
        $job = $this->createJob([
            'tenant_id' => null,
            'platform_admin_id' => (int)$admin->id,
            'operation' => 'custom_retry',
            'state' => TenantOperationJob::STATUS_APPROVED,
            'status' => TenantOperationJob::STATUS_APPROVED,
            'input' => ['max_attempts' => 2],
        ]);

        $attempts = 0;
        $service = new TenantOperationWorkerService(
            operationExecutor: function () use (&$attempts): array {
                $attempts++;
                throw new RuntimeException('Transient failure');
            },
            workerId: 'retry-worker',
        );

        $this->assertSame(1, $service->runNextBatch());
        $first = $this->getTableLocator()->get('TenantOperationJobs')->get((int)$job->id);
        $this->assertSame(TenantOperationJob::STATUS_APPROVED, $first->state);
        $this->assertSame(1, (int)($first->progress_json['attempt_count'] ?? 0));
        $this->assertSame(2, (int)($first->progress_json['max_attempts'] ?? 0));

        $this->assertSame(1, $service->runNextBatch());
        $failed = $this->getTableLocator()->get('TenantOperationJobs')->get((int)$job->id);
        $this->assertSame(TenantOperationJob::STATUS_FAILED, $failed->state);
        $this->assertSame(2, (int)($failed->progress_json['attempt_count'] ?? 0));
        $this->assertSame(2, $attempts);
    }

    public function testRunNextBatchRetriesThenCompletes(): void
    {
        $admin = $this->createPlatformAdmin();
        $job = $this->createJob([
            'tenant_id' => null,
            'platform_admin_id' => (int)$admin->id,
            'operation' => 'custom_retry_success',
            'state' => TenantOperationJob::STATUS_APPROVED,
            'status' => TenantOperationJob::STATUS_APPROVED,
            'input' => ['max_attempts' => 3],
        ]);

        $attempts = 0;
        $service = new TenantOperationWorkerService(
            operationExecutor: function () use (&$attempts): array {
                $attempts++;
                if ($attempts === 1) {
                    throw new RuntimeException('Retry me');
                }

                return ['attempt' => $attempts];
            },
            workerId: 'retry-success-worker',
        );

        $this->assertSame(1, $service->runNextBatch());
        $retryable = $this->getTableLocator()->get('TenantOperationJobs')->get((int)$job->id);
        $this->assertSame(TenantOperationJob::STATUS_APPROVED, (string)$retryable->state);
        $this->assertSame(1, (int)($retryable->progress_json['attempt_count'] ?? 0));

        $this->assertSame(1, $service->runNextBatch());
        $completed = $this->getTableLocator()->get('TenantOperationJobs')->get((int)$job->id);
        $this->assertSame(TenantOperationJob::STATUS_COMPLETED, (string)$completed->state);
        $this->assertSame(2, (int)($completed->progress_json['attempt_count'] ?? 0));
        $this->assertSame(2, $attempts);
    }

    public function testRunNextBatchHonorsCancellationWithoutExecuting(): void
    {
        $admin = $this->createPlatformAdmin();
        $job = $this->createJob([
            'tenant_id' => null,
            'platform_admin_id' => (int)$admin->id,
            'operation' => 'custom_cancelled',
            'state' => TenantOperationJob::STATUS_APPROVED,
            'status' => TenantOperationJob::STATUS_APPROVED,
            'cancelled_at' => new DateTime('now'),
        ]);

        $called = false;
        $service = new TenantOperationWorkerService(
            operationExecutor: function () use (&$called): array {
                $called = true;

                return [];
            },
            workerId: 'cancel-worker',
        );

        $this->assertSame(1, $service->runNextBatch());
        $cancelled = $this->getTableLocator()->get('TenantOperationJobs')->get((int)$job->id);
        $this->assertFalse($called);
        $this->assertSame(TenantOperationJob::STATUS_CANCELLED, $cancelled->state);
    }

    public function testRunNextBatchCapturesTenantMigrationMetadata(): void
    {
        $tenant = $this->createTenant();
        $tenant->schema_version = '20240101000000';
        $this->getTableLocator()->get('Tenants')->saveOrFail($tenant);
        $admin = $this->createPlatformAdmin();
        $job = $this->createJob([
            'tenant_id' => (int)$tenant->id,
            'platform_admin_id' => (int)$admin->id,
            'operation' => 'tenant_migrate',
            'state' => TenantOperationJob::STATUS_APPROVED,
            'status' => TenantOperationJob::STATUS_APPROVED,
            'input' => [
                'tenant_slug' => (string)$tenant->slug,
                'schema_before' => '20240101000000',
                'parent_tenant_operation_job_id' => 77,
                'migration_scope' => 'tenant_schema',
                'migration_scopes' => ['core', 'plugins'],
            ],
        ]);

        $fakeProvisioningService = new class extends TenantProvisioningService {
            public function migrateTenant(Tenant $tenant, ?string $plugin = null): string
            {
                return '20260601000000';
            }
        };
        $service = new TenantOperationWorkerService(
            tenantProvisioningService: $fakeProvisioningService,
            workerId: 'metadata-worker',
        );

        $this->assertSame(1, $service->runNextBatch());
        $updatedJob = $this->getTableLocator()->get('TenantOperationJobs')->get((int)$job->id);
        $result = (array)$updatedJob->result_json;

        $this->assertSame(TenantOperationJob::STATUS_COMPLETED, $updatedJob->state);
        $this->assertSame('20240101000000', (string)($result['schema_before'] ?? ''));
        $this->assertSame('20260601000000', (string)($result['schema_after'] ?? ''));
        $this->assertSame(77, (int)($result['parent_tenant_operation_job_id'] ?? 0));
        $this->assertSame('tenant_schema', (string)($result['migration_scope'] ?? ''));
        $this->assertSame(['core', 'plugins'], (array)($result['migration_scopes'] ?? []));
        $this->assertArrayHasKey('duration_ms', $result);
        $this->assertIsInt($result['duration_ms']);
    }

    public function testRunNextBatchCancelsAfterClaimWhenCancellationRequestedMidExecution(): void
    {
        $admin = $this->createPlatformAdmin();
        $job = $this->createJob([
            'tenant_id' => null,
            'platform_admin_id' => (int)$admin->id,
            'operation' => 'custom_cancel_midflight',
            'state' => TenantOperationJob::STATUS_APPROVED,
            'status' => TenantOperationJob::STATUS_APPROVED,
        ]);

        $jobs = $this->getTableLocator()->get('TenantOperationJobs');
        $service = new TenantOperationWorkerService(
            operationExecutor: function (TenantOperationJob $claimedJob, callable $progress) use ($jobs): array {
                $row = $jobs->get((int)$claimedJob->id);
                $row->cancelled_at = DateTime::now();
                $jobs->saveOrFail($row);
                $progress('cancel-checkpoint', 'Running cancel checkpoint', 55, 'cancel:checkpoint');

                return ['should_not' => 'complete'];
            },
            workerId: 'cancel-midflight-worker',
        );

        $this->assertSame(1, $service->runNextBatch());
        $cancelled = $jobs->get((int)$job->id);
        $this->assertSame(TenantOperationJob::STATUS_CANCELLED, (string)$cancelled->state);
        $this->assertSame('Cancelled while executing operation.', (string)$cancelled->status_message);
        $this->assertNotNull($cancelled->completed_at);
    }

    public function testRunNextBatchSkipsRunningJobWithActiveLease(): void
    {
        $admin = $this->createPlatformAdmin();
        $job = $this->createJob([
            'tenant_id' => null,
            'platform_admin_id' => (int)$admin->id,
            'operation' => 'custom_locked_running',
            'state' => TenantOperationJob::STATUS_RUNNING,
            'status' => TenantOperationJob::STATUS_RUNNING,
            'lease_owner' => 'existing-worker',
            'lease_token' => 'existing-lease-token',
            'lease_acquired_at' => DateTime::now()->subMinutes(1),
            'lease_expires_at' => DateTime::now()->addMinutes(10),
            'heartbeat_at' => DateTime::now()->subSeconds(30),
        ]);

        $called = false;
        $service = new TenantOperationWorkerService(
            operationExecutor: function () use (&$called): array {
                $called = true;

                return [];
            },
            workerId: 'conflict-worker',
        );

        $this->assertSame(0, $service->runNextBatch());
        $this->assertFalse($called);
        $unchanged = $this->getTableLocator()->get('TenantOperationJobs')->get((int)$job->id);
        $this->assertSame('existing-lease-token', (string)$unchanged->lease_token);
        $this->assertSame(TenantOperationJob::STATUS_RUNNING, (string)$unchanged->state);
    }

    public function testRunNextBatchReclaimsStaleRunningLeaseAndCompletes(): void
    {
        $admin = $this->createPlatformAdmin();
        $job = $this->createJob([
            'tenant_id' => null,
            'platform_admin_id' => (int)$admin->id,
            'operation' => 'custom_reclaim_stale',
            'state' => TenantOperationJob::STATUS_RUNNING,
            'status' => TenantOperationJob::STATUS_RUNNING,
            'lease_owner' => 'stale-worker',
            'lease_token' => 'stale-lease-token',
            'lease_acquired_at' => DateTime::now()->subMinutes(20),
            'lease_expires_at' => DateTime::now()->subMinutes(1),
            'heartbeat_at' => DateTime::now()->subMinutes(10),
        ]);

        $claimedToken = '';
        $service = new TenantOperationWorkerService(
            operationExecutor: function (TenantOperationJob $claimedJob) use (&$claimedToken): array {
                $claimedToken = (string)$claimedJob->lease_token;

                return ['reclaimed' => true];
            },
            workerId: 'stale-recovery-worker',
        );

        $this->assertSame(1, $service->runNextBatch());
        $completed = $this->getTableLocator()->get('TenantOperationJobs')->get((int)$job->id);
        $this->assertNotSame('stale-lease-token', $claimedToken);
        $this->assertSame(TenantOperationJob::STATUS_COMPLETED, (string)$completed->state);
        $this->assertNull($completed->lease_token);
    }

    public function testRunNextBatchCompletesTenantDatabaseSecretRotationWorkflow(): void
    {
        $tenant = $this->createTenant();
        $this->createPrimaryDatabaseConfigWithSecret($tenant, 'managed:tenant/old');
        $admin = $this->createPlatformAdmin();
        $job = $this->createJob([
            'tenant_id' => (int)$tenant->id,
            'platform_admin_id' => (int)$admin->id,
            'operation' => 'tenant_rotate_db_secret',
            'state' => TenantOperationJob::STATUS_APPROVED,
            'status' => TenantOperationJob::STATUS_APPROVED,
            'input' => [
                'new_secret_reference' => 'managed:tenant/new',
                'max_attempts' => 1,
            ],
        ]);

        $rotationService = new TenantDatabaseSecretRotationService(
            connectionVerifier: static fn(Tenant $unused): bool => true,
        );
        $service = new TenantOperationWorkerService(
            workerId: 'rotate-secret-worker',
            tenantDatabaseSecretRotationService: $rotationService,
        );
        $this->assertSame(1, $service->runNextBatch());

        $updatedJob = $this->getTableLocator()->get('TenantOperationJobs')->get((int)$job->id);
        $updatedConfig = $this->getTableLocator()->get('TenantDatabaseConfigs')->find()
            ->where(['tenant_id' => (int)$tenant->id, 'connection_role' => 'primary'])
            ->firstOrFail();
        $invalidation = $this->getTableLocator()->get('TenantRuntimeInvalidationVersions')->find()
            ->where(['tenant_id' => (int)$tenant->id])
            ->firstOrFail();

        $this->assertSame(TenantOperationJob::STATUS_COMPLETED, (string)$updatedJob->state);
        $this->assertSame('managed:tenant/new', (string)$updatedConfig->secret_reference);
        $this->assertSame('tenant_secret_rotated', (string)$invalidation->last_change_type);
        $this->assertSame(
            0,
            $this->getTableLocator()->get('TenantOperationLocks')->find()->where(['tenant_id' => (int)$tenant->id])->count(),
        );
    }

    public function testRunNextBatchRollsBackTenantDatabaseSecretRotationOnVerificationFailure(): void
    {
        $tenant = $this->createTenant();
        $this->createPrimaryDatabaseConfigWithSecret($tenant, 'managed:tenant/original');
        $admin = $this->createPlatformAdmin();
        $job = $this->createJob([
            'tenant_id' => (int)$tenant->id,
            'platform_admin_id' => (int)$admin->id,
            'operation' => 'tenant_rotate_db_secret',
            'state' => TenantOperationJob::STATUS_APPROVED,
            'status' => TenantOperationJob::STATUS_APPROVED,
            'input' => [
                'new_secret_reference' => 'managed:tenant/new-bad',
                'max_attempts' => 1,
            ],
        ]);

        $rotationService = new TenantDatabaseSecretRotationService(
            connectionVerifier: static function (Tenant $unused): void {
                throw new RuntimeException('Unable to verify tenant connection.');
            },
        );
        $service = new TenantOperationWorkerService(
            workerId: 'rotate-secret-fail-worker',
            tenantDatabaseSecretRotationService: $rotationService,
        );
        $this->assertSame(1, $service->runNextBatch());

        $failedJob = $this->getTableLocator()->get('TenantOperationJobs')->get((int)$job->id);
        $config = $this->getTableLocator()->get('TenantDatabaseConfigs')->find()
            ->where(['tenant_id' => (int)$tenant->id, 'connection_role' => 'primary'])
            ->firstOrFail();
        $invalidation = $this->getTableLocator()->get('TenantRuntimeInvalidationVersions')->find()
            ->where(['tenant_id' => (int)$tenant->id])
            ->firstOrFail();

        $this->assertSame(TenantOperationJob::STATUS_FAILED, (string)$failedJob->state);
        $this->assertSame('managed:tenant/original', (string)$config->secret_reference);
        $this->assertSame('tenant_secret_rotation_rollback', (string)$invalidation->last_change_type);
        $this->assertStringContainsString('Unable to verify tenant connection.', (string)$failedJob->error_message);
        $this->assertSame(
            0,
            $this->getTableLocator()->get('TenantOperationLocks')->find()->where(['tenant_id' => (int)$tenant->id])->count(),
        );
    }

    public function testRunNextBatchFailsRestoreCutoverWhenTargetDatabaseAlreadyExists(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createPlatformAdmin();
        $this->createPrimaryDatabaseConfig((int)$tenant->id, 'restore_worker_current');
        $otherTenant = $this->createTenant();
        $this->createPrimaryDatabaseConfig((int)$otherTenant->id, 'restore_worker_taken');
        $job = $this->createJob([
            'tenant_id' => (int)$tenant->id,
            'platform_admin_id' => (int)$admin->id,
            'operation' => 'tenant_restore_cutover',
            'state' => TenantOperationJob::STATUS_APPROVED,
            'status' => TenantOperationJob::STATUS_APPROVED,
            'input' => [
                'new_database_name' => 'restore_worker_taken',
                'restore_key' => 'restore-key',
                'backup_file_base64' => base64_encode('payload'),
            ],
        ]);

        $service = new TenantOperationWorkerService(workerId: 'restore-validation-worker');
        $this->assertSame(1, $service->runNextBatch());

        $failed = $this->getTableLocator()->get('TenantOperationJobs')->get((int)$job->id);
        $this->assertSame(TenantOperationJob::STATUS_FAILED, (string)$failed->state);
        $this->assertStringContainsString('already exists in tenant config', (string)$failed->error_message);
    }

    public function testRunNextBatchFailsRestoreCutoverWhenTargetDatabaseWasRecentlyUsed(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createPlatformAdmin();
        $this->createPrimaryDatabaseConfig((int)$tenant->id, 'restore_worker_current');
        $otherTenant = $this->createTenant();
        $this->createPrimaryDatabaseConfig((int)$otherTenant->id, 'restore_worker_other_current');
        $this->createJob([
            'tenant_id' => (int)$otherTenant->id,
            'platform_admin_id' => (int)$admin->id,
            'operation' => 'tenant_restore_cutover',
            'state' => TenantOperationJob::STATUS_COMPLETED,
            'status' => TenantOperationJob::STATUS_COMPLETED,
            'input' => [
                'new_database_name' => 'restore_worker_recent_target',
                'restore_key' => 'previous-restore-key',
                'backup_file_base64' => base64_encode('previous payload'),
            ],
        ]);
        $job = $this->createJob([
            'tenant_id' => (int)$tenant->id,
            'platform_admin_id' => (int)$admin->id,
            'operation' => 'tenant_restore_cutover',
            'state' => TenantOperationJob::STATUS_APPROVED,
            'status' => TenantOperationJob::STATUS_APPROVED,
            'input' => [
                'new_database_name' => 'restore_worker_recent_target',
                'restore_key' => 'restore-key',
                'backup_file_base64' => base64_encode('payload'),
            ],
        ]);

        $service = new TenantOperationWorkerService(workerId: 'restore-recent-target-worker');
        $this->assertSame(1, $service->runNextBatch());

        $failed = $this->getTableLocator()->get('TenantOperationJobs')->get((int)$job->id);
        $this->assertSame(TenantOperationJob::STATUS_FAILED, (string)$failed->state);
        $this->assertStringContainsString(
            'already used by',
            (string)$failed->error_message,
        );
    }

    public function testRunNextBatchEnforcesTenantDrainModeBeforeRestoreCutover(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createPlatformAdmin();
        $databaseSuffix = str_replace('-', '_', (string)$tenant->slug);
        $currentDatabase = 'restore_worker_drain_current_' . $databaseSuffix;
        $targetDatabase = 'restore_worker_drain_target_' . $databaseSuffix;
        $primaryConfig = $this->createPrimaryDatabaseConfig((int)$tenant->id, $currentDatabase);
        $job = $this->createJob([
            'tenant_id' => (int)$tenant->id,
            'platform_admin_id' => (int)$admin->id,
            'operation' => 'tenant_restore_cutover',
            'state' => TenantOperationJob::STATUS_APPROVED,
            'status' => TenantOperationJob::STATUS_APPROVED,
            'input' => [
                'new_database_name' => $targetDatabase,
                'restore_key' => 'restore-key',
                'backup_file_base64' => base64_encode('payload'),
            ],
        ]);

        $tenantId = (int)$tenant->id;
        $invalidationReasons = [];
        $provisioningService = new class extends TenantProvisioningService {
            public function createPhysicalDatabase(array $data): ?string
            {
                return null;
            }
        };
        $service = new TenantOperationWorkerService(
            tenantProvisioningService: $provisioningService,
            workerId: 'restore-drain-enforcement-worker',
            restoreMigrationExecutor: static fn() => null,
            restoreImportExecutor: function () use ($tenantId): array {
                $tenants = TableRegistry::getTableLocator()->get('Tenants');
                $tenant = $tenants->get($tenantId);
                $tenant->status = Tenant::STATUS_ACTIVE;
                $tenants->saveOrFail($tenant);

                return [
                    'row_count' => 1,
                    'table_count' => 1,
                    'constraints_not_valid' => 0,
                ];
            },
            tenantInvalidationPublisher: function (int $unusedTenantId, string $reason, array $unusedContext) use (&$invalidationReasons): int {
                $invalidationReasons[] = $reason;

                return count($invalidationReasons);
            },
            restoreIntegrityChecker: static fn(string $unusedDatabaseName, array $unusedImportResult) => null,
        );
        $this->assertSame(1, $service->runNextBatch());

        $failed = $this->getTableLocator()->get('TenantOperationJobs')->get((int)$job->id);
        $reloadedPrimary = $this->getTableLocator()->get('TenantDatabaseConfigs')->get((int)$primaryConfig->id);
        $this->assertSame(TenantOperationJob::STATUS_FAILED, (string)$failed->state);
        $this->assertStringContainsString('must stay in drain mode', (string)$failed->error_message);
        $this->assertSame($currentDatabase, (string)$reloadedPrimary->database_name);
        $this->assertSame(['tenant_restore_drain_started'], $invalidationReasons);
    }

    public function testRunNextBatchRestoreCutoverReleasesDrainStatusOnFailure(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createPlatformAdmin();
        $databaseSuffix = str_replace('-', '_', (string)$tenant->slug);
        $currentDatabase = 'restore_worker_fail_current_' . $databaseSuffix;
        $targetDatabase = 'restore_worker_fail_target_' . $databaseSuffix;
        $primaryConfig = $this->createPrimaryDatabaseConfig((int)$tenant->id, $currentDatabase);
        $job = $this->createJob([
            'tenant_id' => (int)$tenant->id,
            'platform_admin_id' => (int)$admin->id,
            'operation' => 'tenant_restore_cutover',
            'state' => TenantOperationJob::STATUS_APPROVED,
            'status' => TenantOperationJob::STATUS_APPROVED,
            'input' => [
                'new_database_name' => $targetDatabase,
                'restore_key' => 'restore-key',
                'backup_file_base64' => base64_encode('payload'),
            ],
        ]);

        $failingProvisioningService = new class extends TenantProvisioningService {
            public function createPhysicalDatabase(array $data): ?string
            {
                throw new RuntimeException('forced create db failure');
            }
        };
        $service = new TenantOperationWorkerService(
            tenantProvisioningService: $failingProvisioningService,
            workerId: 'restore-failure-worker',
        );
        $this->assertSame(1, $service->runNextBatch());

        $failed = $this->getTableLocator()->get('TenantOperationJobs')->get((int)$job->id);
        $reloadedTenant = $this->getTableLocator()->get('Tenants')->get((int)$tenant->id);
        $reloadedPrimary = $this->getTableLocator()->get('TenantDatabaseConfigs')->get((int)$primaryConfig->id);
        $this->assertSame(TenantOperationJob::STATUS_FAILED, (string)$failed->state);
        $this->assertStringContainsString('forced create db failure', (string)$failed->error_message);
        $this->assertSame(Tenant::STATUS_ACTIVE, (string)$reloadedTenant->status);
        $this->assertSame($currentDatabase, (string)$reloadedPrimary->database_name);
    }

    public function testRunNextBatchRollsBackRestoreCutoverWhenCutoverStepFails(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createPlatformAdmin();
        $databaseSuffix = str_replace('-', '_', (string)$tenant->slug);
        $currentDatabase = 'restore_worker_cutover_current_' . $databaseSuffix;
        $targetDatabase = 'restore_worker_cutover_target_' . $databaseSuffix;
        $primaryConfig = $this->createPrimaryDatabaseConfig((int)$tenant->id, $currentDatabase);
        $job = $this->createJob([
            'tenant_id' => (int)$tenant->id,
            'platform_admin_id' => (int)$admin->id,
            'operation' => 'tenant_restore_cutover',
            'state' => TenantOperationJob::STATUS_APPROVED,
            'status' => TenantOperationJob::STATUS_APPROVED,
            'input' => [
                'new_database_name' => $targetDatabase,
                'restore_key' => 'restore-key',
                'backup_file_base64' => base64_encode('payload'),
            ],
        ]);

        $invalidationEvents = [];
        $provisioningService = new class extends TenantProvisioningService {
            public function createPhysicalDatabase(array $data): ?string
            {
                return null;
            }
        };
        $service = new TenantOperationWorkerService(
            tenantProvisioningService: $provisioningService,
            workerId: 'restore-cutover-rollback-worker',
            restoreMigrationExecutor: static fn() => null,
            restoreImportExecutor: static fn(): array => [
                'row_count' => 3,
                'table_count' => 2,
                'constraints_not_valid' => 0,
            ],
            tenantInvalidationPublisher: function (int $unusedTenantId, string $reason, array $context) use (&$invalidationEvents): int {
                $invalidationEvents[] = ['reason' => $reason, 'context' => $context];
                if ($reason === 'tenant_restore_cutover') {
                    throw new RuntimeException('forced cutover invalidation failure');
                }

                return count($invalidationEvents);
            },
            restoreIntegrityChecker: static fn(string $unusedDatabaseName, array $unusedImportResult) => null,
        );
        $this->assertSame(1, $service->runNextBatch());

        $failed = $this->getTableLocator()->get('TenantOperationJobs')->get((int)$job->id);
        $reloadedTenant = $this->getTableLocator()->get('Tenants')->get((int)$tenant->id);
        $reloadedPrimary = $this->getTableLocator()->get('TenantDatabaseConfigs')->get((int)$primaryConfig->id);
        $this->assertSame(TenantOperationJob::STATUS_FAILED, (string)$failed->state);
        $this->assertStringContainsString(
            'Restore cutover failed and rolled back to "' . $currentDatabase . '"',
            (string)$failed->error_message,
        );
        $this->assertSame(Tenant::STATUS_ACTIVE, (string)$reloadedTenant->status);
        $this->assertSame($currentDatabase, (string)$reloadedPrimary->database_name);
        $this->assertSame(
            [
                'tenant_restore_drain_started',
                'tenant_restore_cutover',
                'tenant_restore_cutover_rollback',
                'tenant_restore_drain_released',
            ],
            array_column($invalidationEvents, 'reason'),
        );
        $this->assertSame(
            $targetDatabase,
            (string)($invalidationEvents[2]['context']['failedCutoverDatabaseName'] ?? ''),
        );
        $this->assertSame(
            $currentDatabase,
            (string)($invalidationEvents[2]['context']['databaseName'] ?? ''),
        );
    }

    public function testRestoreCutoverSnapshotRestoresPrimaryConfigDuringRollback(): void
    {
        $tenant = $this->createTenant();
        $config = $this->createPrimaryDatabaseConfig((int)$tenant->id, 'restore_snapshot_current');
        $config->driver = 'Cake\Database\Driver\Mysql';
        $config->host = 'snapshot-host';
        $config->port = 3307;
        $config->username = 'snapshot_user';
        $config->secret_reference = 'managed:tenant/snapshot';
        $config->encrypted_dsn = 'encrypted-dsn';
        $config->metadata = ['region' => 'test', 'restored' => false];
        $this->getTableLocator()->get('TenantDatabaseConfigs')->saveOrFail($config);

        $service = new TenantOperationWorkerService(workerId: 'restore-snapshot-worker');
        $snapshotMethod = new ReflectionMethod(TenantOperationWorkerService::class, 'databaseConfigSnapshot');
        $snapshotMethod->setAccessible(true);
        /** @var array<string, mixed> $snapshot */
        $snapshot = $snapshotMethod->invoke($service, $config);

        $config->database_name = 'restore_snapshot_target';
        $config->host = 'mutated-host';
        $config->port = 9999;
        $config->username = 'mutated_user';
        $config->secret_reference = 'managed:tenant/mutated';
        $config->encrypted_dsn = 'mutated-dsn';
        $config->metadata = ['region' => 'mutated', 'restored' => true];
        $this->getTableLocator()->get('TenantDatabaseConfigs')->saveOrFail($config);

        $restoreMethod = new ReflectionMethod(TenantOperationWorkerService::class, 'restorePrimaryDatabaseConfig');
        $restoreMethod->setAccessible(true);
        $restoreMethod->invoke($service, $config, $snapshot);

        $reloaded = $this->getTableLocator()->get('TenantDatabaseConfigs')->get((int)$config->id);
        $this->assertSame((string)$snapshot['database_name'], (string)$reloaded->database_name);
        $this->assertSame((string)$snapshot['host'], (string)$reloaded->host);
        $this->assertSame((int)$snapshot['port'], (int)$reloaded->port);
        $this->assertSame((string)$snapshot['username'], (string)$reloaded->username);
        $this->assertSame((string)$snapshot['secret_reference'], (string)$reloaded->secret_reference);
        $this->assertSame((string)$snapshot['encrypted_dsn'], (string)$reloaded->encrypted_dsn);
        $this->assertSame($snapshot['metadata'], $reloaded->metadata);
    }

    public function testRunNextBatchFailsGatewayJobWhenOperationIsNotCatalogApproved(): void
    {
        $admin = $this->createPlatformAdmin();
        $job = $this->createJob([
            'tenant_id' => null,
            'platform_admin_id' => (int)$admin->id,
            'operation' => 'tenant_backup',
            'state' => TenantOperationJob::STATUS_APPROVED,
            'status' => TenantOperationJob::STATUS_APPROVED,
            'input' => [
                'gateway' => [
                    'tenant_target_mode' => 'single',
                    'parameters' => [],
                ],
                'max_attempts' => 1,
            ],
        ]);

        $service = new TenantOperationWorkerService(workerId: 'gateway-validation-worker');
        $this->assertSame(1, $service->runNextBatch());

        $failed = $this->getTableLocator()->get('TenantOperationJobs')->get((int)$job->id);
        $this->assertSame(TenantOperationJob::STATUS_FAILED, (string)$failed->state);
        $this->assertStringContainsString(
            'Gateway command validation failed for operation "tenant_backup"',
            (string)($failed->error_json['message'] ?? ''),
        );
        $this->assertStringContainsString(
            'Allowed operations: tenant_doctor, tenant_migrate, tenant_rotate_db_secret, tenant_status.',
            (string)($failed->error_json['message'] ?? ''),
        );
    }

    public function testRunNextBatchFailsGatewayJobWhenInputDoesNotMatchApprovedParameters(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createPlatformAdmin();
        $job = $this->createJob([
            'tenant_id' => (int)$tenant->id,
            'platform_admin_id' => (int)$admin->id,
            'operation' => 'tenant_status',
            'state' => TenantOperationJob::STATUS_APPROVED,
            'status' => TenantOperationJob::STATUS_APPROVED,
            'input' => [
                'status' => Tenant::STATUS_ACTIVE,
                'gateway' => [
                    'tenant_target_mode' => 'single',
                    'parameters' => ['status' => Tenant::STATUS_MAINTENANCE],
                ],
                'max_attempts' => 1,
            ],
        ]);

        $service = new TenantOperationWorkerService(workerId: 'gateway-param-validation-worker');
        $this->assertSame(1, $service->runNextBatch());

        $failed = $this->getTableLocator()->get('TenantOperationJobs')->get((int)$job->id);
        $this->assertSame(TenantOperationJob::STATUS_FAILED, (string)$failed->state);
        $this->assertStringContainsString(
            'input.status does not match approved parameters',
            (string)($failed->error_json['message'] ?? ''),
        );
    }

    public function testRunNextBatchFailsGatewayJobWhenTargetModeMetadataIsMissing(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createPlatformAdmin();
        $job = $this->createJob([
            'tenant_id' => (int)$tenant->id,
            'platform_admin_id' => (int)$admin->id,
            'operation' => 'tenant_status',
            'state' => TenantOperationJob::STATUS_APPROVED,
            'status' => TenantOperationJob::STATUS_APPROVED,
            'input' => [
                'status' => Tenant::STATUS_ACTIVE,
                'gateway' => [
                    'parameters' => ['status' => Tenant::STATUS_ACTIVE],
                ],
                'max_attempts' => 1,
            ],
        ]);

        $service = new TenantOperationWorkerService(workerId: 'gateway-target-mode-worker');
        $this->assertSame(1, $service->runNextBatch());

        $failed = $this->getTableLocator()->get('TenantOperationJobs')->get((int)$job->id);
        $this->assertSame(TenantOperationJob::STATUS_FAILED, (string)$failed->state);
        $this->assertStringContainsString(
            'missing gateway.tenant_target_mode',
            (string)($failed->error_json['message'] ?? ''),
        );
    }

    public function testRunNextBatchFailsGatewayJobWhenIdempotencyScopeViolatesCatalogPolicy(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createPlatformAdmin();
        $job = $this->createJob([
            'tenant_id' => (int)$tenant->id,
            'platform_admin_id' => (int)$admin->id,
            'operation' => 'tenant_status',
            'state' => TenantOperationJob::STATUS_APPROVED,
            'status' => TenantOperationJob::STATUS_APPROVED,
            'idempotency_scope' => 'platform',
            'input' => [
                'status' => Tenant::STATUS_ACTIVE,
                'gateway' => [
                    'tenant_target_mode' => 'single',
                    'parameters' => ['status' => Tenant::STATUS_ACTIVE],
                ],
                'max_attempts' => 1,
            ],
        ]);

        $service = new TenantOperationWorkerService(workerId: 'gateway-scope-validation-worker');
        $this->assertSame(1, $service->runNextBatch());

        $failed = $this->getTableLocator()->get('TenantOperationJobs')->get((int)$job->id);
        $this->assertSame(TenantOperationJob::STATUS_FAILED, (string)$failed->state);
        $this->assertStringContainsString(
            'requires idempotency scope "tenant" (received "platform")',
            (string)($failed->error_json['message'] ?? ''),
        );
    }

    /**
     * @return void
     */
    private function truncatePlatformTables(): void
    {
        $connection = ConnectionManager::get('test');
        foreach (
            [
            'tenant_operation_jobs',
            'tenant_operation_approvals',
            'tenant_operation_locks',
            'tenant_database_configs',
            'tenant_service_configs',
            'tenant_runtime_invalidation_versions',
            'tenant_aliases',
            'tenants',
            'platform_admins',
            ] as $table
        ) {
            $connection->execute(sprintf('DELETE FROM %s', $connection->getDriver()->quoteIdentifier($table)));
        }
        TableRegistry::getTableLocator()->clear();
    }

    /**
     * @return \App\Model\Entity\Tenant
     */
    private function createTenant(): Tenant
    {
        $tenant = $this->getTableLocator()->get('Tenants')->newEntity([
            'slug' => 'worker-' . uniqid(),
            'display_name' => 'Worker Tenant',
            'status' => Tenant::STATUS_ACTIVE,
        ]);
        $tenant->primary_host = $tenant->slug . '.example.test';
        $this->getTableLocator()->get('Tenants')->saveOrFail($tenant);

        return $tenant;
    }

    /**
     * @return \Cake\Datasource\EntityInterface
     */
    private function createPlatformAdmin()
    {
        $admin = $this->getTableLocator()->get('PlatformAdmins')->newEntity([
            'email' => sprintf('worker-%s@example.test', uniqid()),
            'display_name' => 'Worker Admin',
            'password_hash' => '$2y$10$examplehashforworker0000000000000000000000000',
            'status' => 'active',
            'role' => 'break_glass',
            'require_password_change' => false,
            'failed_attempts' => 0,
        ]);
        $this->getTableLocator()->get('PlatformAdmins')->saveOrFail($admin);

        return $admin;
    }

    /**
     * @param \App\Model\Entity\Tenant $tenant Tenant
     * @param string $secretReference Secret reference
     * @return void
     */
    private function createPrimaryDatabaseConfigWithSecret(Tenant $tenant, string $secretReference): void
    {
        $config = $this->getTableLocator()->get('TenantDatabaseConfigs')->newEntity([
            'tenant_id' => (int)$tenant->id,
            'connection_role' => 'primary',
            'driver' => 'Cake\\Database\\Driver\\Mysql',
            'host' => 'localhost',
            'port' => 3306,
            'database_name' => 'tenant_' . str_replace('-', '_', (string)$tenant->slug),
            'username' => 'tenant_user',
            'secret_reference' => $secretReference,
            'read_enabled' => true,
            'write_enabled' => true,
            'is_active' => true,
            'metadata' => '{}',
        ]);
        $this->getTableLocator()->get('TenantDatabaseConfigs')->saveOrFail($config);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return \App\Model\Entity\TenantOperationJob
     */
    private function createJob(array $overrides): TenantOperationJob
    {
        $jobs = $this->getTableLocator()->get('TenantOperationJobs');
        $payload = array_merge([
            'tenant_id' => null,
            'platform_admin_id' => null,
            'operation' => 'tenant_status',
            'state' => TenantOperationJob::STATUS_APPROVED,
            'status' => TenantOperationJob::STATUS_APPROVED,
            'idempotency_scope' => 'tenant',
            'idempotency_key' => uniqid('worker-', true),
            'input' => [],
            'progress_json' => [],
            'cancelled_at' => null,
        ], $overrides);
        $job = $jobs->newEntity($payload);
        $jobs->saveOrFail($job);

        return $job;
    }

    /**
     * @return \Cake\Datasource\EntityInterface
     */
    private function createPrimaryDatabaseConfig(int $tenantId, string $databaseName)
    {
        $configs = $this->getTableLocator()->get('TenantDatabaseConfigs');
        $config = $configs->newEntity([
            'tenant_id' => $tenantId,
            'connection_role' => 'primary',
            'driver' => 'Cake\Database\Driver\Sqlite',
            'host' => 'localhost',
            'port' => null,
            'database_name' => $databaseName,
            'username' => null,
            'secret_reference' => null,
            'encrypted_dsn' => null,
            'read_enabled' => true,
            'write_enabled' => true,
            'is_active' => true,
            'metadata' => [],
        ]);
        $configs->saveOrFail($config);

        return $config;
    }
}
