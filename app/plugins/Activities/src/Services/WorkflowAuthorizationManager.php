<?php

declare(strict_types=1);

namespace Activities\Services;

use Activities\Model\Entity\Authorization;
use App\KMP\StaticHelpers;
use Cake\I18n\DateTime;
use Cake\Log\Log;
use Cake\Mailer\MailerAwareTrait;
use Cake\ORM\TableRegistry;
use App\Services\ActiveWindowManager\ActiveWindowManagerInterface;
use App\Services\ServiceResult;
use App\Services\WorkflowEngine\WorkflowEngineInterface;
use App\Services\WorkflowEngine\ApprovalGateService;

/**
 * Workflow-based Authorization Manager Service
 *
 * Replaces DefaultAuthorizationManager by delegating state transitions to the
 * workflow engine while preserving backward-compatible database records and
 * email notifications.
 *
 * @see AuthorizationManagerInterface Service contract definition
 * @see WorkflowEngineInterface Workflow orchestration service
 * @see ApprovalGateService Approval gate management
 */
class WorkflowAuthorizationManager implements AuthorizationManagerInterface
{
    use MailerAwareTrait;

    private WorkflowEngineInterface $workflowEngine;
    private ApprovalGateService $approvalGateService;
    private ActiveWindowManagerInterface $activeWindowManager;

    public function __construct(
        WorkflowEngineInterface $workflowEngine,
        ApprovalGateService $approvalGateService,
        ActiveWindowManagerInterface $activeWindowManager,
    ) {
        $this->workflowEngine = $workflowEngine;
        $this->approvalGateService = $approvalGateService;
        $this->activeWindowManager = $activeWindowManager;
    }

    /**
     * Initiate a new authorization request and start a workflow instance.
     *
     * @param int $requesterId Member requesting authorization
     * @param int $activityId Activity for authorization
     * @param int $approverId Designated approver
     * @param bool $isRenewal Whether this is a renewal
     * @return ServiceResult
     */
    public function request(
        int $requesterId,
        int $activityId,
        int $approverId,
        bool $isRenewal,
    ): ServiceResult {
        $table = TableRegistry::getTableLocator()->get("Activities.Authorizations");

        // Renewal validation
        if ($isRenewal) {
            $existingAuths = $table
                ->find()
                ->where([
                    "member_id" => $requesterId,
                    "activity_id" => $activityId,
                    "status" => Authorization::APPROVED_STATUS,
                    "expires_on >" => DateTime::now(),
                ])
                ->count();
            if ($existingAuths == 0) {
                return new ServiceResult(false, "There is no existing authorization to renew");
            }
        }

        // Duplicate pending request check
        $existingRequests = $table
            ->find()
            ->where([
                "member_id" => $requesterId,
                "activity_id" => $activityId,
                "status" => Authorization::PENDING_STATUS,
            ])
            ->count();
        if ($existingRequests > 0) {
            return new ServiceResult(false, "There is already a pending request for this activity");
        }

        $auth = $table->newEmptyEntity();
        $auth->member_id = $requesterId;
        $auth->activity_id = $activityId;
        $auth->requested_on = DateTime::now();
        $auth->status = Authorization::PENDING_STATUS;
        $auth->is_renewal = $isRenewal;

        $table->getConnection()->begin();

        if (!$table->save($auth)) {
            $table->getConnection()->rollback();
            return new ServiceResult(false, "Failed to save authorization");
        }

        // Create first approval record
        $approval = $table->AuthorizationApprovals->newEmptyEntity();
        $approval->authorization_id = $auth->id;
        $approval->approver_id = $approverId;
        $approval->requested_on = DateTime::now();
        $approval->authorization_token = StaticHelpers::generateToken(32);

        if (!$table->AuthorizationApprovals->save($approval)) {
            $table->getConnection()->rollback();
            return new ServiceResult(false, "Failed to save authorization approval");
        }

        // Start workflow instance with entity context
        $auth = $table->get($auth->id, contain: ['Activities']);
        $wfResult = $this->workflowEngine->startWorkflow(
            'activity-authorization',
            'Activities.Authorizations',
            $auth->id,
            $requesterId,
            $this->buildWorkflowContext($auth, 'request', $requesterId),
        );
        if (!$wfResult->success) {
            $table->getConnection()->rollback();
            return new ServiceResult(false, "Failed to start workflow: " . ($wfResult->reason ?? 'unknown error'));
        }

        // Auto-transition from 'requested' to 'pending-approval'
        $instance = $wfResult->data;
        $submitResult = $this->workflowEngine->transition(
            $instance->id,
            'submit-for-approval',
            $requesterId,
            $this->buildWorkflowContext($auth, 'submit-for-approval', $requesterId),
        );
        if (!$submitResult->success) {
            $table->getConnection()->rollback();
            return new ServiceResult(false, "Failed to submit for approval: " . ($submitResult->reason ?? 'unknown error'));
        }

        // Send notification to approver
        if (
            !$this->sendApprovalRequestNotification(
                $activityId,
                $requesterId,
                $approverId,
                $approval->authorization_token,
            )
        ) {
            $table->getConnection()->rollback();
            return new ServiceResult(false, "Failed to send approval request notification");
        }

        $table->getConnection()->commit();
        return new ServiceResult(true);
    }

    /**
     * Process approval with workflow gate integration.
     *
     * @param int $authorizationApprovalId Approval record ID
     * @param int $approverId Approver member ID
     * @param int|null $nextApproverId Next approver for multi-level workflow
     * @return ServiceResult
     */
    public function approve(
        int $authorizationApprovalId,
        int $approverId,
        ?int $nextApproverId = null,
    ): ServiceResult {
        $approvalTable = TableRegistry::getTableLocator()->get(
            "Activities.AuthorizationApprovals",
        );
        $authTable = $approvalTable->Authorizations;
        $transConnection = $approvalTable->getConnection();
        $transConnection->begin();

        $approval = $approvalTable->get(
            $authorizationApprovalId,
            contain: ["Authorizations.Activities"],
        );
        if (!$approval) {
            $transConnection->rollback();
            return new ServiceResult(false, "Approval not found");
        }

        $authorization = $approval->authorization;
        if (!$authorization) {
            $transConnection->rollback();
            return new ServiceResult(false, "Authorization not found");
        }

        $activity = $authorization->activity;
        if (!$activity) {
            $transConnection->rollback();
            return new ServiceResult(false, "Activity not found");
        }

        // Save the approval record (mark as approved)
        $approval->responded_on = DateTime::now();
        $approval->approved = true;
        $approval->approver_id = $approverId;
        if (!$approvalTable->save($approval)) {
            $transConnection->rollback();
            return new ServiceResult(false, "Failed to save approval");
        }

        // Get the workflow instance for this authorization
        $instanceResult = $this->workflowEngine->getInstanceForEntity(
            'Activities.Authorizations',
            $authorization->id,
        );

        if (!$instanceResult->success || !$instanceResult->data) {
            // Fall back to non-workflow approval logic if no workflow instance exists
            return $this->approveWithoutWorkflow(
                $authorization,
                $activity,
                $approverId,
                $nextApproverId,
                $approvalTable,
                $authTable,
                $transConnection,
            );
        }

        $instance = $instanceResult->data;
        $instanceId = is_object($instance) ? $instance->id : $instance['id'];

        // Find the approval gate for the current workflow state
        $gatesTable = TableRegistry::getTableLocator()->get('WorkflowApprovalGates');
        $gate = $gatesTable->find()
            ->where(['workflow_state_id' => (is_object($instance) ? $instance->current_state_id : $instance['current_state_id'])])
            ->first();

        if ($gate) {
            // Record approval in the gate service with entity context for threshold resolution
            $gateResult = $this->approvalGateService->recordApproval(
                $instanceId,
                $gate->id,
                $approverId,
                'approved',
                null,
                $this->buildWorkflowContext($authorization),
            );

            if (!$gateResult->success) {
                $transConnection->rollback();
                return new ServiceResult(false, "Failed to record approval in gate: " . ($gateResult->reason ?? ''));
            }

            $gateStatus = $gateResult->data['gate_status'] ?? [];
            $satisfied = $gateStatus['satisfied'] ?? false;

            if ($satisfied) {
                // Gate is satisfied — transition the workflow to approved
                $transResult = $this->workflowEngine->transition(
                    $instanceId, 'approve', $approverId,
                    $this->buildWorkflowContext($approval->authorization, 'approve', $approverId),
                );
                if (!$transResult->success) {
                    $transConnection->rollback();
                    return new ServiceResult(false, "Failed to transition workflow: " . ($transResult->reason ?? ''));
                }

                // Update authorization status
                $authorization->status = Authorization::APPROVED_STATUS;
                $authorization->approval_count = $authorization->approval_count + 1;
                if (!$authTable->save($authorization)) {
                    $transConnection->rollback();
                    return new ServiceResult(false, "Failed to update authorization status");
                }

                // Start active window (role assignment)
                $awResult = $this->activeWindowManager->start(
                    "Activities.Authorizations",
                    $authorization->id,
                    $approverId,
                    DateTime::now(),
                    null,
                    $authorization->activity->term_length,
                    $authorization->activity->grants_role_id,
                );
                if (!$awResult->success) {
                    $transConnection->rollback();
                    return new ServiceResult(false, "Failed to start active window");
                }

                // Notify requester of approval
                $this->sendAuthorizationStatusToRequester(
                    $authorization->activity_id,
                    $authorization->member_id,
                    $approverId,
                    $authorization->status,
                    null,
                );

                // Update workflow context with post-approval entity state
                $this->updateInstanceContext($instanceId, $authorization);

                $transConnection->commit();
                return new ServiceResult(true);
            }

            // Gate not satisfied — more approvals needed
            if ($nextApproverId !== null) {
                if (
                    !$this->processForwardToNextApprover(
                        $approverId,
                        $nextApproverId,
                        $authorization,
                        $approvalTable,
                        $authTable,
                    )
                ) {
                    $transConnection->rollback();
                    return new ServiceResult(false, "Failed to forward to next approver");
                }

                // Record intermediate gate approval in context
                $this->appendGateApprovalToContext(
                    $instanceId,
                    $approverId,
                    $gateStatus,
                    $authorization,
                );

                $transConnection->commit();
                return new ServiceResult(true);
            }

            // Gate not satisfied and no next approver provided
            $transConnection->rollback();
            return new ServiceResult(false, "Additional approvals required. Please provide the next approver.");
        }

        // No gate found — fall back to non-workflow approval logic
        return $this->approveWithoutWorkflow(
            $authorization,
            $activity,
            $approverId,
            $nextApproverId,
            $approvalTable,
            $authTable,
            $transConnection,
        );
    }

    /**
     * Process denial with workflow transition.
     *
     * @param int $authorizationApprovalId Approval record ID
     * @param int $approverId Denier member ID
     * @param string $denyReason Reason for denial
     * @return ServiceResult
     */
    public function deny(
        int $authorizationApprovalId,
        int $approverId,
        string $denyReason,
    ): ServiceResult {
        $table = TableRegistry::getTableLocator()->get(
            "Activities.AuthorizationApprovals",
        );
        $approval = $table->get(
            $authorizationApprovalId,
            contain: ["Authorizations"],
        );

        $approval->responded_on = DateTime::now();
        $approval->approved = false;
        $approval->approver_id = $approverId;
        $approval->approver_notes = $denyReason;
        $approval->authorization->revoker_id = $approverId;
        $approval->authorization->revoked_reason = $denyReason;
        $approval->authorization->status = Authorization::DENIED_STATUS;
        $approval->authorization->start_on = DateTime::now()->subSeconds(1);
        $approval->authorization->expires_on = DateTime::now()->subSeconds(1);

        $table->getConnection()->begin();

        if (
            !$table->save($approval) ||
            !$table->Authorizations->save($approval->authorization)
        ) {
            $table->getConnection()->rollback();
            return new ServiceResult(false, "Failed to deny authorization approval");
        }

        // Transition workflow to denied state
        $instanceResult = $this->workflowEngine->getInstanceForEntity(
            'Activities.Authorizations',
            $approval->authorization->id,
        );
        if ($instanceResult->success && $instanceResult->data) {
            $instance = $instanceResult->data;
            $instanceId = is_object($instance) ? $instance->id : $instance['id'];
            $this->workflowEngine->transition(
                $instanceId, 'deny', $approverId,
                $this->buildWorkflowContext($approval->authorization, 'deny', $approverId),
            );
            $this->updateInstanceContext($instanceId, $approval->authorization);
        }

        // Notify requester
        if (
            !$this->sendAuthorizationStatusToRequester(
                $approval->authorization->activity_id,
                $approval->authorization->member_id,
                $approverId,
                $approval->authorization->status,
                null,
            )
        ) {
            $table->getConnection()->rollback();
            return new ServiceResult(false, "Failed to send authorization status to requester");
        }

        $table->getConnection()->commit();
        return new ServiceResult(true);
    }

    /**
     * Revoke an active authorization with workflow transition.
     *
     * @param int $authorizationId Authorization to revoke
     * @param int $revokerId Admin performing revocation
     * @param string $revokedReason Reason for revocation
     * @return ServiceResult
     */
    public function revoke(
        int $authorizationId,
        int $revokerId,
        string $revokedReason,
    ): ServiceResult {
        $table = TableRegistry::getTableLocator()->get("Activities.Authorizations");
        $table->getConnection()->begin();

        // Revoke via ActiveWindowManager (handles status + role removal)
        $awResult = $this->activeWindowManager->stop(
            "Activities.Authorizations",
            $authorizationId,
            $revokerId,
            Authorization::REVOKED_STATUS,
            $revokedReason,
            DateTime::now(),
        );
        if (!$awResult->success) {
            $table->getConnection()->rollback();
            return new ServiceResult(false, "Failed to revoke member role");
        }

        // Reload authorization after status update by activeWindowManager
        $authorization = $table->get($authorizationId, contain: ['Activities']);

        // Transition workflow to revoked state
        $instanceResult = $this->workflowEngine->getInstanceForEntity(
            'Activities.Authorizations',
            $authorizationId,
        );
        if ($instanceResult->success && $instanceResult->data) {
            $instance = $instanceResult->data;
            $instanceId = is_object($instance) ? $instance->id : $instance['id'];
            $this->workflowEngine->transition(
                $instanceId, 'revoke', $revokerId,
                $this->buildWorkflowContext($authorization, 'revoke', $revokerId),
            );
            $this->updateInstanceContext($instanceId, $authorization);
        }
        if (
            !$this->sendAuthorizationStatusToRequester(
                $authorization->activity_id,
                $authorization->member_id,
                $revokerId,
                $authorization->status,
                null,
            )
        ) {
            $table->getConnection()->rollback();
            return new ServiceResult(false, "Failed to send authorization status to requester");
        }

        $table->getConnection()->commit();
        return new ServiceResult(true);
    }

    /**
     * Retract a pending authorization request with workflow transition.
     *
     * @param int $authorizationId Authorization to retract
     * @param int $requesterId Requester (must be owner)
     * @return ServiceResult
     */
    public function retract(
        int $authorizationId,
        int $requesterId,
    ): ServiceResult {
        $table = TableRegistry::getTableLocator()->get("Activities.Authorizations");

        $authorization = $table->find()
            ->where(['id' => $authorizationId])
            ->first();

        if (!$authorization) {
            return new ServiceResult(false, "Authorization not found");
        }

        if ($authorization->status !== Authorization::PENDING_STATUS) {
            return new ServiceResult(false, "Only pending authorizations can be retracted");
        }

        if ($authorization->member_id !== $requesterId) {
            return new ServiceResult(false, "You can only retract your own authorization requests");
        }

        $table->getConnection()->begin();

        // Use ActiveWindowManager to stop
        $retractedReason = "Retracted by requester on " . DateTime::now()->format('Y-m-d H:i:s');
        $awResult = $this->activeWindowManager->stop(
            "Activities.Authorizations",
            $authorizationId,
            $requesterId,
            Authorization::RETRACTED_STATUS,
            $retractedReason,
            DateTime::now(),
        );

        if (!$awResult->success) {
            $table->getConnection()->rollback();
            return new ServiceResult(false, "Failed to retract authorization");
        }

        // Transition workflow to retracted state
        $instanceResult = $this->workflowEngine->getInstanceForEntity(
            'Activities.Authorizations',
            $authorizationId,
        );
        if ($instanceResult->success && $instanceResult->data) {
            $instance = $instanceResult->data;
            $instanceId = is_object($instance) ? $instance->id : $instance['id'];
            $this->workflowEngine->transition(
                $instanceId, 'retract', $requesterId,
                $this->buildWorkflowContext($authorization, 'retract', $requesterId),
            );
            // Reload authorization after status update so context has latest state
            $authorization = $table->get($authorizationId, contain: ['Activities']);
            $this->updateInstanceContext($instanceId, $authorization);
        } else {
            // Reload authorization even without workflow
            $authorization = $table->get($authorizationId);
        }

        // Notify approver of retraction (non-critical)
        $approvalsTable = TableRegistry::getTableLocator()->get("Activities.AuthorizationApprovals");
        $pendingApproval = $approvalsTable->find()
            ->where([
                'authorization_id' => $authorizationId,
                'responded_on IS' => null,
            ])
            ->first();

        if ($pendingApproval && $pendingApproval->approver_id) {
            $this->sendRetractedNotificationToApprover(
                $authorization->activity_id,
                $authorization->member_id,
                $pendingApproval->approver_id,
            );
        }

        $table->getConnection()->commit();
        return new ServiceResult(true, null, ['authorization' => $authorization]);
    }

    // region private helpers

    /**
     * Build workflow context from an authorization entity.
     * Provides entity data for condition evaluation, approval gates, and audit trail.
     */
    private function buildWorkflowContext($authorization, ?string $action = null, ?int $actorId = null): array
    {
        $context = [
            'entity' => [
                'id' => $authorization->id,
                'member_id' => $authorization->member_id,
                'activity_id' => $authorization->activity_id,
                'status' => $authorization->status,
                'is_renewal' => $authorization->is_renewal,
                'approval_count' => $authorization->approval_count ?? 0,
            ],
        ];

        // Include activity data if loaded
        if (isset($authorization->activity)) {
            $context['entity']['activity'] = [
                'name' => $authorization->activity->name,
                'num_required_authorizors' => $authorization->activity->num_required_authorizors ?? 1,
                'num_required_renewers' => $authorization->activity->num_required_renewers ?? 1,
                'term_length' => $authorization->activity->term_length ?? null,
                'grants_role_id' => $authorization->activity->grants_role_id ?? null,
            ];
        }

        if ($action) {
            $context['action'] = $action;
        }
        if ($actorId) {
            $context['actor_id'] = $actorId;
        }

        return $context;
    }

    /**
     * Update the workflow instance context with the current entity state.
     * Called after entity status changes so the instance reflects reality.
     */
    private function updateInstanceContext(int $instanceId, $authorization): void
    {
        try {
            $instancesTable = TableRegistry::getTableLocator()->get('WorkflowInstances');
            $instance = $instancesTable->get($instanceId);
            $existingContext = json_decode($instance->context ?? '{}', true) ?? [];
            $existingContext['entity'] = $this->buildWorkflowContext($authorization)['entity'];
            $instance->context = json_encode($existingContext);
            $instancesTable->save($instance);
        } catch (\Exception $e) {
            // Non-critical — don't fail the business operation
            Log::warning('WorkflowEngine: Failed to update instance context: ' . $e->getMessage());
        }
    }

    /**
     * Record an intermediate gate approval in the instance context.
     * Called when a gate approval is recorded but doesn't trigger a state transition.
     */
    private function appendGateApprovalToContext(
        int $instanceId,
        int $approverId,
        array $gateStatus,
        $authorization,
    ): void {
        try {
            $instancesTable = TableRegistry::getTableLocator()->get('WorkflowInstances');
            $instance = $instancesTable->get($instanceId);
            $existingContext = json_decode($instance->context ?? '{}', true) ?? [];
            $existingContext['entity'] = $this->buildWorkflowContext($authorization)['entity'];
            $existingContext['transitions'] = $existingContext['transitions'] ?? [];
            $existingContext['transitions'][] = [
                'type' => 'gate_approval',
                'state' => 'pending-approval',
                'by' => $approverId,
                'action' => 'approve',
                'approval_count' => $gateStatus['approved_count'] ?? null,
                'required_count' => $gateStatus['required'] ?? null,
                'at' => (new \DateTime())->format('Y-m-d H:i:s'),
            ];
            $instance->context = json_encode($existingContext);
            $instancesTable->save($instance);
        } catch (\Exception $e) {
            Log::warning('WorkflowEngine: Failed to append gate approval to context: ' . $e->getMessage());
        }
    }

    /**
     * Fallback approval processing when no workflow instance or gate exists.
     * Replicates DefaultAuthorizationManager logic for backward compatibility.
     */
    private function approveWithoutWorkflow(
        $authorization,
        $activity,
        int $approverId,
        ?int $nextApproverId,
        $approvalTable,
        $authTable,
        $transConnection,
    ): ServiceResult {
        $requiredApprovalCount = $authorization->is_renewal
            ? $activity->num_required_renewers
            : $activity->num_required_authorizors;

        if ($requiredApprovalCount > 1) {
            $acceptedApprovals = $approvalTable
                ->find()
                ->where([
                    "authorization_id" => $authorization->id,
                    "approved" => true,
                ])
                ->count();

            if ($acceptedApprovals < $requiredApprovalCount) {
                if (
                    !$this->processForwardToNextApprover(
                        $approverId,
                        $nextApproverId,
                        $authorization,
                        $approvalTable,
                        $authTable,
                    )
                ) {
                    $transConnection->rollback();
                    return new ServiceResult(false, "Failed to forward to next approver");
                }
                $transConnection->commit();
                return new ServiceResult(true);
            }
        }

        // Final approval
        $authorization->status = Authorization::APPROVED_STATUS;
        $authorization->approval_count = $authorization->approval_count + 1;
        if (!$authTable->save($authorization)) {
            $transConnection->rollback();
            return new ServiceResult(false, "Failed to process approved authorization");
        }

        $awResult = $this->activeWindowManager->start(
            "Activities.Authorizations",
            $authorization->id,
            $approverId,
            DateTime::now(),
            null,
            $activity->term_length,
            $activity->grants_role_id,
        );
        if (!$awResult->success) {
            $transConnection->rollback();
            return new ServiceResult(false, "Failed to process approved authorization");
        }

        if (
            !$this->sendAuthorizationStatusToRequester(
                $authorization->activity_id,
                $authorization->member_id,
                $approverId,
                $authorization->status,
                null,
            )
        ) {
            $transConnection->rollback();
            return new ServiceResult(false, "Failed to process approved authorization");
        }

        $transConnection->commit();
        return new ServiceResult(true);
    }

    /**
     * Forward authorization to the next approver in a multi-level chain.
     */
    private function processForwardToNextApprover(
        int $approverId,
        ?int $nextApproverId,
        $authorization,
        $approvalTable,
        $authTable,
    ): bool {
        if ($nextApproverId === null) {
            return false;
        }
        if (
            $approvalTable->Approvers
                ->find()
                ->where(["id" => $nextApproverId])
                ->count() == 0
        ) {
            return false;
        }

        $authorization->status = Authorization::PENDING_STATUS;
        $authorization->approval_count = $authorization->approval_count + 1;
        $authorization->setDirty("status", true);
        $authorization->setDirty("approval_count", true);
        if (!$authTable->save($authorization)) {
            return false;
        }

        $nextApproval = $approvalTable->newEmptyEntity();
        $nextApproval->authorization_id = $authorization->id;
        $nextApproval->approver_id = $nextApproverId;
        $nextApproval->requested_on = DateTime::now();
        $nextApproval->authorization_token = StaticHelpers::generateToken(32);
        if (!$approvalTable->save($nextApproval)) {
            return false;
        }

        if (
            !$this->sendApprovalRequestNotification(
                $authorization->activity_id,
                $authorization->member_id,
                $nextApproverId,
                $nextApproval->authorization_token,
            )
        ) {
            return false;
        }

        if (
            !$this->sendAuthorizationStatusToRequester(
                $authorization->activity_id,
                $authorization->member_id,
                $approverId,
                $authorization->status,
                $nextApproverId,
            )
        ) {
            return false;
        }

        return true;
    }

    // endregion

    // region notifications

    /**
     * Send approval request notification to the designated approver.
     */
    private function sendApprovalRequestNotification(
        int $activityId,
        int $requesterId,
        int $approverId,
        string $authorizationToken,
    ): bool {
        $authTypesTable = TableRegistry::getTableLocator()->get("Activities.Activities");
        $membersTable = TableRegistry::getTableLocator()->get("Members");

        $activity = $authTypesTable
            ->find()
            ->where(["id" => $activityId])
            ->select(["name"])
            ->all()
            ->first();
        $member = $membersTable
            ->find()
            ->where(["id" => $requesterId])
            ->select(["sca_name"])
            ->all()
            ->first();
        $approver = $membersTable
            ->find()
            ->where(["id" => $approverId])
            ->select(["sca_name", "email_address"])
            ->all()
            ->first();

        $this->getMailer("Activities.Activities")->send("notifyApprover", [
            $approver->email_address,
            $authorizationToken,
            $member->sca_name,
            $approver->sca_name,
            $activity->name,
        ]);

        return true;
    }

    /**
     * Send authorization status notification to the requester.
     */
    protected function sendAuthorizationStatusToRequester(
        int $activityId,
        int $requesterId,
        int $approverId,
        string $status,
        ?int $nextApproverId = null,
    ): bool {
        $authTypesTable = TableRegistry::getTableLocator()->get("Activities.Activities");
        $membersTable = TableRegistry::getTableLocator()->get("Members");

        $activity = $authTypesTable
            ->find()
            ->where(["id" => $activityId])
            ->select(["name"])
            ->all()
            ->first();
        $member = $membersTable
            ->find()
            ->where(["id" => $requesterId])
            ->select(["sca_name", "email_address"])
            ->all()
            ->first();
        $approver = $membersTable
            ->find()
            ->where(["id" => $approverId])
            ->select(["sca_name"])
            ->all()
            ->first();

        if ($nextApproverId) {
            $nextApprover = $membersTable
                ->find()
                ->where(["id" => $nextApproverId])
                ->select(["sca_name"])
                ->all()
                ->first();
            $nextApproverScaName = $nextApprover->sca_name;
        } else {
            $nextApproverScaName = '';
        }

        $this->getMailer("Activities.Activities")->send("notifyRequester", [
            $member->email_address,
            $status,
            $member->sca_name,
            $requesterId,
            $approver->sca_name,
            $nextApproverScaName,
            $activity->name,
        ]);

        return true;
    }

    /**
     * Send retraction notification to the approver.
     */
    private function sendRetractedNotificationToApprover(
        int $activityId,
        int $requesterId,
        int $approverId,
    ): bool {
        $activitiesTable = TableRegistry::getTableLocator()->get("Activities.Activities");
        $membersTable = TableRegistry::getTableLocator()->get("Members");

        $activity = $activitiesTable->get($activityId);
        $requester = $membersTable->get($requesterId);
        $approver = $membersTable->get($approverId);

        try {
            $this->getMailer("Activities.Activities")->send("notifyApproverOfRetraction", [
                $approver->email_address,
                $activity->name,
                $approver->sca_name,
                $requester->sca_name,
            ]);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    // endregion
}
