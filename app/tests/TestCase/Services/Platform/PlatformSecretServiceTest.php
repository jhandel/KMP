<?php
declare(strict_types=1);

namespace App\Test\TestCase\Services\Platform;

use App\Services\Platform\PlatformSecretService;
use Cake\TestSuite\TestCase;
use Migrations\Migrations;
use RuntimeException;

class PlatformSecretServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        putenv('PLATFORM_SECRET_KEY=test-platform-secret-key-32-chars-minimum');
        $_ENV['PLATFORM_SECRET_KEY'] = 'test-platform-secret-key-32-chars-minimum';
        $_SERVER['PLATFORM_SECRET_KEY'] = 'test-platform-secret-key-32-chars-minimum';
        (new Migrations())->migrate([
            'connection' => 'test',
            'source' => 'PlatformMigrations',
        ]);
        $this->getTableLocator()->get('PlatformSecrets')->deleteAll([]);
        (new PlatformSecretService())->clearCache();
    }

    protected function tearDown(): void
    {
        putenv('PLATFORM_SECRET_KEY');
        unset($_ENV['PLATFORM_SECRET_KEY'], $_SERVER['PLATFORM_SECRET_KEY']);
        (new PlatformSecretService())->clearCache();
        parent::tearDown();
    }

    public function testStoreAndResolveManagedSecret(): void
    {
        $service = new PlatformSecretService();
        $reference = $service->storeSecret('tenant/1/email/default', 'smtp-secret', 'Test secret');

        $this->assertSame('managed:tenant/1/email/default', $reference);
        $this->assertSame('smtp-secret', $service->resolveSecretReference($reference));

        $row = $this->getTableLocator()->get('PlatformSecrets')->find()
            ->where(['name' => 'tenant/1/email/default'])
            ->firstOrFail();
        $this->assertNotSame('smtp-secret', $row->encrypted_value);
    }

    public function testMissingManagedSecretFailsLoudly(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Managed secret "tenant/missing" was not found.');

        (new PlatformSecretService())->resolveSecretReference('managed:tenant/missing');
    }

    public function testManagedSecretRequiresPlatformKey(): void
    {
        putenv('PLATFORM_SECRET_KEY');
        unset($_ENV['PLATFORM_SECRET_KEY'], $_SERVER['PLATFORM_SECRET_KEY']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PLATFORM_SECRET_KEY must be set');

        (new PlatformSecretService())->storeSecret('tenant/1/db', 'db-secret');
    }
}
