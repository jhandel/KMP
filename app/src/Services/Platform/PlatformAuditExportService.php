<?php
declare(strict_types=1);

namespace App\Services\Platform;

use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\ORM\Query\SelectQuery;
use RuntimeException;

/**
 * Exports platform audit events with optional filters.
 */
class PlatformAuditExportService
{
    use LocatorAwareTrait;

    /**
     * Export audit events to a file.
     *
     * @param string $outputPath Absolute or project-relative output path
     * @param array<string, mixed> $filters Query filters
     * @param string $format Export format (jsonl|json)
     * @return array<string, mixed>
     */
    public function exportToFile(string $outputPath, array $filters = [], string $format = 'jsonl'): array
    {
        $normalizedFormat = strtolower(trim($format));
        if (!in_array($normalizedFormat, ['jsonl', 'json'], true)) {
            throw new RuntimeException('Unsupported export format. Use "jsonl" or "json".');
        }

        $dir = dirname($outputPath);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException(sprintf('Unable to create export directory: %s', $dir));
        }

        $stream = @fopen($outputPath, 'wb');
        if (!is_resource($stream)) {
            throw new RuntimeException(sprintf('Unable to open export path: %s', $outputPath));
        }

        $hashContext = hash_init('sha256');
        $count = 0;
        $firstEventId = null;
        $lastEventId = null;
        $lastEventHash = null;
        $lastPreviousHash = null;

        try {
            if ($normalizedFormat === 'json') {
                fwrite($stream, '[');
            }
            $isFirstJsonRecord = true;

            foreach ($this->findFilteredEvents($filters) as $event) {
                $row = $this->serializeEvent($event);
                $line = json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
                if (!is_string($line)) {
                    throw new RuntimeException('Failed to serialize audit export line.');
                }

                if ($normalizedFormat === 'jsonl') {
                    fwrite($stream, $line . PHP_EOL);
                    hash_update($hashContext, $line . "\n");
                } else {
                    if (!$isFirstJsonRecord) {
                        fwrite($stream, ',');
                        hash_update($hashContext, ',');
                    }
                    fwrite($stream, $line);
                    hash_update($hashContext, $line);
                    $isFirstJsonRecord = false;
                }

                $count++;
                $firstEventId ??= (int)$event->id;
                $lastEventId = (int)$event->id;
                $lastEventHash = (string)$event->event_hash;
                $lastPreviousHash = $event->previous_hash !== null ? (string)$event->previous_hash : null;
            }

            if ($normalizedFormat === 'json') {
                fwrite($stream, ']');
                hash_update($hashContext, ']');
            }
        } finally {
            fclose($stream);
        }

        return [
            'count' => $count,
            'path' => $outputPath,
            'format' => $normalizedFormat,
            'sha256' => hash_final($hashContext),
            'first_event_id' => $firstEventId,
            'last_event_id' => $lastEventId,
            'last_event_hash' => $lastEventHash,
            'last_previous_hash' => $lastPreviousHash,
        ];
    }

    /**
     * Find audit events matching supported filters.
     *
     * @param array<string, mixed> $filters Query filters
     * @return \Cake\ORM\Query\SelectQuery
     */
    public function findFilteredEvents(array $filters = []): SelectQuery
    {
        $query = $this->fetchTable('PlatformAuditEvents')->find()
            ->contain(['PlatformAdmins', 'Tenants'])
            ->orderByAsc('PlatformAuditEvents.id');

        if (!empty($filters['from'])) {
            $query->where(['PlatformAuditEvents.created >=' => $this->asDateTime($filters['from'])]);
        }
        if (!empty($filters['to'])) {
            $query->where(['PlatformAuditEvents.created <=' => $this->asDateTime($filters['to'])]);
        }
        if (isset($filters['tenant_id']) && $filters['tenant_id'] !== null && $filters['tenant_id'] !== '') {
            $query->where(['PlatformAuditEvents.tenant_id' => (int)$filters['tenant_id']]);
        }
        if (!empty($filters['action'])) {
            $query->where(['PlatformAuditEvents.action' => (string)$filters['action']]);
        }
        if (!empty($filters['correlation_id'])) {
            $quoted = addcslashes((string)$filters['correlation_id'], '\\%"_');
            $query->where(['PlatformAuditEvents.metadata LIKE' => sprintf('%%"correlation_id":"%s"%%', $quoted)]);
        }
        if (isset($filters['min_id']) && is_numeric((string)$filters['min_id'])) {
            $query->where(['PlatformAuditEvents.id >=' => (int)$filters['min_id']]);
        }
        if (isset($filters['max_id']) && is_numeric((string)$filters['max_id'])) {
            $query->where(['PlatformAuditEvents.id <=' => (int)$filters['max_id']]);
        }

        return $query;
    }

    /**
     * @param mixed $value Candidate datetime input
     * @return \Cake\I18n\DateTime
     */
    private function asDateTime(mixed $value): DateTime
    {
        if ($value instanceof DateTime) {
            return $value;
        }

        return new DateTime((string)$value);
    }

    /**
     * @param \Cake\Datasource\EntityInterface $event Audit event
     * @return array<string, mixed>
     */
    private function serializeEvent(object $event): array
    {
        $created = $event->created ?? null;
        $createdValue = is_object($created) && method_exists($created, 'format')
            ? $created->format(DATE_ATOM)
            : ($created !== null ? (string)$created : null);

        $metadata = $event->metadata;
        if (is_string($metadata) && $metadata !== '') {
            $decoded = json_decode($metadata, true);
            $metadata = is_array($decoded) ? $decoded : ['raw' => $metadata];
        }
        if (!is_array($metadata)) {
            $metadata = $metadata === null ? null : ['raw' => (string)$metadata];
        }

        return [
            'id' => (int)$event->id,
            'created' => $createdValue,
            'platform_admin_id' => $event->platform_admin_id !== null ? (int)$event->platform_admin_id : null,
            'platform_admin_email' => $event->platform_admin->email ?? null,
            'tenant_id' => $event->tenant_id !== null ? (int)$event->tenant_id : null,
            'tenant_slug' => $event->tenant->slug ?? null,
            'event_type' => (string)$event->event_type,
            'severity' => (string)$event->severity,
            'action' => (string)$event->action,
            'result' => (string)$event->result,
            'subject_type' => $event->subject_type,
            'subject_id' => $event->subject_id,
            'request_id' => $event->request_id,
            'ip_address' => $event->ip_address,
            'user_agent' => $event->user_agent,
            'metadata' => $metadata,
            'previous_hash' => $event->previous_hash,
            'event_hash' => (string)$event->event_hash,
        ];
    }
}
