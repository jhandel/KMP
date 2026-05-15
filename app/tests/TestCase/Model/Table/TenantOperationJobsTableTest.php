<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Table;

use App\Model\Entity\TenantOperationJob;
use App\Model\Table\TenantOperationJobsTable;
use App\Test\TestCase\BaseTestCase;

class TenantOperationJobsTableTest extends BaseTestCase
{
    /**
     * @var \App\Model\Table\TenantOperationJobsTable
     */
    protected $TenantOperationJobs;

    protected function setUp(): void
    {
        parent::setUp();
        $config = $this->getTableLocator()->exists('TenantOperationJobs')
            ? []
            : ['className' => TenantOperationJobsTable::class];
        $this->TenantOperationJobs = $this->getTableLocator()->get('TenantOperationJobs', $config);
    }

    public function testValidationRejectsInvalidStateAndProgress(): void
    {
        $job = $this->TenantOperationJobs->newEntity([
            'operation' => 'tenant_create',
            'state' => 'not-a-state',
            'status' => TenantOperationJob::STATUS_RUNNING,
            'progress_percent' => 101,
        ]);

        $this->assertNotEmpty($job->getErrors()['state']);
        $this->assertNotEmpty($job->getErrors()['progress_percent']);
    }

    public function testValidationAcceptsCanonicalStateMetadata(): void
    {
        $job = $this->TenantOperationJobs->newEntity([
            'operation' => 'tenant_create',
            'state' => TenantOperationJob::STATUS_APPROVAL_REQUIRED,
            'parent_tenant_operation_job_id' => 123,
            'idempotency_scope' => 'tenant',
            'idempotency_key' => 'create:test-tenant',
            'status_message' => 'Waiting for approval',
            'progress_percent' => 40,
        ]);

        $this->assertEmpty($job->getErrors());
    }

    public function testBeforeMarshalSyncsStatusAndState(): void
    {
        $stateOnly = $this->TenantOperationJobs->newEntity([
            'operation' => 'tenant_create',
            'state' => TenantOperationJob::STATUS_BLOCKED,
        ]);
        $this->assertSame(TenantOperationJob::STATUS_BLOCKED, $stateOnly->status);

        $statusOnly = $this->TenantOperationJobs->newEntity([
            'operation' => 'tenant_create',
            'status' => TenantOperationJob::STATUS_APPROVED,
        ]);
        $this->assertSame(TenantOperationJob::STATUS_APPROVED, $statusOnly->state);
    }

    public function testSaveOrFailRejectsDuplicateIdempotencyTuple(): void
    {
        $admins = $this->getTableLocator()->get('PlatformAdmins');
        $admin = $admins->newEntity([
            'email' => sprintf('idempotency-%s@example.test', uniqid()),
            'display_name' => 'Idempotency Admin',
            'password_hash' => '$2y$10$examplehashforidempotency0000000000000000',
            'status' => 'active',
            'role' => 'operator',
            'require_password_change' => false,
            'failed_attempts' => 0,
        ]);
        $admins->saveOrFail($admin);
        $tenants = $this->getTableLocator()->get('Tenants');
        $tenant = $tenants->find()
            ->select(['id'])
            ->where(['slug' => 'test'])
            ->enableHydration(false)
            ->first();
        if ($tenant === null) {
            $createdTenant = $tenants->newEntity([
                'slug' => 'test',
                'display_name' => 'Test Tenant',
                'status' => 'active',
                'primary_host' => 'test.example.test',
            ]);
            $tenants->saveOrFail($createdTenant);
            $tenantId = (int)$createdTenant->id;
        } else {
            $tenantId = (int)$tenant['id'];
        }
        $idempotencyKey = uniqid('op-idem-', true);
        $first = $this->TenantOperationJobs->newEntity([
            'operation' => 'tenant_create',
            'tenant_id' => $tenantId,
            'platform_admin_id' => (int)$admin->id,
            'state' => TenantOperationJob::STATUS_QUEUED,
            'status' => TenantOperationJob::STATUS_QUEUED,
            'idempotency_scope' => 'tenant',
            'idempotency_key' => $idempotencyKey,
            'input' => [],
        ]);
        $this->TenantOperationJobs->saveOrFail($first);

        $duplicate = $this->TenantOperationJobs->newEntity([
            'operation' => 'tenant_create',
            'tenant_id' => $tenantId,
            'platform_admin_id' => (int)$admin->id,
            'state' => TenantOperationJob::STATUS_QUEUED,
            'status' => TenantOperationJob::STATUS_QUEUED,
            'idempotency_scope' => 'tenant',
            'idempotency_key' => $idempotencyKey,
            'input' => [],
        ]);

        try {
            $this->TenantOperationJobs->saveOrFail($duplicate);
            $this->fail('Expected duplicate idempotency key to fail.');
        } catch (\Throwable $exception) {
            $this->assertStringContainsStringIgnoringCase('duplicate', $exception->getMessage());
        }
    }
}
