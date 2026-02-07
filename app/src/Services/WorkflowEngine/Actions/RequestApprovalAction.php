<?php
declare(strict_types=1);

namespace App\Services\WorkflowEngine\Actions;

use App\Services\ServiceResult;
use Cake\Log\Log;

/**
 * Creates approval records and generates tokens for email-based approval.
 *
 * Stub implementation that logs intent. Full implementation requires
 * the ApprovalGateService from Phase 4.
 */
class RequestApprovalAction implements ActionInterface
{
    public function execute(array $params, array $context): ServiceResult
    {
        $gateId = $params['gate_id'] ?? null;
        $notify = $params['notify'] ?? true;

        if ($gateId === null) {
            return new ServiceResult(false, "request_approval: 'gate_id' parameter is required");
        }

        Log::info("WorkflowEngine: request_approval gate_id={$gateId} notify=" . ($notify ? 'true' : 'false') . ' (stub)');

        return new ServiceResult(true, 'Approval requested (stub)', [
            'gate_id' => $gateId,
            'notify' => $notify,
        ]);
    }

    public function getName(): string
    {
        return 'request_approval';
    }

    public function getDescription(): string
    {
        return 'Creates approval records and generates tokens for email-based approval';
    }

    public function getParameterSchema(): array
    {
        return [
            'gate_id' => ['type' => 'integer', 'required' => true, 'description' => 'Approval gate ID'],
            'notify' => ['type' => 'boolean', 'required' => false, 'description' => 'Send notification (default: true)'],
        ];
    }
}
