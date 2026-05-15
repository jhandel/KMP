<?php
declare(strict_types=1);

namespace App\Services\Platform;

use App\Model\Entity\Tenant;
use App\Model\Entity\TenantOperationJob;
use App\Services\Tenant\TenantMigrationService;
use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\Utility\Text;
use RuntimeException;
use Throwable;

/**
 * Coordinates deployment migrations with platform-first gating and per-tenant child jobs.
 */
class DeploymentMigrationOrchestratorService
{
    use LocatorAwareTrait;

    private const PARENT_OPERATION = 'tenant_migrate_all';

    /**
     * @var callable
     */
    private $workerBatchRunner;

    /**
     * @var callable
     */
    private $sleepFn;

    public function __construct(
        private readonly ?TenantMigrationService $migrationService = null,
        ?callable $workerBatchRunner = null,
        ?callable $sleepFn = null,
    ) {
        $this->workerBatchRunner = $workerBatchRunner ?? function (int $limit): int {
            return (new TenantOperationWorkerService())->runNextBatch($limit);
        };
        $this->sleepFn = $sleepFn ?? function (int $seconds): void {
            sleep($seconds);
        };
    }

    /**
     * Start or resume a deployment migration orchestration run.
     *
     * @param array<string, mixed> $options Orchestration options
     * @return array<string, mixed>
     */
    public function orchestrate(array $options = []): array
    {
        $wait = (bool)($options['wait'] ?? false);
        $driveWorker = (bool)($options['drive_worker'] ?? $wait);
        $onFailure = (string)($options['on_failure'] ?? 'hold');
        if (!in_array($onFailure, ['hold', 'fail'], true)) {
            throw new RuntimeException('on_failure must be either "hold" or "fail".');
        }
        $workerBatchSize = max(1, (int)($options['worker_batch_size'] ?? 5));
        $pollInterval = max(1, (int)($options['poll_interval_seconds'] ?? 5));
        $timeoutSeconds = max(1, (int)($options['timeout_seconds'] ?? 3600));
        $maxAttempts = max(1, (int)($options['max_attempts'] ?? TenantOperationWorkerService::DEFAULT_MAX_ATTEMPTS));

        if (isset($options['resume_parent_id']) && (int)$options['resume_parent_id'] > 0) {
            $parentJob = $this->resumeParent((int)$options['resume_parent_id']);

            return $this->monitorChildren(
                $parentJob,
                [
                    'wait' => $wait,
                    'drive_worker' => $driveWorker,
                    'worker_batch_size' => $workerBatchSize,
                    'poll_interval_seconds' => $pollInterval,
                    'timeout_seconds' => $timeoutSeconds,
                    'on_failure' => $onFailure,
                ],
            );
        }

        if (!(bool)($options['skip_platform_gate'] ?? false)) {
            $migrationService = $this->migrationService ?? new TenantMigrationService();
            $migrationService->migratePlatform();
        }

        $includeMaintenance = (bool)($options['include_maintenance'] ?? false);
        $targetTenants = $this->discoverActiveTenants($includeMaintenance);

        $jobs = $this->fetchTable('TenantOperationJobs');
        $runId = trim((string)($options['run_id'] ?? ''));
        if ($runId === '') {
            $runId = DateTime::now()->format('YmdHis') . '-' . substr((string)Text::uuid(), 0, 8);
        }
        $correlationId = Text::uuid();
        $platformAdminId = $this->resolveSystemAdminId(
            (string)($options['system_admin_email'] ?? 'deployment-orchestrator@localhost.test'),
        );
        $targetSchemaVersion = ($this->migrationService ?? new TenantMigrationService())->targetSchemaVersion();

        $parentJob = $jobs->newEntity([
            'tenant_id' => null,
            'platform_admin_id' => $platformAdminId,
            'operation' => self::PARENT_OPERATION,
            'state' => TenantOperationJob::STATUS_RUNNING,
            'status' => TenantOperationJob::STATUS_RUNNING,
            'idempotency_scope' => 'platform',
            'idempotency_key' => 'deployment-migrate:' . $runId,
            'input' => [
                'run_id' => $runId,
                'include_maintenance' => $includeMaintenance,
                'max_attempts' => $maxAttempts,
                'target_schema_version' => $targetSchemaVersion,
            ],
            'progress_json' => [
                'phase' => 'enqueue',
                'message' => 'Enqueueing tenant migration children',
                'run_id' => $runId,
                'updated_at' => DateTime::now()->toIso8601String(),
            ],
            'status_message' => 'Creating tenant migration child jobs.',
            'operation_correlation_id' => $correlationId,
            'started_at' => DateTime::now(),
        ]);
        $jobs->saveOrFail($parentJob);

        $childIds = [];
        foreach ($targetTenants as $tenant) {
            $child = $jobs->newEntity([
                'tenant_id' => (int)$tenant->id,
                'platform_admin_id' => $platformAdminId,
                'parent_tenant_operation_job_id' => (int)$parentJob->id,
                'operation' => 'tenant_migrate',
                'state' => TenantOperationJob::STATUS_APPROVED,
                'status' => TenantOperationJob::STATUS_APPROVED,
                'idempotency_scope' => 'deployment-run',
                'idempotency_key' => sprintf('%d:%d', (int)$parentJob->id, (int)$tenant->id),
                'input' => [
                    'tenant_slug' => (string)$tenant->slug,
                    'schema_before' => (string)($tenant->schema_version ?? ''),
                    'target_schema_version' => $targetSchemaVersion,
                    'parent_operation_id' => (int)$parentJob->id,
                    'parent_tenant_operation_job_id' => (int)$parentJob->id,
                    'migration_scope' => 'tenant_schema',
                    'migration_scopes' => ['core', 'plugins'],
                    'orchestration' => [
                        'type' => self::PARENT_OPERATION,
                        'run_id' => $runId,
                        'on_failure' => $onFailure,
                    ],
                    'max_attempts' => $maxAttempts,
                ],
                'progress_json' => [
                    'phase' => 'queued',
                    'message' => 'Queued by deployment migration orchestrator',
                    'updated_at' => DateTime::now()->toIso8601String(),
                ],
                'status_message' => 'Queued for deployment migration run.',
                'operation_correlation_id' => $correlationId,
            ]);
            $jobs->saveOrFail($child);
            $childIds[] = (int)$child->id;
        }

        $jobs->updateAll(
            [
                'status_message' => sprintf('Queued %d tenant migration child job(s).', count($childIds)),
                'progress_json' => [
                    'phase' => 'queued',
                    'queued_children' => count($childIds),
                    'updated_at' => DateTime::now()->toIso8601String(),
                ],
            ],
            ['id' => (int)$parentJob->id],
        );

        $parentJob = $jobs->get((int)$parentJob->id);

        return $this->monitorChildren(
            $parentJob,
            [
                'wait' => $wait,
                'drive_worker' => $driveWorker,
                'worker_batch_size' => $workerBatchSize,
                'poll_interval_seconds' => $pollInterval,
                'timeout_seconds' => $timeoutSeconds,
                'on_failure' => $onFailure,
            ],
        );
    }

    /**
     * @param bool $includeMaintenance Include maintenance tenants
     * @return array<int, \App\Model\Entity\Tenant>
     */
    private function discoverActiveTenants(bool $includeMaintenance): array
    {
        $statuses = [Tenant::STATUS_ACTIVE];
        if ($includeMaintenance) {
            $statuses[] = Tenant::STATUS_MAINTENANCE;
        }

        return $this->fetchTable('Tenants')->find()
            ->where(['Tenants.status IN' => $statuses])
            ->orderByAsc('Tenants.id')
            ->all()
            ->toList();
    }

    /**
     * @param int $parentId Parent job id
     * @return \App\Model\Entity\TenantOperationJob
     */
    private function resumeParent(int $parentId): TenantOperationJob
    {
        $jobs = $this->fetchTable('TenantOperationJobs');
        $parent = $jobs->get($parentId);
        if ((string)$parent->operation !== self::PARENT_OPERATION) {
            throw new RuntimeException(sprintf('Job %d is not a %s parent operation.', $parentId, self::PARENT_OPERATION));
        }

        $children = $this->childJobs($parent);
        foreach ($children as $child) {
            if (!in_array((string)$child->state, [
                TenantOperationJob::STATUS_FAILED,
                TenantOperationJob::STATUS_BLOCKED,
                TenantOperationJob::STATUS_HOLD,
            ], true)) {
                continue;
            }
            $input = is_array($child->input ?? null) ? $child->input : [];
            $resumeCount = (int)($input['resume_count'] ?? 0) + 1;
            $input['resume_count'] = $resumeCount;
            $jobs->updateAll([
                'state' => TenantOperationJob::STATUS_APPROVED,
                'status' => TenantOperationJob::STATUS_APPROVED,
                'status_message' => sprintf('Resume requested for child migration (attempt %d).', $resumeCount),
                'input' => $input,
                'error_json' => null,
                'error_message' => null,
                'completed_at' => null,
                'lease_owner' => null,
                'lease_token' => null,
                'lease_acquired_at' => null,
                'lease_expires_at' => null,
                'heartbeat_at' => null,
            ], ['id' => (int)$child->id]);
        }

        $jobs->updateAll([
            'state' => TenantOperationJob::STATUS_RUNNING,
            'status' => TenantOperationJob::STATUS_RUNNING,
            'status_message' => 'Resumed deployment migration orchestration.',
            'error_json' => null,
            'error_message' => null,
            'completed_at' => null,
            'progress_json' => [
                'phase' => 'resumed',
                'message' => 'Resuming child migration monitoring',
                'updated_at' => DateTime::now()->toIso8601String(),
            ],
        ], ['id' => (int)$parent->id]);

        return $jobs->get((int)$parent->id);
    }

    /**
     * @param \App\Model\Entity\TenantOperationJob $parent Parent job
     * @param array<string, mixed> $options Monitoring options
     * @return array<string, mixed>
     */
    private function monitorChildren(TenantOperationJob $parent, array $options): array
    {
        $wait = (bool)($options['wait'] ?? false);
        $driveWorker = (bool)($options['drive_worker'] ?? false);
        $workerBatchSize = max(1, (int)($options['worker_batch_size'] ?? 5));
        $pollInterval = max(1, (int)($options['poll_interval_seconds'] ?? 5));
        $timeoutSeconds = max(1, (int)($options['timeout_seconds'] ?? 3600));
        $onFailure = (string)($options['on_failure'] ?? 'hold');
        $deadline = time() + $timeoutSeconds;

        $children = $this->childJobs($parent);
        if (!$wait) {
            $childSummaries = array_map(function (TenantOperationJob $job): array {
                $input = is_array($job->input ?? null) ? $job->input : [];
                $progress = is_array($job->progress_json ?? null) ? $job->progress_json : [];

                return [
                    'job_id' => (int)$job->id,
                    'parent_job_id' => (int)($job->parent_tenant_operation_job_id ?? $input['parent_tenant_operation_job_id'] ?? $input['parent_operation_id'] ?? 0),
                    'tenant_id' => (int)($job->tenant_id ?? 0),
                    'tenant_slug' => (string)($input['tenant_slug'] ?? ''),
                    'state' => (string)$job->state,
                    'schema_before' => (string)($input['schema_before'] ?? ''),
                    'target_schema_version' => (string)($input['target_schema_version'] ?? ''),
                    'migration_scope' => (string)($input['migration_scope'] ?? 'tenant_schema'),
                    'migration_scopes' => is_array($input['migration_scopes'] ?? null) ? $input['migration_scopes'] : [],
                    'attempt_count' => (int)($progress['attempt_count'] ?? 0),
                    'max_attempts' => isset($input['max_attempts']) ? (int)$input['max_attempts'] : null,
                ];
            }, $children);

            return [
                'parent_job_id' => (int)$parent->id,
                'correlation_id' => (string)($parent->operation_correlation_id ?? ''),
                'state' => (string)$parent->state,
                'counts' => $this->summarizeStates($children),
                'child_job_ids' => array_map(fn(TenantOperationJob $job): int => (int)$job->id, $children),
                'children' => $childSummaries,
            ];
        }

        while (true) {
            if ($driveWorker) {
                $runner = $this->workerBatchRunner;
                $runner($workerBatchSize);
            }

            $children = $this->childJobs($parent);
            $counts = $this->summarizeStates($children);
            $terminalCount = ($counts[TenantOperationJob::STATUS_COMPLETED] ?? 0)
                + ($counts[TenantOperationJob::STATUS_FAILED] ?? 0)
                + ($counts[TenantOperationJob::STATUS_CANCELLED] ?? 0);

            if (($counts[TenantOperationJob::STATUS_FAILED] ?? 0) > 0 && $onFailure === 'hold') {
                return $this->finalizeParent(
                    $parent,
                    TenantOperationJob::STATUS_HOLD,
                    'Deployment migration placed on hold due to failed tenant child job(s).',
                    $children,
                );
            }
            if (($counts[TenantOperationJob::STATUS_FAILED] ?? 0) > 0 && $onFailure === 'fail') {
                return $this->finalizeParent(
                    $parent,
                    TenantOperationJob::STATUS_FAILED,
                    'Deployment migration failed due to failed tenant child job(s).',
                    $children,
                );
            }
            if ($terminalCount >= count($children)) {
                return $this->finalizeParent(
                    $parent,
                    TenantOperationJob::STATUS_COMPLETED,
                    'Deployment migration completed successfully for all tenant child jobs.',
                    $children,
                );
            }
            if (time() >= $deadline) {
                return $this->finalizeParent(
                    $parent,
                    TenantOperationJob::STATUS_HOLD,
                    'Deployment migration timed out while waiting for tenant child jobs.',
                    $children,
                );
            }

            $sleep = $this->sleepFn;
            $sleep($pollInterval);
        }
    }

    /**
     * @param \App\Model\Entity\TenantOperationJob $parent Parent job
     * @return array<int, \App\Model\Entity\TenantOperationJob>
     */
    private function childJobs(TenantOperationJob $parent): array
    {
        $query = $this->fetchTable('TenantOperationJobs')->find()
            ->where(['operation' => 'tenant_migrate']);
        if ((int)$parent->id > 0) {
            $query->where(['parent_tenant_operation_job_id' => (int)$parent->id]);
        } else {
            $query->where([
                'operation_correlation_id' => (string)($parent->operation_correlation_id ?? ''),
            ]);
        }

        return $query
            ->orderByAsc('id')
            ->all()
            ->toList();
    }

    /**
     * @param array<int, \App\Model\Entity\TenantOperationJob> $children Child jobs
     * @return array<string, int>
     */
    private function summarizeStates(array $children): array
    {
        $counts = [];
        foreach ($children as $job) {
            $state = (string)$job->state;
            if (!isset($counts[$state])) {
                $counts[$state] = 0;
            }
            $counts[$state]++;
        }

        return $counts;
    }

    /**
     * @param \App\Model\Entity\TenantOperationJob $parent Parent job
     * @param string $state Terminal/hold state
     * @param string $message Status message
     * @param array<int, \App\Model\Entity\TenantOperationJob> $children Child jobs
     * @return array<string, mixed>
     */
    private function finalizeParent(
        TenantOperationJob $parent,
        string $state,
        string $message,
        array $children,
    ): array {
        $counts = $this->summarizeStates($children);
        $tenantResults = array_map(function (TenantOperationJob $job): array {
            $input = is_array($job->input ?? null) ? $job->input : [];
            $result = is_array($job->result_json ?? null) ? $job->result_json : [];
            $error = is_array($job->error_json ?? null) ? $job->error_json : [];
            $progress = is_array($job->progress_json ?? null) ? $job->progress_json : [];
            $attemptCount = (int)($progress['attempt_count'] ?? 0);
            $maxAttempts = (int)($progress['max_attempts'] ?? $input['max_attempts'] ?? 0);
            $retryable = isset($error['retryable']) ? (bool)$error['retryable'] : null;

            return [
                'job_id' => (int)$job->id,
                'parent_job_id' => (int)($job->parent_tenant_operation_job_id ?? $input['parent_tenant_operation_job_id'] ?? $input['parent_operation_id'] ?? 0),
                'tenant_id' => (int)($job->tenant_id ?? 0),
                'tenant_slug' => (string)($result['slug'] ?? $input['tenant_slug'] ?? ''),
                'state' => (string)$job->state,
                'schema_before' => (string)($result['schema_before'] ?? $input['schema_before'] ?? ''),
                'schema_after' => (string)($result['schema_after'] ?? $result['schema_version'] ?? ''),
                'target_schema_version' => (string)($result['target_schema_version'] ?? $input['target_schema_version'] ?? ''),
                'migration_scope' => (string)($result['migration_scope'] ?? $input['migration_scope'] ?? 'tenant_schema'),
                'migration_scopes' => is_array($result['migration_scopes'] ?? null)
                    ? $result['migration_scopes']
                    : (is_array($input['migration_scopes'] ?? null) ? $input['migration_scopes'] : []),
                'duration_ms' => isset($result['duration_ms']) ? (int)$result['duration_ms'] : null,
                'attempt_count' => $attemptCount,
                'max_attempts' => $maxAttempts > 0 ? $maxAttempts : null,
                'retryable' => $retryable,
                'retry_exhausted' => $retryable === true && $maxAttempts > 0 && $attemptCount >= $maxAttempts,
                'hold_state' => in_array((string)$job->state, [TenantOperationJob::STATUS_HOLD, TenantOperationJob::STATUS_BLOCKED], true),
                'result' => $result,
                'error' => $error,
            ];
        }, $children);

        $payload = [
            'summary' => $message,
            'counts' => $counts,
            'tenant_results' => $tenantResults,
            'completed_at' => DateTime::now()->toIso8601String(),
        ];

        $updates = [
            'state' => $state,
            'status' => $state,
            'status_message' => $message,
            'result_json' => $payload,
            'progress_json' => [
                'phase' => 'completed',
                'message' => $message,
                'counts' => $counts,
                'updated_at' => DateTime::now()->toIso8601String(),
            ],
        ];
        if (in_array($state, [
            TenantOperationJob::STATUS_COMPLETED,
            TenantOperationJob::STATUS_FAILED,
            TenantOperationJob::STATUS_CANCELLED,
        ], true)) {
            $updates['completed_at'] = DateTime::now();
        }
        if ($state === TenantOperationJob::STATUS_FAILED) {
            $updates['error_message'] = $message;
            $updates['error_json'] = [
                'code' => 'deployment_migrate_failed',
                'message' => $message,
                'retryable' => false,
                'category' => 'dependency',
                'details' => ['failed_children' => (int)($counts[TenantOperationJob::STATUS_FAILED] ?? 0)],
                'occurred_at' => DateTime::now()->toIso8601String(),
            ];
        }

        $this->fetchTable('TenantOperationJobs')->updateAll($updates, ['id' => (int)$parent->id]);
        $refreshed = $this->fetchTable('TenantOperationJobs')->get((int)$parent->id);

        return [
            'parent_job_id' => (int)$refreshed->id,
            'correlation_id' => (string)($refreshed->operation_correlation_id ?? ''),
            'state' => (string)$refreshed->state,
            'counts' => $counts,
            'child_job_ids' => array_map(fn(TenantOperationJob $job): int => (int)$job->id, $children),
            'children' => $tenantResults,
            'tenant_results' => $tenantResults,
        ];
    }

    /**
     * @param string $email System admin email
     * @return int
     */
    private function resolveSystemAdminId(string $email): int
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            throw new RuntimeException('system_admin_email cannot be empty.');
        }

        $admins = $this->fetchTable('PlatformAdmins');
        $admin = $admins->find()->where(['email' => $email])->first();
        if ($admin !== null) {
            return (int)$admin->id;
        }

        try {
            $admin = $admins->newEntity([
                'email' => $email,
                'display_name' => 'Deployment Orchestrator',
                'password_hash' => password_hash(Text::uuid(), PASSWORD_DEFAULT),
                'status' => 'active',
                'role' => 'provisioner',
                'require_password_change' => true,
                'failed_attempts' => 0,
            ]);
            $admins->saveOrFail($admin);
        } catch (Throwable $e) {
            throw new RuntimeException('Unable to create deployment orchestrator platform admin: ' . $e->getMessage(), 0, $e);
        }

        return (int)$admin->id;
    }
}
