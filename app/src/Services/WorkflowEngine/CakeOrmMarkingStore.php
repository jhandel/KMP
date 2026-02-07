<?php
declare(strict_types=1);

namespace App\Services\WorkflowEngine;

use Cake\Log\Log;
use Cake\ORM\TableRegistry;
use Symfony\Component\Workflow\Marking;
use Symfony\Component\Workflow\MarkingStore\MarkingStoreInterface;

/**
 * Reads/writes workflow marking from subject objects and persists to DB.
 *
 * Subjects carry state as a property. When a workflowInstanceId is present,
 * state changes are also persisted to the workflow_instances table.
 */
class CakeOrmMarkingStore implements MarkingStoreInterface
{
    public function __construct(
        private bool $singleState = true,
        private string $property = 'currentState',
    ) {
    }

    public function getMarking(object $subject): Marking
    {
        $marking = new Marking();
        $value = $subject->{$this->property} ?? null;

        if ($value === null) {
            return $marking;
        }

        if ($this->singleState) {
            $marking->mark($value);
        } else {
            foreach ((array) $value as $place) {
                $marking->mark($place);
            }
        }

        return $marking;
    }

    public function setMarking(object $subject, Marking $marking, array $context = []): void
    {
        $places = array_keys($marking->getPlaces());

        if ($this->singleState) {
            $subject->{$this->property} = $places[0] ?? null;
        } else {
            $subject->{$this->property} = $places;
        }

        // Persist to DB if instance ID is available
        if (!empty($subject->workflowInstanceId)) {
            $this->persistToDb($subject, $context);
        }
    }

    private function persistToDb(object $subject, array $context): void
    {
        try {
            $instancesTable = TableRegistry::getTableLocator()->get('WorkflowInstances');
            $statesTable = TableRegistry::getTableLocator()->get('WorkflowStates');

            $instance = $instancesTable->get($subject->workflowInstanceId);

            // Resolve current state slug to state ID
            $currentStateSlug = $this->singleState
                ? $subject->{$this->property}
                : ($subject->{$this->property}[0] ?? null);

            if ($currentStateSlug) {
                $state = $statesTable->find()
                    ->where([
                        'WorkflowStates.workflow_definition_id' => $instance->workflow_definition_id,
                        'WorkflowStates.slug' => $currentStateSlug,
                    ])
                    ->first();

                if ($state) {
                    $instance->previous_state_id = $instance->current_state_id;
                    $instance->current_state_id = $state->id;
                    $instancesTable->save($instance);
                }
            }
        } catch (\Exception $e) {
            // Log but don't fail — DB persistence is best-effort during transition
            Log::warning('WorkflowEngine: Failed to persist marking to DB: ' . $e->getMessage());
        }
    }
}
