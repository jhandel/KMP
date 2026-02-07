<?php
declare(strict_types=1);

namespace Activities\Services\WorkflowEngine\Actions;

use App\Services\ServiceResult;
use App\Services\WorkflowEngine\Actions\ActionInterface;
use Cake\Log\Log;

/**
 * Generates and sends approval tokens to the next approver in the chain.
 */
class SendApprovalTokenAction implements ActionInterface
{
    public function execute(array $params, array $context): ServiceResult
    {
        $entityId = $context['entity_id'] ?? null;
        $approverId = $params['approver_id'] ?? null;
        $tokenType = $params['token_type'] ?? 'approval';

        // Stub: full integration in Phase 7
        Log::info("Activities: Sending '{$tokenType}' token for entity #{$entityId} to approver #{$approverId}");

        return new ServiceResult(true, null, [
            'token_sent' => true,
            'approver_id' => $approverId,
        ]);
    }

    public function getName(): string
    {
        return 'send_approval_token';
    }

    public function getDescription(): string
    {
        return 'Generates and sends approval tokens to the next approver';
    }

    public function getParameterSchema(): array
    {
        return [
            'approver_id' => [
                'type' => 'integer',
                'required' => true,
                'description' => 'ID of the member to receive the approval token',
            ],
            'token_type' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Type of approval token (default: approval)',
            ],
        ];
    }
}
