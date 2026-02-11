<?php

declare(strict_types=1);

namespace App\Services\WorkflowEngine;

use App\Model\Entity\WorkflowApproval;
use App\Model\Entity\WorkflowApprovalResponse;
use App\Services\ServiceResult;
use App\Services\WorkflowRegistry\WorkflowApproverResolverRegistry;
use Cake\Datasource\ConnectionManager;
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
    public function recordResponse(int $approvalId, int $memberId, string $decision, ?string $comment = null, ?int $nextApproverId = null): ServiceResult
    {
        try {
            $connection = ConnectionManager::get('default');

            return $connection->transactional(function () use ($approvalId, $memberId, $decision, $comment, $nextApproverId) {
                $approvalsTable = TableRegistry::getTableLocator()->get('WorkflowApprovals');
                $responsesTable = TableRegistry::getTableLocator()->get('WorkflowApprovalResponses');

                // Lock the approval row to prevent concurrent modifications
                /** @var \App\Model\Entity\WorkflowApproval|null $approval */
                $approval = $approvalsTable->find()
                    ->where(['WorkflowApprovals.id' => $approvalId])
                    ->epilog('FOR UPDATE')
                    ->first();

                if (!$approval) {
                    return new ServiceResult(false, 'Approval not found.');
                }

                if ($approval->status !== WorkflowApproval::STATUS_PENDING) {
                    return new ServiceResult(false, 'Approval is no longer pending.');
                }

                // Check eligibility before accepting the response
                if (!$this->isMemberEligible($approval, $memberId)) {
                    return new ServiceResult(false, 'You are not eligible to respond to this approval.');
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

                // Atomic increment of counts to prevent lost updates
                if ($decision === WorkflowApprovalResponse::DECISION_APPROVE) {
                    $approvalsTable->updateAll(
                        ['approved_count = approved_count + 1'],
                        ['id' => $approval->id]
                    );
                } elseif ($decision === WorkflowApprovalResponse::DECISION_REJECT) {
                    $approvalsTable->updateAll(
                        ['rejected_count = rejected_count + 1'],
                        ['id' => $approval->id]
                    );
                }

                // Reload approval to get accurate counts after atomic increment
                $approval = $approvalsTable->get($approval->id);

                // Check for serial pick-next mode
                $approverConfig = $approval->approver_config ?? [];
                $isSerialPickNext = !empty($approverConfig['serial_pick_next']);

                // Check resolution: threshold met or any rejection
                if ($approval->approved_count >= $approval->required_count) {
                    $approval->status = WorkflowApproval::STATUS_APPROVED;
                } elseif ($approval->rejected_count > 0) {
                    $approval->status = WorkflowApproval::STATUS_REJECTED;
                } elseif ($isSerialPickNext && $decision === WorkflowApprovalResponse::DECISION_APPROVE) {
                    // Serial pick-next: more approvals needed, update approver_config
                    if ($nextApproverId) {
                        $approverConfig['current_approver_id'] = $nextApproverId;
                    } else {
                        // No next approver specified — clear current to allow any eligible
                        unset($approverConfig['current_approver_id']);
                    }

                    // Append to approval chain for audit trail
                    $chain = $approverConfig['approval_chain'] ?? [];
                    $chain[] = [
                        'approver_id' => $memberId,
                        'responded_at' => DateTime::now()->toIso8601String(),
                        'next_picked' => $nextApproverId,
                    ];
                    $approverConfig['approval_chain'] = $chain;

                    // Track already-used approvers in exclude list
                    $excludeIds = $approverConfig['exclude_member_ids'] ?? [];
                    $excludeIds[] = $memberId;
                    $approverConfig['exclude_member_ids'] = array_unique(array_map('intval', $excludeIds));

                    $approval->approver_config = $approverConfig;
                    // Status stays PENDING — don't resolve yet

                    if (!$approvalsTable->save($approval)) {
                        Log::error("Failed to update approval {$approvalId} approver_config for serial pick-next");
                        return new ServiceResult(false, 'Failed to update approval for next approver.');
                    }

                    return new ServiceResult(true, null, [
                        'approvalStatus' => 'pending',
                        'needsMore' => true,
                        'instanceId' => $approval->workflow_instance_id,
                        'nodeId' => $approval->node_id,
                    ]);
                }

                if ($approval->status !== WorkflowApproval::STATUS_PENDING) {
                    if (!$approvalsTable->save($approval)) {
                        Log::error("Failed to update approval {$approvalId} after response");
                        return new ServiceResult(false, 'Failed to update approval status.');
                    }
                }

                return new ServiceResult(true, null, [
                    'approvalStatus' => $approval->status,
                    'instanceId' => $approval->workflow_instance_id,
                    'nodeId' => $approval->node_id,
                ]);
            });
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
     *
     * Optimized to pre-fetch member permissions/roles in two queries,
     * then filter approvals in-memory instead of N+1 per-approval DB lookups.
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

            // Pre-fetch member's active permissions and roles (2 queries total)
            $memberPermissions = $this->getMemberActivePermissions($memberId);
            $memberRoles = $this->getMemberActiveRoles($memberId);

            $pendingApprovals = $query->all()->toArray();

            // Filter using cached permission/role sets — no per-approval DB queries
            $eligible = [];
            foreach ($pendingApprovals as $approval) {
                if ($this->isMemberEligibleCached($approval, $memberId, $memberPermissions, $memberRoles)) {
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

        // Serial pick-next: when current_approver_id is set, only that member is eligible
        if (!empty($config['serial_pick_next']) && !empty($config['current_approver_id'])) {
            return $memberId === (int)$config['current_approver_id'];
        }

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
                return $this->resolveDynamicEligibility($approval, $memberId);

            case WorkflowApproval::APPROVER_TYPE_POLICY:
                return $this->memberPassesPolicy($approval, $memberId);

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
                return $this->findDynamicApprovers($approval);

            case WorkflowApproval::APPROVER_TYPE_POLICY:
                // Fall back to permission-based list, then filter by policy
                $permissionName = $config['permission'] ?? null;
                if ($permissionName) {
                    return $this->findMembersByPermission($permissionName);
                }
                return [];

            default:
                return [];
        }
    }

    /**
     * Check if a member has a specific permission via their active (unexpired) roles.
     */
    private function memberHasPermission(int $memberId, string $permissionName): bool
    {
        $memberRolesTable = TableRegistry::getTableLocator()->get('MemberRoles');
        $now = DateTime::now();

        $count = $memberRolesTable->find()
            ->innerJoinWith('Roles.Permissions')
            ->where([
                'MemberRoles.member_id' => $memberId,
                'Permissions.name' => $permissionName,
                'OR' => [
                    'MemberRoles.start_on IS' => null,
                    'MemberRoles.start_on <=' => $now,
                ],
            ])
            ->where([
                'OR' => [
                    'MemberRoles.expires_on IS' => null,
                    'MemberRoles.expires_on >=' => $now,
                ],
            ])
            ->count();

        return $count > 0;
    }

    /**
     * Check if a member has a specific active (unexpired) role.
     */
    private function memberHasRole(int $memberId, string $roleName): bool
    {
        $memberRolesTable = TableRegistry::getTableLocator()->get('MemberRoles');
        $now = DateTime::now();

        $count = $memberRolesTable->find()
            ->innerJoinWith('Roles')
            ->where([
                'MemberRoles.member_id' => $memberId,
                'Roles.name' => $roleName,
                'OR' => [
                    'MemberRoles.start_on IS' => null,
                    'MemberRoles.start_on <=' => $now,
                ],
            ])
            ->where([
                'OR' => [
                    'MemberRoles.expires_on IS' => null,
                    'MemberRoles.expires_on >=' => $now,
                ],
            ])
            ->count();

        return $count > 0;
    }

    /**
     * Find all members who have an active (unexpired) role granting the specified permission.
     *
     * @return \App\Model\Entity\Member[]
     */
    private function findMembersByPermission(string $permissionName): array
    {
        $membersTable = TableRegistry::getTableLocator()->get('Members');
        $now = DateTime::now();

        return $membersTable->find()
            ->innerJoinWith('MemberRoles.Roles.Permissions')
            ->where([
                'Permissions.name' => $permissionName,
                'OR' => [
                    'MemberRoles.start_on IS' => null,
                    'MemberRoles.start_on <=' => $now,
                ],
            ])
            ->where([
                'OR' => [
                    'MemberRoles.expires_on IS' => null,
                    'MemberRoles.expires_on >=' => $now,
                ],
            ])
            ->group(['Members.id'])
            ->all()
            ->toArray();
    }

    /**
     * Find all members who have the specified active (unexpired) role.
     *
     * @return \App\Model\Entity\Member[]
     */
    private function findMembersByRole(string $roleName): array
    {
        $membersTable = TableRegistry::getTableLocator()->get('Members');
        $now = DateTime::now();

        return $membersTable->find()
            ->innerJoinWith('MemberRoles.Roles')
            ->where([
                'Roles.name' => $roleName,
                'OR' => [
                    'MemberRoles.start_on IS' => null,
                    'MemberRoles.start_on <=' => $now,
                ],
            ])
            ->where([
                'OR' => [
                    'MemberRoles.expires_on IS' => null,
                    'MemberRoles.expires_on >=' => $now,
                ],
            ])
            ->group(['Members.id'])
            ->all()
            ->toArray();
    }

    /**
     * Get all active permission names for a member in a single query.
     *
     * @return string[]
     */
    private function getMemberActivePermissions(int $memberId): array
    {
        $memberRolesTable = TableRegistry::getTableLocator()->get('MemberRoles');
        $now = DateTime::now();

        return $memberRolesTable->find()
            ->innerJoinWith('Roles.Permissions')
            ->select(['permission_name' => 'Permissions.name'])
            ->where([
                'MemberRoles.member_id' => $memberId,
                'OR' => [
                    'MemberRoles.start_on IS' => null,
                    'MemberRoles.start_on <=' => $now,
                ],
            ])
            ->where([
                'OR' => [
                    'MemberRoles.expires_on IS' => null,
                    'MemberRoles.expires_on >=' => $now,
                ],
            ])
            ->distinct()
            ->all()
            ->extract('permission_name')
            ->toArray();
    }

    /**
     * Get all active role names for a member in a single query.
     *
     * @return string[]
     */
    private function getMemberActiveRoles(int $memberId): array
    {
        $memberRolesTable = TableRegistry::getTableLocator()->get('MemberRoles');
        $now = DateTime::now();

        return $memberRolesTable->find()
            ->innerJoinWith('Roles')
            ->select(['role_name' => 'Roles.name'])
            ->where([
                'MemberRoles.member_id' => $memberId,
                'OR' => [
                    'MemberRoles.start_on IS' => null,
                    'MemberRoles.start_on <=' => $now,
                ],
            ])
            ->where([
                'OR' => [
                    'MemberRoles.expires_on IS' => null,
                    'MemberRoles.expires_on >=' => $now,
                ],
            ])
            ->distinct()
            ->all()
            ->extract('role_name')
            ->toArray();
    }

    /**
     * Check eligibility using pre-fetched permission/role sets to avoid N+1 queries.
     */
    private function isMemberEligibleCached(
        WorkflowApproval $approval,
        int $memberId,
        array $memberPermissions,
        array $memberRoles,
    ): bool {
        $config = $approval->approver_config ?? [];

        // Serial pick-next: when current_approver_id is set, only that member is eligible
        if (!empty($config['serial_pick_next']) && !empty($config['current_approver_id'])) {
            return $memberId === (int)$config['current_approver_id'];
        }

        switch ($approval->approver_type) {
            case WorkflowApproval::APPROVER_TYPE_PERMISSION:
                $permissionName = $config['permission'] ?? null;
                return $permissionName && in_array($permissionName, $memberPermissions, true);

            case WorkflowApproval::APPROVER_TYPE_ROLE:
                $roleName = $config['role'] ?? null;
                return $roleName && in_array($roleName, $memberRoles, true);

            case WorkflowApproval::APPROVER_TYPE_MEMBER:
                $targetMemberId = (int)($config['member_id'] ?? 0);
                return $memberId === $targetMemberId;

            case WorkflowApproval::APPROVER_TYPE_DYNAMIC:
                return $this->resolveDynamicEligibility($approval, $memberId);

            case WorkflowApproval::APPROVER_TYPE_POLICY:
                return $this->memberPassesPolicy($approval, $memberId);

            default:
                return false;
        }
    }

    /**
     * Check if a member is eligible via the dynamic callback approver.
     */
    private function resolveDynamicEligibility(WorkflowApproval $approval, int $memberId): bool
    {
        try {
            $eligibleIds = $this->resolveDynamicApproverIds($approval);
            return in_array($memberId, $eligibleIds, true);
        } catch (\RuntimeException $e) {
            Log::error("Dynamic approver resolution failed: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Resolve eligible member IDs via configured callback service.
     *
     * Expects approver_config: {"service": "App\\Services\\MyService", "method": "getEligibleApprovers"}
     * The callback receives the WorkflowApproval and must return int[] of member IDs.
     *
     * @return int[]
     * @throws \RuntimeException If config is missing or callback is invalid
     */
    private function resolveDynamicApproverIds(WorkflowApproval $approval): array
    {
        $config = $approval->approver_config ?? [];
        $serviceRef = $config['service'] ?? null;
        $method = $config['method'] ?? null;

        // Try registry lookup first
        $registryEntry = $serviceRef ? WorkflowApproverResolverRegistry::getResolver($serviceRef) : null;
        if ($registryEntry) {
            $serviceClass = $registryEntry['serviceClass'];
            $method = $method ?? $registryEntry['serviceMethod'];
        } else {
            // Backward compat: treat as direct class name
            $serviceClass = $serviceRef;
        }

        if (!$serviceClass || !$method) {
            throw new \RuntimeException(
                "Dynamic approver type requires 'service' and 'method' in approver_config. "
                . "Approval ID: {$approval->id}"
            );
        }

        if (!class_exists($serviceClass)) {
            throw new \RuntimeException("Dynamic approver service class '{$serviceClass}' not found.");
        }

        $service = new $serviceClass();
        if (!method_exists($service, $method)) {
            throw new \RuntimeException("Method '{$method}' not found on '{$serviceClass}'.");
        }

        $result = $service->$method($approval);
        if (!is_array($result)) {
            Log::warning("Dynamic approver {$serviceClass}::{$method} did not return an array");
            return [];
        }

        return array_map('intval', $result);
    }

    /**
     * Find Member entities eligible via the dynamic callback approver.
     *
     * @return \App\Model\Entity\Member[]
     */
    private function findDynamicApprovers(WorkflowApproval $approval): array
    {
        try {
            $memberIds = $this->resolveDynamicApproverIds($approval);
            if (empty($memberIds)) {
                return [];
            }

            $membersTable = TableRegistry::getTableLocator()->get('Members');
            return $membersTable->find()
                ->where(['Members.id IN' => $memberIds])
                ->all()
                ->toArray();
        } catch (\RuntimeException $e) {
            Log::error("Dynamic approver resolution failed: {$e->getMessage()}");
            return [];
        }
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

    /**
     * Check if a member passes a CakePHP policy check for the approval's entity.
     *
     * Resolves the entity from the workflow instance context, loads the member
     * as an identity, instantiates the policy class, and calls the action method.
     */
    private function memberPassesPolicy(WorkflowApproval $approval, int $memberId): bool
    {
        $config = $approval->approver_config ?? [];
        $policyClass = $config['policyClass'] ?? null;
        $policyAction = $config['policyAction'] ?? null;
        $entityTable = $config['entityTable'] ?? null;
        $entityIdKey = $config['entityIdKey'] ?? null;

        if (!$policyClass || !$policyAction || !$entityTable || !$entityIdKey) {
            Log::warning("Policy approver type missing config: policyClass={$policyClass}, policyAction={$policyAction}, entityTable={$entityTable}, entityIdKey={$entityIdKey}");
            return false;
        }

        // Load the workflow instance to get context
        $instancesTable = TableRegistry::getTableLocator()->get('WorkflowInstances');
        $instance = $instancesTable->get($approval->workflow_instance_id);
        $context = $instance->context ?? [];

        // Resolve entity ID from context using dot-path key
        $entityId = $this->resolveContextValue($context, $entityIdKey);
        if (!$entityId) {
            Log::warning("Policy check: could not resolve entity ID from context key '{$entityIdKey}'");
            return false;
        }

        // Load the target entity
        $table = TableRegistry::getTableLocator()->get($entityTable);
        $entity = $table->find()->where([$table->getAlias() . '.id' => $entityId])->first();
        if (!$entity) {
            Log::warning("Policy check: entity {$entityTable}#{$entityId} not found");
            return false;
        }

        // Load the member as an identity (has getPolicies() via KmpIdentityInterface)
        $membersTable = TableRegistry::getTableLocator()->get('Members');
        $member = $membersTable->get($memberId);

        // Instantiate the policy and call the action method
        if (!class_exists($policyClass)) {
            Log::warning("Policy check: class {$policyClass} not found");
            return false;
        }

        $policy = new $policyClass();
        $methodName = $policyAction;
        if (!method_exists($policy, $methodName)) {
            Log::warning("Policy check: method {$methodName} not found on {$policyClass}");
            return false;
        }

        try {
            return $policy->$methodName($member, $entity);
        } catch (\Exception $e) {
            Log::error("Policy check failed: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Resolve a dot-path value from a nested context array.
     * E.g., "trigger.rosterId" resolves $context['trigger']['rosterId'].
     */
    private function resolveContextValue(array $context, string $key): mixed
    {
        $parts = explode('.', $key);
        $current = $context;
        foreach ($parts as $part) {
            if (!is_array($current) || !array_key_exists($part, $current)) {
                return null;
            }
            $current = $current[$part];
        }
        return $current;
    }
}
