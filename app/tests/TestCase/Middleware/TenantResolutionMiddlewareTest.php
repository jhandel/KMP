<?php
declare(strict_types=1);

namespace App\Test\TestCase\Middleware;

use App\Middleware\TenantResolutionMiddleware;
use App\Model\Entity\Tenant;
use App\Services\Tenant\TenantResolver;
use App\Test\TestCase\Services\Tenant\FakeTenantRegistry;
use Cake\Core\Configure;
use Cake\Http\ServerRequest;
use Cake\Routing\Router;
use Cake\TestSuite\TestCase;

class TenantResolutionMiddlewareTest extends TestCase
{
    private string $originalFullBaseUrl;

    private string $originalSessionName;

    protected function setUp(): void
    {
        parent::setUp();
        Configure::write('App.encoding', 'UTF-8');
        $this->originalFullBaseUrl = Router::fullBaseUrl();
        $this->originalSessionName = session_name();
    }

    protected function tearDown(): void
    {
        Router::fullBaseUrl($this->originalFullBaseUrl);
        if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
            session_name($this->originalSessionName);
        }
        parent::tearDown();
    }

    public function testResolvesTenantAndConfiguresConnectionBeforeHandler(): void
    {
        $tenant = new Tenant([
            'id' => 5,
            'slug' => 'resolved-tenant',
            'display_name' => 'Resolved Tenant',
            'status' => Tenant::STATUS_ACTIVE,
            'schema_version' => '2026.04',
            'tenant_database_configs' => [],
        ]);
        $factory = new RecordingTenantConnectionFactory();
        $handler = new RecordingHandler();
        $middleware = new TenantResolutionMiddleware(
            new TenantResolver(new FakeTenantRegistry(['tenant.example.org' => $tenant])),
            $factory,
        );

        $response = $middleware->process(
            (new ServerRequest(['url' => '/members']))->withHeader('Host', 'Tenant.Example.Org:443'),
            $handler,
        );

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('resolved-tenant', $factory->configuredContext?->slug);
        $this->assertSame('resolved-tenant', $handler->request?->getAttribute('tenantContext')->slug);
        $this->assertSame('http://tenant.example.org', Router::fullBaseUrl());
        if (!headers_sent()) {
            $this->assertSame('KMPSESSID_5_resolved_tenant', session_name());
        }
    }

    public function testUnknownTenantReturnsSafe404BeforeHandler(): void
    {
        $handler = new RecordingHandler();
        $middleware = new TenantResolutionMiddleware(
            new TenantResolver(new FakeTenantRegistry([])),
            new RecordingTenantConnectionFactory(),
        );

        $response = $middleware->process(
            (new ServerRequest(['url' => '/members']))->withHeader('Host', 'missing.example.org'),
            $handler,
        );

        $this->assertSame(404, $response->getStatusCode());
        $this->assertNull($handler->request);
        $this->assertStringContainsString('Tenant not found', (string)$response->getBody());
    }

    public function testInactiveTenantReturnsSafe503BeforeHandler(): void
    {
        $tenant = new Tenant([
            'id' => 6,
            'slug' => 'maintenance-tenant',
            'display_name' => 'Maintenance Tenant',
            'status' => Tenant::STATUS_MAINTENANCE,
            'schema_version' => '2026.04',
            'tenant_database_configs' => [],
        ]);
        $handler = new RecordingHandler();
        $middleware = new TenantResolutionMiddleware(
            new TenantResolver(new FakeTenantRegistry(['tenant.example.org' => $tenant])),
            new RecordingTenantConnectionFactory(),
        );

        $response = $middleware->process(
            (new ServerRequest(['url' => '/members']))->withHeader('Host', 'tenant.example.org'),
            $handler,
        );

        $this->assertSame(503, $response->getStatusCode());
        $this->assertNull($handler->request);
        $this->assertStringContainsString('Tenant unavailable', (string)$response->getBody());
    }

    public function testHealthEndpointSkipsTenantResolution(): void
    {
        $handler = new RecordingHandler();
        $middleware = new TenantResolutionMiddleware(
            new TenantResolver(new FakeTenantRegistry([])),
            new RecordingTenantConnectionFactory(),
        );

        $response = $middleware->process(
            (new ServerRequest(['url' => '/health']))->withHeader('Host', 'missing.example.org'),
            $handler,
        );

        $this->assertSame(204, $response->getStatusCode());
        $this->assertNotNull($handler->request);
    }
}
