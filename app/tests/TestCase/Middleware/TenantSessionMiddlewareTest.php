<?php
declare(strict_types=1);

namespace App\Test\TestCase\Middleware;

use App\Middleware\TenantSessionMiddleware;
use App\Services\Tenant\TenantContext;
use Cake\Core\Configure;
use Cake\Http\ServerRequest;
use Cake\Http\Session;
use Cake\TestSuite\TestCase;

class TenantSessionMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Configure::write('App.encoding', 'UTF-8');
    }

    public function testMatchingTenantSessionContinuesRequest(): void
    {
        $session = new Session();
        $session->write('Auth', ['id' => 1]);
        $context = $this->tenantContext(5, 'tenant-a');
        TenantSessionMiddleware::writeTenantSession($session, $context);
        $handler = new RecordingHandler();

        $response = (new TenantSessionMiddleware())->process(
            $this->request($session, $context),
            $handler,
        );

        $this->assertSame(204, $response->getStatusCode());
        $this->assertNotNull($handler->request);
        $this->assertSame(['id' => 1], $session->read('Auth'));
    }

    public function testMismatchedTenantSessionIsDestroyedAndRedirected(): void
    {
        $session = new Session();
        $session->write('Auth', ['id' => 1]);
        TenantSessionMiddleware::writeTenantSession($session, $this->tenantContext(5, 'tenant-a'));
        $handler = new RecordingHandler();

        $response = (new TenantSessionMiddleware())->process(
            $this->request($session, $this->tenantContext(6, 'tenant-b')),
            $handler,
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('/members/login', $response->getHeaderLine('Location'));
        $this->assertNull($handler->request);
        $this->assertNull($session->read('Auth'));
        $this->assertNull($session->read(TenantSessionMiddleware::TENANT_ID_SESSION_KEY));
    }

    public function testApiTenantSessionMismatchReturnsUnauthorized(): void
    {
        $session = new Session();
        $session->write('Auth', ['id' => 1]);
        TenantSessionMiddleware::writeTenantSession($session, $this->tenantContext(5, 'tenant-a'));

        $response = (new TenantSessionMiddleware())->process(
            $this->request($session, $this->tenantContext(6, 'tenant-b'), '/api/v1/members'),
            new RecordingHandler(),
        );

        $this->assertSame(401, $response->getStatusCode());
        $this->assertStringContainsString('Tenant session mismatch', (string)$response->getBody());
        $this->assertNull($session->read('Auth'));
    }

    public function testUnauthenticatedRequestDoesNotRequireTenantSessionKeys(): void
    {
        $handler = new RecordingHandler();

        $response = (new TenantSessionMiddleware())->process(
            $this->request(new Session(), $this->tenantContext(6, 'tenant-b')),
            $handler,
        );

        $this->assertSame(204, $response->getStatusCode());
        $this->assertNotNull($handler->request);
    }

    public function testSkippedPathDoesNotValidateTenantSession(): void
    {
        $session = new Session();
        $session->write('Auth', ['id' => 1]);
        $handler = new RecordingHandler();

        $response = (new TenantSessionMiddleware(['/health']))->process(
            $this->request($session, $this->tenantContext(6, 'tenant-b'), '/health'),
            $handler,
        );

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame(['id' => 1], $session->read('Auth'));
    }

    private function request(Session $session, TenantContext $context, string $url = '/members/profile'): ServerRequest
    {
        return (new ServerRequest([
            'url' => $url,
            'session' => $session,
        ]))->withAttribute('tenantContext', $context);
    }

    private function tenantContext(int $id, string $slug): TenantContext
    {
        return new TenantContext(
            $id,
            $slug,
            ucfirst($slug),
            'active',
            '2026.04',
            $slug . '.example.org',
            $slug . '.example.org',
        );
    }
}
