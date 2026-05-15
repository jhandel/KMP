<?php
declare(strict_types=1);

namespace App\Services\Platform;

use App\Model\Entity\PlatformAdmin;
use App\Services\Logging\SensitiveDataRedactor;
use App\Services\Logging\TenantLogContextBuilder;
use Cake\Datasource\EntityInterface;
use Cake\Http\ServerRequest;
use Cake\ORM\Locator\LocatorAwareTrait;
use JsonException;

/**
 * Writes redacted, hash-chained platform audit events.
 */
class PlatformAuditService
{
    use LocatorAwareTrait;

    /**
     * @param \App\Services\Logging\SensitiveDataRedactor|null $redactor Redactor
     * @param \App\Services\Logging\TenantLogContextBuilder|null $contextBuilder Context builder
     */
    public function __construct(
        private readonly ?SensitiveDataRedactor $redactor = null,
        private readonly ?TenantLogContextBuilder $contextBuilder = null,
    ) {
    }

    /**
     * Record a platform audit event.
     *
     * @param string $action Action identifier
     * @param string $result Result string
     * @param array<string, mixed> $metadata Metadata payload
     * @param \App\Model\Entity\PlatformAdmin|null $admin Platform admin
     * @param \Cake\Http\ServerRequest|null $request Request context
     * @param int|null $tenantId Tenant id
     * @param string $eventType Event type
     * @param array<string, mixed> $contextOverrides Extra context fields
     * @return \Cake\Datasource\EntityInterface
     */
    public function record(
        string $action,
        string $result,
        array $metadata = [],
        ?PlatformAdmin $admin = null,
        ?ServerRequest $request = null,
        ?int $tenantId = null,
        string $eventType = 'platform_admin',
        array $contextOverrides = [],
    ): EntityInterface {
        $events = $this->fetchTable('PlatformAuditEvents');
        $previous = $events->find()
            ->select(['event_hash'])
            ->orderByDesc('id')
            ->first();
        $previousHash = is_object($previous) ? (string)$previous->get('event_hash') : null;
        $contextBuilder = $this->contextBuilder ?? new TenantLogContextBuilder();
        $context = $contextBuilder->build(
            request: $request,
            admin: $admin,
            tenantId: $tenantId,
            tenantSlug: isset($metadata['tenant_slug']) ? (string)$metadata['tenant_slug'] : null,
            operationId: isset($metadata['operation_id']) ? (string)$metadata['operation_id'] : null,
            correlationId: isset($metadata['correlation_id']) ? (string)$metadata['correlation_id'] : null,
            extra: $contextOverrides,
        );
        $resolvedTenantId = $tenantId;
        $metadata = array_merge($context, $metadata);
        $metadata = ($this->redactor ?? new SensitiveDataRedactor())->redact($metadata);
        $payload = [
            'platform_admin_id' => $admin?->id,
            'tenant_id' => $resolvedTenantId,
            'event_type' => $eventType,
            'severity' => $result === 'success' ? 'info' : 'warning',
            'action' => $action,
            'result' => $result,
            'subject_type' => $metadata['subject_type'] ?? null,
            'subject_id' => isset($metadata['subject_id']) ? (string)$metadata['subject_id'] : null,
            'request_id' => isset($context['request_id']) ? (string)$context['request_id'] : null,
            'ip_address' => $request?->clientIp(),
            'user_agent' => $request?->getHeaderLine('User-Agent'),
            'metadata' => $metadata === [] ? null : $this->encodeMetadata($metadata),
            'previous_hash' => $previousHash,
        ];
        $payload['event_hash'] = $this->eventHash($payload);

        $event = $events->newEntity($payload);
        $events->saveOrFail($event);

        return $event;
    }

    /**
     * @param array<string, mixed> $payload Event payload
     */
    private function eventHash(array $payload): string
    {
        try {
            $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            $json = serialize($payload);
        }

        return hash('sha256', $json);
    }

    /**
     * @param array<string, mixed> $metadata Redacted metadata
     */
    private function encodeMetadata(array $metadata): string
    {
        try {
            return json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            return json_encode(['encoding_error' => true], JSON_THROW_ON_ERROR);
        }
    }
}
