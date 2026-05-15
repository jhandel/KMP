<?php
declare(strict_types=1);

namespace App\Services\Platform;

use App\Model\Entity\PlatformAdmin;
use App\Model\Entity\Tenant;
use App\Model\Entity\TenantOperationJob;
use App\Services\Tenant\TenantProvisioningService;
use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\Utility\Text;
use RuntimeException;

/**
 * Creates approved, idempotent tenant operation jobs from trusted gateway requests.
 */
class TenantOperationGatewayService
{
    use LocatorAwareTrait;
    private const DEFAULT_BULK_BATCH_SIZE = 25;
    private const MAX_BULK_BATCH_SIZE = 250;
    private const MAX_BULK_PAUSE_MS = 30000;

    /**
     * Submit an approved operation request and return created or reused jobs.
     *
     * @param string $operation Approved operation name
     * @param \App\Model\Entity\PlatformAdmin $requester Requesting platform admin
     * @param string $tenantTargetMode single|selected|all-tenant
     * @param array<string, mixed> $parameters Validated operation parameters
     * @param array<int, string>|null $tenantSlugs Target tenant slugs for single/selected mode
     * @param \App\Model\Entity\PlatformAdmin|null $approvedBy Approving platform admin
     * @param string|null $correlationId Correlation id override
     * @param string|null $idempotencyKey Explicit idempotency key override
     * @param string $idempotencyScope Idempotency scope
     * @param array<string, mixed> $bulkOptions Bulk controls: batch_size, pause_ms, continue_on_error, max_targets
     * @return array{
     *   jobs: array<int, \App\Model\Entity\TenantOperationJob>,
     *   created_count: int,
     *   deduplicated_count: int,
     *   failed_count: int,
     *   failures: array<int, array{tenant_slug: string, message: string, code: string}>,
     *   correlation_id: string,
     *   tenant_target_mode: string,
     *   tenant_snapshot: array<int, string>,
     *   parent_job_id: int|null
     * }
     */
    public function submitApprovedRequest(
        string $operation,
        PlatformAdmin $requester,
        string $tenantTargetMode,
        array $parameters = [],
        ?array $tenantSlugs = null,
        ?PlatformAdmin $approvedBy = null,
        ?string $correlationId = null,
        ?string $idempotencyKey = null,
        string $idempotencyScope = 'tenant',
        array $bulkOptions = [],
    ): array {
        $operation = trim($operation);
        if ($operation === '') {
            throw new RuntimeException('Operation is required.');
        }
        $approver = $approvedBy ?? $requester;
        $this->assertGatewayPrivileges($operation, $requester, $approver);
        $approvalPolicy = TenantOperationCommandCatalog::approvalPolicy($operation);
        $normalizedParameters = TenantOperationCommandCatalog::validateGatewayRequest(
            operation: $operation,
            targetMode: $tenantTargetMode,
            parameters: $parameters,
        );
        TenantOperationCommandCatalog::validateIdempotencyScope($operation, $idempotencyScope);
        $targets = $this->resolveTargets($tenantTargetMode, $tenantSlugs);
        $snapshot = array_map(
            static fn (Tenant $tenant): string => (string)$tenant->slug,
            $targets,
        );
        $resolvedCorrelationId = $this->resolveCorrelationId($correlationId);
        $requestedAt = DateTime::now();
        $jobs = $this->fetchTable('TenantOperationJobs');
        $approvals = $this->fetchTable('TenantOperationApprovals');
        $bulkConfig = $this->resolveBulkConfig($tenantTargetMode, count($targets), $bulkOptions);
        $parentJob = $this->createBulkParentJob(
            $operation,
            $requester,
            $tenantTargetMode,
            $snapshot,
            $normalizedParameters,
            $resolvedCorrelationId,
            $requestedAt,
            $bulkConfig,
        );

        $createdJobs = [];
        $createdCount = 0;
        $deduplicatedCount = 0;
        $failures = [];
        $batchTotal = (int)ceil(count($targets) / $bulkConfig['batch_size']);

        foreach (array_chunk($targets, $bulkConfig['batch_size']) as $batchIndex => $batchTargets) {
            if ($batchIndex > 0 && $bulkConfig['pause_ms'] > 0) {
                usleep($bulkConfig['pause_ms'] * 1000);
            }
            foreach ($batchTargets as $tenant) {
                try {
                    $requestHash = $this->requestHash(
                        operation: $operation,
                        tenant: $tenant,
                        tenantTargetMode: $tenantTargetMode,
                        parameters: $normalizedParameters,
                    );
                    $resolvedIdempotencyKey = $this->resolveIdempotencyKey(
                        operation: $operation,
                        tenant: $tenant,
                        providedKey: $idempotencyKey,
                        requestHash: $requestHash,
                    );

                    $existing = $jobs->find()
                        ->where([
                            'operation' => $operation,
                            'tenant_id' => (int)$tenant->id,
                            'idempotency_scope' => $idempotencyScope,
                            'idempotency_key' => $resolvedIdempotencyKey,
                        ])
                        ->first();
                    if ($existing instanceof TenantOperationJob) {
                        $this->assertMatchingIdempotentRequest($existing, $requestHash);
                        $createdJobs[] = $existing;
                        $deduplicatedCount++;
                        $this->updateBulkParentProgress(
                            $parentJob,
                            $batchIndex,
                            $batchTotal,
                            $createdCount,
                            $deduplicatedCount,
                            count($failures),
                            count($targets),
                        );

                        continue;
                    }

                    $input = $this->buildInputPayload(
                        operation: $operation,
                        tenant: $tenant,
                        parameters: $normalizedParameters,
                        tenantTargetMode: $tenantTargetMode,
                        tenantSnapshot: $snapshot,
                        requester: $requester,
                        approver: $approver,
                        approvalPolicy: $approvalPolicy,
                        requestedAt: $requestedAt,
                        requestHash: $requestHash,
                        idempotencyScope: $idempotencyScope,
                        batchIndex: $batchIndex + 1,
                        batchTotal: $batchTotal,
                        batchSize: $bulkConfig['batch_size'],
                    );
                    $approvalsRequired = max(0, (int)($approvalPolicy['required_approvals'] ?? 0));
                    $initialApprovalCount = $this->initialApprovalCount($approvalPolicy, $requester, $approver);
                    $initialState = $approvalsRequired > 0 && $initialApprovalCount < $approvalsRequired
                        ? TenantOperationJob::STATUS_APPROVAL_REQUIRED
                        : TenantOperationJob::STATUS_APPROVED;
                    $job = $jobs->newEntity([
                        'tenant_id' => (int)$tenant->id,
                        'platform_admin_id' => (int)$requester->id,
                        'operation' => $operation,
                        'state' => $initialState,
                        'status' => $initialState,
                        'approval_policy_json' => $approvalPolicy,
                        'approvals_required' => $approvalsRequired,
                        'approvals_received' => $initialApprovalCount,
                        'idempotency_scope' => $idempotencyScope,
                        'idempotency_key' => $resolvedIdempotencyKey,
                        'input' => $input,
                        'progress_json' => [
                            'phase' => 'queued',
                            'message' => 'Queued by gateway',
                            'batch_index' => $batchIndex + 1,
                            'batch_total' => $batchTotal,
                            'batch_size' => $bulkConfig['batch_size'],
                                'updated_at' => DateTime::now()->toIso8601String(),
                            ],
                        'status_message' => $initialState === TenantOperationJob::STATUS_APPROVAL_REQUIRED
                            ? 'Awaiting additional admin approval before worker execution.'
                            : 'Operation approved and queued for worker execution.',
                        'operation_correlation_id' => $resolvedCorrelationId,
                    ]);
                    $jobs->saveOrFail($job);
                    $approval = $approvals->newEntity([
                        'tenant_operation_job_id' => (int)$job->id,
                        'platform_admin_id' => (int)$approver->id,
                        'approval_type' => 'gateway_approved',
                        'decision' => 'approved',
                        'decision_note' => $initialApprovalCount === 0
                            ? 'Recorded at submission; requester separation policy still requires an additional approver.'
                            : null,
                        'decided_at' => $requestedAt,
                        'approved_at' => $requestedAt,
                    ]);
                    $approvals->saveOrFail($approval);

                    $createdJobs[] = $job;
                    $createdCount++;
                    $this->updateBulkParentProgress(
                        $parentJob,
                        $batchIndex,
                        $batchTotal,
                        $createdCount,
                        $deduplicatedCount,
                        count($failures),
                        count($targets),
                    );
                } catch (RuntimeException $e) {
                    $failures[] = [
                        'tenant_slug' => (string)$tenant->slug,
                        'message' => $e->getMessage(),
                        'code' => (new \ReflectionClass($e))->getShortName(),
                    ];
                    $this->updateBulkParentProgress(
                        $parentJob,
                        $batchIndex,
                        $batchTotal,
                        $createdCount,
                        $deduplicatedCount,
                        count($failures),
                        count($targets),
                    );
                    if ($bulkConfig['fail_fast']) {
                        throw new RuntimeException(sprintf(
                            'Bulk operation failed for tenant "%s": %s',
                            (string)$tenant->slug,
                            $e->getMessage(),
                        ), 0, $e);
                    }
                }
            }
        }

        $this->finalizeBulkParent(
            $parentJob,
            $createdCount,
            $deduplicatedCount,
            $failures,
            count($targets),
            $bulkConfig,
        );

        return [
            'jobs' => $createdJobs,
            'created_count' => $createdCount,
            'deduplicated_count' => $deduplicatedCount,
            'failed_count' => count($failures),
            'failures' => $failures,
            'correlation_id' => $resolvedCorrelationId,
            'tenant_target_mode' => $tenantTargetMode,
            'tenant_snapshot' => $snapshot,
            'parent_job_id' => $parentJob?->id !== null ? (int)$parentJob->id : null,
        ];
    }

    /**
     * @param string $tenantTargetMode Target mode
     * @param int $targetCount Number of resolved targets
     * @param array<string, mixed> $bulkOptions Raw bulk options
     * @return array{batch_size: int, pause_ms: int, fail_fast: bool}
     */
    private function resolveBulkConfig(string $tenantTargetMode, int $targetCount, array $bulkOptions): array
    {
        $batchSize = (int)($bulkOptions['batch_size'] ?? self::DEFAULT_BULK_BATCH_SIZE);
        $pauseMs = (int)($bulkOptions['pause_ms'] ?? 0);
        $continueOnError = (bool)($bulkOptions['continue_on_error'] ?? ($tenantTargetMode !== 'single'));
        $maxTargets = isset($bulkOptions['max_targets']) ? (int)$bulkOptions['max_targets'] : null;

        $batchSize = max(1, min(self::MAX_BULK_BATCH_SIZE, $batchSize));
        $pauseMs = max(0, min(self::MAX_BULK_PAUSE_MS, $pauseMs));
        if ($maxTargets !== null && $maxTargets > 0 && $targetCount > $maxTargets) {
            throw new RuntimeException(sprintf(
                'Resolved %d tenants exceeds max_targets safety guard of %d.',
                $targetCount,
                $maxTargets,
            ));
        }

        return [
            'batch_size' => $batchSize,
            'pause_ms' => $pauseMs,
            'fail_fast' => !$continueOnError,
        ];
    }

    /**
     * @param array<int, string> $snapshot Target snapshot
     * @param array<string, mixed> $parameters Normalized params
     * @param array{batch_size: int, pause_ms: int, fail_fast: bool} $bulkConfig Bulk controls
     * @return \App\Model\Entity\TenantOperationJob|null
     */
    private function createBulkParentJob(
        string $operation,
        PlatformAdmin $requester,
        string $tenantTargetMode,
        array $snapshot,
        array $parameters,
        string $correlationId,
        DateTime $requestedAt,
        array $bulkConfig,
    ): ?TenantOperationJob {
        if ($tenantTargetMode === 'single') {
            return null;
        }
        $jobs = $this->fetchTable('TenantOperationJobs');
        $parent = $jobs->newEntity([
            'tenant_id' => null,
            'platform_admin_id' => (int)$requester->id,
            'operation' => $operation . '_bulk_submit',
            'state' => TenantOperationJob::STATUS_RUNNING,
            'status' => TenantOperationJob::STATUS_RUNNING,
            'idempotency_scope' => 'bulk_request',
            'idempotency_key' => sprintf('bulk:%s:%s', $operation, substr($correlationId, 0, 24)),
            'input' => [
                'operation' => $operation,
                'tenant_target_mode' => $tenantTargetMode,
                'tenant_snapshot' => $snapshot,
                'requested_at' => $requestedAt->toIso8601String(),
                'parameters' => $parameters,
                'bulk' => $bulkConfig,
            ],
            'progress_json' => [
                'phase' => 'batching',
                'message' => 'Submitting tenant operations in batches',
                'total_targets' => count($snapshot),
                'updated_at' => DateTime::now()->toIso8601String(),
            ],
            'status_message' => sprintf(
                'Submitting %d tenant operation job(s) in batches.',
                count($snapshot),
            ),
            'operation_correlation_id' => $correlationId,
            'started_at' => DateTime::now(),
        ]);
        $jobs->saveOrFail($parent);

        return $parent;
    }

    /**
     * @param \App\Model\Entity\TenantOperationJob|null $parentJob Parent operation job
     * @return void
     */
    private function updateBulkParentProgress(
        ?TenantOperationJob $parentJob,
        int $batchIndex,
        int $batchTotal,
        int $createdCount,
        int $deduplicatedCount,
        int $failedCount,
        int $totalTargets,
    ): void {
        if (!$parentJob instanceof TenantOperationJob || $parentJob->id === null) {
            return;
        }
        $processed = $createdCount + $deduplicatedCount + $failedCount;
        $percent = $totalTargets > 0 ? (int)floor(($processed / $totalTargets) * 100) : 100;
        $this->fetchTable('TenantOperationJobs')->updateAll([
            'progress_percent' => max(0, min(99, $percent)),
            'status_message' => sprintf(
                'Submitting batch %d/%d (%d/%d processed).',
                $batchIndex + 1,
                max(1, $batchTotal),
                $processed,
                $totalTargets,
            ),
            'progress_json' => [
                'phase' => 'batching',
                'batch_index' => $batchIndex + 1,
                'batch_total' => $batchTotal,
                'processed' => $processed,
                'created_count' => $createdCount,
                'deduplicated_count' => $deduplicatedCount,
                'failed_count' => $failedCount,
                'total_targets' => $totalTargets,
                'updated_at' => DateTime::now()->toIso8601String(),
            ],
        ], ['id' => (int)$parentJob->id]);
    }

    /**
     * @param \App\Model\Entity\TenantOperationJob|null $parentJob Parent operation job
     * @param array<int, array{tenant_slug: string, message: string, code: string}> $failures Tenant failures
     * @param array{batch_size: int, pause_ms: int, fail_fast: bool} $bulkConfig Bulk controls
     * @return void
     */
    private function finalizeBulkParent(
        ?TenantOperationJob $parentJob,
        int $createdCount,
        int $deduplicatedCount,
        array $failures,
        int $totalTargets,
        array $bulkConfig,
    ): void {
        if (!$parentJob instanceof TenantOperationJob || $parentJob->id === null) {
            return;
        }
        $failedCount = count($failures);
        $summary = $failedCount > 0
            ? sprintf('Bulk submission completed with %d tenant failure(s).', $failedCount)
            : sprintf('Bulk submission completed for %d tenant(s).', $totalTargets);
        $this->fetchTable('TenantOperationJobs')->updateAll([
            'state' => TenantOperationJob::STATUS_COMPLETED,
            'status' => TenantOperationJob::STATUS_COMPLETED,
            'progress_percent' => 100,
            'status_message' => $summary,
            'progress_json' => [
                'phase' => 'completed',
                'message' => $summary,
                'created_count' => $createdCount,
                'deduplicated_count' => $deduplicatedCount,
                'failed_count' => $failedCount,
                'total_targets' => $totalTargets,
                'updated_at' => DateTime::now()->toIso8601String(),
            ],
            'result_json' => [
                'summary' => $summary,
                'created_count' => $createdCount,
                'deduplicated_count' => $deduplicatedCount,
                'failed_count' => $failedCount,
                'failures' => $failures,
                'total_targets' => $totalTargets,
                'batch_size' => $bulkConfig['batch_size'],
                'pause_ms' => $bulkConfig['pause_ms'],
            ],
            'completed_at' => DateTime::now(),
        ], ['id' => (int)$parentJob->id]);
    }

    /**
     * @param string $tenantTargetMode single|selected|all-tenant
     * @param array<int, string>|null $tenantSlugs Requested tenant slugs
     * @return array<int, \App\Model\Entity\Tenant>
     */
    private function resolveTargets(string $tenantTargetMode, ?array $tenantSlugs): array
    {
        if ($tenantTargetMode === 'all-tenant') {
            $tenants = (new TenantProvisioningService())->listTenants();
            if ($tenants === []) {
                throw new RuntimeException('No tenants were found for all-tenant operation.');
            }

            return $tenants;
        }

        $normalizedSlugs = [];
        foreach ((array)$tenantSlugs as $slug) {
            $candidate = trim((string)$slug);
            if ($candidate !== '') {
                $normalizedSlugs[] = $candidate;
            }
        }
        $normalizedSlugs = array_values(array_unique($normalizedSlugs));
        if ($tenantTargetMode === 'single' && count($normalizedSlugs) !== 1) {
            throw new RuntimeException('single target mode requires exactly one tenant slug.');
        }
        if ($tenantTargetMode === 'selected' && $normalizedSlugs === []) {
            throw new RuntimeException('selected target mode requires at least one tenant slug.');
        }
        $tenants = $this->fetchTable('Tenants')->find()
            ->where(['slug IN' => $normalizedSlugs])
            ->orderByAsc('slug')
            ->all()
            ->toList();
        if (count($tenants) !== count($normalizedSlugs)) {
            $found = array_map(static fn (Tenant $tenant): string => (string)$tenant->slug, $tenants);
            $missing = array_values(array_diff($normalizedSlugs, $found));
            throw new RuntimeException(sprintf(
                'Unknown tenant slug(s): %s',
                implode(', ', $missing),
            ));
        }

        return $tenants;
    }

    /**
     * @param \App\Model\Entity\TenantOperationJob $existing Existing job
     * @param string $requestHash New request hash
     * @return void
     */
    private function assertMatchingIdempotentRequest(TenantOperationJob $existing, string $requestHash): void
    {
        $existingInput = is_array($existing->input ?? null) ? $existing->input : [];
        $existingHash = (string)($existingInput['gateway']['request_hash'] ?? '');
        if ($existingHash !== '' && hash_equals($existingHash, $requestHash)) {
            return;
        }

        throw new RuntimeException(
            'Idempotency key already exists with a different request payload.',
        );
    }

    /**
     * @param string $operation Operation
     * @param \App\Model\Entity\Tenant $tenant Target tenant
     * @param string $tenantTargetMode Target mode
     * @param array<string, mixed> $parameters Parameters
     * @return string
     */
    private function requestHash(
        string $operation,
        Tenant $tenant,
        string $tenantTargetMode,
        array $parameters,
    ): string {
        $payload = $this->sortForHash([
            'operation' => $operation,
            'tenant_id' => (int)$tenant->id,
            'tenant_slug' => (string)$tenant->slug,
            'tenant_target_mode' => $tenantTargetMode,
            'parameters' => $parameters,
        ]);
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded) || $encoded === '') {
            throw new RuntimeException('Unable to hash operation request payload.');
        }

        return hash('sha256', $encoded);
    }

    /**
     * @param string $operation Operation
     * @param \App\Model\Entity\Tenant $tenant Target tenant
     * @param string|null $providedKey Explicit key
     * @param string $requestHash Request hash
     * @return string
     */
    private function resolveIdempotencyKey(
        string $operation,
        Tenant $tenant,
        ?string $providedKey,
        string $requestHash,
    ): string {
        $candidate = trim((string)$providedKey);
        if ($candidate !== '') {
            return $candidate;
        }

        return sprintf(
            'gateway:%s:%s:%s',
            $operation,
            (string)$tenant->slug,
            substr($requestHash, 0, 24),
        );
    }

    /**
     * @param string|null $correlationId Correlation id override
     * @return string
     */
    private function resolveCorrelationId(?string $correlationId): string
    {
        $candidate = trim((string)$correlationId);
        if ($candidate !== '') {
            return $candidate;
        }

        return Text::uuid();
    }

    /**
     * @param \App\Model\Entity\Tenant $tenant Tenant entity
     * @param array<string, mixed> $parameters Validated parameters
     * @param string $tenantTargetMode Targeting mode
     * @param array<int, string> $tenantSnapshot Target snapshot
     * @param \App\Model\Entity\PlatformAdmin $requester Requester
     * @param \App\Model\Entity\PlatformAdmin $approver Approver
     * @param array<string, mixed> $approvalPolicy Approval policy snapshot
     * @param \Cake\I18n\DateTime $requestedAt Request timestamp
     * @param string $requestHash Deterministic request hash
     * @param string $idempotencyScope Idempotency scope
     * @param int $batchIndex 1-based batch index
     * @param int $batchTotal Total number of batches
     * @param int $batchSize Requested batch size
     * @return array<string, mixed>
     */
    private function buildInputPayload(
        string $operation,
        Tenant $tenant,
        array $parameters,
        string $tenantTargetMode,
        array $tenantSnapshot,
        PlatformAdmin $requester,
        PlatformAdmin $approver,
        array $approvalPolicy,
        DateTime $requestedAt,
        string $requestHash,
        string $idempotencyScope,
        int $batchIndex = 1,
        int $batchTotal = 1,
        int $batchSize = 1,
    ): array {
        return array_merge($parameters, [
            'tenant_slug' => (string)$tenant->slug,
            'gateway' => [
                'tenant_target_mode' => $tenantTargetMode,
                'tenant_snapshot' => $tenantSnapshot,
                'parameters' => $parameters,
                'requested_by_admin_id' => (int)$requester->id,
                'requested_by_email' => (string)$requester->email,
                'approved_by_admin_id' => (int)$approver->id,
                'approved_by_email' => (string)$approver->email,
                'requested_at' => $requestedAt->toIso8601String(),
                'request_hash' => $requestHash,
                'approval_policy' => $approvalPolicy,
                'catalog' => [
                    'operation' => $operation,
                    'approval_required' => TenantOperationCommandCatalog::approvalRequired($operation),
                    'idempotency_scope' => $idempotencyScope,
                    'allowed_target_modes' => TenantOperationCommandCatalog::operationConfig($operation)['allowed_target_modes'],
                ],
                'batch_index' => $batchIndex,
                'batch_total' => $batchTotal,
                'batch_size' => $batchSize,
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $payload Source data
     * @return array<string, mixed>
     */
    private function sortForHash(array $payload): array
    {
        ksort($payload);
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                /** @var array<string, mixed> $value */
                $payload[$key] = $this->sortForHash($value);
            }
        }

        return $payload;
    }

    /**
     * Validate requester and approver RBAC for gateway-submitted operations.
     */
    private function assertGatewayPrivileges(string $operation, PlatformAdmin $requester, PlatformAdmin $approver): void
    {
        $requiredCapability = TenantOperationCommandCatalog::requiredCapability($operation)
            ?? PlatformAdmin::CAPABILITY_OPERATE_TENANTS;
        if (!$requester->hasCapability($requiredCapability)) {
            throw new RuntimeException('Requester role is not permitted to submit this gateway operation.');
        }
        if (!$approver->hasCapability($requiredCapability)) {
            throw new RuntimeException('Approver role is not permitted to approve this gateway operation.');
        }
    }

    /**
     * @param array<string, mixed> $approvalPolicy Approval policy
     * @param \App\Model\Entity\PlatformAdmin $requester Requester
     * @param \App\Model\Entity\PlatformAdmin $approver Approver
     * @return int
     */
    private function initialApprovalCount(array $approvalPolicy, PlatformAdmin $requester, PlatformAdmin $approver): int
    {
        $requireRequesterSeparation = (bool)($approvalPolicy['require_requester_separation'] ?? false);
        if ($requireRequesterSeparation && (int)$requester->id === (int)$approver->id) {
            return 0;
        }

        return 1;
    }
}
