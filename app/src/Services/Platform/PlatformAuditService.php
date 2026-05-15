<?php
declare(strict_types=1);

namespace App\Services\Platform;

use App\Model\Entity\PlatformAdmin;
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

    private const REDACTED_KEYS = [
        'password',
        'password_hash',
        'mfa_code',
        'email_code',
        'verify_email_code',
        'recovery_code',
        'backup_key',
        'restore_key',
        'secret',
        'database_secret_value',
        'email_secret_value',
        'storage_secret_value',
        'secret_reference_value',
        'token',
    ];

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
    ): EntityInterface {
        $events = $this->fetchTable('PlatformAuditEvents');
        $previous = $events->find()
            ->select(['event_hash'])
            ->orderByDesc('id')
            ->first();
        $previousHash = is_object($previous) ? (string)$previous->get('event_hash') : null;
        $metadata = $this->redact($metadata);
        $payload = [
            'platform_admin_id' => $admin?->id,
            'tenant_id' => $tenantId,
            'event_type' => $eventType,
            'severity' => $result === 'success' ? 'info' : 'warning',
            'action' => $action,
            'result' => $result,
            'subject_type' => $metadata['subject_type'] ?? null,
            'subject_id' => isset($metadata['subject_id']) ? (string)$metadata['subject_id'] : null,
            'request_id' => $request?->getAttribute('requestId'),
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

    /**
     * @param array<string, mixed> $value Metadata
     * @return array<string, mixed>
     */
    private function redact(array $value): array
    {
        foreach ($value as $key => $item) {
            if (in_array(strtolower((string)$key), self::REDACTED_KEYS, true)) {
                $value[$key] = '[redacted]';
                continue;
            }
            if (is_array($item)) {
                $value[$key] = $this->redact($item);
            }
        }

        return $value;
    }
}
