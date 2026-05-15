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
        putenv('ANSTEORRA_S3_SECRET');
        putenv('OUTLANDS_SMTP_PASSWORD');
        putenv('TENANT_A_SMTP_PASSWORD');
        putenv('TENANT_B_SMTP_PASSWORD');
        putenv('PLATFORM_SECRET_KEY');
        unset($_ENV['ANSTEORRA_S3_SECRET'], $_ENV['OUTLANDS_SMTP_PASSWORD'], $_ENV['TENANT_A_SMTP_PASSWORD'], $_ENV['TENANT_B_SMTP_PASSWORD']);
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

    public function testApplyingSecondTenantConfigDoesNotLeakFirstTenantValues(): void
    {
        $service = new TenantRuntimeConfigService();
        $tenantA = new TenantContext(
            201,
            'tenant-a',
            'Tenant A',
            'active',
            null,
            'tenant-a.example.org',
            'tenant-a.example.org',
            [],
            [[
                'serviceName' => 'email',
                'configKey' => 'default',
                'secretReference' => 'env:TENANT_A_SMTP_PASSWORD',
                'metadata' => [
                    'transport' => ['host' => 'smtp.tenant-a.example.org', 'username' => 'tenant-a'],
                    'email' => ['from' => 'no-reply@tenant-a.example.org'],
                ],
                'isActive' => true,
            ]],
        );
        $tenantB = new TenantContext(
            202,
            'tenant-b',
            'Tenant B',
            'active',
            null,
            'tenant-b.example.org',
            'tenant-b.example.org',
            [],
            [[
                'serviceName' => 'email',
                'configKey' => 'default',
                'secretReference' => 'env:TENANT_B_SMTP_PASSWORD',
                'metadata' => [
                    'transport' => ['host' => 'smtp.tenant-b.example.org', 'username' => 'tenant-b'],
                    'email' => ['from' => 'no-reply@tenant-b.example.org'],
                ],
                'isActive' => true,
            ]],
        );

        putenv('TENANT_A_SMTP_PASSWORD=tenant-a-secret');
        $_ENV['TENANT_A_SMTP_PASSWORD'] = 'tenant-a-secret';
        $service->apply($tenantA);
        $this->assertSame('smtp.tenant-a.example.org', Configure::read('EmailTransport.default.host'));
        $this->assertSame('tenant-a-secret', Configure::read('EmailTransport.default.password'));

        putenv('TENANT_B_SMTP_PASSWORD=tenant-b-secret');
        $_ENV['TENANT_B_SMTP_PASSWORD'] = 'tenant-b-secret';
        $service->apply($tenantB);

        $this->assertSame('smtp.tenant-b.example.org', Configure::read('EmailTransport.default.host'));
        $this->assertSame('tenant-b-secret', Configure::read('EmailTransport.default.password'));
        $this->assertSame('no-reply@tenant-b.example.org', Configure::read('Email.default.from'));
        $this->assertNotSame('smtp.tenant-a.example.org', Configure::read('EmailTransport.default.host'));
        $this->assertNotSame('tenant-a-secret', Configure::read('EmailTransport.default.password'));

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
