<?php
declare(strict_types=1);

namespace App\Test\TestCase\Middleware;

use App\Middleware\TenantResolutionMiddleware;
use App\Model\Entity\Tenant;
use App\Services\Tenant\TenantConnectionPoolMonitor;
use App\Services\Tenant\TenantContext;
use App\Services\Tenant\TenantResolver;
use App\Services\Tenant\TenantRuntimeConfigService;
use App\Test\TestCase\Services\Tenant\FakeTenantRegistry;
use Cake\Core\Configure;
use Cake\Datasource\Exception\MissingDatasourceConfigException;
use Cake\Http\ServerRequest;
use Cake\Routing\Router;
use Cake\TestSuite\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

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

    public function testDrainingTenantReturns503WithDeterministicDrainSemantics(): void
    {
        $tenant = new Tenant([
            'id' => 12,
            'slug' => 'draining-tenant',
            'display_name' => 'Draining Tenant',
            'status' => Tenant::STATUS_DRAINING,
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
        $this->assertSame('30', $response->getHeaderLine('Retry-After'));
        $this->assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $this->assertStringContainsString('draining for cutover', (string)$response->getBody());
        $this->assertNull($handler->request);
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

    public function testPlatformResolutionFailureReturnsSafe503BeforeHandler(): void
    {
        $handler = new RecordingHandler();
        $resolver = new class () extends TenantResolver {
            public function __construct()
            {
                parent::__construct(new FakeTenantRegistry([]));
            }

            public function resolve(ServerRequestInterface $request): TenantContext
            {
                throw new RuntimeException('SQLSTATE[HY000] [2002] Connection refused');
            }
        };
        $middleware = new TenantResolutionMiddleware(
            $resolver,
            new RecordingTenantConnectionFactory(),
        );

        $response = $middleware->process(
            (new ServerRequest(['url' => '/members']))->withHeader('Host', 'tenant.example.org'),
            $handler,
        );

        $this->assertSame(503, $response->getStatusCode());
        $this->assertNull($handler->request);
        $this->assertStringContainsString('Tenant service unavailable', (string)$response->getBody());
    }

    public function testTenantDatabaseConfigurationFailureReturnsSafe503BeforeHandler(): void
    {
        $tenant = new Tenant([
            'id' => 7,
            'slug' => 'tenant-db-unavailable',
            'display_name' => 'Tenant DB Unavailable',
            'status' => Tenant::STATUS_ACTIVE,
            'schema_version' => '2026.04',
            'tenant_database_configs' => [],
        ]);
        $handler = new RecordingHandler();
        $factory = new class () extends RecordingTenantConnectionFactory {
            public function configure(TenantContext $context): void
            {
                throw new MissingDatasourceConfigException(['name' => 'tenant']);
            }
        };
        $middleware = new TenantResolutionMiddleware(
            new TenantResolver(new FakeTenantRegistry(['tenant.example.org' => $tenant])),
            $factory,
        );

        $response = $middleware->process(
            (new ServerRequest(['url' => '/members']))->withHeader('Host', 'tenant.example.org'),
            $handler,
        );

        $this->assertSame(503, $response->getStatusCode());
        $this->assertNull($handler->request);
        $this->assertStringContainsString('Tenant service unavailable', (string)$response->getBody());
    }

    public function testHandlerExceptionStillResetsRuntimeAndTenantContext(): void
    {
        $tenant = new Tenant([
            'id' => 11,
            'slug' => 'exploding-tenant',
            'display_name' => 'Exploding Tenant',
            'status' => Tenant::STATUS_ACTIVE,
            'schema_version' => '2026.04',
            'tenant_database_configs' => [],
        ]);
        $runtimeConfig = new class extends TenantRuntimeConfigService {
            public int $applyCalls = 0;

            public int $resetCalls = 0;

            public function apply(TenantContext $context): void
            {
                $this->applyCalls++;
            }

            public function reset(): void
            {
                $this->resetCalls++;
            }
        };
        $middleware = new TenantResolutionMiddleware(
            new TenantResolver(new FakeTenantRegistry(['tenant.example.org' => $tenant])),
            new RecordingTenantConnectionFactory(),
            false,
            ['/health'],
            $runtimeConfig,
        );

        try {
            $middleware->process(
                (new ServerRequest(['url' => '/members']))->withHeader('Host', 'tenant.example.org'),
                new class implements RequestHandlerInterface {
                    public function handle(ServerRequestInterface $request): ResponseInterface
                    {
                        throw new RuntimeException('handler exploded');
                    }
                },
            );
            $this->fail('Expected handler exception was not thrown');
        } catch (RuntimeException $exception) {
            $this->assertSame('handler exploded', $exception->getMessage());
        }

        $this->assertSame(1, $runtimeConfig->applyCalls);
        $this->assertSame(1, $runtimeConfig->resetCalls);
        $this->assertNull(TenantContext::getCurrent());
    }

    public function testResolvesTenantAndSamplesConnectionPoolSignals(): void
    {
        $tenant = new Tenant([
            'id' => 13,
            'slug' => 'pooled-tenant',
            'display_name' => 'Pooled Tenant',
            'status' => Tenant::STATUS_ACTIVE,
            'schema_version' => '2026.04',
            'tenant_database_configs' => [],
        ]);
        $monitor = new class extends TenantConnectionPoolMonitor {
            public int $calls = 0;

            /**
             * @return array<string, mixed>
             */
            public function sampleTenantPool(): array
            {
                $this->calls++;

                return [
                    'sampled' => true,
                    'driver' => 'postgres',
                    'risk' => 'normal',
                    'active_connections' => 5,
                    'idle_connections' => 5,
                    'waiting_connections' => 0,
                    'total_connections' => 10,
                    'max_connections' => 20,
                    'saturation_ratio' => 0.25,
                ];
            }
        };
        $middleware = new TenantResolutionMiddleware(
            new TenantResolver(new FakeTenantRegistry(['tenant.example.org' => $tenant])),
            new RecordingTenantConnectionFactory(),
            false,
            ['/health'],
            new TenantRuntimeConfigService(),
            $monitor,
        );

        $response = $middleware->process(
            (new ServerRequest(['url' => '/members']))->withHeader('Host', 'tenant.example.org'),
            new RecordingHandler(),
        );

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame(1, $monitor->calls);
    }
}
