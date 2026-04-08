<?php

declare(strict_types=1);

namespace App\Test\TestCase\Config\Seeds;

use App\Test\TestCase\BaseTestCase;

class InitWorkflowDefinitionsSeedTest extends BaseTestCase
{
    public function testAuthorizationWorkflowUsesAuthorizationsEntityType(): void
    {
        require_once ROOT . '/config/Seeds/InitWorkflowDefinitionsSeed.php';

        $seed = new \InitWorkflowDefinitionsSeed();
        $authorizationWorkflow = null;

        foreach ($seed->getWorkflowMeta() as $workflowMeta) {
            if (($workflowMeta['slug'] ?? null) === 'activities-authorization-request') {
                $authorizationWorkflow = $workflowMeta;
                break;
            }
        }

        $this->assertNotNull($authorizationWorkflow);
        $this->assertSame('Activities.Authorizations', $authorizationWorkflow['entity_type']);
    }
}
