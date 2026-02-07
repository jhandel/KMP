<?php

declare(strict_types=1);

namespace App\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\ORM\TableRegistry;

/**
 * Lists all workflow definitions and optionally active instances.
 *
 * Usage: bin/cake workflow_list
 *        bin/cake workflow_list --instances
 */
class WorkflowListCommand extends Command
{
    public static function defaultName(): string
    {
        return 'workflow_list';
    }

    /**
     * @inheritDoc
     */
    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser = parent::buildOptionParser($parser);

        $parser
            ->setDescription('List all workflow definitions and optionally active instances.')
            ->addOption('instances', [
                'help' => 'Also show active workflow instances.',
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
        $definitionsTable = TableRegistry::getTableLocator()->get('WorkflowDefinitions');
        $instancesTable = TableRegistry::getTableLocator()->get('WorkflowInstances');

        $definitions = $definitionsTable->find()
            ->contain(['WorkflowStates'])
            ->orderBy(['WorkflowDefinitions.name' => 'ASC', 'WorkflowDefinitions.version' => 'DESC'])
            ->all();

        if ($definitions->isEmpty()) {
            $io->warning('No workflow definitions found.');

            return Command::CODE_SUCCESS;
        }

        $io->info('=== Workflow Definitions ===');
        $io->out('');

        $headers = ['ID', 'Name', 'Slug', 'Entity Type', 'Ver', 'Active', 'Default', 'States', 'Active Instances'];
        $rows = [];

        foreach ($definitions as $def) {
            $activeCount = $instancesTable->find()
                ->where([
                    'workflow_definition_id' => $def->id,
                    'completed_at IS' => null,
                ])
                ->count();

            $rows[] = [
                $def->id,
                $def->name,
                $def->slug,
                $def->entity_type,
                $def->version,
                $def->is_active ? 'Yes' : 'No',
                $def->is_default ? 'Yes' : 'No',
                count($def->workflow_states),
                $activeCount,
            ];
        }

        $io->helper('Table')->output(array_merge([$headers], $rows));

        if ($args->getOption('instances')) {
            $this->displayActiveInstances($instancesTable, $io);
        }

        return Command::CODE_SUCCESS;
    }

    /**
     * Display all active workflow instances.
     */
    private function displayActiveInstances(object $instancesTable, ConsoleIo $io): void
    {
        $io->out('');
        $io->info('=== Active Instances ===');

        $instances = $instancesTable->find()
            ->where(['completed_at IS' => null])
            ->contain(['WorkflowDefinitions', 'CurrentState'])
            ->orderBy(['WorkflowInstances.started_at' => 'ASC'])
            ->all();

        if ($instances->isEmpty()) {
            $io->out('  No active instances.');

            return;
        }

        $headers = ['ID', 'Definition', 'Entity Type', 'Entity ID', 'Current State', 'Started'];
        $rows = [];

        foreach ($instances as $inst) {
            $rows[] = [
                $inst->id,
                $inst->workflow_definition->name ?? "def#{$inst->workflow_definition_id}",
                $inst->entity_type,
                $inst->entity_id,
                $inst->current_state->label ?? "state#{$inst->current_state_id}",
                (string)$inst->started_at,
            ];
        }

        $io->helper('Table')->output(array_merge([$headers], $rows));
    }
}
