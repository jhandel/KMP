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

        // DB persistence is handled by DefaultWorkflowEngine::transition()
        // to avoid stale-entity race conditions with double saves.
    }
}
