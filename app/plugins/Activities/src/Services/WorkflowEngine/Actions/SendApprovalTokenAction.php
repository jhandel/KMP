<?php

declare(strict_types=1);

namespace Activities\Services\WorkflowEngine\Actions;

use App\Services\WorkflowEngine\Actions\ActionInterface;
use App\Services\WorkflowEngine\ContextResolverTrait;
use App\Services\ServiceResult;
use App\KMP\StaticHelpers;
use Cake\Log\Log;
use Cake\ORM\TableRegistry;

/**
 * Generates a secure approval token and sends an approval-request email to the next approver.
 *
 * Used in the authorization approval chain to create an AuthorizationApproval record
 * with a token and notify the assigned approver.
 */
class SendApprovalTokenAction implements ActionInterface
{
    use ContextResolverTrait;

    /**
     * @inheritDoc
     */
    public function execute(array $params, array $context): ServiceResult
    {
        $entityId = $context['entity_id'] ?? null;
        $approverId = $this->resolveValue($params['approver_id'] ?? null, $context);

        if ($entityId === null || $approverId === null) {
            return new ServiceResult(false, 'Missing entity_id or approver_id for send_approval_token');
        }

        $authTable = TableRegistry::getTableLocator()->get('Activities.Authorizations');
        $authorization = $authTable->get($entityId, contain: ['Activities']);

        if (!$authorization) {
            return new ServiceResult(false, 'Authorization not found');
        }

        $approvalTable = TableRegistry::getTableLocator()->get('Activities.AuthorizationApprovals');
        $token = StaticHelpers::generateToken(32);

        $approval = $approvalTable->newEntity([
            'authorization_id' => $authorization->id,
            'approver_id' => (int)$approverId,
            'requested_on' => new \Cake\I18n\DateTime(),
            'authorization_token' => $token,
        ]);

        if (!$approvalTable->save($approval)) {
            return new ServiceResult(false, 'Failed to create authorization approval record');
        }

        // Send email notification to approver
        $membersTable = TableRegistry::getTableLocator()->get('Members');
        $activitiesTable = TableRegistry::getTableLocator()->get('Activities.Activities');

        $approver = $membersTable->find()
            ->where(['id' => (int)$approverId])
            ->select(['sca_name', 'email_address'])
            ->first();

        $requester = $membersTable->find()
            ->where(['id' => $authorization->member_id])
            ->select(['sca_name'])
            ->first();

        $activity = $activitiesTable->find()
            ->where(['id' => $authorization->activity_id])
            ->select(['name'])
            ->first();

        if ($approver && $requester && $activity) {
            try {
                $mailer = new \Cake\Mailer\Mailer('Activities.Activities');
                $mailer->send('notifyApprover', [
                    $approver->email_address,
                    $token,
                    $requester->sca_name,
                    $approver->sca_name,
                    $activity->name,
                ]);
            } catch (\Exception $e) {
                Log::warning("SendApprovalTokenAction: email failed - " . $e->getMessage());
            }
        }

        Log::info("SendApprovalTokenAction: token created for auth {$entityId}, approver {$approverId}");

        return new ServiceResult(true, null, [
            'approval_id' => $approval->id,
            'token' => $token,
            'approver_id' => (int)$approverId,
        ]);
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'send_approval_token';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Generates a secure approval token, creates an approval record, and notifies the approver by email';
    }

    /**
     * @inheritDoc
     */
    public function getParameterSchema(): array
    {
        return [
            'approver_id' => [
                'type' => 'string',
                'required' => true,
                'description' => 'Approver member ID or context path (e.g. "{{context.next_approver_id}}")',
            ],
        ];
    }
}
