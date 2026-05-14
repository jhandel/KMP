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
 * Shared tenant status command behavior.
 */
trait TenantStatusCommandTrait
{
    /**
     * Status applied by this command.
     *
     * @return string
     */
    abstract protected function status(): string;

    /**
     * Configure command options.
     *
     * @param \Cake\Console\ConsoleOptionParser $parser Option parser
     * @return \Cake\Console\ConsoleOptionParser
     */
    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return parent::buildOptionParser($parser)
            ->setDescription(sprintf('Set a tenant status to %s.', $this->status()))
            ->addArgument('slug', ['help' => 'Tenant slug.', 'required' => true]);
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
            $tenant = (new TenantProvisioningService())->setStatus(
                (string)$args->getArgument('slug'),
                $this->status(),
            );
            $io->success(sprintf('Tenant "%s" status is now %s.', $tenant->slug, $tenant->status));
        } catch (Throwable $e) {
            $io->error($e->getMessage());

            return Command::CODE_ERROR;
        }

        return Command::CODE_SUCCESS;
    }
}
