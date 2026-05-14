<?php
declare(strict_types=1);

namespace App\Test\TestCase\Services\Platform;

use App\Services\Platform\PlatformAuditService;
use Cake\Http\ServerRequest;
use Cake\TestSuite\TestCase;
use Migrations\Migrations;

class PlatformAuditServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (new Migrations())->migrate([
            'connection' => 'test',
            'source' => 'PlatformMigrations',
        ]);
        $this->getTableLocator()->get('PlatformAuditEvents')->deleteAll([]);
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
            ],
            null,
            new ServerRequest(['url' => '/platform-admin/tenants/test/secrets']),
        );
        $event = $this->getTableLocator()->get('PlatformAuditEvents')->get($event->id);
        $metadata = is_array($event->metadata) ? $event->metadata : json_decode((string)$event->metadata, true);

        $this->assertSame('[redacted]', $metadata['database_secret_value']);
        $this->assertSame('[redacted]', $metadata['email_secret_value']);
        $this->assertSame('[redacted]', $metadata['storage_secret_value']);
    }
}
