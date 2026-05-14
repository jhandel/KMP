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
 * RevertDatabase command.
 */
class UpdateDatabaseCommand extends Command
{
    use TenantAwareCommandTrait;

    /**
     * Hook method for defining this command's option parser.
     *
     * @see https://book.cakephp.org/4/en/console-commands/commands.html#defining-arguments-and-options
     * @param \Cake\Console\ConsoleOptionParser $parser The parser to be defined
     * @return \Cake\Console\ConsoleOptionParser The built parser.
     */
    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser = parent::buildOptionParser($parser)
            ->addOption('plugin', [
                'short' => 'p',
                'help' => 'The plugin to run migrations for',
            ])
            ->addOption('connection', [
                'short' => 'c',
                'help' => 'Datasource connection to migrate.',
                'default' => 'default',
            ]);
        $this->addTenantOptions($parser);

        return $parser;
    }

    /**
     * Implement this method with your command's logic.
     *
     * @param \Cake\Console\Arguments $args The command arguments.
     * @param \Cake\Console\ConsoleIo $io The console io
     * @return int|null|void The exit code or null for success
     */
    public function execute(Arguments $args, ConsoleIo $io): ?int
    {
        return $this->runTenantAware(
            $args,
            $io,
            fn(Arguments $args, ConsoleIo $io): ?int => $this->executeForTenant($args, $io),
        );
    }

    /**
     * Execute migrations with an already configured tenant connection when selected.
     *
     * @param \Cake\Console\Arguments $args The command arguments.
     * @param \Cake\Console\ConsoleIo $io The console io
     * @return int|null|void The exit code or null for success
     */
    private function executeForTenant(Arguments $args, ConsoleIo $io): ?int
    {
        $service = new TenantMigrationService();
        $connection = $args->getOption('tenant') || $args->getOption('all-tenants')
            ? 'tenant'
            : ($args->getOption('connection') ?: 'default');
        $plugin = $args->getOption('plugin');

        try {
            foreach ($service->migrate((string)$connection, $plugin === null ? null : (string)$plugin) as $scope) {
                $io->out(sprintf('Migrated %s on %s.', $scope, $connection));
            }
        } catch (Throwable $e) {
            $io->error($e->getMessage());

            return Command::CODE_ERROR;
        }

        return Command::CODE_SUCCESS;
    }
}
