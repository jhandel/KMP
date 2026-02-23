<?php

declare(strict_types=1);

namespace App\Test\TestCase\Services;

use App\Services\DockerUpdateProvider;
use App\Services\ManualUpdateProvider;
use App\Services\RailwayUpdateProvider;
use App\Services\SharedHostingUpdateProvider;
use App\Services\UpdateProviderFactory;
use Cake\Core\Configure;
use Cake\TestSuite\TestCase;

class UpdateProviderFactoryTest extends TestCase
{
    private mixed $originalProvider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalProvider = Configure::read('App.deploymentProvider');
    }

    protected function tearDown(): void
    {
        Configure::write('App.deploymentProvider', $this->originalProvider);
        parent::tearDown();
    }

    public function testCreateReturnsRailwayProvider(): void
    {
        Configure::write('App.deploymentProvider', 'railway');

        $provider = UpdateProviderFactory::create();

        $this->assertInstanceOf(RailwayUpdateProvider::class, $provider);
    }

    public function testCreateMapsVpcToDockerProvider(): void
    {
        Configure::write('App.deploymentProvider', 'vpc');

        $provider = UpdateProviderFactory::create();

        $this->assertInstanceOf(DockerUpdateProvider::class, $provider);
    }

    public function testCreateReturnsSharedHostingProvider(): void
    {
        Configure::write('App.deploymentProvider', 'shared-hosting');

        $provider = UpdateProviderFactory::create();

        $this->assertInstanceOf(SharedHostingUpdateProvider::class, $provider);
    }

    public function testCreateReturnsManualProviderForUnknownProvider(): void
    {
        Configure::write('App.deploymentProvider', 'custom-provider');

        $provider = UpdateProviderFactory::create();

        $this->assertInstanceOf(ManualUpdateProvider::class, $provider);
    }
}
