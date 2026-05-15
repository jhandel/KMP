<?php
declare(strict_types=1);

namespace App\Command;

use App\Services\Platform\PlatformAuditRetentionService;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\I18n\DateTime;
use Throwable;

/**
 * Plan or execute safe archive/purge for platform audit history.
 */
class PlatformAuditRetentionCommand extends Command
{
    /**
     * @return string
     */
    public static function defaultName(): string
    {
        return 'platform_audit:retention';
    }

    /**
     * @param \Cake\Console\ConsoleOptionParser $parser Parser
     * @return \Cake\Console\ConsoleOptionParser
     */
    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return parent::buildOptionParser($parser)
            ->setDescription('Archive/purge oldest platform audit events while preserving chain anchor metadata.')
            ->addOption('before', [
                'required' => true,
                'help' => 'Delete/archive events older than this timestamp (for example 2026-06-01T00:00:00Z).',
            ])
            ->addOption('archive-path', [
                'default' => null,
                'help' => 'Optional export path for the purged prefix (JSONL unless path ends with .json).',
            ])
            ->addOption('purge', [
                'boolean' => true,
                'default' => false,
                'help' => 'Delete candidate rows after optional archival. Without this flag, command is plan/archive-only.',
            ])
            ->addOption('allow-purge-without-archive', [
                'boolean' => true,
                'default' => false,
                'help' => 'Override safety guard that requires archive output before purge.',
            ]);
    }

    /**
     * @param \Cake\Console\Arguments $args Args
     * @param \Cake\Console\ConsoleIo $io IO
     * @return int|null
     */
    public function execute(Arguments $args, ConsoleIo $io): ?int
    {
        try {
            $before = new DateTime((string)$args->getOption('before'));
            $result = (new PlatformAuditRetentionService())->execute(
                before: $before,
                archivePath: $this->nullableString($args->getOption('archive-path')),
                purge: (bool)$args->getOption('purge'),
                allowPurgeWithoutArchive: (bool)$args->getOption('allow-purge-without-archive'),
            );
        } catch (Throwable $e) {
            $io->error($e->getMessage());

            return Command::CODE_ERROR;
        }

        $plan = is_array($result['plan'] ?? null) ? $result['plan'] : [];
        if (!(bool)($plan['has_candidates'] ?? false)) {
            $io->success('No audit rows matched the retention cutoff.');

            return Command::CODE_SUCCESS;
        }

        $io->out(sprintf(
            'Retention boundary: purge_max_id=%d candidate_count=%d boundary_hash=%s',
            (int)$plan['purge_max_id'],
            (int)$plan['candidate_count'],
            (string)$plan['boundary_hash'],
        ));
        if (isset($result['archived']) && is_array($result['archived'])) {
            $io->out(sprintf(
                'Archive written: %s (events=%d sha256=%s)',
                (string)$result['archived']['path'],
                (int)$result['archived']['count'],
                (string)$result['archived']['sha256'],
            ));
        }
        if ((int)($result['deleted_count'] ?? 0) > 0) {
            $io->success(sprintf(
                'Purged %d event(s). Anchor id=%d',
                (int)$result['deleted_count'],
                (int)$result['anchor_id'],
            ));
        } else {
            $io->success('Plan/archive completed. No rows were deleted (omit --purge for this mode).');
        }

        return Command::CODE_SUCCESS;
    }

    /**
     * @param mixed $value Candidate value
     * @return string|null
     */
    private function nullableString(mixed $value): ?string
    {
        $candidate = trim((string)$value);

        return $candidate === '' ? null : $candidate;
    }
}

