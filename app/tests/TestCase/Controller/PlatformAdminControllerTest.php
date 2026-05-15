<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Services\Platform\PlatformAdminAuthService;
use Cake\Core\Configure;
use Cake\Http\ServerRequest;
use Cake\I18n\DateTime;
use Cake\Mailer\Mailer;
use Cake\Mailer\TransportFactory;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use Migrations\Migrations;

class PlatformAdminControllerTest extends TestCase
{
    use IntegrationTestTrait;

    private string|false $originalPlatformSecretKey;

    /**
     * @var array<int, string>
     */
    private array $originalPlatformAdminHosts = [];

    /**
     * @var array<int, string>
     */
    private array $originalPlatformRedirectHosts = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalPlatformSecretKey = getenv('PLATFORM_SECRET_KEY');
        $this->originalPlatformAdminHosts = (array)Configure::read('PlatformAdmin.hosts');
        $this->originalPlatformRedirectHosts = (array)Configure::read('PlatformAdmin.redirectFromHosts');
        Configure::write('PlatformAdmin.hosts', ['admin.localhost']);
        Configure::write('PlatformAdmin.redirectFromHosts', ['localhost', '127.0.0.1']);
        $this->setPlatformSecretKey('test-platform-secret-key-32-chars-minimum');
        (new Migrations())->migrate([
            'connection' => 'test',
            'source' => 'PlatformMigrations',
        ]);
        $this->configureDebugEmail();
        $this->getTableLocator()->get('PlatformServiceConfigs')->deleteAll([]);
        $this->getTableLocator()->get('PlatformSecrets')->deleteAll([]);
        $this->getTableLocator()->get('PlatformAdminSessions')->deleteAll([]);
        $this->getTableLocator()->get('PlatformAdminEmailCodes')->deleteAll([]);
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
        Configure::write('PlatformAdmin.hosts', $this->originalPlatformAdminHosts);
        Configure::write('PlatformAdmin.redirectFromHosts', $this->originalPlatformRedirectHosts);
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
        $login = $this->createAuthenticatedAdmin(
            'first-login@platform.test',
            'First Login',
            'InitialPlatformPassword!',
            true,
        );
        $this->configRequest([
            'headers' => ['Host' => 'admin.localhost'],
            'cookies' => ['__Host-KMPPlatformAdmin' => $login['token']],
        ]);

        $this->get('/platform-admin');

        $this->assertRedirectContains('/platform-admin/change-password');
    }

    public function testChangePasswordPageIsAvailableDuringFirstLoginRotation(): void
    {
        $login = $this->createAuthenticatedAdmin(
            'rotate@platform.test',
            'Rotate Login',
            'InitialPlatformPassword!',
            true,
        );
        $this->configRequest([
            'headers' => ['Host' => 'admin.localhost'],
            'cookies' => ['__Host-KMPPlatformAdmin' => $login['token']],
        ]);

        $this->get('/platform-admin/change-password');

        $this->assertResponseOk();
        $this->assertResponseContains('This account must choose a new password');
    }

    public function testCreateTenantSecretConfigFailureDoesNotConsumeActionCode(): void
    {
        $login = $this->createAuthenticatedAdmin(
            'tenant-create@platform.test',
            'Tenant Create',
            'InitialPlatformPassword!',
        );
        $service = new PlatformAdminAuthService();
        $challenge = $service->requestActionCode(
            $login['admin'],
            new ServerRequest(['url' => '/platform-admin/action-code']),
            'Create tenant',
        );
        $this->setEmailCode((int)$challenge['challengeId'], '123456');
        $this->clearPlatformSecretKey();
        $this->configRequest([
            'headers' => ['Host' => 'admin.localhost'],
            'cookies' => ['__Host-KMPPlatformAdmin' => $login['token']],
        ]);
        $this->session([
            'PlatformAdmin' => [
                'actionChallengeIds' => [
                    'Create or update tenant' => $challenge['challengeId'],
                ],
            ],
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
            'verify_email_code' => '123456',
        ]);

        $this->assertResponseOk();
        $this->assertSame(
            1,
            $this->getTableLocator()->get('PlatformAdminEmailCodes')->find()
                ->where([
                    'platform_admin_id' => $login['admin']->id,
                    'purpose' => 'action',
                    'used_at IS' => null,
                ])
                ->count(),
        );
    }

    public function testLoginPasswordStepEmailsCodeWithoutCreatingSessionCookie(): void
    {
        (new PlatformAdminAuthService())->createAdmin(
            'browser-login@platform.test',
            'Browser Login',
            'InitialPlatformPassword!',
        );
        $this->configRequest(['headers' => ['Host' => 'admin.localhost']]);
        $this->enableCsrfToken();
        $this->enableSecurityToken();

        $this->post('/platform-admin/login', [
            'email' => 'browser-login@platform.test',
            'password' => 'InitialPlatformPassword!',
        ]);

        $this->assertResponseOk();
        $this->assertResponseContains('Enter the verification code emailed to browser-login@platform.test.');
        $this->assertResponseContains('name="email_code"');
        $this->assertResponseNotContains('After your password is verified');
        $this->assertSame(0, $this->getTableLocator()->get('PlatformAdminSessions')->find()->count());
        $this->assertSame(1, $this->getTableLocator()->get('PlatformAdminEmailCodes')->find()
            ->where(['purpose' => 'login', 'used_at IS' => null])
            ->count());
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

    /**
     * @return array{admin: \App\Model\Entity\PlatformAdmin, token: string}
     */
    private function createAuthenticatedAdmin(
        string $email,
        string $displayName,
        string $password,
        bool $requirePasswordChange = false,
    ): array {
        $service = new PlatformAdminAuthService();
        $created = $service->createAdmin($email, $displayName, $password, $requirePasswordChange);
        $request = new ServerRequest(['url' => '/platform-admin/login']);
        $challenge = $service->beginLogin($email, $password, $request);
        $this->setEmailCode((int)$challenge['challengeId'], '123456');
        $token = $service->completeLogin((int)$challenge['challengeId'], '123456', $request);

        return ['admin' => $created['admin'], 'token' => $token];
    }

    private function setEmailCode(int $challengeId, string $code, ?DateTime $expiresAt = null): void
    {
        $codesTable = $this->getTableLocator()->get('PlatformAdminEmailCodes');
        $record = $codesTable->get($challengeId);
        $record->code_hash = password_hash($code, PASSWORD_DEFAULT);
        if ($expiresAt !== null) {
            $record->expires_at = $expiresAt;
        }
        $codesTable->saveOrFail($record);
    }

    private function configureDebugEmail(): void
    {
        TransportFactory::drop('default');
        TransportFactory::setConfig('default', ['className' => 'Debug']);
        Mailer::drop('default');
        Mailer::setConfig('default', [
            'transport' => 'default',
            'from' => 'platform-admin@platform.test',
        ]);
    }
}
