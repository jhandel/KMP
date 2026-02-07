<?php

declare(strict_types=1);

namespace App\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\ORM\TableRegistry;
use App\Services\WorkflowEngine\WorkflowEngineInterface;
use App\Services\WorkflowEngine\DefaultWorkflowEngine;
use App\Services\WorkflowEngine\DefaultRuleEvaluator;
use App\Services\WorkflowEngine\DefaultActionExecutor;
use App\Services\WorkflowEngine\DefaultVisibilityEvaluator;

/**
 * CLI command to process scheduled and automatic workflow transitions.
 *
 * Checks active workflow instances for timed transitions and approval
 * timeouts. Intended for scheduled execution via cron or other automation.
 */
class WorkflowProcessCommand extends Command
{
    protected WorkflowEngineInterface $workflowEngine;

    public static function defaultName(): string
    {
        return 'workflow process';
    }

    /**
     * @inheritDoc
     */
    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser = parent::buildOptionParser($parser);

        $parser
            ->setDescription('Process scheduled and automatic workflow transitions, including approval timeouts.')
            ->addOption('dry-run', [
                'short' => 'd',
                'boolean' => true,
                'default' => false,
                'help' => 'Show what would be processed without actually transitioning.',
            ]);

        return $parser;
    }

    /**
     * Execute command.
     *
     * @param \Cake\Console\Arguments $args Console arguments instance.
     * @param \Cake\Console\ConsoleIo $io Console IO instance.
     * @return int
     */
    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $dryRun = (bool)$args->getOption('dry-run');

        $io->out('Processing scheduled workflow transitions...');

        try {
            $engine = $this->getWorkflowEngine();
        } catch (\Exception $e) {
            $io->error("Failed to initialize workflow engine: {$e->getMessage()}");

            return Command::CODE_ERROR;
        }

        if ($dryRun) {
            $io->info('DRY RUN - no changes will be made');
            $instancesTable = TableRegistry::getTableLocator()->get('WorkflowInstances');
            $count = $instancesTable->find()->where(['completed_at IS' => null])->count();
            $io->out("Active workflow instances to check: {$count}");

            return Command::CODE_SUCCESS;
        }

        $result = $engine->processScheduledTransitions();

        if ($result->success) {
            $data = $result->data;
            $io->success("Processed {$data['processed']} scheduled transitions.");
            if (!empty($data['errors'])) {
                $io->warning('Some transitions had errors:');
                foreach ($data['errors'] as $error) {
                    $io->warning("  - {$error}");
                }
            }

            return Command::CODE_SUCCESS;
        }

        $io->error("Failed to process transitions: {$result->reason}");

        return Command::CODE_ERROR;
    }

    /**
     * Get or create the workflow engine instance.
     */
    protected function getWorkflowEngine(): WorkflowEngineInterface
    {
        if (isset($this->workflowEngine)) {
            return $this->workflowEngine;
        }

        $ruleEvaluator = new DefaultRuleEvaluator();
        $actionExecutor = new DefaultActionExecutor();
        $visibilityEvaluator = new DefaultVisibilityEvaluator();

        $this->workflowEngine = new DefaultWorkflowEngine(
            $ruleEvaluator,
            $actionExecutor,
            $visibilityEvaluator,
        );

        return $this->workflowEngine;
    }
}
