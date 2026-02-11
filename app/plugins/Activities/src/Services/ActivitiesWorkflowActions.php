<?php

declare(strict_types=1);

namespace Activities\Services;

use Activities\Services\AuthorizationManagerInterface;
use App\KMP\StaticHelpers;
use App\Services\WorkflowEngine\WorkflowContextAwareTrait;
use Cake\Core\App;
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
     * @param array $context Current workflow context
     * @param array $config Config with authorizationApprovalId, approverId, denyReason
     * @return array Output with denied boolean
     */
    public function handleDenial(array $context, array $config): array
    {
        try {
            $authApprovalId = (int)$this->resolveValue($config['authorizationApprovalId'], $context);
            $approverId = $this->resolveValue($config['approverId'] ?? null, $context);
            if (!$approverId) {
                $approverId = $context['resumeData']['approverId'] ?? $context['triggeredBy'] ?? 0;
            }
            $approverId = (int)$approverId;

            $denyReason = $this->resolveValue($config['denyReason'] ?? '', $context);
            if (empty($denyReason)) {
                $denyReason = $context['resumeData']['comment'] ?? 'Denied via workflow';
            }

            $result = $this->authManager->deny($authApprovalId, $approverId, $denyReason);

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
