<?php
declare(strict_types=1);

namespace App\Services\Platform;

use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;
use RuntimeException;

/**
 * Archives and purges oldest platform audit rows while preserving chain anchors.
 */
class PlatformAuditRetentionService
{
    use LocatorAwareTrait;

    /**
     * Build a retention plan for events older than a cutoff.
     *
     * @param \Cake\I18n\DateTime $before Cutoff timestamp
     * @return array<string, mixed>
     */
    public function plan(DateTime $before): array
    {
        $events = $this->fetchTable('PlatformAuditEvents');
        $candidate = $events->find()
            ->select(['id', 'event_hash', 'created'])
            ->where(['created <' => $before])
            ->orderByDesc('id')
            ->first();

        if ($candidate === null) {
            return [
                'has_candidates' => false,
                'purge_max_id' => null,
                'candidate_count' => 0,
                'boundary_hash' => null,
                'next_event_id' => null,
                'next_event_previous_hash' => null,
            ];
        }

        $purgeMaxId = (int)$candidate->id;
        $candidateCount = (int)$events->find()
            ->where(['id <=' => $purgeMaxId])
            ->count();

        $next = $events->find()
            ->select(['id', 'previous_hash'])
            ->where(['id >' => $purgeMaxId])
            ->orderByAsc('id')
            ->first();

        return [
            'has_candidates' => true,
            'purge_max_id' => $purgeMaxId,
            'candidate_count' => $candidateCount,
            'boundary_hash' => (string)$candidate->event_hash,
            'next_event_id' => $next !== null ? (int)$next->id : null,
            'next_event_previous_hash' => $next !== null && $next->previous_hash !== null
                ? (string)$next->previous_hash
                : null,
        ];
    }

    /**
     * Execute archive/purge retention for oldest events.
     *
     * @param \Cake\I18n\DateTime $before Cutoff timestamp
     * @param string|null $archivePath Optional export path
     * @param bool $purge Whether to delete candidates
     * @param bool $allowPurgeWithoutArchive Skip archive requirement
     * @return array<string, mixed>
     */
    public function execute(
        DateTime $before,
        ?string $archivePath = null,
        bool $purge = false,
        bool $allowPurgeWithoutArchive = false,
    ): array {
        $plan = $this->plan($before);
        if (!(bool)$plan['has_candidates']) {
            return [
                'plan' => $plan,
                'archived' => null,
                'deleted_count' => 0,
                'anchor_id' => null,
            ];
        }

        if ($purge && $archivePath === null && !$allowPurgeWithoutArchive) {
            throw new RuntimeException(
                'Refusing purge without archive. Provide --archive-path or use --allow-purge-without-archive.',
            );
        }
        if ($plan['next_event_id'] !== null && $plan['next_event_previous_hash'] !== $plan['boundary_hash']) {
            throw new RuntimeException(
                'Audit chain boundary mismatch. Refusing purge because remaining chain would lose verifiable linkage.',
            );
        }

        $archiveSummary = null;
        if ($archivePath !== null) {
            $archiveSummary = (new PlatformAuditExportService())->exportToFile(
                outputPath: $archivePath,
                filters: ['max_id' => (int)$plan['purge_max_id']],
                format: str_ends_with(strtolower($archivePath), '.json') ? 'json' : 'jsonl',
            );
        }

        if (!$purge) {
            return [
                'plan' => $plan,
                'archived' => $archiveSummary,
                'deleted_count' => 0,
                'anchor_id' => null,
            ];
        }

        $events = $this->fetchTable('PlatformAuditEvents');
        $anchors = $this->fetchTable('PlatformAuditRetentionAnchors');
        $connection = $events->getConnection();

        return $connection->transactional(function () use ($events, $anchors, $plan, $archiveSummary): array {
            $anchor = $anchors->newEntity([
                'purged_before_event_id' => (int)$plan['purge_max_id'],
                'purged_before_event_hash' => (string)$plan['boundary_hash'],
                'next_event_id' => $plan['next_event_id'] !== null ? (int)$plan['next_event_id'] : null,
                'next_event_previous_hash' => $plan['next_event_previous_hash'],
                'archived_path' => $archiveSummary['path'] ?? null,
                'archive_sha256' => $archiveSummary['sha256'] ?? null,
                'archive_event_count' => $archiveSummary['count'] ?? null,
                'metadata' => [
                    'archive' => $archiveSummary,
                    'retention_plan' => $plan,
                ],
            ]);
            $anchors->saveOrFail($anchor);

            $events->deleteAll(['id <=' => (int)$plan['purge_max_id']]);

            return [
                'plan' => $plan,
                'archived' => $archiveSummary,
                'deleted_count' => (int)$plan['candidate_count'],
                'anchor_id' => (int)$anchor->id,
            ];
        });
    }
}

