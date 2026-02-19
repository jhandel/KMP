<?php

declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\KMP\StaticHelpers;
use App\Services\Updater\UpgradePipelineService;
use App\Test\TestCase\Support\HttpIntegrationTestCase;
use Cake\Core\Configure;
use RuntimeException;

/**
 * @uses \App\Controller\UpdatesController
 */
class UpdatesControllerTest extends HttpIntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->enableCsrfToken();
        $this->enableSecurityToken();
        Configure::write('Updater.githubRepository', '');
        Configure::delete('Updater.pipelineServiceClass');
    }

    public function testIndexRequiresAuthentication(): void
    {
        $this->get('/admin/updates');

        $this->assertRedirectContains('/members/login');
    }

    public function testIndexLoadsForSuperUser(): void
    {
        $this->authenticateAsSuperUser();

        $this->get('/admin/updates');

        $this->assertResponseOk();
        $this->assertResponseContains('Updates');
        $this->assertResponseContains('Checking latest release...');
        $this->assertResponseContains('Integrity');
    }

    public function testIndexUsesUpdaterChannelAppSetting(): void
    {
        $this->authenticateAsSuperUser();
        StaticHelpers::setAppSetting('Updater.Channel', 'beta', null, true);

        $this->get('/admin/updates');

        $this->assertResponseOk();
        $this->assertResponseContains('beta');
    }

    public function testIndexShowsInstalledReleaseIdentity(): void
    {
        $this->authenticateAsSuperUser();
        Configure::write('App.releaseTag', 'nightly/v1.4.2');
        Configure::write('App.releaseHash', '0123456789abcdef0123456789abcdef01234567');

        $this->get('/admin/updates');

        $this->assertResponseOk();
        $this->assertResponseContains('nightly/v1.4.2');
        $this->assertResponseContains('0123456789abcdef0123456789abcdef01234567');
    }

    public function testCheckRedirectsToIndex(): void
    {
        $this->authenticateAsSuperUser();

        $this->get('/admin/updates/check');

        $this->assertRedirectContains('/admin/updates');
    }

    public function testSetChannelUpdatesAppSetting(): void
    {
        $this->authenticateAsSuperUser();
        StaticHelpers::setAppSetting('Updater.Channel', 'stable', null, true);

        $this->get('/admin/updates/channel?channel=nightly');

        $this->assertRedirectContains('/admin/updates');
        $this->assertSame('nightly', strtolower((string)StaticHelpers::getAppSetting('Updater.Channel', 'stable')));
    }

    public function testSetChannelRejectsInvalidValue(): void
    {
        $this->authenticateAsSuperUser();
        StaticHelpers::setAppSetting('Updater.Channel', 'beta', null, true);

        $this->get('/admin/updates/channel?channel=production');

        $this->assertRedirectContains('/admin/updates');
        $this->assertSame('beta', strtolower((string)StaticHelpers::getAppSetting('Updater.Channel', 'stable')));
    }

    public function testSetChannelReturnsJsonForAjaxRequests(): void
    {
        $this->authenticateAsSuperUser();
        $this->configRequest([
            'headers' => [
                'X-Requested-With' => 'XMLHttpRequest',
                'Accept' => 'application/json',
            ],
        ]);

        $this->get('/admin/updates/channel?channel=dev');

        $this->assertResponseOk();
        $this->assertContentType('application/json');
        $this->assertResponseContains('"success":true');
        $this->assertResponseContains('"channel":"dev"');
    }

    public function testApplyRedirectsBackToIndex(): void
    {
        $this->authenticateAsSuperUser();

        $this->get('/admin/updates/apply');

        $this->assertRedirectContains('/admin/updates');
    }

    public function testApplyUsesConfiguredPipelineServiceClass(): void
    {
        $this->authenticateAsSuperUser();
        Configure::write('Updater.pipelineServiceClass', SuccessfulUpgradePipelineService::class);

        $this->get('/admin/updates/apply');

        $this->assertRedirectContains('/admin/updates');
    }

    public function testApplyHandlesPipelineException(): void
    {
        $this->authenticateAsSuperUser();
        Configure::write('Updater.pipelineServiceClass', FailingUpgradePipelineService::class);

        $this->get('/admin/updates/apply');

        $this->assertRedirectContains('/admin/updates');
    }
}

class SuccessfulUpgradePipelineService extends UpgradePipelineService
{
    public function applyLatestRelease(?string $repository = null): array
    {
        return [
            'status' => 'updated',
            'releaseTag' => 'nightly/v1.4.2',
            'releaseHash' => str_repeat('a', 64),
        ];
    }
}

class FailingUpgradePipelineService extends UpgradePipelineService
{
    public function applyLatestRelease(?string $repository = null): array
    {
        throw new RuntimeException('simulated failure');
    }
}
