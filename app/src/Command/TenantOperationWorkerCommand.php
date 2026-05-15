<?php
declare(strict_types=1);

namespace App\Command;

use App\Services\Platform\TenantOperationWorkerService;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;

/**
 * Execute tenant operation jobs outside the web request lifecycle.
 */
class TenantOperationWorkerCommand extends Command
{
    /**
     * @return string
     */
    public static function defaultName(): string
    {
        return 'tenant_operation:worker';
    }

    /**
     * @param \Cake\Console\ConsoleOptionParser $parser Parser
     * @return \Cake\Console\ConsoleOptionParser
     */
    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return parent::buildOptionParser($parser)
            ->setDescription('Run durable tenant operation jobs with lease-aware state transitions.')
            ->addOption('once', [
                'boolean' => true,
                'default' => false,
                'help' => 'Process a single poll cycle and exit.',
            ])
            ->addOption('limit', [
                'default' => 1,
                'help' => 'Maximum jobs to process per cycle.',
            ])
            ->addOption('sleep', [
                'default' => 5,
                'help' => 'Idle poll sleep seconds for continuous mode.',
            ])
            ->addOption('lease-ttl', [
                'default' => 300,
                'help' => 'Lease TTL in seconds before another worker may resume.',
            ])
            ->addOption('worker-id', [
                'default' => null,
                'help' => 'Explicit worker identity for lease ownership.',
            ]);
    }

    /**
     * @param \Cake\Console\Arguments $args Arguments
     * @param \Cake\Console\ConsoleIo $io Console io
     * @return int|null
     */
    public function execute(Arguments $args, ConsoleIo $io): ?int
    {
        $limit = max(1, (int)$args->getOption('limit'));
        $sleepSeconds = max(1, (int)$args->getOption('sleep'));
        $once = (bool)$args->getOption('once');
        $service = new TenantOperationWorkerService(
            leaseTtlSeconds: max(30, (int)$args->getOption('lease-ttl')),
            workerId: is_string($args->getOption('worker-id')) ? $args->getOption('worker-id') : null,
        );
        $io->out(sprintf(
            'Starting tenant operation worker (once=%s, limit=%d, sleep=%ds).',
            $once ? 'yes' : 'no',
            $limit,
            $sleepSeconds,
        ));

        if ($once) {
            $processed = $service->runNextBatch($limit);
            $io->out(sprintf('Processed %d job(s).', $processed));

            return Command::CODE_SUCCESS;
        }

        while (true) {
            $processed = $service->runNextBatch($limit);
            if ($processed > 0) {
                $io->out(sprintf('Processed %d job(s).', $processed));

                continue;
            }
            sleep($sleepSeconds);
        }
    }
}

