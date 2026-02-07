<?php

declare(strict_types=1);

namespace App\Command;

use App\Services\WorkflowEngine\DefaultWorkflowEngine;
use App\Services\WorkflowEngine\WorkflowBridge;
use App\Services\WorkflowEngine\WorkflowEngineInterface;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;

/**
 * Processes scheduled and automatic workflow transitions.
 * Intended to be run by cron (e.g., every 5 minutes).
 *
 * Usage: bin/cake workflow_process
 */
class WorkflowProcessCommand extends Command
{
    public static function defaultName(): string
    {
        return 'workflow_process';
    }

    /**
     * @inheritDoc
     */
    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser = parent::buildOptionParser($parser);

        $parser
            ->setDescription('Process scheduled and automatic workflow transitions.')
            ->addOption('dry-run', [
                'short' => 'd',
                'help' => 'Show what would be processed without making changes.',
                'boolean' => true,
                'default' => false,
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

        $io->info('Processing scheduled workflow transitions...');

        if ($dryRun) {
            $io->warning('DRY RUN mode — no changes will be made.');
            $this->previewScheduledTransitions($io);
            $io->success('Dry run complete.');

            return Command::CODE_SUCCESS;
        }

        $engine = $this->getEngine();
        $result = $engine->processScheduledTransitions();

        if ($result->success) {
            $processed = $result->data['processed'] ?? 0;
            $io->success(sprintf(
                'Processed %d automatic transition%s.',
                $processed,
                $processed === 1 ? '' : 's',
            ));

            return Command::CODE_SUCCESS;
        }

        $io->error('Error: ' . ($result->reason ?? 'Unknown error'));

        return Command::CODE_ERROR;
    }

    /**
     * Preview pending automatic transitions without executing them.
     */
    private function previewScheduledTransitions(ConsoleIo $io): void
    {
        $instancesTable = \Cake\ORM\TableRegistry::getTableLocator()->get('WorkflowInstances');
        $transitionsTable = \Cake\ORM\TableRegistry::getTableLocator()->get('WorkflowTransitions');

        $instances = $instancesTable->find()
            ->where(['completed_at IS' => null])
            ->contain(['WorkflowDefinitions', 'CurrentState'])
            ->all();

        $pending = 0;
        foreach ($instances as $instance) {
            $autoTransitions = $transitionsTable->find()
                ->where([
                    'WorkflowTransitions.workflow_definition_id' => $instance->workflow_definition_id,
                    'WorkflowTransitions.is_automatic' => true,
                ])
                ->all();

            foreach ($autoTransitions as $t) {
                $io->out(sprintf(
                    '  Would process: Instance #%d (%s #%d) → transition "%s"',
                    $instance->id,
                    $instance->entity_type,
                    $instance->entity_id,
                    $t->slug,
                ));
                $pending++;
                break; // Only one per instance
            }
        }

        if ($pending === 0) {
            $io->out('  No automatic transitions pending.');
        } else {
            $io->out(sprintf('  %d transition%s would be processed.', $pending, $pending === 1 ? '' : 's'));
        }
    }

    private function getEngine(): WorkflowEngineInterface
    {
        $bridge = new WorkflowBridge();

        return new DefaultWorkflowEngine($bridge);
    }
}
