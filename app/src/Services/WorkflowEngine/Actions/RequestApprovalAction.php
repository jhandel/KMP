<?php

declare(strict_types=1);

namespace App\Services\WorkflowEngine\Actions;

use App\Services\ServiceResult;
use App\Services\WorkflowEngine\WorkflowEngineInterface;
use Cake\ORM\TableRegistry;

/**
 * Generates approval tokens and queues notification emails for all approvers
 * defined by the gate's approver_rule when a workflow enters an approval state.
 *
 * Config: {"gate_id": <int>, "notification_template": "template_name"}
 */
class RequestApprovalAction implements ActionInterface
{
    public function execute(array $config, array $context): ServiceResult
    {
        $gateId = $config['gate_id'] ?? null;
        if (!$gateId) {
            return new ServiceResult(false, 'RequestApprovalAction requires gate_id in config.');
        }

        $instanceId = $context['instance']['id'] ?? null;
        if (!$instanceId) {
            return new ServiceResult(false, 'No workflow instance in context.');
        }

        $gatesTable = TableRegistry::getTableLocator()->get('WorkflowApprovalGates');
        $gate = $gatesTable->get($gateId);
        $approverRule = $gate->decoded_approver_rule;

        // Resolve approver IDs from the rule
        $approverIds = $this->resolveApprovers($approverRule, $context);
        if (empty($approverIds)) {
            return new ServiceResult(false, 'No approvers resolved from gate rule.');
        }

        // Get the workflow engine from the DI container
        $engine = \Cake\Core\Container::getInstance()->get(WorkflowEngineInterface::class);

        $tokens = [];
        $isChain = $gate->approval_type === 'chain';

        foreach ($approverIds as $order => $approverId) {
            $approvalOrder = $isChain ? ($order + 1) : null;

            // For chain approvals, only generate token for first approver
            if ($isChain && $order > 0) {
                continue;
            }

            $result = $engine->generateApprovalToken($instanceId, $gateId, $approverId, $approvalOrder);
            if ($result->success) {
                $tokens[] = [
                    'approver_id' => $approverId,
                    'token' => $result->data['token'],
                    'order' => $approvalOrder,
                ];
            }
        }

        return new ServiceResult(true, null, [
            'tokens_generated' => count($tokens),
            'tokens' => $tokens,
            'total_approvers' => count($approverIds),
        ]);
    }

    /**
     * Resolve approver IDs from an approver_rule definition.
     */
    protected function resolveApprovers(array $rule, array $context): array
    {
        $type = $rule['type'] ?? 'role';

        return match ($type) {
            'role' => $this->resolveByRole($rule, $context),
            'permission' => $this->resolveByPermission($rule, $context),
            'member_ids' => $rule['ids'] ?? [],
            'entity_field' => $this->resolveByEntityField($rule, $context),
            default => [],
        };
    }

    protected function resolveByRole(array $rule, array $context): array
    {
        $roleName = $rule['role'] ?? null;
        if (!$roleName) {
            return [];
        }
        $membersTable = TableRegistry::getTableLocator()->get('Members');

        return $membersTable->find()
            ->innerJoinWith('Roles', fn($q) => $q->where(['Roles.name' => $roleName]))
            ->select(['Members.id'])
            ->all()
            ->extract('id')
            ->toArray();
    }

    protected function resolveByPermission(array $rule, array $context): array
    {
        $permission = $rule['permission'] ?? null;
        if (!$permission) {
            return [];
        }
        $membersTable = TableRegistry::getTableLocator()->get('Members');

        return $membersTable->find()
            ->innerJoinWith('Roles.Permissions', fn($q) => $q->where(['Permissions.name' => $permission]))
            ->select(['Members.id'])
            ->distinct()
            ->all()
            ->extract('id')
            ->toArray();
    }

    protected function resolveByEntityField(array $rule, array $context): array
    {
        $field = $rule['field'] ?? null;
        $entity = $context['entity'] ?? [];
        if (!$field || empty($entity[$field])) {
            return [];
        }
        $value = $entity[$field];

        return is_array($value) ? $value : [$value];
    }

    public function getName(): string
    {
        return 'request_approval';
    }

    public function getDescription(): string
    {
        return 'Generate approval tokens and queue notifications for approvers defined by the gate.';
    }

    public function getParameterSchema(): array
    {
        return [
            'gate_id' => ['type' => 'integer', 'required' => true, 'description' => 'Approval gate ID'],
            'notification_template' => ['type' => 'string', 'required' => false, 'description' => 'Email template name'],
        ];
    }
}
