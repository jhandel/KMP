<?php

declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\KMP\StaticHelpers;
use App\Test\TestCase\Support\HttpIntegrationTestCase;
use Cake\Core\Configure;

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
        $this->assertResponseContains('Save channel');
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

    public function testCheckRedirectsWhenRepositoryNotConfigured(): void
    {
        Configure::write('Updater.githubRepository', '');
        $this->authenticateAsSuperUser();

        $this->post('/admin/updates/check');

        $this->assertRedirectContains('/admin/updates');
    }

    public function testSetChannelUpdatesAppSetting(): void
    {
        $this->authenticateAsSuperUser();
        StaticHelpers::setAppSetting('Updater.Channel', 'stable', null, true);

        $this->post('/admin/updates/channel', ['channel' => 'nightly']);

        $this->assertRedirectContains('/admin/updates');
        $this->assertSame('nightly', strtolower((string)StaticHelpers::getAppSetting('Updater.Channel', 'stable')));
    }

    public function testSetChannelRejectsInvalidValue(): void
    {
        $this->authenticateAsSuperUser();
        StaticHelpers::setAppSetting('Updater.Channel', 'beta', null, true);

        $this->post('/admin/updates/channel', ['channel' => 'production']);

        $this->assertRedirectContains('/admin/updates');
        $this->assertSame('beta', strtolower((string)StaticHelpers::getAppSetting('Updater.Channel', 'stable')));
    }

    public function testApplyRedirectsBackToIndex(): void
    {
        $this->authenticateAsSuperUser();

        $this->post('/admin/updates/apply');

        $this->assertRedirectContains('/admin/updates');
    }
}
