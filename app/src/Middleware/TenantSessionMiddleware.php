<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Services\Tenant\TenantContext;
use Cake\Http\Response;
use Cake\Http\ServerRequest as CakeServerRequest;
use Cake\Http\Session;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Ensures persisted web sessions cannot cross tenant boundaries.
 */
class TenantSessionMiddleware implements MiddlewareInterface
{
    public const TENANT_ID_SESSION_KEY = 'Tenant.id';
    public const TENANT_SLUG_SESSION_KEY = 'Tenant.slug';

    /**
     * @var array<int, string>
     */
    private array $skipPathPrefixes;

    /**
     * @param array<int, string> $skipPathPrefixes Paths that never need tenant session validation
     */
    public function __construct(array $skipPathPrefixes = ['/health'])
    {
        $this->skipPathPrefixes = $skipPathPrefixes;
    }

    /**
     * @param \Psr\Http\Message\ServerRequestInterface $request Request
     * @param \Psr\Http\Server\RequestHandlerInterface $handler Handler
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->shouldSkip($request)) {
            return $handler->handle($request);
        }

        if (!$request instanceof CakeServerRequest) {
            return $handler->handle($request);
        }

        $session = $request->getSession();
        if (!$session->check('Auth')) {
            return $handler->handle($request);
        }

        $context = $request->getAttribute('tenantContext');
        if ($context instanceof TenantContext && $this->sessionMatchesTenant($session, $context)) {
            return $handler->handle($request);
        }

        $session->destroy();

        if (str_starts_with($request->getUri()->getPath(), '/api/')) {
            return (new Response())
                ->withStatus(401)
                ->withType('application/json')
                ->withStringBody((string)json_encode([
                    'error' => 'Tenant session mismatch. Please authenticate again.',
                ]));
        }

        return (new Response())
            ->withStatus(302)
            ->withHeader('Location', '/members/login?redirect=' . rawurlencode($request->getRequestTarget()));
    }

    /**
     * Store the resolved tenant identity in the authenticated web session.
     *
     * @param \Cake\Http\Session $session Session
     * @param \App\Services\Tenant\TenantContext $context Tenant context
     * @return void
     */
    public static function writeTenantSession(Session $session, TenantContext $context): void
    {
        $session->write(self::TENANT_ID_SESSION_KEY, $context->id);
        $session->write(self::TENANT_SLUG_SESSION_KEY, $context->slug);
    }

    /**
     * @param \Cake\Http\Session $session Session
     * @param \App\Services\Tenant\TenantContext $context Tenant context
     * @return bool
     */
    private function sessionMatchesTenant(Session $session, TenantContext $context): bool
    {
        return (int)$session->read(self::TENANT_ID_SESSION_KEY) === $context->id
            && (string)$session->read(self::TENANT_SLUG_SESSION_KEY) === $context->slug;
    }

    /**
     * @param \Psr\Http\Message\ServerRequestInterface $request Request
     * @return bool
     */
    private function shouldSkip(ServerRequestInterface $request): bool
    {
        $path = $request->getUri()->getPath() ?: '/';
        foreach ($this->skipPathPrefixes as $prefix) {
            $normalizedPrefix = rtrim($prefix, '/');
            if (
                $path === $prefix
                || (
                    $prefix !== '/'
                    && (
                        str_starts_with($path, $normalizedPrefix . '/')
                        || str_starts_with($path, $normalizedPrefix . '.')
                    )
                )
            ) {
                return true;
            }
        }

        return false;
    }
}
