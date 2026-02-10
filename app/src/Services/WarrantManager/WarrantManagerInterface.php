<?php

declare(strict_types=1);

namespace App\Services\WarrantManager;

use App\Model\Entity\WarrantPeriod;
use App\Services\ServiceResult;
use Cake\I18n\DateTime;

/**
 * Warrant Manager Interface
 *
 * Defines the contract for managing KMP warrant lifecycle: requests, approvals,
 * and lifecycle management for authorized roles/positions within branches.
 *
 * @see \App\Services\WarrantManager\DefaultWarrantManager Default implementation
 * @see \App\Services\WarrantManager\WarrantRequest Warrant request data structure
 * @see \App\Model\Entity\Warrant Warrant entity
 */
interface WarrantManagerInterface
{
    /**
     * Submit a batch of warrant requests for approval.
     *
     * @param string $request_name Name for the warrant roster
     * @param string $desc Description of the warrant requests
     * @param WarrantRequest[] $warrantRequests Array of warrant request objects
     * @return ServiceResult Success with roster ID, or failure with errors
     */
    public function request($request_name, $desc, $warrantRequests): ServiceResult;

    /**
     * Approve a warrant roster and activate all contained warrants.
     *
     * @param int $warrant_roster_id ID of the WarrantRoster to approve
     * @param int $approver_id ID of the member providing approval
     * @return ServiceResult Success if approval recorded, failure with errors
     */
    public function approve($warrant_roster_id, $approver_id): ServiceResult;

    /**
     * Decline an entire warrant roster and cancel all contained warrants.
     *
     * @param int $warrant_roster_id ID of the WarrantRoster to decline
     * @param int $rejecter_id ID of the member declining
     * @param string $reason Explanation for the decline
     * @return ServiceResult Success if declined, failure with errors
     */
    public function decline($warrant_roster_id, $rejecter_id, $reason): ServiceResult;

    /**
     * Cancel/revoke a specific warrant by ID.
     *
     * @param int $warrant_id ID of the warrant to cancel
     * @param string $reason Explanation for cancellation
     * @param int $rejecter_id ID of member cancelling
     * @param DateTime $expiresOn When warrant should terminate
     * @return ServiceResult Always returns success
     */
    public function cancel($warrant_id, $reason, $rejecter_id, $expiresOn): ServiceResult;

    /**
     * Cancel all warrants associated with a specific entity.
     *
     * @param string $entityType Entity type (e.g., 'Branches', 'Activities')
     * @param int $entityId ID of the entity instance
     * @param string $reason Explanation for cancellation
     * @param int $rejecter_id ID of member cancelling
     * @param DateTime $expiresOn When warrants should terminate
     * @return ServiceResult Always returns success
     */
    public function cancelByEntity($entityType, $entityId, $reason, $rejecter_id, $expiresOn): ServiceResult;

    /**
     * Decline a single warrant within a roster.
     *
     * @param int $warrant_id ID of the warrant to decline
     * @param string $reason Explanation for decline
     * @param int $rejecter_id ID of member declining
     * @return ServiceResult Success if declined, failure with errors
     */
    public function declineSingleWarrant($warrant_id, $reason, $rejecter_id): ServiceResult;

    /**
     * Activate warrants in an already-approved roster.
     *
     * Expects roster status=APPROVED and correct approval_count.
     * Activates pending warrants, expires overlapping ones, sends notifications.
     * Idempotent: returns success if no pending warrants remain.
     *
     * @param int $rosterId ID of the approved WarrantRoster
     * @param int $approverId ID of the member who approved
     * @return ServiceResult Success if warrants activated or already active
     */
    public function activateApprovedRoster(int $rosterId, int $approverId): ServiceResult;

    /**
     * Sync a workflow approval response to the roster approval table.
     *
     * Creates a warrant_roster_approval record with dedup guard.
     * Increments approval_count atomically. Returns success if duplicate found (idempotent).
     * Does NOT change roster status or activate warrants.
     *
     * @param int $rosterId ID of the WarrantRoster
     * @param int $approverId ID of the approving member
     * @param string|null $notes Optional approval notes
     * @param \DateTimeInterface|null $approvedOn When approval occurred (defaults to now)
     * @return ServiceResult Success if recorded or duplicate found
     */
    public function syncWorkflowApprovalToRoster(int $rosterId, int $approverId, ?string $notes = null, ?\DateTimeInterface $approvedOn = null): ServiceResult;

    /**
     * Find or create a warrant period covering the specified date range.
     *
     * @param DateTime $startOn Desired warrant start date
     * @param DateTime|null $endOn Desired warrant end date
     * @return WarrantPeriod|null Matching period, or null if none found
     */
    public function getWarrantPeriod(DateTime $startOn, ?DateTime $endOn): ?WarrantPeriod;
}
