<?php
declare(strict_types=1);

namespace App\Command;

use App\Services\Tenant\TenantProvisioningService;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Throwable;

/**
 * List platform tenant registry records.
 */
class TenantListCommand extends Command
{
    /**
     * Get the default command name.
     *
     * @return string
     */
    public static function defaultName(): string
    {
        return 'tenant:list';
    }

    /**
     * Configure command options.
     *
     * @param \Cake\Console\ConsoleOptionParser $parser Option parser
     * @return \Cake\Console\ConsoleOptionParser
     */
    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return parent::buildOptionParser($parser)
            ->setDescription('List tenants in the platform registry.');
    }

    /**
     * Execute the command.
     *
     * @param \Cake\Console\Arguments $args Arguments
     * @param \Cake\Console\ConsoleIo $io Console IO
     * @return int|null
     */
    public function execute(Arguments $args, ConsoleIo $io): ?int
    {
        try {
            $rows = [];
            foreach ((new TenantProvisioningService())->listTenants() as $tenant) {
                $rows[] = [
                    $tenant->slug,
                    $tenant->display_name,
                    $tenant->status,
                    $tenant->schema_version ?? '',
                    $tenant->primary_host ?? '',
                ];
            }
            $this->outputRows($io, ['Slug', 'Name', 'Status', 'Schema', 'Primary Host'], $rows);
        } catch (Throwable $e) {
            $io->error($e->getMessage());

            return Command::CODE_ERROR;
        }

        return Command::CODE_SUCCESS;
    }

    /**
     * @param \Cake\Console\ConsoleIo $io Console IO
     * @param array<int, string> $header Header row
     * @param array<int, array<int, mixed>> $rows Data rows
     * @return void
     */
    private function outputRows(ConsoleIo $io, array $header, array $rows): void
    {
        $io->out(implode("\t", $header));
        foreach ($rows as $row) {
            $io->out(implode("\t", array_map('strval', $row)));
        }
    }
}
