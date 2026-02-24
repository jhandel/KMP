<?php

declare(strict_types=1);

namespace App\Test\TestCase\Services;

use App\Services\DeploymentUpdateCapabilityService;
use Cake\TestSuite\TestCase;

class DeploymentUpdateCapabilityServiceTest extends TestCase
{
    public function testSharedHostingCapabilitiesDisableWebUpdate(): void
    {
        $service = new DeploymentUpdateCapabilityService();
        $capabilities = $service->getCapabilitiesForProvider('shared-hosting');

        $this->assertSame('shared', $capabilities['provider']);
        $this->assertFalse($capabilities['web_update']);
        $this->assertFalse($capabilities['requires_root_access']);
        $this->assertTrue($capabilities['components']['app']['supported']);
        $this->assertFalse($capabilities['components']['updater']['supported']);
    }

    public function testVpcAliasNormalizesToDocker(): void
    {
        $service = new DeploymentUpdateCapabilityService();
        $capabilities = $service->getCapabilitiesForProvider('vpc');

        $this->assertSame('docker', $capabilities['provider']);
        $this->assertSame('cli-managed', $capabilities['update_mode']);
        $this->assertFalse($capabilities['web_update']);
        $this->assertTrue($capabilities['components']['app']['supported']);
    }

    public function testMatrixIncludesWaveOneProviders(): void
    {
        $service = new DeploymentUpdateCapabilityService();
        $matrix = $service->getMatrix();

        $this->assertArrayHasKey('docker', $matrix);
        $this->assertArrayHasKey('railway', $matrix);
        $this->assertArrayHasKey('shared', $matrix);
    }
}
