<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Model\Entity\Tenant;
use App\Model\Entity\TenantOperationJob;
use Cake\I18n\DateTime;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use Migrations\Migrations;

class HealthControllerTest extends TestCase
{
    use IntegrationTestTrait;

    private int $platformAdminId;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrations())->migrate([
            'connection' => 'test',
            'source' => 'PlatformMigrations',
        ]);
        $this->truncatePlatformTables();
        $this->platformAdminId = $this->createPlatformAdminId();
    }

    public function testHealthEndpointSerializesTenantHealthSignalsWithoutTenantRows(): void
    {
        $tenant = $this->createTenant('health-hidden', Tenant::STATUS_DRAINING, '20220101000000');
        $this->createOperationJob((int)$tenant->id, TenantOperationJob::STATUS_QUEUED);
        $this->createOperationJob((int)$tenant->id, TenantOperationJob::STATUS_FAILED, DateTime::now()->subHours(2));
        $this->seedInvalidationVersion((int)$tenant->id, DateTime::now()->subMinutes(5));

        $this->get('/health');

        $this->assertResponseOk();
        $payload = (array)json_decode((string)$this->_response->getBody(), true);
        $tenantHealth = (array)($payload['tenant_health'] ?? []);
        $this->assertTrue((bool)($tenantHealth['available'] ?? false));
        $this->assertArrayHasKey('migration', $tenantHealth);
        $this->assertArrayHasKey('operation_counts', $tenantHealth);
        $this->assertArrayHasKey('drain_status', $tenantHealth);
        $this->assertArrayHasKey('invalidation_lag', $tenantHealth);
        $this->assertArrayNotHasKey('tenant_rows', $tenantHealth);
        $this->assertStringNotContainsString('health-hidden', (string)$this->_response->getBody());
    }

    private function truncatePlatformTables(): void
    {
        $this->getTableLocator()->get('TenantOperationApprovals')->deleteAll([]);
        $this->getTableLocator()->get('TenantOperationJobs')->deleteAll([]);
        $this->getTableLocator()->get('TenantRuntimeInvalidationVersions')->deleteAll([]);
        $this->getTableLocator()->get('TenantDatabaseConfigs')->deleteAll([]);
        $this->getTableLocator()->get('TenantServiceConfigs')->deleteAll([]);
        $this->getTableLocator()->get('TenantAliases')->deleteAll([]);
        $this->getTableLocator()->get('Tenants')->deleteAll([]);
        $this->getTableLocator()->get('PlatformAdmins')->deleteAll(['email' => 'health-endpoint-admin@platform.test']);
    }

    private function createTenant(string $slug, string $status, ?string $schemaVersion): Tenant
    {
        $tenant = $this->getTableLocator()->get('Tenants')->newEntity([
            'slug' => $slug,
            'display_name' => strtoupper($slug),
            'status' => $status,
            'schema_version' => $schemaVersion,
            'primary_host' => $slug . '.localhost',
        ]);
        $this->getTableLocator()->get('Tenants')->saveOrFail($tenant);

        return $tenant;
    }

    private function createOperationJob(int $tenantId, string $state, ?DateTime $modifiedAt = null): void
    {
        $jobs = $this->getTableLocator()->get('TenantOperationJobs');
        $job = $jobs->newEntity([
            'tenant_id' => $tenantId,
            'platform_admin_id' => $this->platformAdminId,
            'operation' => 'tenant_health_test',
            'state' => $state,
            'status' => $state,
            'idempotency_scope' => 'test',
            'idempotency_key' => uniqid('health-json-job-', true),
            'input' => [],
        ]);
        $jobs->saveOrFail($job);
        if ($modifiedAt !== null) {
            $jobs->updateAll(['modified' => $modifiedAt], ['id' => (int)$job->id]);
        }
    }

    private function seedInvalidationVersion(int $tenantId, DateTime $modifiedAt): void
    {
        $versions = $this->getTableLocator()->get('TenantRuntimeInvalidationVersions');
        $record = $versions->newEntity([
            'tenant_id' => $tenantId,
            'version' => 1,
            'last_change_type' => 'health_test',
        ]);
        $versions->saveOrFail($record);
        $versions->updateAll(
            ['modified' => $modifiedAt, 'created' => $modifiedAt],
            ['tenant_id' => $tenantId],
        );
    }

    private function createPlatformAdminId(): int
    {
        $admins = $this->getTableLocator()->get('PlatformAdmins');
        $admin = $admins->newEntity([
            'email' => 'health-endpoint-admin@platform.test',
            'display_name' => 'Health Endpoint Admin',
            'password_hash' => password_hash('TestPassword!123', PASSWORD_DEFAULT),
            'status' => 'active',
            'role' => 'operator',
            'require_password_change' => false,
            'failed_attempts' => 0,
        ]);
        $admins->saveOrFail($admin);

        return (int)$admin->id;
    }
}
