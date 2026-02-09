<?php

declare(strict_types=1);

namespace App\Services\WorkflowEngine;

use App\Services\ServiceResult;

/**
 * Interface for managing workflow approval gates and responses.
 */
interface WorkflowApprovalManagerInterface
{
    /**
     * Record a member's approval decision.
     */
    public function recordResponse(int $approvalId, int $memberId, string $decision, ?string $comment = null): ServiceResult;

    /**
     * Create an approval gate for a workflow node.
     */
    public function createApproval(int $instanceId, string $nodeId, int $executionLogId, array $config): ServiceResult;

    /**
     * Get pending approvals a member is eligible to respond to.
     *
     * @return \App\Model\Entity\WorkflowApproval[]
     */
    public function getPendingApprovalsForMember(int $memberId): array;

    /**
     * Get all approvals for a workflow instance.
     *
     * @return \App\Model\Entity\WorkflowApproval[]
     */
    public function getApprovalsForInstance(int $instanceId): array;

    /**
     * Check if an approval gate has been resolved.
     */
    public function isResolved(int $approvalId): bool;

    /**
     * Cancel all pending approvals for a workflow instance.
     */
    public function cancelApprovalsForInstance(int $instanceId): ServiceResult;

    /**
     * Get members eligible to respond to an approval.
     *
     * @return \App\Model\Entity\Member[]
     */
    public function getEligibleApprovers(int $approvalId): array;
}
