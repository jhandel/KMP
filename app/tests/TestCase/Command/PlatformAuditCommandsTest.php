<?php
declare(strict_types=1);

namespace App\Test\TestCase\Command;

use App\Services\Platform\PlatformAdminAuthService;
use App\Services\Platform\PlatformAuditService;
use Cake\Console\TestSuite\ConsoleIntegrationTestTrait;
use Cake\Http\ServerRequest;
use Cake\I18n\DateTime;
use Cake\TestSuite\TestCase;
use Migrations\Migrations;

class PlatformAuditCommandsTest extends TestCase
{
    use ConsoleIntegrationTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrations())->migrate([
            'connection' => 'test',
            'source' => 'PlatformMigrations',
        ]);
        $this->getTableLocator()->get('PlatformAuditRetentionAnchors')->deleteAll([]);
        $this->getTableLocator()->get('PlatformAuditEvents')->deleteAll([]);
        $this->getTableLocator()->get('TenantOperationJobs')->deleteAll([]);
        $this->getTableLocator()->get('Tenants')->deleteAll([]);
        $this->getTableLocator()->get('PlatformAdmins')->deleteAll(['email LIKE' => '%@platform.test']);
    }

    public function testPlatformAuditExportCommandWritesFilteredJsonl(): void
    {
        $admin = (new PlatformAdminAuthService())->createAdmin(
            'audit-export@platform.test',
            'Audit Export',
            'InitialPlatformPassword!',
        )['admin'];
        $tenant = $this->getTableLocator()->get('Tenants')->newEntity([
            'slug' => 'audit-export-' . uniqid(),
            'display_name' => 'Audit Export Tenant',
            'status' => 'active',
            'primary_host' => 'audit-export.localhost',
        ]);
        $this->getTableLocator()->get('Tenants')->saveOrFail($tenant);

        (new PlatformAuditService())->record(
            'tenant.status',
            'success',
            ['subject_type' => 'tenant', 'subject_id' => (string)$tenant->id],
            $admin,
            (new ServerRequest(['url' => '/platform-admin/audit']))->withAttribute('requestId', 'req-export-1'),
            (int)$tenant->id,
            'platform_admin',
            ['tenant_slug' => (string)$tenant->slug, 'correlation_id' => 'corr-export-1'],
        );
        (new PlatformAuditService())->record(
            'tenant.status',
            'success',
            ['subject_type' => 'tenant', 'subject_id' => (string)$tenant->id],
            $admin,
            (new ServerRequest(['url' => '/platform-admin/audit']))->withAttribute('requestId', 'req-export-2'),
            (int)$tenant->id,
            'platform_admin',
            ['tenant_slug' => (string)$tenant->slug, 'correlation_id' => 'corr-export-2'],
        );

        $outputPath = ROOT . DS . 'tmp' . DS . 'platform-audit-export-' . uniqid() . '.jsonl';
        $this->exec(sprintf(
            'platform_audit:export --output=%s --tenant-id=%d --action=tenant.status --correlation-id=corr-export-1',
            escapeshellarg($outputPath),
            (int)$tenant->id,
        ));

        $this->assertExitSuccess();
        $this->assertOutputContains('Exported 1 audit event(s).');
        $this->assertFileExists($outputPath);
        $contents = (string)file_get_contents($outputPath);
        $this->assertStringContainsString('corr-export-1', $contents);
        $this->assertStringNotContainsString('corr-export-2', $contents);
        @unlink($outputPath);
    }

    public function testPlatformAuditRetentionCommandPurgesPrefixAndStoresAnchor(): void
    {
        $service = new PlatformAuditService();
        $eventOne = $service->record(
            'platform_admin.login',
            'success',
            ['subject_id' => '1'],
            null,
            new ServerRequest(['url' => '/platform-admin/login']),
        );
        $eventTwo = $service->record(
            'tenant.status',
            'success',
            ['subject_id' => '2'],
            null,
            new ServerRequest(['url' => '/platform-admin/tenants']),
        );
        $service->record(
            'tenant.status',
            'success',
            ['subject_id' => '3'],
            null,
            new ServerRequest(['url' => '/platform-admin/tenants']),
        );

        $events = $this->getTableLocator()->get('PlatformAuditEvents');
        $oldDate = new DateTime('-30 days');
        $events->updateAll(['created' => $oldDate], ['id IN' => [(int)$eventOne->id, (int)$eventTwo->id]]);

        $archivePath = ROOT . DS . 'tmp' . DS . 'platform-audit-retention-' . uniqid() . '.jsonl';
        $this->exec(sprintf(
            'platform_audit:retention --before=%s --archive-path=%s --purge',
            escapeshellarg((new DateTime('-1 day'))->format(DATE_ATOM)),
            escapeshellarg($archivePath),
        ));

        $this->assertExitSuccess();
        $this->assertOutputContains('Purged 2 event(s).');
        $this->assertSame(1, $events->find()->count());
        $this->assertSame(1, $this->getTableLocator()->get('PlatformAuditRetentionAnchors')->find()->count());
        $this->assertFileExists($archivePath);
        @unlink($archivePath);
    }
}

