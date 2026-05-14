<?php
declare(strict_types=1);

namespace App\Test\TestCase\Services\Platform;

use App\Services\Platform\PlatformRuntimeConfigService;
use App\Services\Platform\PlatformSecretService;
use Cake\Core\Configure;
use Cake\TestSuite\TestCase;
use Migrations\Migrations;

class PlatformRuntimeConfigServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (new Migrations())->migrate([
            'connection' => 'test',
            'source' => 'PlatformMigrations',
        ]);
        $this->getTableLocator()->get('PlatformServiceConfigs')->deleteAll(['service_name' => 'email']);
        $this->getTableLocator()->get('PlatformSecrets')->deleteAll([]);
        (new PlatformSecretService())->clearCache();
    }

    protected function tearDown(): void
    {
        $this->getTableLocator()->get('PlatformServiceConfigs')->deleteAll(['service_name' => 'email']);
        $this->getTableLocator()->get('PlatformSecrets')->deleteAll([]);
        putenv('PLATFORM_SECRET_KEY');
        unset($_ENV['PLATFORM_SECRET_KEY']);
        (new PlatformSecretService())->clearCache();
        parent::tearDown();
    }

    public function testAppliesPlatformEmailConfigFromPlatformStore(): void
    {
        $this->getTableLocator()->get('PlatformServiceConfigs')->saveOrFail(
            $this->getTableLocator()->get('PlatformServiceConfigs')->newEntity([
                'service_name' => 'email',
                'config_key' => 'default',
                'adapter' => 'smtp',
                'secret_reference' => 'env:PLATFORM_TEST_SMTP_PASSWORD',
                'metadata' => json_encode([
                    'transport' => [
                        'className' => 'Smtp',
                        'host' => 'platform-mail.test',
                        'username' => 'platform',
                    ],
                    'email' => [
                        'from' => 'platform@example.test',
                    ],
                ], JSON_THROW_ON_ERROR),
                'is_active' => true,
            ]),
        );
        putenv('PLATFORM_TEST_SMTP_PASSWORD=secret-value');

        (new PlatformRuntimeConfigService())->applyEmailConfig();

        $this->assertSame('platform-mail.test', Configure::read('EmailTransport.platform.host'));
        $this->assertSame('secret-value', Configure::read('EmailTransport.platform.password'));
        $this->assertSame('platform@example.test', Configure::read('Email.platform.from'));
    }

    public function testAppliesPlatformEmailConfigWithManagedSecret(): void
    {
        putenv('PLATFORM_SECRET_KEY=test-platform-secret-key-32-chars-minimum');
        $_ENV['PLATFORM_SECRET_KEY'] = 'test-platform-secret-key-32-chars-minimum';
        $reference = (new PlatformSecretService())->storeSecret('platform/email/default', 'managed-platform-secret');
        $this->getTableLocator()->get('PlatformServiceConfigs')->saveOrFail(
            $this->getTableLocator()->get('PlatformServiceConfigs')->newEntity([
                'service_name' => 'email',
                'config_key' => 'default',
                'adapter' => 'smtp',
                'secret_reference' => $reference,
                'metadata' => json_encode([
                    'transport' => [
                        'className' => 'Smtp',
                        'host' => 'platform-managed-mail.test',
                        'username' => 'platform',
                    ],
                    'email' => [
                        'from' => 'platform-managed@example.test',
                    ],
                ], JSON_THROW_ON_ERROR),
                'is_active' => true,
            ]),
        );

        (new PlatformRuntimeConfigService())->applyEmailConfig();

        $this->assertSame('platform-managed-mail.test', Configure::read('EmailTransport.platform.host'));
        $this->assertSame('managed-platform-secret', Configure::read('EmailTransport.platform.password'));
    }
}
