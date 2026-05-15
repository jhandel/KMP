<?php
declare(strict_types=1);

namespace App\Test\TestCase\Services\Platform;

use App\Model\Entity\PlatformAdmin;
use App\Model\Entity\Tenant;
use App\Model\Entity\TenantOperationJob;
use App\Services\Platform\TenantOperationGatewayService;
use Cake\Datasource\ConnectionManager;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;
use Migrations\Migrations;
use RuntimeException;

class TenantOperationGatewayServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (new Migrations())->migrate([
            'connection' => 'test',
            'source' => 'PlatformMigrations',
        ]);
        $this->truncatePlatformTables();
    }

    public function testSubmitApprovedRequestCreatesMetadataAndSupportsIdempotentReplay(): void
    {
        $tenant = $this->createTenant('gateway-tenant');
        $requester = $this->createPlatformAdmin('requester', PlatformAdmin::ROLE_OPERATOR);
        $approver = $this->createPlatformAdmin('approver', PlatformAdmin::ROLE_OPERATOR);
        $service = new TenantOperationGatewayService();

        $first = $service->submitApprovedRequest(
            operation: 'tenant_status',
            requester: $requester,
            tenantTargetMode: 'single',
            parameters: ['status' => Tenant::STATUS_MAINTENANCE],
            tenantSlugs: [(string)$tenant->slug],
            approvedBy: $approver,
            correlationId: 'corr-gateway-test',
            idempotencyKey: 'tenant-status:gateway-tenant:maintenance',
        );

        $this->assertSame(1, (int)$first['created_count']);
        $this->assertSame(0, (int)$first['deduplicated_count']);
        $this->assertSame('corr-gateway-test', (string)$first['correlation_id']);
        $job = $this->getTableLocator()->get('TenantOperationJobs')->get((int)$first['jobs'][0]->id);
        $this->assertSame(TenantOperationJob::STATUS_APPROVED, (string)$job->state);
        $this->assertSame((int)$requester->id, (int)$job->platform_admin_id);
        $this->assertSame((int)$tenant->id, (int)$job->tenant_id);
        $this->assertSame('corr-gateway-test', (string)$job->operation_correlation_id);
        $this->assertSame('tenant', (string)$job->idempotency_scope);
        $this->assertSame('tenant-status:gateway-tenant:maintenance', (string)$job->idempotency_key);
        $this->assertSame('single', (string)$job->input['gateway']['tenant_target_mode']);
        $this->assertSame((int)$requester->id, (int)$job->input['gateway']['requested_by_admin_id']);
        $this->assertSame((int)$approver->id, (int)$job->input['gateway']['approved_by_admin_id']);
        $this->assertSame(true, (bool)$job->input['gateway']['catalog']['approval_required']);
        $this->assertSame('tenant', (string)$job->input['gateway']['catalog']['idempotency_scope']);
        $this->assertContains('single', (array)$job->input['gateway']['catalog']['allowed_target_modes']);
        $this->assertSame(Tenant::STATUS_MAINTENANCE, (string)$job->input['status']);
        $this->assertNotEmpty((string)($job->input['gateway']['request_hash'] ?? ''));
        $this->assertSame(
            1,
            $this->getTableLocator()->get('TenantOperationApprovals')->find()->where([
                'tenant_operation_job_id' => (int)$job->id,
                'platform_admin_id' => (int)$approver->id,
                'approval_type' => 'gateway_approved',
            ])->count(),
        );

        $second = $service->submitApprovedRequest(
            operation: 'tenant_status',
            requester: $requester,
            tenantTargetMode: 'single',
            parameters: ['status' => Tenant::STATUS_MAINTENANCE],
            tenantSlugs: [(string)$tenant->slug],
            approvedBy: $approver,
            correlationId: 'corr-gateway-test',
            idempotencyKey: 'tenant-status:gateway-tenant:maintenance',
        );

        $this->assertSame(0, (int)$second['created_count']);
        $this->assertSame(1, (int)$second['deduplicated_count']);
        $this->assertSame((int)$job->id, (int)$second['jobs'][0]->id);
    }

    public function testSubmitApprovedRequestRejectsIdempotencyConflicts(): void
    {
        $tenant = $this->createTenant('gateway-conflict');
        $requester = $this->createPlatformAdmin('requester-conflict', PlatformAdmin::ROLE_OPERATOR);
        $service = new TenantOperationGatewayService();
        $service->submitApprovedRequest(
            operation: 'tenant_status',
            requester: $requester,
            tenantTargetMode: 'single',
            parameters: ['status' => Tenant::STATUS_MAINTENANCE],
            tenantSlugs: [(string)$tenant->slug],
            approvedBy: $requester,
            idempotencyKey: 'tenant-status:conflict',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Idempotency key already exists with a different request payload.');
        $service->submitApprovedRequest(
            operation: 'tenant_status',
            requester: $requester,
            tenantTargetMode: 'single',
            parameters: ['status' => Tenant::STATUS_DISABLED],
            tenantSlugs: [(string)$tenant->slug],
            approvedBy: $requester,
            idempotencyKey: 'tenant-status:conflict',
        );
    }

    public function testSubmitApprovedRequestBatchesSelectedTargetsAndCreatesParentProgressJob(): void
    {
        $tenantA = $this->createTenant('gateway-batch-a');
        $tenantB = $this->createTenant('gateway-batch-b');
        $tenantC = $this->createTenant('gateway-batch-c');
        $requester = $this->createPlatformAdmin('requester-batch');
        $service = new TenantOperationGatewayService();

        $result = $service->submitApprovedRequest(
            operation: 'tenant_status',
            requester: $requester,
            tenantTargetMode: 'selected',
            parameters: ['status' => Tenant::STATUS_MAINTENANCE],
            tenantSlugs: [(string)$tenantA->slug, (string)$tenantB->slug, (string)$tenantC->slug],
            approvedBy: $requester,
            correlationId: 'corr-gateway-batch',
            idempotencyScope: 'tenant',
            bulkOptions: [
                'batch_size' => 2,
                'pause_ms' => 0,
                'continue_on_error' => true,
            ],
        );

        $this->assertSame(3, (int)$result['created_count']);
        $this->assertSame(0, (int)$result['failed_count']);
        $this->assertNotNull($result['parent_job_id']);
        $jobs = $this->getTableLocator()->get('TenantOperationJobs');
        $parent = $jobs->get((int)$result['parent_job_id']);
        $this->assertSame('tenant_status_bulk_submit', (string)$parent->operation);
        $this->assertSame(TenantOperationJob::STATUS_COMPLETED, (string)$parent->state);
        $this->assertSame(100, (int)$parent->progress_percent);
        $this->assertSame(3, (int)($parent->result_json['total_targets'] ?? 0));
        $this->assertSame(2, (int)($parent->result_json['batch_size'] ?? 0));
    }

    public function testSubmitApprovedRequestIsolatesPerTenantFailureWhenContinueOnErrorEnabled(): void
    {
        $tenantA = $this->createTenant('gateway-partial-a');
        $tenantB = $this->createTenant('gateway-partial-b');
        $requester = $this->createPlatformAdmin('requester-partial');
        $service = new TenantOperationGatewayService();

        $service->submitApprovedRequest(
            operation: 'tenant_status',
            requester: $requester,
            tenantTargetMode: 'single',
            parameters: ['status' => Tenant::STATUS_ACTIVE],
            tenantSlugs: [(string)$tenantA->slug],
            approvedBy: $requester,
            idempotencyKey: 'tenant-status:partial-test',
        );

        $result = $service->submitApprovedRequest(
            operation: 'tenant_status',
            requester: $requester,
            tenantTargetMode: 'selected',
            parameters: ['status' => Tenant::STATUS_MAINTENANCE],
            tenantSlugs: [(string)$tenantA->slug, (string)$tenantB->slug],
            approvedBy: $requester,
            idempotencyKey: 'tenant-status:partial-test',
            bulkOptions: [
                'batch_size' => 1,
                'continue_on_error' => true,
            ],
        );

        $this->assertSame(1, (int)$result['created_count']);
        $this->assertSame(1, (int)$result['failed_count']);
        $this->assertCount(1, (array)$result['failures']);
        $this->assertSame((string)$tenantA->slug, (string)$result['failures'][0]['tenant_slug']);
        $this->assertStringContainsString(
            'Idempotency key already exists with a different request payload.',
            (string)$result['failures'][0]['message'],
        );
        $createdTenantSlugs = array_map(
            static fn (TenantOperationJob $job): string => (string)($job->input['tenant_slug'] ?? ''),
            $result['jobs'],
        );
        $this->assertContains((string)$tenantB->slug, $createdTenantSlugs);
    }

    public function testSubmitApprovedRequestSupportsTenantDatabaseSecretRotation(): void
    {
        $tenant = $this->createTenant('gateway-rotate-db');
        $admin = $this->createPlatformAdmin('rotate-db', PlatformAdmin::ROLE_SECURITY_ADMIN);
        $service = new TenantOperationGatewayService();

        $submission = $service->submitApprovedRequest(
            operation: 'tenant_rotate_db_secret',
            requester: $admin,
            tenantTargetMode: 'single',
            parameters: [
                'new_secret_reference' => 'managed:tenant/42/database/primary/rotation/abc123',
                'max_attempts' => 1,
            ],
            tenantSlugs: [(string)$tenant->slug],
            approvedBy: $admin,
            idempotencyKey: 'tenant-rotate-db:gateway-rotate-db:abc123',
        );

        $this->assertSame(1, (int)$submission['created_count']);
        $job = $this->getTableLocator()->get('TenantOperationJobs')->get((int)$submission['jobs'][0]->id);
        $this->assertSame('tenant_rotate_db_secret', (string)$job->operation);
        $this->assertSame(
            'managed:tenant/42/database/primary/rotation/abc123',
            (string)($job->input['new_secret_reference'] ?? ''),
        );
        $this->assertSame(1, (int)($job->input['max_attempts'] ?? 0));
    }

    public function testSubmitApprovedRequestRejectsTenantDatabaseSecretRotationWithoutSecretsCapability(): void
    {
        $tenant = $this->createTenant('gateway-rotate-rbac');
        $requester = $this->createPlatformAdmin('rotate-requester', PlatformAdmin::ROLE_OPERATOR);
        $approver = $this->createPlatformAdmin('rotate-approver', PlatformAdmin::ROLE_OPERATOR);
        $service = new TenantOperationGatewayService();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Requester role is not permitted to submit this gateway operation.');
        $service->submitApprovedRequest(
            operation: 'tenant_rotate_db_secret',
            requester: $requester,
            tenantTargetMode: 'single',
            parameters: [
                'new_secret_reference' => 'managed:tenant/99/database/primary/rotation/rbac',
                'max_attempts' => 1,
            ],
            tenantSlugs: [(string)$tenant->slug],
            approvedBy: $approver,
            idempotencyKey: 'tenant-rotate-db:gateway-rotate-rbac:1',
        );
    }

    public function testSubmitApprovedRequestAppliesOptionalDefaultsAndStoresCatalogApprovalPolicy(): void
    {
        $tenant = $this->createTenant('gateway-doctor');
        $admin = $this->createPlatformAdmin('doctor-admin');
        $service = new TenantOperationGatewayService();

        $submission = $service->submitApprovedRequest(
            operation: 'tenant_doctor',
            requester: $admin,
            tenantTargetMode: 'single',
            parameters: [],
            tenantSlugs: [(string)$tenant->slug],
            approvedBy: $admin,
        );

        $this->assertSame(1, (int)$submission['created_count']);
        $job = $this->getTableLocator()->get('TenantOperationJobs')->get((int)$submission['jobs'][0]->id);
        $this->assertSame(false, (bool)($job->input['gateway']['catalog']['approval_required'] ?? true));
        $this->assertSame([], (array)($job->input['gateway']['parameters'] ?? ['unexpected']));
    }

    public function testSubmitApprovedRequestTracksTwoPersonApprovalStateForSensitiveOperations(): void
    {
        $tenant = $this->createTenant('gateway-two-person');
        $requester = $this->createPlatformAdmin('gateway-two-person-requester', PlatformAdmin::ROLE_PROVISIONER);
        $approver = $this->createPlatformAdmin('gateway-two-person-approver', PlatformAdmin::ROLE_PROVISIONER);
        $service = new TenantOperationGatewayService();

        $selfApprovedSubmission = $service->submitApprovedRequest(
            operation: 'tenant_migrate',
            requester: $requester,
            tenantTargetMode: 'single',
            parameters: [],
            tenantSlugs: [(string)$tenant->slug],
            approvedBy: $requester,
            idempotencyKey: 'tenant-migrate:gateway-two-person:self',
        );

        $selfApprovedJob = $this->getTableLocator()->get('TenantOperationJobs')->get((int)$selfApprovedSubmission['jobs'][0]->id);
        $this->assertSame(TenantOperationJob::STATUS_APPROVAL_REQUIRED, (string)$selfApprovedJob->state);
        $this->assertSame(2, (int)($selfApprovedJob->approvals_required ?? 0));
        $this->assertSame(0, (int)($selfApprovedJob->approvals_received ?? 0));

        $distinctApproverSubmission = $service->submitApprovedRequest(
            operation: 'tenant_migrate',
            requester: $requester,
            tenantTargetMode: 'single',
            parameters: [],
            tenantSlugs: [(string)$tenant->slug],
            approvedBy: $approver,
            idempotencyKey: 'tenant-migrate:gateway-two-person:distinct',
        );

        $distinctApproverJob = $this->getTableLocator()->get('TenantOperationJobs')->get((int)$distinctApproverSubmission['jobs'][0]->id);
        $this->assertSame(TenantOperationJob::STATUS_APPROVAL_REQUIRED, (string)$distinctApproverJob->state);
        $this->assertSame(2, (int)($distinctApproverJob->approvals_required ?? 0));
        $this->assertSame(1, (int)($distinctApproverJob->approvals_received ?? 0));
    }

    public function testSubmitApprovedRequestRejectsMissingRequiredParameterWithOperatorError(): void
    {
        $tenant = $this->createTenant('gateway-required-param');
        $admin = $this->createPlatformAdmin('required-param-admin');
        $service = new TenantOperationGatewayService();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Operation "tenant_status" requires parameter "status".');
        $service->submitApprovedRequest(
            operation: 'tenant_status',
            requester: $admin,
            tenantTargetMode: 'single',
            parameters: [],
            tenantSlugs: [(string)$tenant->slug],
            approvedBy: $admin,
        );
    }

    public function testSubmitApprovedRequestRejectsRotateSecretForNonSingleTargetMode(): void
    {
        $tenant = $this->createTenant('gateway-rotate-mode');
        $admin = $this->createPlatformAdmin('rotate-mode');
        $service = new TenantOperationGatewayService();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not support target mode');
        $service->submitApprovedRequest(
            operation: 'tenant_rotate_db_secret',
            requester: $admin,
            tenantTargetMode: 'selected',
            parameters: ['new_secret_reference' => 'managed:tenant/77/database/primary/rotation/xyz'],
            tenantSlugs: [(string)$tenant->slug],
            approvedBy: $admin,
            idempotencyKey: 'tenant-rotate-db:gateway-rotate-mode:xyz',
        );
    }

    public function testSubmitApprovedRequestRejectsRequesterWithoutRoleCapability(): void
    {
        $tenant = $this->createTenant('gateway-rbac-requester');
        $requester = $this->createPlatformAdmin('viewer-requester', PlatformAdmin::ROLE_VIEWER);
        $approver = $this->createPlatformAdmin('operator-approver', PlatformAdmin::ROLE_OPERATOR);
        $service = new TenantOperationGatewayService();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Requester role is not permitted to submit this gateway operation.');
        $service->submitApprovedRequest(
            operation: 'tenant_status',
            requester: $requester,
            tenantTargetMode: 'single',
            parameters: ['status' => Tenant::STATUS_MAINTENANCE],
            tenantSlugs: [(string)$tenant->slug],
            approvedBy: $approver,
        );
    }

    public function testSubmitApprovedRequestRejectsApproverWithoutRoleCapability(): void
    {
        $tenant = $this->createTenant('gateway-rbac-approver');
        $requester = $this->createPlatformAdmin('operator-requester', PlatformAdmin::ROLE_OPERATOR);
        $approver = $this->createPlatformAdmin('viewer-approver', PlatformAdmin::ROLE_VIEWER);
        $service = new TenantOperationGatewayService();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Approver role is not permitted to approve this gateway operation.');
        $service->submitApprovedRequest(
            operation: 'tenant_status',
            requester: $requester,
            tenantTargetMode: 'single',
            parameters: ['status' => Tenant::STATUS_MAINTENANCE],
            tenantSlugs: [(string)$tenant->slug],
            approvedBy: $approver,
        );
    }

    public function testSubmitApprovedRequestRejectsDisallowedOperationWithOperatorError(): void
    {
        $tenant = $this->createTenant('gateway-disallowed-op');
        $requester = $this->createPlatformAdmin('requester-disallowed-op');
        $service = new TenantOperationGatewayService();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown operation "tenant_backup". Allowed operations: tenant_doctor, tenant_migrate, tenant_rotate_db_secret, tenant_status.');
        $service->submitApprovedRequest(
            operation: 'tenant_backup',
            requester: $requester,
            tenantTargetMode: 'single',
            parameters: [],
            tenantSlugs: [(string)$tenant->slug],
            approvedBy: $requester,
        );
    }

    public function testSubmitApprovedRequestRejectsUnknownParametersWithOperatorError(): void
    {
        $tenant = $this->createTenant('gateway-bad-params');
        $requester = $this->createPlatformAdmin('requester-bad-params');
        $service = new TenantOperationGatewayService();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Operation "tenant_doctor" received unsupported parameter(s): unexpected_flag.');
        $service->submitApprovedRequest(
            operation: 'tenant_doctor',
            requester: $requester,
            tenantTargetMode: 'single',
            parameters: ['unexpected_flag' => true],
            tenantSlugs: [(string)$tenant->slug],
            approvedBy: $requester,
        );
    }

    public function testSubmitApprovedRequestRejectsInvalidIdempotencyScope(): void
    {
        $tenant = $this->createTenant('gateway-bad-scope');
        $requester = $this->createPlatformAdmin('requester-bad-scope');
        $service = new TenantOperationGatewayService();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Operation "tenant_status" requires idempotency scope "tenant" (received "platform").');
        $service->submitApprovedRequest(
            operation: 'tenant_status',
            requester: $requester,
            tenantTargetMode: 'single',
            parameters: ['status' => Tenant::STATUS_MAINTENANCE],
            tenantSlugs: [(string)$tenant->slug],
            approvedBy: $requester,
            idempotencyScope: 'platform',
        );
    }

    /**
     * @return void
     */
    private function truncatePlatformTables(): void
    {
        $connection = ConnectionManager::get('test');
        foreach ([
            'tenant_operation_approvals',
            'tenant_operation_jobs',
            'tenant_database_configs',
            'tenant_service_configs',
            'tenant_aliases',
            'tenants',
            'platform_admins',
        ] as $table) {
            $connection->execute(sprintf('DELETE FROM %s', $connection->getDriver()->quoteIdentifier($table)));
        }
        TableRegistry::getTableLocator()->clear();
    }

    /**
     * @param string $slug Tenant slug
     * @return \App\Model\Entity\Tenant
     */
    private function createTenant(string $slug): Tenant
    {
        $tenant = $this->getTableLocator()->get('Tenants')->newEntity([
            'slug' => $slug,
            'display_name' => ucfirst(str_replace('-', ' ', $slug)),
            'status' => Tenant::STATUS_ACTIVE,
            'primary_host' => $slug . '.example.test',
        ]);
        $this->getTableLocator()->get('Tenants')->saveOrFail($tenant);

        return $tenant;
    }

    /**
     * @param string $prefix Email prefix
     * @return \App\Model\Entity\PlatformAdmin
     */
    private function createPlatformAdmin(string $prefix, string $role = PlatformAdmin::ROLE_BREAK_GLASS): PlatformAdmin
    {
        $admin = $this->getTableLocator()->get('PlatformAdmins')->newEntity([
            'email' => sprintf('%s-%s@example.test', $prefix, uniqid()),
            'display_name' => 'Gateway Admin',
            'password_hash' => '$2y$10$examplehashforgateway0000000000000000000000000',
            'status' => PlatformAdmin::STATUS_ACTIVE,
            'role' => $role,
            'require_password_change' => false,
            'failed_attempts' => 0,
        ]);
        $this->getTableLocator()->get('PlatformAdmins')->saveOrFail($admin);

        return $admin;
    }
}
