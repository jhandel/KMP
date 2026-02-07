<?php
declare(strict_types=1);

namespace App\Services\WorkflowEngine;

use Cake\Log\Log;
use Cake\ORM\TableRegistry;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Workflow\Definition;
use Symfony\Component\Workflow\DefinitionBuilder;
use Symfony\Component\Workflow\MarkingStore\MarkingStoreInterface;
use Symfony\Component\Workflow\Metadata\InMemoryMetadataStore;
use Symfony\Component\Workflow\Transition;
use Symfony\Component\Workflow\Workflow;

/**
 * Builds symfony/workflow Workflow objects from config arrays or DB definitions.
 *
 * Caches compiled workflows per slug. Supports both config-array input (for
 * testing/spike) and DB-backed definitions (for production).
 */
class WorkflowBridge
{
    /** @var array<string, Workflow> */
    private array $cache = [];

    /** @var array<string, EventDispatcherInterface> */
    private array $dispatchers = [];

    /**
     * Build a Workflow from a DB-stored definition by its ID.
     */
    public function buildFromDefinition(int $definitionId, ?MarkingStoreInterface $markingStore = null): Workflow
    {
        $cacheKey = 'db-' . $definitionId;
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $definitionsTable = TableRegistry::getTableLocator()->get('WorkflowDefinitions');
        $definition = $definitionsTable->get($definitionId, contain: [
            'WorkflowStates',
            'WorkflowTransitions',
        ]);

        $config = $this->definitionToConfig($definition);

        return $this->buildFromConfig($config, $markingStore, $cacheKey);
    }

    /**
     * Build a Workflow from a DB-stored definition by its slug.
     */
    public function buildFromSlug(string $slug, ?MarkingStoreInterface $markingStore = null): ?Workflow
    {
        if (isset($this->cache[$slug])) {
            return $this->cache[$slug];
        }

        $definitionsTable = TableRegistry::getTableLocator()->get('WorkflowDefinitions');
        $definition = $definitionsTable->findBySlug($slug);

        if (!$definition) {
            return null;
        }

        // Re-fetch with associations
        $definition = $definitionsTable->get($definition->id, contain: [
            'WorkflowStates',
            'WorkflowTransitions',
        ]);

        $config = $this->definitionToConfig($definition);

        return $this->buildFromConfig($config, $markingStore, $slug);
    }

    /**
     * Convert a WorkflowDefinition entity (with states and transitions) to a config array.
     */
    private function definitionToConfig(object $definition): array
    {
        $states = [];
        $initialStates = [];
        foreach ($definition->workflow_states ?? [] as $state) {
            $stateConfig = [
                'slug' => $state->slug,
                'label' => $state->label,
                'state_type' => $state->state_type,
                'status_category' => $state->status_category,
                'metadata' => $state->decoded_metadata,
            ];
            $states[] = $stateConfig;

            if ($state->state_type === 'initial') {
                $initialStates[] = $state->slug;
            }
        }

        // Build a state ID → slug lookup for transitions
        $stateIdToSlug = [];
        foreach ($definition->workflow_states ?? [] as $state) {
            $stateIdToSlug[$state->id] = $state->slug;
        }

        $transitions = [];
        foreach ($definition->workflow_transitions ?? [] as $t) {
            $fromSlug = $stateIdToSlug[$t->from_state_id] ?? null;
            $toSlug = $stateIdToSlug[$t->to_state_id] ?? null;
            if (!$fromSlug || !$toSlug) {
                continue;
            }
            $transitions[] = [
                'slug' => $t->slug,
                'label' => $t->label,
                'from' => $fromSlug,
                'to' => $toSlug,
                'metadata' => [
                    'conditions' => $t->decoded_conditions,
                    'actions' => $t->decoded_actions,
                    'trigger_type' => $t->trigger_type,
                    'is_automatic' => $t->is_automatic,
                ],
            ];
        }

        return [
            'slug' => $definition->slug,
            'name' => $definition->name,
            'type' => 'state_machine',
            'initial_states' => $initialStates,
            'states' => $states,
            'transitions' => $transitions,
        ];
    }

    /**
     * Build a Workflow from a configuration array.
     *
     * Also used internally by buildFromDefinition() after converting DB data to config format.
     *
     * @param array $config Configuration with keys: slug, name, type, states, transitions, initial_states
     * @param MarkingStoreInterface|null $markingStore Custom marking store
     * @param string|null $cacheKey Override cache key (used by DB-backed methods)
     * @return Workflow
     */
    public function buildFromConfig(array $config, ?MarkingStoreInterface $markingStore = null, ?string $cacheKey = null): Workflow
    {
        $slug = $cacheKey ?? $config['slug'];

        if (isset($this->cache[$slug])) {
            return $this->cache[$slug];
        }

        // Determine if single-state (state machine) early — needed for transition expansion
        $isSingleState = ($config['type'] ?? 'state_machine') === 'state_machine';

        $builder = new DefinitionBuilder();

        // Add places (states)
        $placeSlugs = array_column($config['states'], 'slug');
        $builder->addPlaces($placeSlugs);

        // Set initial places
        if (!empty($config['initial_states'])) {
            $builder->setInitialPlaces($config['initial_states']);
        }

        // Build metadata for states
        $placesMetadata = [];
        foreach ($config['states'] as $state) {
            $meta = $state['metadata'] ?? [];
            $meta['label'] = $state['label'] ?? $state['slug'];
            $meta['state_type'] = $state['state_type'] ?? 'intermediate';
            $meta['status_category'] = $state['status_category'] ?? null;
            $placesMetadata[$state['slug']] = $meta;
        }

        // Add transitions
        $transitionObjects = [];
        $transitionsMetadata = new \SplObjectStorage();
        foreach ($config['transitions'] as $t) {
            $froms = (array) $t['from'];
            $meta = $t['metadata'] ?? [];
            $meta['label'] = $t['label'] ?? $t['slug'];

            // For state machines, expand array froms into individual transitions
            // (Symfony treats array froms as AND; state machines need OR semantics)
            if ($isSingleState && count($froms) > 1) {
                foreach ($froms as $from) {
                    $transition = new Transition($t['slug'], $from, $t['to']);
                    $builder->addTransition($transition);
                    $transitionObjects[] = $transition;
                    $transitionsMetadata[$transition] = $meta;
                }
            } else {
                $transition = new Transition($t['slug'], $t['from'], $t['to']);
                $builder->addTransition($transition);
                $transitionObjects[] = $transition;
                $transitionsMetadata[$transition] = $meta;
            }
        }

        // Build definition with metadata
        $metadataStore = new InMemoryMetadataStore(
            workflowMetadata: ['slug' => $config['slug'], 'name' => $config['name'] ?? $config['slug']],
            placesMetadata: $placesMetadata,
            transitionsMetadata: $transitionsMetadata,
        );

        $definition = $builder->build();
        // Rebuild with metadata since DefinitionBuilder doesn't accept it
        $definition = new Definition(
            $definition->getPlaces(),
            $definition->getTransitions(),
            $config['initial_states'] ?? null,
            $metadataStore,
        );

        // Create event dispatcher for this workflow
        $dispatcher = new EventDispatcher();
        $this->dispatchers[$slug] = $dispatcher;

        $markingStore = $markingStore ?? new CakeOrmMarkingStore(
            singleState: $isSingleState,
        );

        $workflow = new Workflow($definition, $markingStore, $dispatcher, $config['slug']);

        $this->cache[$slug] = $workflow;

        return $workflow;
    }

    /**
     * Get the event dispatcher for a workflow (for registering guard/transition listeners).
     */
    public function getDispatcher(string $slug): ?EventDispatcherInterface
    {
        return $this->dispatchers[$slug] ?? null;
    }

    /**
     * Clear the cache (useful for testing or when definitions change).
     */
    public function clearCache(?string $slug = null): void
    {
        if ($slug !== null) {
            unset($this->cache[$slug], $this->dispatchers[$slug]);
        } else {
            $this->cache = [];
            $this->dispatchers = [];
        }
    }
}
