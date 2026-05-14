<?php
declare(strict_types=1);

namespace App\Services\Tenant;

use Cake\Core\Configure;
use Cake\Routing\Router;

/**
 * Provides access to the current tenant context for services outside controllers.
 */
class TenantContextAccessor
{
    private const CONFIG_KEY = 'Tenant.context';

    /**
     * Set the current process/request tenant context.
     */
    public static function set(?TenantContext $context): void
    {
        if ($context === null) {
            Configure::delete(self::CONFIG_KEY);

            return;
        }

        Configure::write(self::CONFIG_KEY, $context);
    }

    /**
     * Return the current tenant context when one is active.
     */
    public static function get(): ?TenantContext
    {
        $request = Router::getRequest();
        $context = $request?->getAttribute('tenantContext');
        if ($context instanceof TenantContext) {
            return $context;
        }

        $context = Configure::read(self::CONFIG_KEY);
        if ($context instanceof TenantContext) {
            return $context;
        }

        return null;
    }

    /**
     * Build a normalized tenant path prefix for file/object storage.
     */
    public static function storagePrefix(): string
    {
        $context = self::get();
        if ($context === null) {
            return '';
        }

        return 'tenants/' . self::safeSlug($context->slug) . '/';
    }

    /**
     * Prefix a relative storage path with the current tenant namespace.
     */
    public static function prefixStoragePath(string $path): string
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');
        $prefix = self::storagePrefix();
        if ($prefix === '' || str_starts_with($path, $prefix)) {
            return $path;
        }

        return $prefix . $path;
    }

    /**
     * Normalize tenant slugs for filesystem/object storage use.
     */
    public static function safeSlug(string $slug): string
    {
        $safe = strtolower(preg_replace('/[^a-zA-Z0-9_-]+/', '-', $slug) ?? '');
        $safe = trim($safe, '-_');

        return $safe !== '' ? $safe : 'tenant';
    }
}
