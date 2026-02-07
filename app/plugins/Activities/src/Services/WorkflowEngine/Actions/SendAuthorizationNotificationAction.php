<?php
declare(strict_types=1);

namespace Activities\Services\WorkflowEngine\Actions;

use App\Services\ServiceResult;
use App\Services\WorkflowEngine\Actions\ActionInterface;
use Cake\Log\Log;
use Cake\Mailer\MailerAwareTrait;
use Cake\ORM\TableRegistry;

/**
 * Sends notification emails based on authorization state changes.
 *
 * Supports notification types: approval_request, approved, denied, revoked.
 * Uses the Activities.Activities mailer with notifyRequester and notifyApprover templates.
 */
class SendAuthorizationNotificationAction implements ActionInterface
{
    use MailerAwareTrait;

    public function execute(array $params, array $context): ServiceResult
    {
        $notificationType = $params['notification_type'] ?? null;
        $entityId = $context['entity_id'] ?? null;
        $triggeredBy = $context['triggered_by'] ?? null;

        if ($notificationType === null) {
            return new ServiceResult(false, "send_authorization_notification: 'notification_type' parameter is required");
        }

        if ($entityId === null) {
            return new ServiceResult(false, "send_authorization_notification: 'entity_id' is required in context");
        }

        try {
            $authTable = TableRegistry::getTableLocator()->get('Activities.Authorizations');
            $authorization = $authTable->get($entityId, contain: ['Activities']);

            $membersTable = TableRegistry::getTableLocator()->get('Members');
            $member = $membersTable->find()
                ->where(['id' => $authorization->member_id])
                ->select(['id', 'sca_name', 'email_address'])
                ->first();

            $approverScaName = '';
            if ($triggeredBy) {
                $approver = $membersTable->find()
                    ->where(['id' => $triggeredBy])
                    ->select(['sca_name', 'email_address'])
                    ->first();
                $approverScaName = $approver ? $approver->sca_name : '';
            }

            switch ($notificationType) {
                case 'approved':
                case 'denied':
                case 'revoked':
                    $statusMap = [
                        'approved' => 'Approved',
                        'denied' => 'Denied',
                        'revoked' => 'Revoked',
                    ];
                    $this->getMailer('Activities.Activities')->send('notifyRequester', [
                        $member->email_address,
                        $statusMap[$notificationType],
                        $member->sca_name,
                        $authorization->member_id,
                        $approverScaName,
                        '', // nextApproverScaName
                        $authorization->activity->name,
                    ]);
                    break;

                case 'approval_request':
                    if ($triggeredBy && isset($approver)) {
                        $token = $params['token'] ?? '';
                        $this->getMailer('Activities.Activities')->send('notifyApprover', [
                            $approver->email_address,
                            $token,
                            $member->sca_name,
                            $approver->sca_name,
                            $authorization->activity->name,
                        ]);
                    }
                    break;

                default:
                    return new ServiceResult(false, "Unknown notification type: {$notificationType}");
            }

            Log::info("Activities: Sent '{$notificationType}' notification for authorization #{$entityId}");

            return new ServiceResult(true, 'Notification sent', [
                'notification_type' => $notificationType,
                'authorization_id' => $entityId,
            ]);
        } catch (\Exception $e) {
            Log::error("Activities: Failed to send notification for authorization #{$entityId}: " . $e->getMessage());

            return new ServiceResult(false, 'Failed to send notification: ' . $e->getMessage());
        }
    }

    public function getName(): string
    {
        return 'send_authorization_notification';
    }

    public function getDescription(): string
    {
        return 'Send notification emails based on authorization state changes';
    }

    public function getParameterSchema(): array
    {
        return [
            'notification_type' => [
                'type' => 'string',
                'required' => true,
                'description' => 'Type of notification: approval_request, approved, denied, revoked',
            ],
            'token' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Approval token (for approval_request type)',
            ],
        ];
    }
}
