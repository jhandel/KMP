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
 * Run core and plugin migrations for one or all tenants.
 */
class TenantMigrateCommand extends Command
{
    /**
     * Get the default command name.
     *
     * @return string
     */
    public static function defaultName(): string
    {
        return 'tenant:migrate';
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
            ->setDescription('Run tenant database migrations.')
            ->addOption('tenant', ['help' => 'Tenant slug to migrate.'])
            ->addOption('all-tenants', [
                'help' => 'Migrate all tenants in the platform registry.',
                'boolean' => true,
                'default' => false,
            ])
            ->addOption('plugin', ['short' => 'p', 'help' => 'Only run migrations for a plugin.']);
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
        $tenantSlug = (string)($args->getOption('tenant') ?? '');
        $allTenants = (bool)$args->getOption('all-tenants');
        if (($tenantSlug === '' && !$allTenants) || ($tenantSlug !== '' && $allTenants)) {
            $io->error('Specify exactly one of --tenant or --all-tenants.');

            return Command::CODE_ERROR;
        }

        $service = new TenantProvisioningService();
        $tenants = $allTenants ? $service->listTenants() : [$service->getTenant($tenantSlug)];
        $failed = false;
        foreach ($tenants as $tenant) {
            try {
                $io->out(sprintf('Migrating tenant %s...', $tenant->slug));
                $schemaVersion = $service->migrateTenant($tenant, $args->getOption('plugin'));
                $io->success(sprintf('Tenant %s migrated to schema_version=%s.', $tenant->slug, $schemaVersion));
            } catch (Throwable $e) {
                $failed = true;
                $io->error(sprintf('Tenant %s migration failed: %s', $tenant->slug ?? $tenantSlug, $e->getMessage()));
            }
        }

        return $failed ? Command::CODE_ERROR : Command::CODE_SUCCESS;
    }
}
