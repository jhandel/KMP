<?php
declare(strict_types=1);

namespace App\Services\Tenant;

use App\Model\Entity\Tenant;
use App\Services\Telemetry\TenantMetrics;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * Resolves the current tenant from host-based routing metadata.
 */
class TenantResolver
{
    /**
     * Constructor.
     *
     * @param \App\Services\Tenant\TenantRegistry $registry Platform tenant registry
     * @param string|null $requiredSchemaVersion Required schema version gate
     */
    public function __construct(
        private readonly TenantRegistry $registry,
        private readonly ?string $requiredSchemaVersion = null,
    ) {
    }

    /**
     * Normalize host casing, ports, URL wrappers, and trailing dots.
     *
     * @param string $host Raw host or URL
     * @return string
     */
    public static function normalizeHost(string $host): string
    {
        return TenantRegistry::normalizeHost($host);
    }

    /**
     * Resolve tenant context from a PSR-7 request host.
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request Request
     * @return \App\Services\Tenant\TenantContext
     */
    public function resolve(ServerRequestInterface $request): TenantContext
    {
        $host = $request->getHeaderLine('Host');
        if ($host === '') {
            $host = $request->getUri()->getHost();
        }

        return $this->resolveHost($host);
    }

    /**
     * Resolve tenant context from a raw host value.
     *
     * @param string $host Raw host
     * @return \App\Services\Tenant\TenantContext
     * @throws \App\Services\Tenant\TenantResolutionException
     */
    public function resolveHost(string $host): TenantContext
    {
        $startedAt = hrtime(true);
        $outcome = 'error';
        try {
            $normalizedHost = self::normalizeHost($host);
            if ($normalizedHost === '') {
                throw new TenantResolutionException(
                    TenantResolutionException::EMPTY_HOST,
                    'Tenant could not be resolved because the request host is empty.',
                );
            }

            $tenant = $this->registry->findTenantForHost($normalizedHost);
            if ($tenant === null) {
                throw new TenantResolutionException(
                    TenantResolutionException::UNKNOWN_TENANT,
                    sprintf('No tenant is registered for host "%s".', $normalizedHost),
                    $normalizedHost,
                );
            }

            if ($tenant->status === Tenant::STATUS_DRAINING) {
                throw new TenantResolutionException(
                    TenantResolutionException::DRAINING_TENANT,
                    sprintf('Tenant "%s" is in drain mode.', $tenant->slug),
                    $normalizedHost,
                    (string)$tenant->slug,
                );
            }

            if ($tenant->status !== Tenant::STATUS_ACTIVE) {
                throw new TenantResolutionException(
                    TenantResolutionException::INACTIVE_TENANT,
                    sprintf('Tenant "%s" is not active.', $tenant->slug),
                    $normalizedHost,
                    (string)$tenant->slug,
                );
            }

            if (
                $this->requiredSchemaVersion !== null
                && (string)($tenant->schema_version ?? '') !== $this->requiredSchemaVersion
            ) {
                throw new TenantResolutionException(
                    TenantResolutionException::SCHEMA_MISMATCH,
                    sprintf('Tenant "%s" schema version does not match the required version.', $tenant->slug),
                    $normalizedHost,
                    (string)$tenant->slug,
                );
            }

            $outcome = 'success';
            TenantMetrics::incrementTenantHealthSignal('resolution_success');

            return TenantContext::fromTenant($tenant, $normalizedHost);
        } catch (TenantResolutionException $exception) {
            $outcome = $this->outcomeFromReason($exception->getReason());
            TenantMetrics::incrementTenantHealthSignal($this->healthSignalFromOutcome($outcome));

            throw $exception;
        } catch (Throwable $exception) {
            $outcome = 'error';
            TenantMetrics::incrementTenantHealthSignal('resolution_error');

            throw $exception;
        } finally {
            $elapsedMilliseconds = (hrtime(true) - $startedAt) / 1_000_000;
            TenantMetrics::observeTenantResolutionLatency($elapsedMilliseconds, $outcome);
        }
    }

    /**
     * @param string $reason Resolution exception reason
     * @return string
     */
    private function outcomeFromReason(string $reason): string
    {
        return match ($reason) {
            TenantResolutionException::EMPTY_HOST => 'empty_host',
            TenantResolutionException::UNKNOWN_TENANT => 'unknown_tenant',
            TenantResolutionException::DRAINING_TENANT => 'draining_tenant',
            TenantResolutionException::INACTIVE_TENANT => 'inactive_tenant',
            TenantResolutionException::SCHEMA_MISMATCH => 'schema_mismatch',
            default => 'error',
        };
    }

    /**
     * @param string $outcome Sanitized metric outcome
     * @return string
     */
    private function healthSignalFromOutcome(string $outcome): string
    {
        return match ($outcome) {
            'empty_host' => 'resolution_empty_host',
            'unknown_tenant' => 'resolution_unknown_tenant',
            'draining_tenant' => 'resolution_draining_tenant',
            'inactive_tenant' => 'resolution_inactive_tenant',
            'schema_mismatch' => 'resolution_schema_mismatch',
            default => 'resolution_error',
        };
    }
}
