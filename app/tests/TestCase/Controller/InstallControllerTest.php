<?php

declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\KMP\StaticHelpers;
use App\Services\Installer\InstallerLockService;
use App\Test\TestCase\Support\HttpIntegrationTestCase;
use Cake\Core\Configure;

/**
 * @uses \App\Controller\InstallController
 */
class InstallControllerTest extends HttpIntegrationTestCase
{
    private InstallerLockService $lockService;
    private array $originalRequiredTables;
    private string $testEnvFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableCsrfToken();
        $this->enableSecurityToken();
        $this->originalRequiredTables = (array)Configure::read('Installer.requiredTables', ['members']);
        $this->testEnvFile = sys_get_temp_dir() . '/kmp-install-test.env';
        Configure::write('Installer.lockFile', sys_get_temp_dir() . '/kmp-install-test.lock');
        Configure::write('Installer.envFile', $this->testEnvFile);
        $this->lockService = new InstallerLockService();
        $this->lockService->clearLock();
        @unlink($this->testEnvFile);
    }

    protected function tearDown(): void
    {
        $this->lockService->clearLock();
        Configure::delete('Installer.lockFile');
        Configure::delete('Installer.envFile');
        Configure::write('Installer.requiredTables', $this->originalRequiredTables);
        @unlink($this->testEnvFile);
        parent::tearDown();
    }

    public function testIndexRendersWhenUnlocked(): void
    {
        $this->get('/install');

        $this->assertResponseOk();
        $this->assertResponseContains('KMP Installer');
        $this->assertResponseContains('Preflight checks');
    }

    public function testIndexRedirectsWhenLocked(): void
    {
        $this->assertTrue($this->lockService->writeLock(['test' => true]));

        $this->get('/install');

        $this->assertRedirectContains('/members/login');
    }

    public function testWizardStepPostStoresDataAndRedirects(): void
    {
        $this->post('/install', [
            'current_step' => 'database',
            'next_step' => 'communications',
            'db_host' => 'localhost',
            'db_port' => '3306',
            'db_name' => 'KMP_DEV',
            'db_user' => 'KMPSQLDEV',
            'db_password' => 'secret',
            'db_create_database' => '1',
        ]);

        $this->assertRedirectContains('/install?step=communications');
        $this->assertSession('KMP_DEV', 'Installer.WizardData.db_name');
    }

    public function testFinalizeSavesBrandingAndWritesLock(): void
    {
        $this->post('/install/finalize', [
            'db_host' => (string)(getenv('MYSQL_HOST') ?: 'localhost'),
            'db_port' => (string)(getenv('MYSQL_PORT') ?: '3306'),
            'db_name' => (string)(getenv('MYSQL_DB_NAME') ?: 'KMP_DEV'),
            'db_user' => (string)(getenv('MYSQL_USERNAME') ?: 'KMPSQLDEV'),
            'db_password' => (string)(getenv('MYSQL_PASSWORD') ?: 'P@ssw0rd'),
            'db_create_database' => '0',
            'email_smtp_host' => 'localhost',
            'email_smtp_port' => '1025',
            'email_smtp_username' => '',
            'email_smtp_password' => '',
            'system_email_from' => 'site@test.com',
            'storage_adapter' => 'local',
            'azure_connection_string' => '',
            'azure_container' => 'documents',
            'azure_prefix' => '',
            's3_bucket' => '',
            's3_region' => 'us-east-1',
            's3_endpoint' => '',
            's3_key' => '',
            's3_secret' => '',
            's3_session_token' => '',
            's3_prefix' => '',
            's3_use_path_style_endpoint' => '0',
            'kingdom_name' => 'Ansteorra',
            'long_site_title' => 'Ansteorra Kingdom Management Portal',
            'short_site_title' => 'KMP',
            'default_timezone' => 'America/Chicago',
            'banner_logo_mode' => 'external',
            'banner_logo_external_url' => 'https://example.org/logo.png',
        ]);

        $this->assertRedirectContains('/members/login');
        $this->assertTrue($this->lockService->isLocked());
        $this->assertSame('Ansteorra', StaticHelpers::getAppSetting('KMP.KingdomName', ''));
        $this->assertSame('external', StaticHelpers::getAppSetting('KMP.BannerLogoMode', ''));
        $this->assertSame('https://example.org/logo.png', StaticHelpers::getAppSetting('KMP.BannerLogoExternalUrl', ''));
    }

    public function testRootRedirectsToInstallerWhenRequiredTableMissing(): void
    {
        Configure::write('Installer.requiredTables', ['missing_table_for_installer_gate']);

        $this->get('/');

        $this->assertRedirectContains('/install');
    }
}
