<?php
declare(strict_types=1);

namespace App\Test\TestCase\Middleware;

use App\Middleware\PlatformAdminHostMiddleware;
use Cake\Http\ServerRequest;
use Cake\TestSuite\TestCase;

class PlatformAdminHostMiddlewareTest extends TestCase
{
    public function testRejectsPlatformPathOnTenantHost(): void
    {
        $handler = new RecordingHandler();
        $response = (new PlatformAdminHostMiddleware(['admin.localhost']))->process(
            new ServerRequest(['url' => '/platform-admin', 'environment' => ['HTTP_HOST' => 'localhost']]),
            $handler,
        );

        $this->assertSame(404, $response->getStatusCode());
        $this->assertNull($handler->request);
    }

    public function testAllowsPlatformPathOnAdminHost(): void
    {
        $handler = new RecordingHandler();
        $response = (new PlatformAdminHostMiddleware(['admin.localhost']))->process(
            new ServerRequest(['url' => '/platform-admin', 'environment' => ['HTTP_HOST' => 'admin.localhost']]),
            $handler,
        );

        $this->assertSame(204, $response->getStatusCode());
        $this->assertTrue($handler->request?->getAttribute('isPlatformAdminHost'));
    }

    public function testRedirectsConfiguredLocalHostToAdminHost(): void
    {
        $handler = new RecordingHandler();
        $response = (new PlatformAdminHostMiddleware(['admin.localhost'], ['localhost']))->process(
            new ServerRequest([
                'url' => '/platform-admin/login?next=dashboard',
                'environment' => [
                    'HTTP_HOST' => 'localhost:8080',
                    'SERVER_PORT' => '8080',
                ],
            ]),
            $handler,
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(
            'http://admin.localhost:8080/platform-admin/login?next=dashboard',
            $response->getHeaderLine('Location'),
        );
        $this->assertNull($handler->request);
    }

    public function testRedirectsAdminHostRootToConsole(): void
    {
        $handler = new RecordingHandler();
        $response = (new PlatformAdminHostMiddleware(['admin.localhost']))->process(
            new ServerRequest(['url' => '/', 'environment' => ['HTTP_HOST' => 'admin.localhost']]),
            $handler,
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/platform-admin', $response->getHeaderLine('Location'));
        $this->assertNull($handler->request);
    }
}
