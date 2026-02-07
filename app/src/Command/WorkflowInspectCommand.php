<?php

declare(strict_types=1);

namespace App\Command;

use App\Services\WorkflowEngine\WorkflowBridge;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\ORM\TableRegistry;

/**
 * Inspect workflow instance state for debugging.
 *
 * Usage: bin/cake workflow_inspect --entity-type=AwardsRecommendations --entity-id=42
 *        bin/cake workflow_inspect --instance-id=7
 */
class WorkflowInspectCommand extends Command
{
    public static function defaultName(): string
    {
        return 'workflow_inspect';
    }

    /**
     * @inheritDoc
     */
    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser = parent::buildOptionParser($parser);

        $parser
            ->setDescription('Inspect a workflow instance for debugging.')
            ->addOption('instance-id', [
                'help' => 'Workflow instance ID to inspect.',
                'short' => 'i',
            ])
            ->addOption('entity-type', [
                'help' => 'Entity type to look up.',
                'short' => 't',
            ])
            ->addOption('entity-id', [
                'help' => 'Entity ID to look up.',
                'short' => 'e',
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
        $instancesTable = TableRegistry::getTableLocator()->get('WorkflowInstances');

        $instance = null;

        if ($args->getOption('instance-id')) {
            try {
                $instance = $instancesTable->get(
                    (int)$args->getOption('instance-id'),
                    contain: ['WorkflowDefinitions', 'CurrentState', 'PreviousState', 'WorkflowTransitionLogs'],
                );
            } catch (\Exception $e) {
                $io->error('Instance not found: ' . $e->getMessage());

                return Command::CODE_ERROR;
            }
        } elseif ($args->getOption('entity-type') && $args->getOption('entity-id')) {
            $instance = $instancesTable->findActiveForEntity(
                $args->getOption('entity-type'),
                (int)$args->getOption('entity-id'),
            );

            if ($instance) {
                $instance = $instancesTable->get($instance->id, contain: [
                    'WorkflowDefinitions', 'CurrentState', 'PreviousState', 'WorkflowTransitionLogs',
                ]);
            }
        } else {
            $io->error('Provide --instance-id or both --entity-type and --entity-id');

            return Command::CODE_ERROR;
        }

        if (!$instance) {
            $io->warning('No active workflow instance found.');

            return Command::CODE_SUCCESS;
        }

        $this->displayInstanceInfo($instance, $io);
        $this->displayTransitionHistory($instance, $io);
        $this->displayAvailableTransitions($instance, $io);

        return Command::CODE_SUCCESS;
    }

    /**
     * Display instance details.
     */
    private function displayInstanceInfo(object $instance, ConsoleIo $io): void
    {
        $io->out('');
        $io->info('=== Workflow Instance ===');
        $io->out("ID:          {$instance->id}");
        $io->out("Definition:  {$instance->workflow_definition->name} (v{$instance->workflow_definition->version})");
        $io->out("Entity:      {$instance->entity_type} #{$instance->entity_id}");
        $io->out("State:       {$instance->current_state->label} [{$instance->current_state->slug}]");
        $io->out("Category:    {$instance->current_state->status_category}");
        $io->out("Started:     {$instance->started_at}");
        $io->out('Completed:   ' . ($instance->completed_at ?? 'Active'));
        $io->out('');
    }

    /**
     * Display transition history.
     */
    private function displayTransitionHistory(object $instance, ConsoleIo $io): void
    {
        if (empty($instance->workflow_transition_logs)) {
            return;
        }

        $io->info('=== Transition History ===');
        foreach ($instance->workflow_transition_logs as $log) {
            $io->out(sprintf(
                '  [%s] %s → state_id:%d (by: %s, type: %s)',
                $log->created,
                $log->from_state_id ? "state_id:{$log->from_state_id}" : 'START',
                $log->to_state_id,
                $log->triggered_by ?? 'system',
                $log->trigger_type,
            ));
            if ($log->notes) {
                $io->out("    Notes: {$log->notes}");
            }
        }
    }

    /**
     * Display available transitions from the current state.
     */
    private function displayAvailableTransitions(object $instance, ConsoleIo $io): void
    {
        $io->out('');
        $io->info('=== Available Transitions ===');

        $bridge = new WorkflowBridge();
        try {
            $workflow = $bridge->buildFromDefinition($instance->workflow_definition_id);
            $subject = new \stdClass();
            $subject->currentState = $instance->current_state->slug;

            $enabled = $workflow->getEnabledTransitions($subject);
            if (empty($enabled)) {
                $io->out('  (none — terminal state)');
            } else {
                foreach ($enabled as $t) {
                    $meta = $workflow->getDefinition()->getMetadataStore()->getTransitionMetadata($t);
                    $io->out(sprintf('  → %s (%s)', $t->getName(), $meta['label'] ?? ''));
                }
            }
        } catch (\Exception $e) {
            $io->warning('Could not load workflow: ' . $e->getMessage());
        }
    }
}
