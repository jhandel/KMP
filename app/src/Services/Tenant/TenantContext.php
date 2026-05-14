<?php
declare(strict_types=1);

namespace App\Services\Tenant;

use App\Model\Entity\Tenant;

/**
 * Immutable request/command tenant identity and connection metadata.
 */
final class TenantContext
{
    private static ?self $current = null;

    /**
     * @param array<int, array<string, mixed>> $databaseConfigs
     * @param array<int, array<string, mixed>> $serviceConfigs
     */
    public function __construct(
        public readonly int $id,
        public readonly string $slug,
        public readonly string $displayName,
        public readonly string $status,
        public readonly ?string $schemaVersion,
        public readonly ?string $primaryHost,
        public readonly string $resolvedHost,
        public readonly array $databaseConfigs = [],
        public readonly array $serviceConfigs = [],
    ) {
    }

    /**
     * Build context from a platform tenant entity.
     *
     * @param \App\Model\Entity\Tenant $tenant Tenant entity
     * @param string $resolvedHost Normalized request host
     * @return self
     */
    public static function fromTenant(Tenant $tenant, string $resolvedHost): self
    {
        $databaseConfigs = [];
        foreach ((array)($tenant->tenant_database_configs ?? []) as $config) {
            $databaseConfigs[] = [
                'id' => $config->id,
                'connectionRole' => $config->connection_role,
                'driver' => $config->driver,
                'host' => $config->host,
                'port' => $config->port,
                'databaseName' => $config->database_name,
                'username' => $config->username,
                'secretReference' => $config->secret_reference,
                'readEnabled' => $config->read_enabled,
                'writeEnabled' => $config->write_enabled,
                'isActive' => $config->is_active,
                'metadata' => self::decodeMetadata($config->metadata),
            ];
        }
        $serviceConfigs = [];
        foreach ((array)($tenant->tenant_service_configs ?? []) as $config) {
            $serviceConfigs[] = [
                'id' => $config->id,
                'serviceName' => $config->service_name,
                'configKey' => $config->config_key,
                'adapter' => $config->adapter,
                'secretReference' => $config->secret_reference,
                'metadata' => self::decodeMetadata($config->metadata),
                'isActive' => $config->is_active,
            ];
        }

        return new self(
            (int)$tenant->id,
            (string)$tenant->slug,
            (string)$tenant->display_name,
            (string)$tenant->status,
            $tenant->schema_version === null ? null : (string)$tenant->schema_version,
            $tenant->primary_host === null ? null : TenantRegistry::normalizeHost((string)$tenant->primary_host),
            $resolvedHost,
            $databaseConfigs,
            $serviceConfigs,
        );
    }

    /**
     * Decode platform JSON metadata into arrays.
     *
     * @param mixed $metadata Metadata from ORM entity
     * @return array<string, mixed>|null
     */
    private static function decodeMetadata(mixed $metadata): ?array
    {
        if ($metadata === null || is_array($metadata)) {
            return $metadata;
        }
        if (!is_string($metadata) || $metadata === '') {
            return null;
        }
        $decoded = json_decode($metadata, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Whether this tenant context can serve normal application traffic.
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->status === Tenant::STATUS_ACTIVE;
    }

    /**
     * Set the ambient tenant for request/command-scoped cache namespacing.
     *
     * @param self|null $context Tenant context
     * @return void
     */
    public static function setCurrent(?self $context): void
    {
        self::$current = $context;
    }

    /**
     * Get the ambient tenant context when one is active.
     *
     * @return self|null
     */
    public static function getCurrent(): ?self
    {
        return self::$current;
    }

    /**
     * Clear the ambient tenant context.
     *
     * @return void
     */
    public static function clearCurrent(): void
    {
        self::$current = null;
    }

    /**
     * Prefix a tenant-sensitive cache key when a tenant context is active.
     *
     * @param string $key Cache key
     * @return string
     */
    public static function cacheKey(string $key): string
    {
        $context = self::getCurrent();
        if ($context === null) {
            return $key;
        }

        return self::cacheKeyPrefix($context) . '__' . $key;
    }

    /**
     * Tenant cache prefix safe for Cake cache engines.
     *
     * @param self|null $context Tenant context
     * @return string
     */
    public static function cacheKeyPrefix(?self $context = null): string
    {
        $context ??= self::getCurrent();
        if ($context === null) {
            return '';
        }

        $slug = preg_replace('/[^A-Za-z0-9_.-]+/', '_', $context->slug) ?: 'tenant';

        return sprintf('tenant_%d_%s', $context->id, $slug);
    }
}
