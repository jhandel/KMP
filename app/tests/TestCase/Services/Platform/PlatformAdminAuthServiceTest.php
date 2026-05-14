<?php
declare(strict_types=1);

namespace App\Test\TestCase\Services\Platform;

use App\Services\Platform\PlatformAdminAuthService;
use Cake\Http\ServerRequest;
use Cake\TestSuite\TestCase;
use Migrations\Migrations;

class PlatformAdminAuthServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (new Migrations())->migrate([
            'connection' => 'test',
            'source' => 'PlatformMigrations',
        ]);
        $this->getTableLocator()->get('PlatformAdminSessions')->deleteAll([]);
        $this->getTableLocator()->get('PlatformAdminRecoveryCodes')->deleteAll([]);
        $this->getTableLocator()->get('PlatformAdmins')->deleteAll(['email LIKE' => '%@platform.test']);
    }

    public function testCreateAdminAndAuthenticateWithRecoveryCode(): void
    {
        $service = new PlatformAdminAuthService();
        $created = $service->createAdmin('root@platform.test', 'Root Admin', 'VeryLongPlatformPassword!');

        $this->assertCount(10, $created['recoveryCodes']);

        $token = $service->authenticate(
            'root@platform.test',
            'VeryLongPlatformPassword!',
            $created['recoveryCodes'][0],
            new ServerRequest(['url' => '/platform-admin/login']),
        );
        $admin = $service->adminFromToken($token);

        $this->assertNotNull($admin);
        $this->assertSame('root@platform.test', $admin->email);
    }

    public function testRecoveryCodeIsOneTimeUse(): void
    {
        $service = new PlatformAdminAuthService();
        $created = $service->createAdmin('once@platform.test', 'Once Admin', 'VeryLongPlatformPassword!');
        $code = $created['recoveryCodes'][0];
        $request = new ServerRequest(['url' => '/platform-admin/login']);

        $service->authenticate('once@platform.test', 'VeryLongPlatformPassword!', $code, $request);

        $this->expectExceptionMessage('Invalid platform admin credentials.');
        $service->authenticate('once@platform.test', 'VeryLongPlatformPassword!', $code, $request);
    }

    public function testSeedAdminRequiresPasswordChangeAndDoesNotOverwriteByDefault(): void
    {
        $service = new PlatformAdminAuthService();
        $created = $service->seedAdmin('seed@platform.test', 'Seed Admin', 'InitialPlatformPassword!');
        $unchanged = $service->seedAdmin('seed@platform.test', 'Changed Name', 'ReplacementPlatformPassword!');

        $this->assertTrue($created['created']);
        $this->assertFalse($unchanged['updated']);
        $this->assertTrue((bool)$created['admin']->require_password_change);
        $this->assertSame([], $unchanged['recoveryCodes']);
    }

    public function testChangePasswordClearsFirstLoginFlagAndRotatesCodes(): void
    {
        $service = new PlatformAdminAuthService();
        $created = $service->createAdmin('change@platform.test', 'Change Admin', 'InitialPlatformPassword!', true);
        $admin = $created['admin'];

        $codes = $service->changePassword($admin, 'InitialPlatformPassword!', 'ReplacementPlatformPassword!');

        $this->assertCount(10, $codes);
        $reloaded = $this->getTableLocator()->get('PlatformAdmins')->get($admin->id);
        $this->assertFalse((bool)$reloaded->require_password_change);
        $this->assertTrue(password_verify('ReplacementPlatformPassword!', (string)$reloaded->password_hash));
    }

    public function testCliResetRequiresPasswordChangeAndRotatesCodes(): void
    {
        $service = new PlatformAdminAuthService();
        $service->createAdmin('reset@platform.test', 'Reset Admin', 'InitialPlatformPassword!');

        $codes = $service->resetPassword('reset@platform.test', 'ReplacementPlatformPassword!');

        $this->assertCount(10, $codes);
        $reloaded = $this->getTableLocator()->get('PlatformAdmins')->find()
            ->where(['email' => 'reset@platform.test'])
            ->firstOrFail();
        $this->assertTrue((bool)$reloaded->require_password_change);
        $this->assertTrue(password_verify('ReplacementPlatformPassword!', (string)$reloaded->password_hash));
    }
}
