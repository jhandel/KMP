<?php

declare(strict_types=1);

namespace App\Services\WorkflowEngine;

use App\Model\Entity\WorkflowApproval;
use App\Model\Entity\WorkflowApprovalResponse;
use App\Services\ServiceResult;
use Cake\I18n\DateTime;
use Cake\Log\Log;
use Cake\ORM\TableRegistry;

/**
 * Default implementation of WorkflowApprovalManagerInterface.
 *
 * Manages approval gate lifecycle: creation, response recording,
 * eligibility checks, and resolution detection.
 */
class DefaultWorkflowApprovalManager implements WorkflowApprovalManagerInterface
{
    /**
     * @inheritDoc
     */
    public function recordResponse(int $approvalId, int $memberId, string $decision, ?string $comment = null): ServiceResult
    {
        try {
            $approvalsTable = TableRegistry::getTableLocator()->get('WorkflowApprovals');
            $responsesTable = TableRegistry::getTableLocator()->get('WorkflowApprovalResponses');

            /** @var \App\Model\Entity\WorkflowApproval|null $approval */
            $approval = $approvalsTable->find()
                ->where(['WorkflowApprovals.id' => $approvalId])
                ->first();

            if (!$approval) {
                return new ServiceResult(false, 'Approval not found.');
            }

            if ($approval->status !== WorkflowApproval::STATUS_PENDING) {
                return new ServiceResult(false, 'Approval is no longer pending.');
            }

            // Check for duplicate response
            $existing = $responsesTable->find()
                ->where([
                    'workflow_approval_id' => $approvalId,
                    'member_id' => $memberId,
                ])
                ->first();

            if ($existing) {
                return new ServiceResult(false, 'Member has already responded to this approval.');
            }

            // Create response
            $response = $responsesTable->newEntity([
                'workflow_approval_id' => $approvalId,
                'member_id' => $memberId,
                'decision' => $decision,
                'comment' => $comment,
                'responded_at' => DateTime::now(),
            ]);

            if (!$responsesTable->save($response)) {
                Log::error("Failed to save approval response for approval {$approvalId}");
                return new ServiceResult(false, 'Failed to save response.');
            }

            // Update counts
            if ($decision === WorkflowApprovalResponse::DECISION_APPROVE) {
                $approval->approved_count++;
            } elseif ($decision === WorkflowApprovalResponse::DECISION_REJECT) {
                $approval->rejected_count++;
            }

            // Check resolution: threshold met or any rejection
            if ($approval->approved_count >= $approval->required_count) {
                $approval->status = WorkflowApproval::STATUS_APPROVED;
            } elseif ($approval->rejected_count > 0) {
                $approval->status = WorkflowApproval::STATUS_REJECTED;
            }

            if (!$approvalsTable->save($approval)) {
                Log::error("Failed to update approval {$approvalId} after response");
                return new ServiceResult(false, 'Failed to update approval status.');
            }

            return new ServiceResult(true, null, [
                'approvalStatus' => $approval->status,
                'instanceId' => $approval->workflow_instance_id,
                'nodeId' => $approval->node_id,
            ]);
        } catch (\Exception $e) {
            Log::error("Error recording approval response: {$e->getMessage()}");
            return new ServiceResult(false, 'An unexpected error occurred.');
        }
    }

    /**
     * @inheritDoc
     */
    public function createApproval(int $instanceId, string $nodeId, int $executionLogId, array $config): ServiceResult
    {
        try {
            $approvalsTable = TableRegistry::getTableLocator()->get('WorkflowApprovals');

            $approverType = $config['approverType'] ?? WorkflowApproval::APPROVER_TYPE_PERMISSION;
            $approverConfig = $config['approverConfig'] ?? null;
            $requiredCount = (int)($config['requiredCount'] ?? 1);
            $allowParallel = (bool)($config['allowParallel'] ?? true);
            $deadline = null;

            if (!empty($config['deadline'])) {
                $deadline = $this->parseDeadline($config['deadline']);
            }

            $approval = $approvalsTable->newEntity([
                'workflow_instance_id' => $instanceId,
                'node_id' => $nodeId,
                'execution_log_id' => $executionLogId,
                'approver_type' => $approverType,
                'approver_config' => $approverConfig,
                'required_count' => $requiredCount,
                'approved_count' => 0,
                'rejected_count' => 0,
                'status' => WorkflowApproval::STATUS_PENDING,
                'allow_parallel' => $allowParallel,
                'deadline' => $deadline,
            ]);

            if (!$approvalsTable->save($approval)) {
                Log::error("Failed to create approval for instance {$instanceId}, node {$nodeId}");
                return new ServiceResult(false, 'Failed to create approval.');
            }

            return new ServiceResult(true, null, ['approvalId' => $approval->id]);
        } catch (\Exception $e) {
            Log::error("Error creating approval: {$e->getMessage()}");
            return new ServiceResult(false, 'An unexpected error occurred.');
        }
    }

    /**
     * @inheritDoc
     */
    public function getPendingApprovalsForMember(int $memberId): array
    {
        try {
            $approvalsTable = TableRegistry::getTableLocator()->get('WorkflowApprovals');
            $responsesTable = TableRegistry::getTableLocator()->get('WorkflowApprovalResponses');

            // Get IDs of approvals this member already responded to
            $respondedIds = $responsesTable->find()
                ->select(['workflow_approval_id'])
                ->where(['member_id' => $memberId])
                ->all()
                ->extract('workflow_approval_id')
                ->toArray();

            $query = $approvalsTable->find()
                ->contain(['WorkflowInstances.WorkflowDefinitions'])
                ->where(['WorkflowApprovals.status' => WorkflowApproval::STATUS_PENDING]);

            if (!empty($respondedIds)) {
                $query->where(['WorkflowApprovals.id NOT IN' => $respondedIds]);
            }

            $pendingApprovals = $query->all()->toArray();

            // Filter by eligibility
            $eligible = [];
            foreach ($pendingApprovals as $approval) {
                if ($this->isMemberEligible($approval, $memberId)) {
                    $eligible[] = $approval;
                }
            }

            return $eligible;
        } catch (\Exception $e) {
            Log::error("Error fetching pending approvals for member {$memberId}: {$e->getMessage()}");
            return [];
        }
    }

    /**
     * @inheritDoc
     */
    public function getApprovalsForInstance(int $instanceId): array
    {
        try {
            $approvalsTable = TableRegistry::getTableLocator()->get('WorkflowApprovals');

            return $approvalsTable->find()
                ->contain(['WorkflowApprovalResponses.Members'])
                ->where(['WorkflowApprovals.workflow_instance_id' => $instanceId])
                ->all()
                ->toArray();
        } catch (\Exception $e) {
            Log::error("Error fetching approvals for instance {$instanceId}: {$e->getMessage()}");
            return [];
        }
    }

    /**
     * @inheritDoc
     */
    public function isResolved(int $approvalId): bool
    {
        try {
            $approvalsTable = TableRegistry::getTableLocator()->get('WorkflowApprovals');

            /** @var \App\Model\Entity\WorkflowApproval|null $approval */
            $approval = $approvalsTable->find()
                ->where(['WorkflowApprovals.id' => $approvalId])
                ->first();

            if (!$approval) {
                return false;
            }

            return $approval->isResolved();
        } catch (\Exception $e) {
            Log::error("Error checking approval resolution for {$approvalId}: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function cancelApprovalsForInstance(int $instanceId): ServiceResult
    {
        try {
            $approvalsTable = TableRegistry::getTableLocator()->get('WorkflowApprovals');

            $pendingApprovals = $approvalsTable->find()
                ->where([
                    'workflow_instance_id' => $instanceId,
                    'status' => WorkflowApproval::STATUS_PENDING,
                ])
                ->all();

            foreach ($pendingApprovals as $approval) {
                $approval->status = WorkflowApproval::STATUS_CANCELLED;
                if (!$approvalsTable->save($approval)) {
                    Log::error("Failed to cancel approval {$approval->id} for instance {$instanceId}");
                }
            }

            return new ServiceResult(true);
        } catch (\Exception $e) {
            Log::error("Error cancelling approvals for instance {$instanceId}: {$e->getMessage()}");
            return new ServiceResult(false, 'Failed to cancel approvals.');
        }
    }

    /**
     * @inheritDoc
     */
    public function getEligibleApprovers(int $approvalId): array
    {
        try {
            $approvalsTable = TableRegistry::getTableLocator()->get('WorkflowApprovals');

            /** @var \App\Model\Entity\WorkflowApproval|null $approval */
            $approval = $approvalsTable->find()
                ->where(['WorkflowApprovals.id' => $approvalId])
                ->first();

            if (!$approval) {
                return [];
            }

            return $this->findEligibleMembers($approval);
        } catch (\Exception $e) {
            Log::error("Error fetching eligible approvers for {$approvalId}: {$e->getMessage()}");
            return [];
        }
    }

    /**
     * Check if a member is eligible to respond to an approval based on approver_type.
     */
    private function isMemberEligible(WorkflowApproval $approval, int $memberId): bool
    {
        $config = $approval->approver_config ?? [];

        switch ($approval->approver_type) {
            case WorkflowApproval::APPROVER_TYPE_PERMISSION:
                $permissionName = $config['permission'] ?? null;
                if (!$permissionName) {
                    return false;
                }
                return $this->memberHasPermission($memberId, $permissionName);

            case WorkflowApproval::APPROVER_TYPE_ROLE:
                $roleName = $config['role'] ?? null;
                if (!$roleName) {
                    return false;
                }
                return $this->memberHasRole($memberId, $roleName);

            case WorkflowApproval::APPROVER_TYPE_MEMBER:
                $targetMemberId = (int)($config['member_id'] ?? 0);
                return $memberId === $targetMemberId;

            case WorkflowApproval::APPROVER_TYPE_DYNAMIC:
                // Dynamic resolution not yet implemented
                return false;

            default:
                return false;
        }
    }

    /**
     * Find all members eligible to respond to an approval.
     *
     * @return \App\Model\Entity\Member[]
     */
    private function findEligibleMembers(WorkflowApproval $approval): array
    {
        $config = $approval->approver_config ?? [];

        switch ($approval->approver_type) {
            case WorkflowApproval::APPROVER_TYPE_PERMISSION:
                $permissionName = $config['permission'] ?? null;
                if (!$permissionName) {
                    return [];
                }
                return $this->findMembersByPermission($permissionName);

            case WorkflowApproval::APPROVER_TYPE_ROLE:
                $roleName = $config['role'] ?? null;
                if (!$roleName) {
                    return [];
                }
                return $this->findMembersByRole($roleName);

            case WorkflowApproval::APPROVER_TYPE_MEMBER:
                $targetMemberId = (int)($config['member_id'] ?? 0);
                if ($targetMemberId <= 0) {
                    return [];
                }
                $membersTable = TableRegistry::getTableLocator()->get('Members');
                $member = $membersTable->find()
                    ->where(['Members.id' => $targetMemberId])
                    ->first();
                return $member ? [$member] : [];

            case WorkflowApproval::APPROVER_TYPE_DYNAMIC:
                return [];

            default:
                return [];
        }
    }

    /**
     * Check if a member has a specific permission via their roles.
     */
    private function memberHasPermission(int $memberId, string $permissionName): bool
    {
        $memberRolesTable = TableRegistry::getTableLocator()->get('MemberRoles');

        $count = $memberRolesTable->find()
            ->innerJoinWith('Roles.Permissions')
            ->where([
                'MemberRoles.member_id' => $memberId,
                'Permissions.name' => $permissionName,
            ])
            ->count();

        return $count > 0;
    }

    /**
     * Check if a member has a specific role.
     */
    private function memberHasRole(int $memberId, string $roleName): bool
    {
        $memberRolesTable = TableRegistry::getTableLocator()->get('MemberRoles');

        $count = $memberRolesTable->find()
            ->innerJoinWith('Roles')
            ->where([
                'MemberRoles.member_id' => $memberId,
                'Roles.name' => $roleName,
            ])
            ->count();

        return $count > 0;
    }

    /**
     * Find all members who have a role granting the specified permission.
     *
     * @return \App\Model\Entity\Member[]
     */
    private function findMembersByPermission(string $permissionName): array
    {
        $membersTable = TableRegistry::getTableLocator()->get('Members');

        return $membersTable->find()
            ->innerJoinWith('MemberRoles.Roles.Permissions')
            ->where(['Permissions.name' => $permissionName])
            ->group(['Members.id'])
            ->all()
            ->toArray();
    }

    /**
     * Find all members who have the specified role.
     *
     * @return \App\Model\Entity\Member[]
     */
    private function findMembersByRole(string $roleName): array
    {
        $membersTable = TableRegistry::getTableLocator()->get('Members');

        return $membersTable->find()
            ->innerJoinWith('MemberRoles.Roles')
            ->where(['Roles.name' => $roleName])
            ->group(['Members.id'])
            ->all()
            ->toArray();
    }

    /**
     * Parse a deadline string (e.g., "7d", "24h") into a DateTime.
     */
    private function parseDeadline(string $deadline): DateTime
    {
        $now = DateTime::now();

        if (preg_match('/^(\d+)d$/', $deadline, $matches)) {
            return $now->modify("+{$matches[1]} days");
        }

        if (preg_match('/^(\d+)h$/', $deadline, $matches)) {
            return $now->modify("+{$matches[1]} hours");
        }

        if (preg_match('/^(\d+)m$/', $deadline, $matches)) {
            return $now->modify("+{$matches[1]} minutes");
        }

        // Fallback: try parsing as a date string
        return new DateTime($deadline);
    }
}
