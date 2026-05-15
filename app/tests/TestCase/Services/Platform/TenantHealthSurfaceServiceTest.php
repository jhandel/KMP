<?php
declare(strict_types=1);

namespace App\Test\TestCase\Services\Platform;

use App\Model\Entity\Tenant;
use App\Model\Entity\TenantOperationJob;
use App\Services\Platform\TenantHealthSurfaceService;
use App\Services\Tenant\TenantMigrationService;
use Cake\I18n\DateTime;
use Cake\TestSuite\TestCase;
use Migrations\Migrations;

class TenantHealthSurfaceServiceTest extends TestCase
{
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

    public function testBuildAdminHealthSummaryComposesTenantSignals(): void
    {
        $targetSchemaVersion = (new TenantMigrationService())->targetSchemaVersion();
        $healthyTenant = $this->createTenant('health-ok', Tenant::STATUS_ACTIVE, $targetSchemaVersion);
        $attentionTenant = $this->createTenant('health-attention', Tenant::STATUS_DRAINING, '20210101000000');

        $this->createOperationJob((int)$healthyTenant->id, TenantOperationJob::STATUS_COMPLETED);
        $this->createOperationJob((int)$attentionTenant->id, TenantOperationJob::STATUS_QUEUED);
        $this->createOperationJob((int)$attentionTenant->id, TenantOperationJob::STATUS_FAILED, DateTime::now()->subHours(1));
        $this->createOperationJob(
            (int)$attentionTenant->id,
            TenantOperationJob::STATUS_RUNNING,
            DateTime::now()->subHours(2),
            DateTime::now()->subMinutes(2),
        );
        $this->seedInvalidationVersion((int)$attentionTenant->id, 1, DateTime::now()->subMinutes(10));

        $summary = (new TenantHealthSurfaceService())->buildAdminHealthSummary();

        $this->assertTrue((bool)$summary['available']);
        $this->assertSame(1, (int)$summary['migration']['outdated']);
        $this->assertSame(1, (int)$summary['drain_status']['draining']);
        $this->assertSame(2, (int)$summary['operation_counts']['backlog']);
        $this->assertSame(1, (int)$summary['operation_counts']['recent_failures_24h']);
        $this->assertSame(1, (int)$summary['operation_counts']['stale_running_leases']);
        $this->assertTrue((bool)$summary['invalidation_lag']['available']);
        $this->assertSame('health-attention', (string)$summary['tenant_rows'][0]['slug']);
        $this->assertSame('outdated', (string)$summary['tenant_rows'][0]['migration_state']);
        $this->assertSame(2, (int)$summary['tenant_rows'][0]['operation_backlog']);
    }

    public function testBuildPublicHealthSummaryExcludesTenantRows(): void
    {
        $this->createTenant('health-public', Tenant::STATUS_ACTIVE, null);

        $summary = (new TenantHealthSurfaceService())->buildPublicHealthSummary();

        $this->assertTrue((bool)$summary['available']);
        $this->assertArrayNotHasKey('tenant_rows', $summary);
        $this->assertArrayHasKey('migration', $summary);
        $this->assertArrayHasKey('operation_counts', $summary);
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
        $this->getTableLocator()->get('PlatformAdmins')->deleteAll(['email' => 'tenant-health-admin@platform.test']);
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

    private function createOperationJob(
        int $tenantId,
        string $state,
        ?DateTime $modifiedAt = null,
        ?DateTime $leaseExpiresAt = null,
    ): void {
        $jobs = $this->getTableLocator()->get('TenantOperationJobs');
        $job = $jobs->newEntity([
            'tenant_id' => $tenantId,
            'platform_admin_id' => $this->platformAdminId,
            'operation' => 'tenant_health_test',
            'state' => $state,
            'status' => $state,
            'idempotency_scope' => 'test',
            'idempotency_key' => uniqid('health-job-', true),
            'lease_expires_at' => $leaseExpiresAt,
            'input' => [],
        ]);
        $jobs->saveOrFail($job);
        if ($modifiedAt !== null) {
            $jobs->updateAll(['modified' => $modifiedAt], ['id' => (int)$job->id]);
        }
    }

    private function seedInvalidationVersion(int $tenantId, int $version, DateTime $modifiedAt): void
    {
        $versions = $this->getTableLocator()->get('TenantRuntimeInvalidationVersions');
        $record = $versions->newEntity([
            'tenant_id' => $tenantId,
            'version' => $version,
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
            'email' => 'tenant-health-admin@platform.test',
            'display_name' => 'Tenant Health Admin',
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
