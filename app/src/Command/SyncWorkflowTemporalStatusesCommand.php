<?php

declare(strict_types=1);

namespace App\Command;

use App\Services\WorkflowEngine\DefaultWorkflowEngine;
use App\Services\WorkflowEngine\WorkflowBridge;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\I18n\DateTime;
use Cake\ORM\TableRegistry;
use Throwable;

/**
 * CLI command to trigger temporal workflow transitions.
 *
 * Finds workflow instances whose entities have passed temporal thresholds
 * (start_on, expires_on) and fires transitions through the workflow engine
 * so that on_enter_actions (e.g. revoke_activity_role) execute correctly.
 */
class SyncWorkflowTemporalStatusesCommand extends Command
{
    /**
     * @inheritDoc
     */
    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser = parent::buildOptionParser($parser);

        $parser->addOption('dry-run', [
            'short' => 'd',
            'boolean' => true,
            'default' => false,
            'help' => 'Preview transitions without executing them.',
        ]);

        $parser->addOption('plugin', [
            'short' => 'p',
            'help' => 'Limit to workflows belonging to a specific plugin (e.g. Activities).',
        ]);

        return $parser;
    }

    /**
     * Execute command.
     *
     * @param \Cake\Console\Arguments $args Console arguments.
     * @param \Cake\Console\ConsoleIo $io Console IO.
     * @return int
     */
    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $dryRun = (bool)$args->getOption('dry-run');
        $pluginFilter = $args->getOption('plugin');
        $now = DateTime::now();

        $io->out(sprintf(
            'Temporal workflow sync started at %s%s',
            $now->toDateTimeString(),
            $dryRun ? ' (dry-run)' : ''
        ));

        $bridge = new WorkflowBridge();
        $engine = new DefaultWorkflowEngine($bridge);

        $definitionsTable = TableRegistry::getTableLocator()->get('WorkflowDefinitions');
        $transitionsTable = TableRegistry::getTableLocator()->get('WorkflowTransitions');
        $instancesTable = TableRegistry::getTableLocator()->get('WorkflowInstances');
        $statesTable = TableRegistry::getTableLocator()->get('WorkflowStates');

        // Find workflow definitions that have activate or expire transitions
        $defsQuery = $definitionsTable->find()
            ->where(['WorkflowDefinitions.is_active' => true]);

        if ($pluginFilter !== null) {
            $defsQuery->where(['WorkflowDefinitions.plugin_name' => $pluginFilter]);
        }

        $definitions = $defsQuery->all();

        $overallActivated = 0;
        $overallExpired = 0;
        $overallErrors = 0;

        foreach ($definitions as $definition) {
            // Find temporal-relevant transitions for this definition
            $temporalTransitions = $transitionsTable->find()
                ->contain(['FromState', 'ToState'])
                ->where([
                    'WorkflowTransitions.workflow_definition_id' => $definition->id,
                    'WorkflowTransitions.slug IN' => ['activate', 'expire'],
                ])
                ->all()
                ->toArray();

            if (empty($temporalTransitions)) {
                continue;
            }

            $io->out(sprintf(
                ' Scanning workflow: %s (%s)',
                $definition->name,
                $definition->slug
            ));

            $activated = 0;
            $expired = 0;
            $errors = 0;

            foreach ($temporalTransitions as $transition) {
                $fromSlug = $transition->from_state->slug;
                $transitionSlug = $transition->slug;

                // Find workflow instances in the "from" state
                $instances = $instancesTable->find()
                    ->where([
                        'WorkflowInstances.workflow_definition_id' => $definition->id,
                        'WorkflowInstances.current_state_id' => $transition->from_state_id,
                        'WorkflowInstances.status' => 'active',
                    ])
                    ->all();

                foreach ($instances as $instance) {
                    try {
                        $entityTable = TableRegistry::getTableLocator()->get($instance->entity_type);
                        $entity = $entityTable->get($instance->entity_id);
                    } catch (Throwable $e) {
                        $io->err(sprintf(
                            '   - Instance #%d: failed to load entity %s#%d (%s)',
                            $instance->id,
                            $instance->entity_type,
                            $instance->entity_id,
                            $e->getMessage()
                        ));
                        $errors++;
                        continue;
                    }

                    $shouldTransition = false;
                    $dateField = '';
                    $dateValue = '';

                    if ($transitionSlug === 'activate') {
                        // Check start_on <= NOW()
                        if ($entity->has('start_on') && $entity->start_on !== null) {
                            $startOn = $entity->start_on instanceof DateTime
                                ? $entity->start_on
                                : new DateTime($entity->start_on);
                            if ($startOn->lessThanOrEquals($now)) {
                                $shouldTransition = true;
                                $dateField = 'start_on';
                                $dateValue = $startOn->toDateString();
                            }
                        }
                    } elseif ($transitionSlug === 'expire') {
                        // Check expires_on <= NOW() AND expires_on IS NOT NULL
                        if ($entity->has('expires_on') && $entity->expires_on !== null) {
                            $expiresOn = $entity->expires_on instanceof DateTime
                                ? $entity->expires_on
                                : new DateTime($entity->expires_on);
                            if ($expiresOn->lessThanOrEquals($now)) {
                                $shouldTransition = true;
                                $dateField = 'expires_on';
                                $dateValue = $expiresOn->toDateString();
                            }
                        }
                    }

                    if (!$shouldTransition) {
                        continue;
                    }

                    $label = sprintf(
                        '   - #%d (entity %d): %s → %s (%s: %s ≤ now)',
                        $instance->id,
                        $instance->entity_id,
                        $fromSlug,
                        $transitionSlug,
                        $dateField,
                        $dateValue
                    );

                    if ($dryRun) {
                        $io->out($label . ' [DRY-RUN]');
                    } else {
                        $result = $engine->transition($instance->id, $transitionSlug, null, [
                            'trigger' => 'temporal_sync',
                        ]);
                        if ($result->success) {
                            $io->out($label . ' [OK]');
                        } else {
                            $io->err($label . ' [FAILED: ' . ($result->reason ?? 'unknown') . ']');
                            $errors++;
                            continue;
                        }
                    }

                    if ($transitionSlug === 'activate') {
                        $activated++;
                    } else {
                        $expired++;
                    }
                }
            }

            $io->out(sprintf('   Summary: %d activated, %d expired', $activated, $expired));

            $overallActivated += $activated;
            $overallExpired += $expired;
            $overallErrors += $errors;
        }

        $io->hr();
        $io->out(sprintf(
            'Overall: %d activated, %d expired, %d errors',
            $overallActivated,
            $overallExpired,
            $overallErrors
        ));

        return $overallErrors === 0 ? Command::CODE_SUCCESS : Command::CODE_ERROR;
    }
}
