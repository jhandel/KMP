<?php
declare(strict_types=1);

namespace App\Test\TestCase\Services\Platform;

use App\Services\Platform\PlatformAdminAuthService;
use Cake\Http\ServerRequest;
use Cake\I18n\DateTime;
use Cake\Mailer\Mailer;
use Cake\Mailer\TransportFactory;
use Cake\TestSuite\TestCase;
use Migrations\Migrations;
use RuntimeException;

class PlatformAdminAuthServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (new Migrations())->migrate([
            'connection' => 'test',
            'source' => 'PlatformMigrations',
        ]);
        $this->configureDebugEmail();
        $this->getTableLocator()->get('PlatformAdminSessions')->deleteAll([]);
        $this->getTableLocator()->get('PlatformAdminEmailCodes')->deleteAll([]);
        $this->getTableLocator()->get('PlatformAdminRecoveryCodes')->deleteAll([]);
        $this->getTableLocator()->get('PlatformAdmins')->deleteAll(['email LIKE' => '%@platform.test']);
    }

    public function testBeginLoginCreatesEmailCodeWithoutCreatingSession(): void
    {
        $service = new PlatformAdminAuthService();
        $service->createAdmin('root@platform.test', 'Root Admin', 'VeryLongPlatformPassword!');

        $challenge = $service->beginLogin(
            'root@platform.test',
            'VeryLongPlatformPassword!',
            new ServerRequest(['url' => '/platform-admin/login']),
        );

        $this->assertSame('root@platform.test', $challenge['email']);
        $this->assertGreaterThan(0, $challenge['challengeId']);
        $this->assertSame(0, $this->getTableLocator()->get('PlatformAdminSessions')->find()->count());
        $codeRecord = $this->getTableLocator()->get('PlatformAdminEmailCodes')->get($challenge['challengeId']);
        $this->assertSame('login', $codeRecord->purpose);
        $this->assertNull($codeRecord->used_at);
        $this->assertGreaterThan(DateTime::now(), $codeRecord->expires_at);
    }

    public function testCompleteLoginWithEmailCodeCreatesSession(): void
    {
        $service = new PlatformAdminAuthService();
        $service->createAdmin('complete@platform.test', 'Complete Admin', 'VeryLongPlatformPassword!');
        $challenge = $service->beginLogin(
            'complete@platform.test',
            'VeryLongPlatformPassword!',
            new ServerRequest(['url' => '/platform-admin/login']),
        );
        $this->setEmailCode((int)$challenge['challengeId'], '123456');

        $token = $service->completeLogin(
            (int)$challenge['challengeId'],
            '123456',
            new ServerRequest(['url' => '/platform-admin/login']),
        );
        $admin = $service->adminFromToken($token);

        $this->assertNotNull($admin);
        $this->assertSame('complete@platform.test', $admin->email);
    }

    public function testInvalidEmailCodeRecordsAttempt(): void
    {
        $service = new PlatformAdminAuthService();
        $service->createAdmin('invalid@platform.test', 'Invalid Admin', 'VeryLongPlatformPassword!');
        $challenge = $service->beginLogin(
            'invalid@platform.test',
            'VeryLongPlatformPassword!',
            new ServerRequest(['url' => '/platform-admin/login']),
        );
        $this->setEmailCode((int)$challenge['challengeId'], '123456');

        $this->expectExceptionMessage('Invalid platform admin credentials.');
        try {
            $service->completeLogin(
                (int)$challenge['challengeId'],
                '000000',
                new ServerRequest(['url' => '/platform-admin/login']),
            );
        } finally {
            $codeRecord = $this->getTableLocator()->get('PlatformAdminEmailCodes')->get($challenge['challengeId']);
            $this->assertSame(1, (int)$codeRecord->attempts);
            $this->assertNull($codeRecord->used_at);
        }
    }

    public function testExpiredEmailCodeIsRejected(): void
    {
        $service = new PlatformAdminAuthService();
        $service->createAdmin('expired@platform.test', 'Expired Admin', 'VeryLongPlatformPassword!');
        $challenge = $service->beginLogin(
            'expired@platform.test',
            'VeryLongPlatformPassword!',
            new ServerRequest(['url' => '/platform-admin/login']),
        );
        $this->setEmailCode((int)$challenge['challengeId'], '123456', DateTime::now()->modify('-1 minute'));

        $this->expectExceptionMessage('Invalid platform admin credentials.');
        $service->completeLogin(
            (int)$challenge['challengeId'],
            '123456',
            new ServerRequest(['url' => '/platform-admin/login']),
        );
    }

    public function testEmailCodeIsOneTimeUse(): void
    {
        $service = new PlatformAdminAuthService();
        $service->createAdmin('once@platform.test', 'Once Admin', 'VeryLongPlatformPassword!');
        $request = new ServerRequest(['url' => '/platform-admin/login']);
        $challenge = $service->beginLogin('once@platform.test', 'VeryLongPlatformPassword!', $request);
        $this->setEmailCode((int)$challenge['challengeId'], '123456');

        $service->completeLogin((int)$challenge['challengeId'], '123456', $request);

        $this->expectExceptionMessage('Invalid platform admin credentials.');
        $service->completeLogin((int)$challenge['challengeId'], '123456', $request);
    }

    public function testNewLoginCodeInvalidatesPreviousUnusedCode(): void
    {
        $service = new PlatformAdminAuthService();
        $service->createAdmin('reissue@platform.test', 'Reissue Admin', 'VeryLongPlatformPassword!');
        $request = new ServerRequest(['url' => '/platform-admin/login']);
        $first = $service->beginLogin('reissue@platform.test', 'VeryLongPlatformPassword!', $request);
        $this->setEmailCode((int)$first['challengeId'], '111111');
        $second = $service->beginLogin('reissue@platform.test', 'VeryLongPlatformPassword!', $request);
        $this->setEmailCode((int)$second['challengeId'], '222222');

        $this->expectExceptionMessage('Invalid platform admin credentials.');
        try {
            $service->completeLogin((int)$first['challengeId'], '111111', $request);
        } finally {
            $token = $service->completeLogin((int)$second['challengeId'], '222222', $request);
            $this->assertNotNull($service->adminFromToken($token));
        }
    }

    public function testActionVerificationUsesEmailedCodeOnce(): void
    {
        $service = new PlatformAdminAuthService();
        $created = $service->createAdmin('action@platform.test', 'Action Admin', 'VeryLongPlatformPassword!');
        $request = new ServerRequest(['url' => '/platform-admin/action-code']);
        $challenge = $service->requestActionCode($created['admin'], $request, 'Create tenant');
        $this->setEmailCode((int)$challenge['challengeId'], '123456');

        $service->verifyAction($created['admin'], 'VeryLongPlatformPassword!', '123456', (int)$challenge['challengeId']);

        $this->expectExceptionMessage('Action verification failed.');
        $service->verifyAction($created['admin'], 'VeryLongPlatformPassword!', '123456', (int)$challenge['challengeId']);
    }

    public function testActionCodesAreBoundToChallenge(): void
    {
        $service = new PlatformAdminAuthService();
        $created = $service->createAdmin('bound-action@platform.test', 'Bound Action', 'VeryLongPlatformPassword!');
        $request = new ServerRequest(['url' => '/platform-admin/action-code']);
        $first = $service->requestActionCode($created['admin'], $request, 'Create tenant');
        $this->setEmailCode((int)$first['challengeId'], '111111');
        $second = $service->requestActionCode($created['admin'], $request, 'Delete tenant');
        $this->setEmailCode((int)$second['challengeId'], '222222');

        $this->expectExceptionMessage('Action verification failed.');
        $service->verifyAction($created['admin'], 'VeryLongPlatformPassword!', '111111', (int)$second['challengeId']);
    }

    public function testEmailCodeMaxAttemptsExhaustsCode(): void
    {
        $service = new PlatformAdminAuthService();
        $service->createAdmin('attempts@platform.test', 'Attempts Admin', 'VeryLongPlatformPassword!');
        $request = new ServerRequest(['url' => '/platform-admin/login']);
        $challenge = $service->beginLogin('attempts@platform.test', 'VeryLongPlatformPassword!', $request);
        $this->setEmailCode((int)$challenge['challengeId'], '123456');

        for ($i = 0; $i < 5; $i++) {
            try {
                $service->completeLogin((int)$challenge['challengeId'], '000000', $request);
            } catch (RuntimeException) {
            }
        }

        $this->expectExceptionMessage('Invalid platform admin credentials.');
        $service->completeLogin((int)$challenge['challengeId'], '123456', $request);
    }

    public function testSeedAdminRequiresPasswordChangeAndDoesNotOverwriteByDefault(): void
    {
        $service = new PlatformAdminAuthService();
        $created = $service->seedAdmin('seed@platform.test', 'Seed Admin', 'InitialPlatformPassword!');
        $unchanged = $service->seedAdmin('seed@platform.test', 'Changed Name', 'ReplacementPlatformPassword!');

        $this->assertTrue($created['created']);
        $this->assertFalse($unchanged['updated']);
        $this->assertTrue((bool)$created['admin']->require_password_change);
    }

    public function testSeedAdminCanSkipPasswordChangeRequirement(): void
    {
        $service = new PlatformAdminAuthService();
        $created = $service->seedAdmin(
            'local@platform.test',
            'Local Admin',
            'InitialPlatformPassword!',
            false,
            false,
        );

        $this->assertTrue($created['created']);
        $this->assertFalse((bool)$created['admin']->require_password_change);

        $updated = $service->seedAdmin(
            'local@platform.test',
            'Local Admin',
            'ReplacementPlatformPassword!',
            true,
            false,
        );

        $this->assertTrue($updated['updated']);
        $reloaded = $this->getTableLocator()->get('PlatformAdmins')->get($created['admin']->id);
        $this->assertFalse((bool)$reloaded->require_password_change);
        $this->assertTrue(password_verify('ReplacementPlatformPassword!', (string)$reloaded->password_hash));
    }

    public function testSeedAdminCanAllowLocalDevPassword(): void
    {
        $service = new PlatformAdminAuthService();
        $created = $service->seedAdmin(
            'dev@platform.test',
            'Dev Admin',
            'TestPassword',
            false,
            false,
            false,
        );

        $this->assertTrue($created['created']);
        $this->assertFalse((bool)$created['admin']->require_password_change);
        $this->assertTrue(password_verify('TestPassword', (string)$created['admin']->password_hash));
    }

    public function testChangePasswordClearsFirstLoginFlag(): void
    {
        $service = new PlatformAdminAuthService();
        $created = $service->createAdmin('change@platform.test', 'Change Admin', 'InitialPlatformPassword!', true);
        $admin = $created['admin'];

        $service->changePassword($admin, 'InitialPlatformPassword!', 'ReplacementPlatformPassword!');

        $reloaded = $this->getTableLocator()->get('PlatformAdmins')->get($admin->id);
        $this->assertFalse((bool)$reloaded->require_password_change);
        $this->assertTrue(password_verify('ReplacementPlatformPassword!', (string)$reloaded->password_hash));
    }

    public function testCliResetRequiresPasswordChange(): void
    {
        $service = new PlatformAdminAuthService();
        $service->createAdmin('reset@platform.test', 'Reset Admin', 'InitialPlatformPassword!');

        $service->resetPassword('reset@platform.test', 'ReplacementPlatformPassword!');

        $reloaded = $this->getTableLocator()->get('PlatformAdmins')->find()
            ->where(['email' => 'reset@platform.test'])
            ->firstOrFail();
        $this->assertTrue((bool)$reloaded->require_password_change);
        $this->assertTrue(password_verify('ReplacementPlatformPassword!', (string)$reloaded->password_hash));
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
