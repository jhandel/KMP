<?php
declare(strict_types=1);

namespace App\Command;

use App\Model\Entity\Tenant;
use App\Services\Tenant\TenantConnectionFactory;
use App\Services\Tenant\TenantContext;
use App\Services\Tenant\TenantContextAccessor;
use App\Services\Tenant\TenantProvisioningService;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Throwable;

/**
 * Adds tenant selection and safe context switching to CLI commands.
 */
trait TenantAwareCommandTrait
{
    /**
     * Add common tenant selection options.
     *
     * @param \Cake\Console\ConsoleOptionParser $parser Option parser
     * @return \Cake\Console\ConsoleOptionParser
     */
    protected function addTenantOptions(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return $parser
            ->addOption('tenant', ['help' => 'Tenant slug to run against.'])
            ->addOption('all-tenants', [
                'help' => 'Run once for each active tenant.',
                'boolean' => true,
                'default' => false,
            ]);
    }

    /**
     * Run callback under selected tenant(s), preserving legacy no-option behavior.
     *
     * @param \Cake\Console\Arguments $args Arguments
     * @param \Cake\Console\ConsoleIo $io Console IO
     * @param callable(\Cake\Console\Arguments,\Cake\Console\ConsoleIo): int|null $callback Command body
     * @return int
     */
    protected function runTenantAware(Arguments $args, ConsoleIo $io, callable $callback): int
    {
        $tenantSlug = trim((string)($args->getOption('tenant') ?? ''));
        $allTenants = (bool)$args->getOption('all-tenants');
        if ($tenantSlug !== '' && $allTenants) {
            $io->error('Specify only one of --tenant or --all-tenants.');

            return Command::CODE_ERROR;
        }

        if ($tenantSlug === '' && !$allTenants) {
            return (int)($callback($args, $io) ?? Command::CODE_SUCCESS);
        }

        $service = new TenantProvisioningService();
        try {
            $tenants = $allTenants ? $service->listTenants() : [$service->getTenant($tenantSlug)];
        } catch (Throwable $e) {
            $io->error($e->getMessage());

            return Command::CODE_ERROR;
        }

        $exitCode = Command::CODE_SUCCESS;
        foreach ($tenants as $tenant) {
            if (!$tenant instanceof Tenant || $tenant->status !== Tenant::STATUS_ACTIVE) {
                if (!$allTenants) {
                    $slug = $tenant instanceof Tenant ? (string)$tenant->slug : $tenantSlug;
                    $status = $tenant instanceof Tenant ? (string)$tenant->status : 'unknown';
                    $io->error(sprintf(
                        'Tenant %s is not active; current status is %s.',
                        $slug,
                        $status,
                    ));

                    return Command::CODE_ERROR;
                }

                continue;
            }

            $io->out(sprintf('Running for tenant %s...', $tenant->slug));
            try {
                $this->configureTenantContext($tenant);
                $result = (int)($callback($args, $io) ?? Command::CODE_SUCCESS);
                if ($result !== Command::CODE_SUCCESS) {
                    $exitCode = Command::CODE_ERROR;
                }
            } catch (Throwable $e) {
                $io->error(sprintf('Tenant %s failed: %s', $tenant->slug, $e->getMessage()));
                $exitCode = Command::CODE_ERROR;
            } finally {
                $this->clearTenantContext();
            }
        }

        return $exitCode;
    }

    /**
     * Configure ORM and ambient context for a tenant.
     *
     * @param \App\Model\Entity\Tenant $tenant Tenant entity
     * @return void
     */
    protected function configureTenantContext(Tenant $tenant): void
    {
        $context = TenantContext::fromTenant($tenant, (string)($tenant->primary_host ?? $tenant->slug));
        (new TenantConnectionFactory())->configure($context);
        TenantContextAccessor::set($context);
    }

    /**
     * Clear tenant globals after a tenant-scoped command run.
     *
     * @return void
     */
    protected function clearTenantContext(): void
    {
        TenantContextAccessor::set(null);
        TenantContext::clearCurrent();
        (new TenantConnectionFactory())->resetOrmState();
    }
}
