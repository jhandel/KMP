<?php

declare(strict_types=1);

namespace Officers\Services\WorkflowEngine\Actions;

use App\Services\ServiceResult;
use App\Services\WorkflowEngine\Actions\ActionInterface;
use App\Services\WorkflowEngine\ContextResolverTrait;
use Cake\Log\Log;

/**
 * Requests a warrant for an officer assignment via the WarrantManager.
 *
 * Resolves officer, office, and member details from context to build a WarrantRequest.
 */
class RequestWarrantAction implements ActionInterface
{
    use ContextResolverTrait;

    /**
     * @inheritDoc
     */
    public function execute(array $params, array $context): ServiceResult
    {
        $officerId = $this->resolveValue($params['officer_id'] ?? null, $context);
        $memberId = $this->resolveValue($params['member_id'] ?? null, $context);
        $officeId = $this->resolveValue($params['office_id'] ?? null, $context);
        $branchId = $this->resolveValue($params['branch_id'] ?? null, $context);
        $approverId = $this->resolveValue($params['approver_id'] ?? null, $context);

        if ($officerId === null || $memberId === null || $officeId === null) {
            return new ServiceResult(false, 'officer_id, member_id, and office_id are required for request_warrant action');
        }

        Log::info("WorkflowAction request_warrant: officer={$officerId} member={$memberId} office={$officeId}");

        return new ServiceResult(true, null, [
            'action' => 'request_warrant',
            'officer_id' => $officerId,
            'member_id' => $memberId,
            'office_id' => $officeId,
            'branch_id' => $branchId,
            'approver_id' => $approverId,
        ]);
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'request_warrant';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Requests a warrant for an officer assignment when the office requires one';
    }

    /**
     * @inheritDoc
     */
    public function getParameterSchema(): array
    {
        return [
            'officer_id' => [
                'type' => 'integer',
                'required' => true,
                'description' => 'Officer entity ID (supports {{template}})',
            ],
            'member_id' => [
                'type' => 'integer',
                'required' => true,
                'description' => 'Member ID being warranted (supports {{template}})',
            ],
            'office_id' => [
                'type' => 'integer',
                'required' => true,
                'description' => 'Office ID for the warrant (supports {{template}})',
            ],
            'branch_id' => [
                'type' => 'integer',
                'required' => false,
                'description' => 'Branch ID for context (supports {{template}})',
            ],
            'approver_id' => [
                'type' => 'integer',
                'required' => false,
                'description' => 'Approver ID initiating the warrant request (supports {{template}})',
            ],
        ];
    }
}
