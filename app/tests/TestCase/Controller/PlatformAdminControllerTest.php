<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Services\Platform\PlatformAdminAuthService;
use Cake\Http\ServerRequest;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use Migrations\Migrations;

class PlatformAdminControllerTest extends TestCase
{
    use IntegrationTestTrait;

    private string|false $originalPlatformSecretKey;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalPlatformSecretKey = getenv('PLATFORM_SECRET_KEY');
        $this->setPlatformSecretKey('test-platform-secret-key-32-chars-minimum');
        (new Migrations())->migrate([
            'connection' => 'test',
            'source' => 'PlatformMigrations',
        ]);
        $this->getTableLocator()->get('PlatformServiceConfigs')->deleteAll([]);
        $this->getTableLocator()->get('PlatformSecrets')->deleteAll([]);
        $this->getTableLocator()->get('PlatformAdminSessions')->deleteAll([]);
        $this->getTableLocator()->get('PlatformAdminRecoveryCodes')->deleteAll([]);
        $this->getTableLocator()->get('PlatformAdmins')->deleteAll(['email LIKE' => '%@platform.test']);
    }

    protected function tearDown(): void
    {
        if ($this->originalPlatformSecretKey === false) {
            putenv('PLATFORM_SECRET_KEY');
            unset($_ENV['PLATFORM_SECRET_KEY'], $_SERVER['PLATFORM_SECRET_KEY']);
        } else {
            $this->setPlatformSecretKey($this->originalPlatformSecretKey);
        }
        parent::tearDown();
    }

    public function testLocalTenantHostRedirectsPlatformAdminRoutesToAdminHost(): void
    {
        $this->configRequest(['headers' => ['Host' => 'localhost']]);

        $this->get('/platform-admin/login');

        $this->assertRedirectContains('http://admin.localhost/platform-admin/login');
    }

    public function testAdminHostCanAccessLogin(): void
    {
        $this->configRequest(['headers' => ['Host' => 'admin.localhost']]);

        $this->get('/platform-admin/login');

        $this->assertResponseOk();
        $this->assertResponseContains('Platform Admin Login');
    }

    public function testAdminHostRootRedirectsToConsole(): void
    {
        $this->configRequest(['headers' => ['Host' => 'admin.localhost']]);

        $this->get('/');

        $this->assertRedirectContains('/platform-admin');
    }

    public function testFirstLoginAdminMustChangePasswordBeforeConsoleAccess(): void
    {
        $service = new PlatformAdminAuthService();
        $created = $service->createAdmin(
            'first-login@platform.test',
            'First Login',
            'InitialPlatformPassword!',
            true,
        );
        $token = $service->authenticate(
            'first-login@platform.test',
            'InitialPlatformPassword!',
            $created['recoveryCodes'][0],
            new ServerRequest(['url' => '/platform-admin/login']),
        );
        $this->configRequest([
            'headers' => ['Host' => 'admin.localhost'],
            'cookies' => ['__Host-KMPPlatformAdmin' => $token],
        ]);

        $this->get('/platform-admin');

        $this->assertRedirectContains('/platform-admin/change-password');
    }

    public function testChangePasswordPageIsAvailableDuringFirstLoginRotation(): void
    {
        $service = new PlatformAdminAuthService();
        $created = $service->createAdmin(
            'rotate@platform.test',
            'Rotate Login',
            'InitialPlatformPassword!',
            true,
        );
        $token = $service->authenticate(
            'rotate@platform.test',
            'InitialPlatformPassword!',
            $created['recoveryCodes'][0],
            new ServerRequest(['url' => '/platform-admin/login']),
        );
        $this->configRequest([
            'headers' => ['Host' => 'admin.localhost'],
            'cookies' => ['__Host-KMPPlatformAdmin' => $token],
        ]);

        $this->get('/platform-admin/change-password');

        $this->assertResponseOk();
        $this->assertResponseContains('This account must choose a new password');
    }

    public function testCreateTenantSecretConfigFailureDoesNotConsumeActionCode(): void
    {
        $service = new PlatformAdminAuthService();
        $created = $service->createAdmin(
            'tenant-create@platform.test',
            'Tenant Create',
            'InitialPlatformPassword!',
        );
        $token = $service->authenticate(
            'tenant-create@platform.test',
            'InitialPlatformPassword!',
            $created['recoveryCodes'][0],
            new ServerRequest(['url' => '/platform-admin/login']),
        );
        $actionCode = $created['recoveryCodes'][1];
        $this->clearPlatformSecretKey();
        $this->configRequest([
            'headers' => ['Host' => 'admin.localhost'],
            'cookies' => ['__Host-KMPPlatformAdmin' => $token],
        ]);
        $this->enableCsrfToken();
        $this->enableSecurityToken();

        $this->post('/platform-admin/tenants/create', [
            'slug' => 'missing-secret-key',
            'display_name' => 'Missing Secret Key',
            'primary_host' => 'missing-secret-key.localhost',
            'driver' => 'Cake\Database\Driver\Mysql',
            'database_host' => 'localhost',
            'database_name' => 'missing_secret_key',
            'database_username' => 'tenant_user',
            'database_secret_value' => 'tenant-db-secret',
            'migrate' => '0',
            'activate' => '0',
            'verify_password' => 'InitialPlatformPassword!',
            'verify_mfa_code' => $actionCode,
        ]);

        $this->assertResponseOk();
        $this->assertSame(
            9,
            $this->getTableLocator()->get('PlatformAdminRecoveryCodes')->find()
                ->where(['platform_admin_id' => $created['admin']->id, 'used_at IS' => null])
                ->count(),
        );
    }

    private function setPlatformSecretKey(string $value): void
    {
        putenv('PLATFORM_SECRET_KEY=' . $value);
        $_ENV['PLATFORM_SECRET_KEY'] = $value;
        $_SERVER['PLATFORM_SECRET_KEY'] = $value;
    }

    private function clearPlatformSecretKey(): void
    {
        putenv('PLATFORM_SECRET_KEY');
        unset($_ENV['PLATFORM_SECRET_KEY'], $_SERVER['PLATFORM_SECRET_KEY']);
    }
}
