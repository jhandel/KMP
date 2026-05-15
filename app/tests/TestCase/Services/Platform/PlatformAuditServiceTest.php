<?php
declare(strict_types=1);

namespace App\Test\TestCase\Services\Platform;

use App\Services\Platform\PlatformAdminAuthService;
use App\Services\Platform\PlatformAuditService;
use Cake\Http\ServerRequest;
use Cake\TestSuite\TestCase;
use Migrations\Migrations;

class PlatformAuditServiceTest extends TestCase
{
    private string|false $originalImageRepo;

    private string|false $originalImageTag;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalImageRepo = getenv('KMP_IMAGE_REPO');
        $this->originalImageTag = getenv('KMP_IMAGE_TAG');
        (new Migrations())->migrate([
            'connection' => 'test',
            'source' => 'PlatformMigrations',
        ]);
        $this->getTableLocator()->get('PlatformAuditEvents')->deleteAll([]);
        $this->getTableLocator()->get('Tenants')->deleteAll(['slug LIKE' => 'audit-service-%']);
        $this->getTableLocator()->get('PlatformAdmins')->deleteAll(['email LIKE' => '%@platform.test']);
    }

    protected function tearDown(): void
    {
        if ($this->originalImageRepo === false) {
            putenv('KMP_IMAGE_REPO');
        } else {
            putenv('KMP_IMAGE_REPO=' . $this->originalImageRepo);
        }
        if ($this->originalImageTag === false) {
            putenv('KMP_IMAGE_TAG');
        } else {
            putenv('KMP_IMAGE_TAG=' . $this->originalImageTag);
        }
        parent::tearDown();
    }

    public function testRecordPopulatesCreatedTimestamp(): void
    {
        $event = (new PlatformAuditService())->record(
            'platform_admin.login',
            'failure',
            ['email' => 'platform-admin@example.test'],
            null,
            new ServerRequest(['url' => '/platform-admin/login']),
        );

        $this->assertNotNull($event->created);
        $this->assertSame('platform_admin.login', $event->action);
        $this->assertSame('failure', $event->result);
    }

    public function testRecordRedactsManagedSecretFields(): void
    {
        $event = (new PlatformAuditService())->record(
            'tenant.secret_update',
            'success',
            [
                'database_secret_value' => 'db-secret',
                'email_secret_value' => 'smtp-secret',
                'storage_secret_value' => 'storage-secret',
                'nested' => ['access_token' => 'top-secret'],
                'smtpPassword' => 'secret-password',
            ],
            null,
            new ServerRequest(['url' => '/platform-admin/tenants/test/secrets']),
        );
        $event = $this->getTableLocator()->get('PlatformAuditEvents')->get($event->id);
        $metadata = is_array($event->metadata) ? $event->metadata : json_decode((string)$event->metadata, true);

        $this->assertSame('[redacted]', $metadata['database_secret_value']);
        $this->assertSame('[redacted]', $metadata['email_secret_value']);
        $this->assertSame('[redacted]', $metadata['storage_secret_value']);
        $this->assertSame('[redacted]', $metadata['nested']['access_token']);
        $this->assertSame('[redacted]', $metadata['smtpPassword']);
    }

    public function testRecordIncludesStructuredContextFields(): void
    {
        putenv('KMP_IMAGE_REPO=ghcr.io/kmp/app');
        putenv('KMP_IMAGE_TAG=v2026.05.01');
        $admin = (new PlatformAdminAuthService())->createAdmin(
            'audit-context@platform.test',
            'Audit Context',
            'InitialPlatformPassword!',
        )['admin'];
        $tenant = $this->createAuditTenant('audit-service-context');
        $request = (new ServerRequest(['url' => '/platform-admin/tenants/create']))
            ->withAttribute('requestId', 'req-structured-123');
        $event = (new PlatformAuditService())->record(
            'tenant.create',
            'success',
            ['subject_type' => 'tenant', 'subject_id' => (string)$tenant->id],
            $admin,
            $request,
            (int)$tenant->id,
            'platform_admin',
            ['tenant_slug' => 'ansteorra', 'operation_id' => '27'],
        );
        $event = $this->getTableLocator()->get('PlatformAuditEvents')->get($event->id);
        $metadata = is_array($event->metadata) ? $event->metadata : json_decode((string)$event->metadata, true);

        $this->assertSame('req-structured-123', $event->request_id);
        $this->assertSame((int)$tenant->id, (int)$event->tenant_id);
        $this->assertSame('req-structured-123', $metadata['request_id']);
        $this->assertSame((int)$tenant->id, (int)$metadata['tenant_id']);
        $this->assertSame('ansteorra', $metadata['tenant_slug']);
        $this->assertSame('27', $metadata['operation_id']);
        $this->assertSame((int)$admin->id, (int)$metadata['platform_admin_id']);
        $this->assertSame('req-structured-123', $metadata['correlation_id']);
        $this->assertSame('ghcr.io/kmp/app', $metadata['operation_image']);
        $this->assertSame('v2026.05.01', $metadata['operation_version']);
    }

    public function testRecordPreservesExplicitOperationCorrelationContract(): void
    {
        $tenant = $this->createAuditTenant('audit-service-correlation');
        $request = (new ServerRequest(['url' => '/platform-admin/tenants/create']))
            ->withAttribute('requestId', 'req-audit-fallback');
        $event = (new PlatformAuditService())->record(
            'tenant.create',
            'success',
            ['subject_type' => 'tenant', 'subject_id' => (string)$tenant->id],
            null,
            $request,
            (int)$tenant->id,
            'platform_admin',
            ['operation_id' => '777', 'correlation_id' => 'corr-job-777'],
        );
        $event = $this->getTableLocator()->get('PlatformAuditEvents')->get($event->id);
        $metadata = is_array($event->metadata) ? $event->metadata : json_decode((string)$event->metadata, true);

        $this->assertSame('777', $metadata['operation_id']);
        $this->assertSame('corr-job-777', $metadata['correlation_id']);
        $this->assertSame('req-audit-fallback', $event->request_id);
    }

    public function testRecordLinksToPreviousHashChain(): void
    {
        $first = (new PlatformAuditService())->record(
            'platform_admin.login',
            'success',
            ['subject_type' => 'platform_admin', 'subject_id' => '11'],
            null,
            new ServerRequest(['url' => '/platform-admin/login']),
        );
        $second = (new PlatformAuditService())->record(
            'tenant.status',
            'success',
            ['subject_type' => 'tenant', 'subject_id' => '22'],
            null,
            new ServerRequest(['url' => '/platform-admin/tenants']),
        );
        $second = $this->getTableLocator()->get('PlatformAuditEvents')->get($second->id);

        $this->assertSame((string)$first->event_hash, (string)$second->previous_hash);
    }

    private function createAuditTenant(string $slug): object
    {
        $tenants = $this->getTableLocator()->get('Tenants');
        $tenant = $tenants->newEntity([
            'slug' => $slug . '-' . uniqid(),
            'display_name' => 'Audit Service Tenant',
            'status' => 'active',
            'primary_host' => $slug . '.localhost',
        ]);
        $tenants->saveOrFail($tenant);

        return $tenant;
    }
}
