<?php
declare(strict_types=1);

namespace App\Command;

use App\Services\Tenant\TenantMigrationService;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Throwable;

/**
 * Run platform registry migrations against the global platform datastore.
 */
class PlatformMigrateCommand extends Command
{
    /**
     * Get the default command name.
     *
     * @return string
     */
    public static function defaultName(): string
    {
        return 'platform:migrate';
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
            ->setDescription('Run platform registry migrations on the separate platform datastore.');
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
            $scopes = (new TenantMigrationService())->migratePlatform();
            $io->success('Platform migrations complete: ' . implode(', ', $scopes));

            return Command::CODE_SUCCESS;
        } catch (Throwable $e) {
            $io->error($e->getMessage());

            return Command::CODE_ERROR;
        }
    }
}
