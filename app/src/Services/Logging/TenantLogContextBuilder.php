<?php
declare(strict_types=1);

namespace App\Services\Logging;

use App\Model\Entity\PlatformAdmin;
use App\Services\Tenant\TenantContext;
use Cake\Http\ServerRequest;

/**
 * Builds a consistent structured logging context for tenant-aware operations.
 */
class TenantLogContextBuilder
{
    /**
     * @param \Cake\Http\ServerRequest|null $request Request
     * @param \App\Model\Entity\PlatformAdmin|null $admin Platform admin
     * @param int|null $tenantId Tenant id
     * @param string|null $tenantSlug Tenant slug
     * @param string|null $operationId Operation id
     * @param string|null $correlationId Correlation id
     * @param array<string, mixed> $extra Additional fields
     * @return array<string, mixed>
     */
    public function build(
        ?ServerRequest $request = null,
        ?PlatformAdmin $admin = null,
        ?int $tenantId = null,
        ?string $tenantSlug = null,
        ?string $operationId = null,
        ?string $correlationId = null,
        array $extra = [],
    ): array {
        $activeTenant = TenantContext::getCurrent();
        $requestId = $request !== null ? (string)$request->getAttribute('requestId') : '';
        $requestId = trim($requestId) === '' ? null : trim($requestId);
        $context = [
            'request_id' => $requestId,
            'tenant_id' => $tenantId ?? $activeTenant?->id,
            'tenant_slug' => $tenantSlug ?? $activeTenant?->slug,
            'operation_id' => $operationId,
            'platform_admin_id' => $admin?->id,
            'correlation_id' => $correlationId ?? $requestId,
            'operation_image' => $this->environmentValue('KMP_IMAGE_REPO', 'IMAGE_REPO'),
            'operation_version' => $this->environmentValue('KMP_IMAGE_TAG', 'APP_VERSION'),
        ];
        $context = array_merge($context, $extra);

        return array_filter(
            $context,
            static fn(mixed $value): bool => $value !== null && $value !== '',
        );
    }

    /**
     * @param string $primary Primary env var
     * @param string $fallback Fallback env var
     * @return string|null
     */
    private function environmentValue(string $primary, string $fallback): ?string
    {
        $value = getenv($primary);
        if ($value === false || trim((string)$value) === '') {
            $value = getenv($fallback);
        }
        if ($value === false) {
            return null;
        }
        $value = trim((string)$value);

        return $value === '' ? null : $value;
    }
}
