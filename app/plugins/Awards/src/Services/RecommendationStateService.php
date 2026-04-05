<?php
declare(strict_types=1);

namespace Awards\Services;

use Awards\Model\Entity\Recommendation;
use Cake\I18n\DateTime;
use Cake\Log\Log;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\ORM\Table;
use DateTimeZone;
use Exception;

/**
 * Handles recommendation state machine transitions and bulk state updates.
 *
 * Encapsulates the transactional logic for changing recommendation states,
 * including gathering assignment, given-date setting, close-reason recording,
 * and bulk-note creation.
 *
 * @see \Awards\Model\Entity\Recommendation::getStatuses() For state → status mapping
 */
class RecommendationStateService
{
    use LocatorAwareTrait;
    /**
     * Perform a transactional bulk state transition for multiple recommendations.
     *
     * @param \Cake\ORM\Table $recommendationsTable The Recommendations ORM table instance.
     * @param array{ids: array<string>, newState: string, gathering_id: string|null, given: string|null, note: string|null, close_reason: string|null} $data Bulk update parameters.
     * @param int $authorId The current user ID for note attribution.
     * @return bool True on success, false on failure.
     */
    public function bulkUpdateStates(
        Table $recommendationsTable,
        array $data,
        int $authorId,
    ): bool {
        $ids = $data['ids'];
        $newState = $data['newState'];
        $gatheringId = $data['gathering_id'] ?? null;
        $given = $data['given'] ?? null;
        $note = $data['note'] ?? null;
        $closeReason = $data['close_reason'] ?? null;

        $recommendationsTable->getConnection()->begin();
        try {
            // Capture previous states for state log entries
            $previousStates = $recommendationsTable->find()
                ->select(['id', 'state', 'status'])
                ->where(['id IN' => $ids])
                ->all()
                ->combine('id', function ($rec) {
                    return ['state' => $rec->state, 'status' => $rec->status];
                })
                ->toArray();

            $statusList = Recommendation::getStatuses();
            $newStatus = '';

            // Find the status corresponding to the new state
            foreach ($statusList as $key => $value) {
                foreach ($value as $state) {
                    if ($state === $newState) {
                        $newStatus = $key;
                        break 2;
                    }
                }
            }

            // Build flat associative array for updateAll
            $updateFields = [
                'state' => $newState,
                'status' => $newStatus,
            ];

            if (Recommendation::supportsGatheringAssignmentForState((string)$newState)) {
                if ($gatheringId) {
                    $updateFields['gathering_id'] = $gatheringId;
                }
            } else {
                $updateFields['gathering_id'] = null;
            }

            if ($given) {
                // Create DateTime at midnight UTC to preserve the exact date
                $updateFields['given'] = new DateTime($given . ' 00:00:00', new DateTimeZone('UTC'));
            }

            if ($closeReason) {
                $updateFields['close_reason'] = $closeReason;
            }

            if (!$recommendationsTable->updateAll($updateFields, ['id IN' => $ids])) {
                throw new Exception('Failed to update recommendations');
            }

            // Write state log entries for each recommendation
            $stateLogsTable = $this->fetchTable('Awards.RecommendationsStatesLogs');
            foreach ($ids as $id) {
                $prev = $previousStates[$id] ?? ['state' => '', 'status' => ''];
                $logEntry = $stateLogsTable->newEmptyEntity();
                $logEntry->recommendation_id = (int)$id;
                $logEntry->from_state = $prev['state'];
                $logEntry->to_state = $newState;
                $logEntry->from_status = $prev['status'];
                $logEntry->to_status = $newStatus;
                $logEntry->created_by = $authorId;
                if (!$stateLogsTable->save($logEntry)) {
                    Log::warning("Failed to save state log for recommendation {$id}");
                }
            }

            if ($note) {
                foreach ($ids as $id) {
                    $newNote = $recommendationsTable->Notes->newEmptyEntity();
                    $newNote->entity_id = $id;
                    $newNote->subject = 'Recommendation Bulk Updated';
                    $newNote->entity_type = 'Awards.Recommendations';
                    $newNote->body = $note;
                    $newNote->author_id = $authorId;

                    if (!$recommendationsTable->Notes->save($newNote)) {
                        throw new Exception('Failed to save note');
                    }
                }
            }

            $recommendationsTable->getConnection()->commit();

            return true;
        } catch (Exception $e) {
            $recommendationsTable->getConnection()->rollback();
            Log::error('Error updating recommendations: ' . $e->getMessage());

            return false;
        }
    }
}
