<?php
declare(strict_types=1);

namespace App\Test\TestCase\Services\Tenant;

use App\Services\Platform\PlatformSecretService;
use App\Services\Tenant\TenantContext;
use App\Services\Tenant\TenantRuntimeConfigService;
use Cake\Core\Configure;
use Cake\TestSuite\TestCase;
use Migrations\Migrations;

class TenantRuntimeConfigServiceTest extends TestCase
{
    private array $baseStorage;

    private array $baseTransport;

    private array $baseEmail;

    protected function setUp(): void
    {
        parent::setUp();
        $this->baseStorage = (array)Configure::read('Documents.storage', []);
        $this->baseTransport = (array)Configure::read('EmailTransport.default', []);
        $this->baseEmail = (array)Configure::read('Email.default', []);
    }

    protected function tearDown(): void
    {
        Configure::write('Documents.storage', $this->baseStorage);
        Configure::write('EmailTransport.default', $this->baseTransport);
        Configure::write('Email.default', $this->baseEmail);
        putenv('PLATFORM_SECRET_KEY');
        unset($_ENV['PLATFORM_SECRET_KEY']);
        (new PlatformSecretService())->clearCache();
        parent::tearDown();
    }

    public function testApplyAndResetTenantStorageConfig(): void
    {
        $service = new TenantRuntimeConfigService();
        $context = new TenantContext(
            99,
            'ansteorra',
            'Ansteorra',
            'active',
            null,
            'ansteorra.example.org',
            'ansteorra.example.org',
            [],
            [[
                'serviceName' => 'storage',
                'configKey' => 'default',
                'adapter' => 's3',
                'secretReference' => 'env:ANSTEORRA_S3_SECRET',
                'metadata' => ['s3' => ['bucket' => 'ansteorra-docs', 'region' => 'us-west-2']],
                'isActive' => true,
            ]],
        );

        putenv('ANSTEORRA_S3_SECRET=tenant-secret');
        $_ENV['ANSTEORRA_S3_SECRET'] = 'tenant-secret';
        $service->apply($context);

        $storage = Configure::read('Documents.storage');
        $this->assertSame('s3', $storage['adapter']);
        $this->assertSame('ansteorra-docs', $storage['s3']['bucket']);
        $this->assertSame('tenant-secret', $storage['s3']['secret']);

        $service->reset();
        $this->assertSame($this->baseStorage, Configure::read('Documents.storage'));
    }

    public function testApplyAndResetTenantEmailConfig(): void
    {
        $service = new TenantRuntimeConfigService();
        $context = new TenantContext(
            100,
            'outlands',
            'Outlands',
            'active',
            null,
            'outlands.example.org',
            'outlands.example.org',
            [],
            [[
                'serviceName' => 'email',
                'configKey' => 'default',
                'secretReference' => 'env:OUTLANDS_SMTP_PASSWORD',
                'metadata' => [
                    'transport' => ['host' => 'smtp.outlands.example.org', 'username' => 'mailer'],
                    'email' => ['from' => 'noreply@outlands.example.org'],
                ],
                'isActive' => true,
            ]],
        );

        putenv('OUTLANDS_SMTP_PASSWORD=smtp-secret');
        $_ENV['OUTLANDS_SMTP_PASSWORD'] = 'smtp-secret';
        $service->apply($context);

        $transport = Configure::read('EmailTransport.default');
        $email = Configure::read('Email.default');
        $this->assertSame('smtp.outlands.example.org', $transport['host']);
        $this->assertSame('smtp-secret', $transport['password']);
        $this->assertSame('noreply@outlands.example.org', $email['from']);

        $service->reset();
        $this->assertSame($this->baseTransport, Configure::read('EmailTransport.default'));
        $this->assertSame($this->baseEmail, Configure::read('Email.default'));
    }

    public function testApplyTenantEmailConfigWithManagedSecret(): void
    {
        putenv('PLATFORM_SECRET_KEY=test-platform-secret-key-32-chars-minimum');
        $_ENV['PLATFORM_SECRET_KEY'] = 'test-platform-secret-key-32-chars-minimum';
        (new Migrations())->migrate([
            'connection' => 'test',
            'source' => 'PlatformMigrations',
        ]);
        $this->getTableLocator()->get('PlatformSecrets')->deleteAll([]);
        (new PlatformSecretService())->storeSecret('tenant/101/email/default', 'managed-smtp-secret');
        $service = new TenantRuntimeConfigService();
        $context = new TenantContext(
            101,
            'calontir',
            'Calontir',
            'active',
            null,
            'calontir.example.org',
            'calontir.example.org',
            [],
            [[
                'serviceName' => 'email',
                'configKey' => 'default',
                'secretReference' => 'managed:tenant/101/email/default',
                'metadata' => [
                    'transport' => ['host' => 'smtp.calontir.example.org', 'username' => 'mailer'],
                    'email' => ['from' => 'noreply@calontir.example.org'],
                ],
                'isActive' => true,
            ]],
        );

        $service->apply($context);

        $transport = Configure::read('EmailTransport.default');
        $this->assertSame('managed-smtp-secret', $transport['password']);
    }
}
