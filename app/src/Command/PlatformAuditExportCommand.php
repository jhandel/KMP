<?php
declare(strict_types=1);

namespace App\Command;

use App\Services\Platform\PlatformAuditExportService;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;
use RuntimeException;
use Throwable;

/**
 * Export platform audit events for incident response and compliance.
 */
class PlatformAuditExportCommand extends Command
{
    use LocatorAwareTrait;

    /**
     * @return string
     */
    public static function defaultName(): string
    {
        return 'platform_audit:export';
    }

    /**
     * @param \Cake\Console\ConsoleOptionParser $parser Parser
     * @return \Cake\Console\ConsoleOptionParser
     */
    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return parent::buildOptionParser($parser)
            ->setDescription('Export platform audit events with optional date/tenant/action/correlation filtering.')
            ->addOption('output', [
                'required' => true,
                'help' => 'Output path. Use .json for JSON array, otherwise JSONL is used.',
            ])
            ->addOption('from', [
                'default' => null,
                'help' => 'Inclusive lower bound for created timestamp (for example 2026-06-01T00:00:00Z).',
            ])
            ->addOption('to', [
                'default' => null,
                'help' => 'Inclusive upper bound for created timestamp.',
            ])
            ->addOption('tenant-id', [
                'default' => null,
                'help' => 'Filter by tenant id.',
            ])
            ->addOption('tenant-slug', [
                'default' => null,
                'help' => 'Filter by tenant slug (resolved to tenant id).',
            ])
            ->addOption('action', [
                'default' => null,
                'help' => 'Filter by exact action name.',
            ])
            ->addOption('correlation-id', [
                'default' => null,
                'help' => 'Filter by exact metadata correlation_id value.',
            ])
            ->addOption('format', [
                'default' => null,
                'choices' => ['jsonl', 'json'],
                'help' => 'Explicit export format override.',
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
            $tenantId = $this->resolveTenantId(
                $this->nullableString($args->getOption('tenant-id')),
                $this->nullableString($args->getOption('tenant-slug')),
            );
            $outputPath = (string)$args->getOption('output');
            $format = $this->nullableString($args->getOption('format'));
            if ($format === null) {
                $format = str_ends_with(strtolower($outputPath), '.json') ? 'json' : 'jsonl';
            }
            $filters = [
                'from' => $this->nullableDateTime($args->getOption('from')),
                'to' => $this->nullableDateTime($args->getOption('to')),
                'tenant_id' => $tenantId,
                'action' => $this->nullableString($args->getOption('action')),
                'correlation_id' => $this->nullableString($args->getOption('correlation-id')),
            ];
            $summary = (new PlatformAuditExportService())->exportToFile(
                outputPath: $outputPath,
                filters: $filters,
                format: $format,
            );
        } catch (Throwable $e) {
            $io->error($e->getMessage());

            return Command::CODE_ERROR;
        }

        $io->success(sprintf('Exported %d audit event(s).', (int)$summary['count']));
        $io->out(sprintf('Path: %s', (string)$summary['path']));
        $io->out(sprintf('Format: %s', (string)$summary['format']));
        $io->out(sprintf('SHA-256: %s', (string)$summary['sha256']));
        if ($summary['first_event_id'] !== null) {
            $io->out(sprintf(
                'Boundary: first_id=%d last_id=%d last_hash=%s',
                (int)$summary['first_event_id'],
                (int)$summary['last_event_id'],
                (string)$summary['last_event_hash'],
            ));
        }

        return Command::CODE_SUCCESS;
    }

    /**
     * @param mixed $value Candidate string
     * @return string|null
     */
    private function nullableString(mixed $value): ?string
    {
        $candidate = trim((string)$value);

        return $candidate === '' ? null : $candidate;
    }

    /**
     * @param mixed $value Candidate datetime string
     * @return \Cake\I18n\DateTime|null
     */
    private function nullableDateTime(mixed $value): ?DateTime
    {
        $candidate = $this->nullableString($value);
        if ($candidate === null) {
            return null;
        }

        return new DateTime($candidate);
    }

    /**
     * @param string|null $tenantId Tenant id option
     * @param string|null $tenantSlug Tenant slug option
     * @return int|null
     */
    private function resolveTenantId(?string $tenantId, ?string $tenantSlug): ?int
    {
        if ($tenantId !== null && $tenantSlug !== null) {
            throw new RuntimeException('Use only one of --tenant-id or --tenant-slug.');
        }
        if ($tenantId !== null) {
            if (!is_numeric($tenantId)) {
                throw new RuntimeException('--tenant-id must be numeric.');
            }

            return (int)$tenantId;
        }
        if ($tenantSlug === null) {
            return null;
        }
        $tenant = $this->fetchTable('Tenants')->find()
            ->select(['id'])
            ->where(['slug' => $tenantSlug])
            ->first();
        if ($tenant === null) {
            throw new RuntimeException(sprintf('Tenant slug "%s" was not found.', $tenantSlug));
        }

        return (int)$tenant->id;
    }
}

