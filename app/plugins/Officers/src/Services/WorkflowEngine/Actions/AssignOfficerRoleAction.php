<?php

declare(strict_types=1);

namespace Officers\Services\WorkflowEngine\Actions;

use App\Services\ServiceResult;
use App\Services\WorkflowEngine\Actions\ActionInterface;
use App\Services\WorkflowEngine\ContextResolverTrait;
use Cake\Log\Log;

/**
 * Grants or revokes a member role tied to an officer assignment.
 *
 * Uses the office's grants_role_id to manage the member role lifecycle.
 */
class AssignOfficerRoleAction implements ActionInterface
{
    use ContextResolverTrait;

    /**
     * @inheritDoc
     */
    public function execute(array $params, array $context): ServiceResult
    {
        $operation = $params['operation'] ?? 'grant';
        $officerId = $this->resolveValue($params['officer_id'] ?? null, $context);
        $memberId = $this->resolveValue($params['member_id'] ?? null, $context);
        $roleId = $this->resolveValue($params['role_id'] ?? null, $context);
        $branchId = $this->resolveValue($params['branch_id'] ?? null, $context);

        if ($officerId === null || $memberId === null) {
            return new ServiceResult(false, 'officer_id and member_id are required for assign_officer_role action');
        }

        Log::info("WorkflowAction assign_officer_role: operation={$operation} officer={$officerId} member={$memberId} role={$roleId}");

        return new ServiceResult(true, null, [
            'action' => 'assign_officer_role',
            'operation' => $operation,
            'officer_id' => $officerId,
            'member_id' => $memberId,
            'role_id' => $roleId,
            'branch_id' => $branchId,
        ]);
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'assign_officer_role';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Grants or revokes a member role associated with an officer assignment';
    }

    /**
     * @inheritDoc
     */
    public function getParameterSchema(): array
    {
        return [
            'operation' => [
                'type' => 'string',
                'required' => true,
                'description' => 'Operation to perform: "grant" or "revoke"',
                'enum' => ['grant', 'revoke'],
            ],
            'officer_id' => [
                'type' => 'integer',
                'required' => true,
                'description' => 'Officer entity ID (supports {{template}})',
            ],
            'member_id' => [
                'type' => 'integer',
                'required' => true,
                'description' => 'Member ID for role assignment (supports {{template}})',
            ],
            'role_id' => [
                'type' => 'integer',
                'required' => false,
                'description' => 'Role ID to grant/revoke; uses office.grants_role_id if omitted (supports {{template}})',
            ],
            'branch_id' => [
                'type' => 'integer',
                'required' => false,
                'description' => 'Branch ID for scoping the role (supports {{template}})',
            ],
        ];
    }
}
