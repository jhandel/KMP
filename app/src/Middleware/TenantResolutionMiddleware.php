<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Services\Logging\SensitiveDataRedactor;
use App\Services\Logging\TenantLogContextBuilder;
use App\Services\Tenant\TenantConnectionFactory;
use App\Services\Tenant\TenantConnectionPoolMonitor;
use App\Services\Tenant\TenantContext;
use App\Services\Tenant\TenantInvalidationApplier;
use App\Services\Tenant\TenantResolutionException;
use App\Services\Tenant\TenantResolver;
use App\Services\Tenant\TenantRuntimeConfigService;
use Cake\Http\Response;
use Cake\Http\ServerRequest as CakeServerRequest;
use Cake\Log\Log;
use Cake\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * Resolves host-based tenant context before routing/auth/ORM access.
 */
class TenantResolutionMiddleware implements MiddlewareInterface
{
    private const SESSION_COOKIE_PREFIX = 'KMPSESSID_';

    /**
     * @var array<int, string>
     */
    private array $skipPathPrefixes;

    private readonly TenantInvalidationApplier $invalidationApplier;
    private readonly TenantLogContextBuilder $logContextBuilder;
    private readonly SensitiveDataRedactor $redactor;

    /**
     * @param \App\Services\Tenant\TenantResolver $resolver Tenant resolver
     * @param \App\Services\Tenant\TenantConnectionFactory $connectionFactory Tenant connection factory
     * @param bool $allowSingleTenantFallback Allow legacy boot if platform registry is unavailable
     * @param array<int, string> $skipPathPrefixes Paths that never need tenant context
     * @param \App\Services\Tenant\TenantConnectionPoolMonitor $connectionPoolMonitor Pool monitoring sampler
     */
    public function __construct(
        private readonly TenantResolver $resolver,
        private readonly TenantConnectionFactory $connectionFactory,
        private readonly bool $allowSingleTenantFallback = false,
        array $skipPathPrefixes = ['/health'],
        private readonly TenantRuntimeConfigService $runtimeConfigService = new TenantRuntimeConfigService(),
        private readonly TenantConnectionPoolMonitor $connectionPoolMonitor = new TenantConnectionPoolMonitor(),
        ?TenantInvalidationApplier $invalidationApplier = null,
        ?TenantLogContextBuilder $logContextBuilder = null,
        ?SensitiveDataRedactor $redactor = null,
    ) {
        $this->skipPathPrefixes = $skipPathPrefixes;
        $this->invalidationApplier = $invalidationApplier ?? new TenantInvalidationApplier(
            connectionFactory: $this->connectionFactory,
            runtimeConfigService: $this->runtimeConfigService,
        );
        $this->logContextBuilder = $logContextBuilder ?? new TenantLogContextBuilder();
        $this->redactor = $redactor ?? new SensitiveDataRedactor();
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

        try {
            $context = $this->resolver->resolve($request);
            $this->invalidationApplier->apply($context);
            $this->connectionFactory->configure($context);
            $this->runtimeConfigService->apply($context);
            $this->emitConnectionPoolSignals($request);
            $this->configureTenantRoutingAndSession($request, $context);
        } catch (TenantResolutionException $exception) {
            TenantContext::clearCurrent();

            return $this->resolutionFailureResponse($exception);
        } catch (Throwable $exception) {
            TenantContext::clearCurrent();
            $context = $this->redactor->redact($this->logContextBuilder->build(
                request: $request instanceof CakeServerRequest ? $request : null,
                extra: [
                    'error_class' => $exception::class,
                    'error_message' => $exception->getMessage(),
                ],
            ));
            if ($this->allowSingleTenantFallback) {
                Log::warning('Tenant resolution fallback active.', $context);
                $this->connectionFactory->resetOrmState();

                return $handler->handle($request);
            }

            Log::error('Tenant resolution failed before request dispatch.', $context);

            return $this->safeResponse(503, 'Tenant service unavailable.');
        }

        try {
            return $handler->handle($request->withAttribute('tenantContext', $context));
        } finally {
            $this->runtimeConfigService->reset();
            TenantContext::clearCurrent();
        }
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

    /**
     * @param \App\Services\Tenant\TenantResolutionException $exception Resolution exception
     * @return \Psr\Http\Message\ResponseInterface
     */
    private function resolutionFailureResponse(TenantResolutionException $exception): ResponseInterface
    {
        return match ($exception->getReason()) {
            TenantResolutionException::UNKNOWN_TENANT,
            TenantResolutionException::EMPTY_HOST => $this->safeResponse(404, 'Tenant not found.'),
            TenantResolutionException::DRAINING_TENANT => $this->drainingResponse(),
            default => $this->safeResponse(503, 'Tenant unavailable.'),
        };
    }

    /**
     * @param int $status Status code
     * @param string $message Safe response body
     * @return \Psr\Http\Message\ResponseInterface
     */
    private function safeResponse(int $status, string $message): ResponseInterface
    {
        return (new Response())
            ->withStatus($status)
            ->withType('text/plain')
            ->withStringBody($message);
    }

    /**
     * Drain-mode response for deterministic request shedding during cutover.
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    private function drainingResponse(): ResponseInterface
    {
        return $this->safeResponse(503, 'Tenant is draining for cutover. Retry shortly.')
            ->withHeader('Retry-After', '30')
            ->withHeader('Cache-Control', 'no-store');
    }

    /**
     * Configure host-based URL generation and per-tenant session cookie naming.
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request Request
     * @param \App\Services\Tenant\TenantContext $context Tenant context
     * @return void
     */
    private function configureTenantRoutingAndSession(ServerRequestInterface $request, TenantContext $context): void
    {
        Router::fullBaseUrl($this->baseUrlForRequest($request, $context));

        if ($request instanceof CakeServerRequest) {
            $request->getSession()->options([
                'session.name' => $this->sessionCookieName($context),
            ]);
        }
    }

    /**
     * @param \Psr\Http\Message\ServerRequestInterface $request Request
     * @param \App\Services\Tenant\TenantContext $context Tenant context
     * @return string
     */
    private function baseUrlForRequest(ServerRequestInterface $request, TenantContext $context): string
    {
        $uri = $request->getUri();
        $scheme = $uri->getScheme() ?: 'http';
        [$host, $port] = $this->hostAndPortForRequest($request, $context);

        if ($port !== null && !in_array($port, [80, 443], true)) {
            $host .= ':' . $port;
        }

        return $scheme . '://' . $host;
    }

    /**
     * @param \Psr\Http\Message\ServerRequestInterface $request Request
     * @param \App\Services\Tenant\TenantContext $context Tenant context
     * @return array{0:string,1:int|null}
     */
    private function hostAndPortForRequest(ServerRequestInterface $request, TenantContext $context): array
    {
        $hostHeader = trim($request->getHeaderLine('Host'));
        if ($hostHeader === '') {
            $uri = $request->getUri();

            return [$uri->getHost() ?: $context->resolvedHost, $uri->getPort()];
        }

        $hostHeader = preg_replace('/[\/?#].*$/', '', $hostHeader) ?? $hostHeader;
        $hostHeader = rtrim(strtolower($hostHeader), '.');
        if (str_starts_with($hostHeader, '[')) {
            $end = strpos($hostHeader, ']');
            if ($end !== false) {
                $host = substr($hostHeader, 1, $end - 1);
                $port = substr($hostHeader, $end + 1);

                return [$host, str_starts_with($port, ':') ? (int)substr($port, 1) : null];
            }
        }

        if (substr_count($hostHeader, ':') === 1) {
            [$host, $port] = explode(':', $hostHeader, 2);

            return [$host, ctype_digit($port) ? (int)$port : null];
        }

        return [$hostHeader, null];
    }

    /**
     * @param \App\Services\Tenant\TenantContext $context Tenant context
     * @return string
     */
    private function sessionCookieName(TenantContext $context): string
    {
        $slug = preg_replace('/[^A-Za-z0-9]/', '_', $context->slug) ?: 'tenant';

        return self::SESSION_COOKIE_PREFIX . $context->id . '_' . $slug;
    }

    /**
     * Emit tenant connection-pool observability signals for dashboards/alerts.
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request Request
     * @return void
     */
    private function emitConnectionPoolSignals(ServerRequestInterface $request): void
    {
        $sample = $this->connectionPoolMonitor->sampleTenantPool();
        if (($sample['sampled'] ?? false) === true) {
            $risk = (string)($sample['risk'] ?? 'normal');
            if (!in_array($risk, ['high', 'critical'], true)) {
                return;
            }
            $context = $this->redactor->redact($this->logContextBuilder->build(
                request: $request instanceof CakeServerRequest ? $request : null,
                extra: [
                    'pool_driver' => $sample['driver'] ?? 'other',
                    'pool_risk' => $risk,
                    'pool_saturation_ratio' => (float)($sample['saturation_ratio'] ?? 0.0),
                    'pool_active_connections' => (int)($sample['active_connections'] ?? 0),
                    'pool_idle_connections' => (int)($sample['idle_connections'] ?? 0),
                    'pool_waiting_connections' => (int)($sample['waiting_connections'] ?? 0),
                    'pool_total_connections' => (int)($sample['total_connections'] ?? 0),
                    'pool_max_connections' => (int)($sample['max_connections'] ?? 0),
                ],
            ));
            Log::warning(
                'Tenant connection pool saturation risk detected.',
                $context + ['scope' => ['app.performance']],
            );

            return;
        }

        if (($sample['reason'] ?? '') === 'probe_failed') {
            $context = $this->redactor->redact($this->logContextBuilder->build(
                request: $request instanceof CakeServerRequest ? $request : null,
                extra: [
                    'pool_driver' => $sample['driver'] ?? 'other',
                    'pool_probe_error' => $sample['error'] ?? 'other',
                ],
            ));
            Log::warning('Tenant connection pool probe failed.', $context + ['scope' => ['app.performance']]);
        }
    }
}
