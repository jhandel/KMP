<?php

declare(strict_types=1);

namespace Activities\Services;

use Activities\Services\AuthorizationManagerInterface;
use App\KMP\StaticHelpers;
use App\Services\WorkflowEngine\WorkflowContextAwareTrait;
use Cake\Core\App;
use Cake\I18n\DateTime;
use Cake\Log\Log;
use Cake\ORM\TableRegistry;

/**
 * Workflow action implementations for activity authorization operations.
 *
 * Delegates authorization lifecycle operations to AuthorizationManagerInterface
 * to avoid duplicating business logic.
 */
class ActivitiesWorkflowActions
{
    use WorkflowContextAwareTrait;

    private AuthorizationManagerInterface $authManager;

    public function __construct(AuthorizationManagerInterface $authManager)
    {
        $this->authManager = $authManager;
    }

    /**
     * Create authorization request with first approval record.
     *
     * @param array $context Current workflow context
     * @param array $config Config with memberId, activityId, approverId, isRenewal
     * @return array Output with authorizationId, authorizationApprovalId
     */
    public function createAuthorizationRequest(array $context, array $config): array
    {
        try {
            $memberId = (int)$this->resolveValue($config['memberId'], $context);
            $activityId = (int)$this->resolveValue($config['activityId'], $context);
            $approverId = (int)$this->resolveValue($config['approverId'], $context);
            $isRenewal = (bool)$this->resolveValue($config['isRenewal'] ?? false, $context);

            $result = $this->authManager->request($memberId, $activityId, $approverId, $isRenewal);

            if (!$result->success) {
                Log::warning('Workflow CreateAuthorizationRequest: ' . $result->reason);
                return ['authorizationId' => null, 'authorizationApprovalId' => null];
            }

            // Fetch the created authorization and its first approval
            $authTable = TableRegistry::getTableLocator()->get('Activities.Authorizations');
            $auth = $authTable->find()
                ->where([
                    'member_id' => $memberId,
                    'activity_id' => $activityId,
                    'status' => 'Pending',
                ])
                ->orderBy(['Authorizations.id' => 'DESC'])
                ->first();

            $approvalId = null;
            if ($auth) {
                $approvalsTable = TableRegistry::getTableLocator()->get('Activities.AuthorizationApprovals');
                $approval = $approvalsTable->find()
                    ->where(['authorization_id' => $auth->id])
                    ->orderBy(['id' => 'DESC'])
                    ->first();
                $approvalId = $approval ? $approval->id : null;
            }

            return [
                'authorizationId' => $auth ? $auth->id : null,
                'authorizationApprovalId' => $approvalId,
            ];
        } catch (\Throwable $e) {
            Log::error('Workflow CreateAuthorizationRequest failed: ' . $e->getMessage());
            return ['authorizationId' => null, 'authorizationApprovalId' => null];
        }
    }

    /**
     * Activate a fully-approved authorization (set status, start ActiveWindow, assign role).
     *
     * @param array $context Current workflow context
     * @param array $config Config with authorizationId, approverId
     * @return array Output with activated boolean and memberRoleId
     */
    public function activateAuthorization(array $context, array $config): array
    {
        try {
            $authorizationId = (int)$this->resolveValue($config['authorizationId'], $context);
            $approverId = $this->resolveValue($config['approverId'] ?? null, $context);
            if (!$approverId) {
                $approverId = $context['resumeData']['approverId'] ?? $context['triggeredBy'] ?? 0;
            }
            $approverId = (int)$approverId;

            $result = $this->authManager->activate($authorizationId, $approverId);

            if (!$result->success) {
                Log::warning('Workflow ActivateAuthorization: ' . $result->reason);
                return ['activated' => false, 'memberRoleId' => null];
            }

            // Sync workflow approval back to activities_authorization_approvals
            $approvalsTable = TableRegistry::getTableLocator()->get('Activities.AuthorizationApprovals');
            $pendingApproval = $approvalsTable->find()
                ->where([
                    'authorization_id' => $authorizationId,
                    'responded_on IS' => null,
                ])
                ->first();
            if ($pendingApproval) {
                $pendingApproval->responded_on = DateTime::now();
                $pendingApproval->approved = true;
                $pendingApproval->approver_id = $approverId;
                $approvalsTable->save($pendingApproval);
            }

            $data = $result->data ?? [];

            return [
                'activated' => true,
                'memberRoleId' => $data['memberRoleId'] ?? null,
            ];
        } catch (\Throwable $e) {
            Log::error('Workflow ActivateAuthorization failed: ' . $e->getMessage());
            return ['activated' => false, 'memberRoleId' => null];
        }
    }

    /**
     * Process denial of an authorization request.
     *
     * Looks up the pending approval record by authorizationId since the
     * workflow definition passes authorizationId, not authorizationApprovalId.
     *
     * @param array $context Current workflow context
     * @param array $config Config with authorizationId, approverId, denyReason
     * @return array Output with denied boolean
     */
    public function handleDenial(array $context, array $config): array
    {
        try {
            $authorizationId = (int)$this->resolveValue($config['authorizationId'], $context);
            $approverId = $this->resolveValue($config['approverId'] ?? null, $context);
            if (!$approverId) {
                $approverId = $context['resumeData']['approverId'] ?? $context['triggeredBy'] ?? 0;
            }
            $approverId = (int)$approverId;

            $denyReason = $this->resolveValue($config['denyReason'] ?? '', $context);
            if (empty($denyReason)) {
                $denyReason = $context['resumeData']['comment'] ?? 'Denied via workflow';
            }

            // Find the pending approval record by authorization_id
            $approvalsTable = TableRegistry::getTableLocator()->get('Activities.AuthorizationApprovals');
            $pendingApproval = $approvalsTable->find()
                ->where([
                    'authorization_id' => $authorizationId,
                    'responded_on IS' => null,
                ])
                ->first();

            if (!$pendingApproval) {
                Log::warning("Workflow HandleDenial: no pending approval for authorization {$authorizationId}");
                return ['denied' => false];
            }

            $result = $this->authManager->deny($pendingApproval->id, $approverId, $denyReason);

            return ['denied' => $result->success];
        } catch (\Throwable $e) {
            Log::error('Workflow HandleDenial failed: ' . $e->getMessage());
            return ['denied' => false];
        }
    }

    /**
     * Send approval request email to designated approver.
     *
     * @param array $context Current workflow context
     * @param array $config Config with activityId, requesterId, approverId, authorizationToken
     * @return array Output with sent boolean
     */
    public function notifyApprover(array $context, array $config): array
    {
        try {
            $activityId = (int)$this->resolveValue($config['activityId'], $context);
            $requesterId = (int)$this->resolveValue($config['requesterId'], $context);
            $approverId = (int)$this->resolveValue($config['approverId'], $context);
            $authorizationToken = $this->resolveValue($config['authorizationToken'], $context);

            $activitiesTable = TableRegistry::getTableLocator()->get('Activities.Activities');
            $membersTable = TableRegistry::getTableLocator()->get('Members');

            $activity = $activitiesTable->find()
                ->where(['id' => $activityId])
                ->select(['name'])
                ->first();
            $member = $membersTable->find()
                ->where(['id' => $requesterId])
                ->select(['sca_name'])
                ->first();
            $approver = $membersTable->find()
                ->where(['id' => $approverId])
                ->select(['sca_name', 'email_address'])
                ->first();

            if (!$activity || !$member || !$approver || empty($approver->email_address)) {
                Log::warning('Workflow NotifyApprover: missing data for notification');
                return ['sent' => false];
            }

            $mailerClass = App::className('Activities.Activities', 'Mailer', 'Mailer');
            $useQueue = (StaticHelpers::getAppSetting('Email.UseQueue', 'no', null, true) === 'yes');

            $data = [
                'class' => $mailerClass,
                'action' => 'notifyApprover',
                'vars' => [
                    'to' => $approver->email_address,
                    'authorizationToken' => $authorizationToken,
                    'memberScaName' => $member->sca_name,
                    'approverScaName' => $approver->sca_name,
                    'activityName' => $activity->name,
                ],
            ];

            if ($useQueue) {
                $queuedJobsTable = TableRegistry::getTableLocator()->get('Queue.QueuedJobs');
                $queuedJobsTable->createJob('Queue.Mailer', $data);
            } else {
                $mailer = new $mailerClass();
                $mailer->send('notifyApprover', [
                    $approver->email_address,
                    $authorizationToken,
                    $member->sca_name,
                    $approver->sca_name,
                    $activity->name,
                ]);
            }

            return ['sent' => true];
        } catch (\Throwable $e) {
            Log::error('Workflow NotifyApprover failed: ' . $e->getMessage());
            return ['sent' => false];
        }
    }

    /**
     * Revoke an active authorization.
     *
     * @param array $context Current workflow context
     * @param array $config Config with authorizationId, revokerId, revokedReason
     * @return array Output with revoked boolean
     */
    public function revokeAuthorization(array $context, array $config): array
    {
        try {
            $authorizationId = (int)$this->resolveValue($config['authorizationId'], $context);
            $revokerId = (int)$this->resolveValue($config['revokerId'], $context);
            $revokedReason = (string)$this->resolveValue($config['revokedReason'] ?? 'Revoked via workflow', $context);

            $result = $this->authManager->revoke($authorizationId, $revokerId, $revokedReason);

            return ['revoked' => $result->success];
        } catch (\Throwable $e) {
            Log::error('Workflow RevokeAuthorization failed: ' . $e->getMessage());
            return ['revoked' => false];
        }
    }

    /**
     * Retract (cancel) a pending authorization request.
     *
     * @param array $context Current workflow context
     * @param array $config Config with authorizationId, requesterId
     * @return array Output with retracted boolean
     */
    public function retractAuthorization(array $context, array $config): array
    {
        try {
            $authorizationId = (int)$this->resolveValue($config['authorizationId'], $context);
            $requesterId = (int)$this->resolveValue($config['requesterId'], $context);

            $result = $this->authManager->retract($authorizationId, $requesterId);

            return ['retracted' => $result->success];
        } catch (\Throwable $e) {
            Log::error('Workflow RetractAuthorization failed: ' . $e->getMessage());
            return ['retracted' => false];
        }
    }

    /**
     * Check if a member is eligible to renew an authorization for an activity.
     *
     * @param array $context Current workflow context
     * @param array $config Config with memberId, activityId
     * @return array Output with eligible boolean and reason string
     */
    public function validateRenewalEligibility(array $context, array $config): array
    {
        try {
            $memberId = (int)$this->resolveValue($config['memberId'], $context);
            $activityId = (int)$this->resolveValue($config['activityId'], $context);

            $authTable = TableRegistry::getTableLocator()->get('Activities.Authorizations');

            // Check for existing approved, non-expired authorization
            $existingAuth = $authTable->find()
                ->where([
                    'member_id' => $memberId,
                    'activity_id' => $activityId,
                    'status' => 'Approved',
                    'expires_on >' => DateTime::now(),
                ])
                ->first();

            if (!$existingAuth) {
                return ['eligible' => false, 'reason' => 'No active authorization exists to renew'];
            }

            // Check for existing pending request
            $pendingCount = $authTable->find()
                ->where([
                    'member_id' => $memberId,
                    'activity_id' => $activityId,
                    'status' => 'Pending',
                ])
                ->count();

            if ($pendingCount > 0) {
                return ['eligible' => false, 'reason' => 'A pending request already exists for this activity'];
            }

            return ['eligible' => true, 'reason' => 'Member is eligible for renewal'];
        } catch (\Throwable $e) {
            Log::error('Workflow ValidateRenewalEligibility failed: ' . $e->getMessage());
            return ['eligible' => false, 'reason' => 'Error checking renewal eligibility'];
        }
    }

    /**
     * Resolve eligible approvers for an activity in a branch.
     *
     * @param array $context Current workflow context
     * @param array $config Config with activityId, branchId, excludeMemberIds (optional)
     * @return array Output with approvers array
     */
    public function resolveApprovers(array $context, array $config): array
    {
        try {
            $activityId = (int)$this->resolveValue($config['activityId'], $context);
            $branchId = (int)$this->resolveValue($config['branchId'], $context);
            $excludeMemberIds = $this->resolveValue($config['excludeMemberIds'] ?? [], $context);
            if (!is_array($excludeMemberIds)) {
                $excludeMemberIds = [];
            }

            $activitiesTable = TableRegistry::getTableLocator()->get('Activities.Activities');
            $activity = $activitiesTable->get($activityId);

            $query = $activity->getApproversQuery($branchId);

            if (!empty($excludeMemberIds)) {
                $query->where(['Members.id NOT IN' => $excludeMemberIds]);
            }

            $query->select(['Members.id', 'Members.sca_name']);

            $approvers = [];
            foreach ($query->all() as $member) {
                $approvers[] = [
                    'id' => $member->id,
                    'sca_name' => $member->sca_name,
                ];
            }

            return ['approvers' => $approvers];
        } catch (\Throwable $e) {
            Log::error('Workflow ResolveApprovers failed: ' . $e->getMessage());
            return ['approvers' => []];
        }
    }

    /**
     * Send status update email to requesting member.
     *
     * @param array $context Current workflow context
     * @param array $config Config with activityId, requesterId, approverId, status, nextApproverId
     * @return array Output with sent boolean
     */
    public function notifyRequester(array $context, array $config): array
    {
        try {
            $activityId = (int)$this->resolveValue($config['activityId'], $context);
            $requesterId = (int)$this->resolveValue($config['requesterId'], $context);
            $approverId = (int)$this->resolveValue($config['approverId'], $context);
            $status = (string)$this->resolveValue($config['status'], $context);
            $nextApproverId = $this->resolveValue($config['nextApproverId'] ?? null, $context);

            $activitiesTable = TableRegistry::getTableLocator()->get('Activities.Activities');
            $membersTable = TableRegistry::getTableLocator()->get('Members');

            $activity = $activitiesTable->find()
                ->where(['id' => $activityId])
                ->select(['name'])
                ->first();
            $member = $membersTable->find()
                ->where(['id' => $requesterId])
                ->select(['sca_name', 'email_address'])
                ->first();
            $approver = $membersTable->find()
                ->where(['id' => $approverId])
                ->select(['sca_name'])
                ->first();

            if (!$activity || !$member || !$approver || empty($member->email_address)) {
                Log::warning('Workflow NotifyRequester: missing data for notification');
                return ['sent' => false];
            }

            $nextApproverScaName = '';
            if ($nextApproverId) {
                $nextApprover = $membersTable->find()
                    ->where(['id' => (int)$nextApproverId])
                    ->select(['sca_name'])
                    ->first();
                $nextApproverScaName = $nextApprover ? $nextApprover->sca_name : '';
            }

            $mailerClass = App::className('Activities.Activities', 'Mailer', 'Mailer');
            $useQueue = (StaticHelpers::getAppSetting('Email.UseQueue', 'no', null, true) === 'yes');

            $data = [
                'class' => $mailerClass,
                'action' => 'notifyRequester',
                'vars' => [
                    'to' => $member->email_address,
                    'status' => $status,
                    'memberScaName' => $member->sca_name,
                    'requesterId' => $requesterId,
                    'approverScaName' => $approver->sca_name,
                    'nextApproverScaName' => $nextApproverScaName,
                    'activityName' => $activity->name,
                ],
            ];

            if ($useQueue) {
                $queuedJobsTable = TableRegistry::getTableLocator()->get('Queue.QueuedJobs');
                $queuedJobsTable->createJob('Queue.Mailer', $data);
            } else {
                $mailer = new $mailerClass();
                $mailer->send('notifyRequester', [
                    $member->email_address,
                    $status,
                    $member->sca_name,
                    $requesterId,
                    $approver->sca_name,
                    $nextApproverScaName,
                    $activity->name,
                ]);
            }

            return ['sent' => true];
        } catch (\Throwable $e) {
            Log::error('Workflow NotifyRequester failed: ' . $e->getMessage());
            return ['sent' => false];
        }
    }
}
