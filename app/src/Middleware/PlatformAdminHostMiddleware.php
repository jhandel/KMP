<?php
declare(strict_types=1);

namespace App\Middleware;

use Cake\Http\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Enforces dedicated-host access for the platform admin console.
 */
class PlatformAdminHostMiddleware implements MiddlewareInterface
{
    /**
     * @param array<int, string> $adminHosts Allowed platform admin hosts
     * @param array<int, string> $redirectHosts Hosts that redirect platform paths to the first admin host
     */
    public function __construct(
        private readonly array $adminHosts,
        private readonly array $redirectHosts = [],
    ) {
    }

    /**
     * Reject platform console routes unless the request uses an admin host.
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request Request
     * @param \Psr\Http\Server\RequestHandlerInterface $handler Handler
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath() ?: '/';
        $host = strtolower($request->getUri()->getHost());
        $isAdminHost = in_array($host, $this->normalizedHosts(), true);
        $isPlatformPath = $path === '/platform-admin'
            || str_starts_with($path, '/platform-admin/')
            || str_starts_with($path, '/platform-admin.');

        if ($isPlatformPath && !$isAdminHost) {
            $redirectHost = $this->redirectHostFor($host);
            if ($redirectHost !== null) {
                return (new Response())
                    ->withStatus(302)
                    ->withHeader('Location', (string)$request->getUri()->withHost($redirectHost));
            }

            return (new Response())
                ->withStatus(404)
                ->withType('text/plain')
                ->withStringBody('Not found.');
        }

        if ($isAdminHost && !$isPlatformPath && $path === '/') {
            return (new Response())
                ->withStatus(302)
                ->withHeader('Location', '/platform-admin');
        }

        return $handler->handle($request->withAttribute('isPlatformAdminHost', $isAdminHost));
    }

    /**
     * @return array<int, string>
     */
    private function normalizedHosts(): array
    {
        return array_values(array_filter(array_map(
            static fn(string $host): string => strtolower(trim($host)),
            $this->adminHosts,
        )));
    }

    /**
     * @param string $host Request host
     * @return string|null Admin host to redirect to
     */
    private function redirectHostFor(string $host): ?string
    {
        $redirectHosts = array_values(array_filter(array_map(
            static fn(string $redirectHost): string => strtolower(trim($redirectHost)),
            $this->redirectHosts,
        )));
        if (!in_array($host, $redirectHosts, true)) {
            return null;
        }

        return $this->normalizedHosts()[0] ?? null;
    }
}
