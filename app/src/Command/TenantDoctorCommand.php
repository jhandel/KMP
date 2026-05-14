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
 * Verify tenant platform metadata and tenant database health.
 */
class TenantDoctorCommand extends Command
{
    /**
     * Get the default command name.
     *
     * @return string
     */
    public static function defaultName(): string
    {
        return 'tenant:doctor';
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
            ->setDescription('Run tenant health checks.')
            ->addOption('tenant', ['help' => 'Tenant slug to inspect.'])
            ->addOption('all-tenants', [
                'help' => 'Inspect all tenants.',
                'boolean' => true,
                'default' => false,
            ]);
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

        try {
            $service = new TenantProvisioningService();
            $tenants = $allTenants ? $service->listTenants() : [$service->getTenant($tenantSlug)];
            $failed = false;
            foreach ($tenants as $tenant) {
                $io->out(sprintf('Tenant %s', $tenant->slug));
                $rows = [];
                foreach ($service->doctor($tenant) as $name => $check) {
                    $ok = (bool)$check['ok'];
                    $failed = $failed || !$ok;
                    $rows[] = [$name, $ok ? 'ok' : 'fail', (string)$check['message']];
                }
                $this->outputRows($io, ['Check', 'Result', 'Message'], $rows);
            }
        } catch (Throwable $e) {
            $io->error($e->getMessage());

            return Command::CODE_ERROR;
        }

        return $failed ? Command::CODE_ERROR : Command::CODE_SUCCESS;
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
