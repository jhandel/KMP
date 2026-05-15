<?php
declare(strict_types=1);

namespace App\Command;

use App\Services\Platform\DeploymentMigrationOrchestratorService;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Throwable;

/**
 * Deploy-time migration orchestration (platform gate + per-tenant child jobs).
 */
class DeploymentMigrateCommand extends Command
{
    /**
     * @return string
     */
    public static function defaultName(): string
    {
        return 'deployment:migrate';
    }

    /**
     * @param \Cake\Console\ConsoleOptionParser $parser Parser
     * @return \Cake\Console\ConsoleOptionParser
     */
    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return parent::buildOptionParser($parser)
            ->setDescription('Run deployment migration orchestration with platform-first gating and tenant child jobs.')
            ->addOption('wait', [
                'boolean' => true,
                'default' => false,
                'help' => 'Wait and monitor child jobs to completion/hold/failure.',
            ])
            ->addOption('drive-worker', [
                'boolean' => true,
                'default' => false,
                'help' => 'Process child jobs in-process while waiting.',
            ])
            ->addOption('worker-batch-size', [
                'default' => 5,
                'help' => 'Jobs to process per worker poll when --drive-worker is enabled.',
            ])
            ->addOption('poll-interval', [
                'default' => 5,
                'help' => 'Seconds between status polls in wait mode.',
            ])
            ->addOption('timeout', [
                'default' => 3600,
                'help' => 'Maximum wait seconds before moving parent to hold.',
            ])
            ->addOption('on-failure', [
                'default' => 'hold',
                'help' => 'Failure behavior while waiting: hold|fail.',
                'choices' => ['hold', 'fail'],
            ])
            ->addOption('include-maintenance', [
                'boolean' => true,
                'default' => false,
                'help' => 'Include maintenance tenants in the migration snapshot.',
            ])
            ->addOption('max-attempts', [
                'default' => 3,
                'help' => 'Per-tenant max attempts persisted to each child operation input.',
            ])
            ->addOption('run-id', [
                'default' => null,
                'help' => 'Optional deterministic run id for idempotency and audit trails.',
            ])
            ->addOption('skip-platform-gate', [
                'boolean' => true,
                'default' => false,
                'help' => 'Skip the platform:migrate gate (useful for explicit resume runs).',
            ])
            ->addOption('resume-parent-id', [
                'default' => null,
                'help' => 'Resume an existing tenant_migrate_all parent operation id.',
            ])
            ->addOption('system-admin-email', [
                'default' => 'deployment-orchestrator@localhost.test',
                'help' => 'Platform admin identity used to persist deployment jobs.',
            ]);
    }

    /**
     * @param \Cake\Console\Arguments $args Arguments
     * @param \Cake\Console\ConsoleIo $io Console io
     * @return int|null
     */
    public function execute(Arguments $args, ConsoleIo $io): ?int
    {
        $wait = (bool)$args->getOption('wait');
        $driveWorker = (bool)$args->getOption('drive-worker');
        if (!$wait && $driveWorker) {
            $io->warning('--drive-worker has no effect without --wait.');
        }

        $service = new DeploymentMigrationOrchestratorService();
        try {
            $result = $service->orchestrate([
                'wait' => $wait,
                'drive_worker' => $wait ? $driveWorker : false,
                'worker_batch_size' => (int)$args->getOption('worker-batch-size'),
                'poll_interval_seconds' => (int)$args->getOption('poll-interval'),
                'timeout_seconds' => (int)$args->getOption('timeout'),
                'on_failure' => (string)$args->getOption('on-failure'),
                'include_maintenance' => (bool)$args->getOption('include-maintenance'),
                'max_attempts' => (int)$args->getOption('max-attempts'),
                'run_id' => $args->getOption('run-id'),
                'skip_platform_gate' => (bool)$args->getOption('skip-platform-gate'),
                'resume_parent_id' => $args->getOption('resume-parent-id'),
                'system_admin_email' => (string)$args->getOption('system-admin-email'),
            ]);
        } catch (Throwable $e) {
            $io->error($e->getMessage());

            return Command::CODE_ERROR;
        }

        $counts = is_array($result['counts'] ?? null) ? $result['counts'] : [];
        $state = (string)($result['state'] ?? 'unknown');
        $io->out(sprintf(
            'Deployment migration parent #%d (%s) correlation=%s',
            (int)($result['parent_job_id'] ?? 0),
            $state,
            (string)($result['correlation_id'] ?? ''),
        ));
        $jsonCounts = json_encode($counts);
        $io->out('Child state counts: ' . ($jsonCounts === false ? '{}' : $jsonCounts));

        if (!$wait) {
            $io->success('Child migration jobs queued. Run tenant_operation:worker to execute them.');

            return Command::CODE_SUCCESS;
        }

        if ($state === 'completed') {
            $io->success('Deployment migration completed.');

            return Command::CODE_SUCCESS;
        }

        $io->warning(sprintf('Deployment migration ended in %s state.', $state));

        return Command::CODE_ERROR;
    }
}
