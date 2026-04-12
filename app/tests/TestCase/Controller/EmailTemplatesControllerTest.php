<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Test\TestCase\Support\HttpIntegrationTestCase;
use Cake\ORM\TableRegistry;

/**
 * @uses \App\Controller\EmailTemplatesController
 */
class EmailTemplatesControllerTest extends HttpIntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->authenticateAsSuperUser();
    }

    public function testEditRendersWhenAvailableVarsStoredAsLegacyStringList(): void
    {
        $templates = TableRegistry::getTableLocator()->get('EmailTemplates');
        $template = $templates->find()
            ->select(['id'])
            ->where(['slug' => 'member-registration-secretary'])
            ->firstOrFail();

        $templates->getConnection()->update('email_templates', [
            'available_vars' => json_encode(['memberScaName', 'memberViewUrl'], JSON_THROW_ON_ERROR),
        ], [
            'id' => $template->id,
        ]);

        $this->get('/email-templates/edit/' . $template->id);

        $this->assertResponseOk();
        $this->assertResponseContains('{{memberScaName}}');
        $this->assertResponseContains('{{memberViewUrl}}');
    }
}
