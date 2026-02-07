<?php
declare(strict_types=1);

namespace App\Services\WorkflowEngine;

use App\Services\ServiceResult;
use Cake\Log\Log;
use Cake\ORM\TableRegistry;

/**
 * Manages approval gates for workflow states.
 *
 * Supports: threshold (N approvals), unanimous (all must approve),
 * any_one (single approval), and chain (sequential) approval types.
 */
class ApprovalGateService
{
    /**
     * Record an approval decision.
     *
     * @param int $instanceId Workflow instance ID
     * @param int $gateId Approval gate ID
     * @param int $approverId Member ID
     * @param string $decision 'approved', 'denied', 'abstained'
     * @param string|null $notes
     * @return ServiceResult Contains gate status after recording
     */
    public function recordApproval(int $instanceId, int $gateId, int $approverId, string $decision, ?string $notes = null, array $context = []): ServiceResult
    {
        $approvalsTable = TableRegistry::getTableLocator()->get('WorkflowApprovals');
        $gatesTable = TableRegistry::getTableLocator()->get('WorkflowApprovalGates');

        try {
            $gate = $gatesTable->get($gateId);
        } catch (\Exception $e) {
            return new ServiceResult(false, "Approval gate {$gateId} not found.");
        }

        // Check for duplicate approver
        $existing = $approvalsTable->find()
            ->where([
                'workflow_instance_id' => $instanceId,
                'approval_gate_id' => $gateId,
                'approver_id' => $approverId,
            ])
            ->first();

        if ($existing && $existing->decision !== null) {
            return new ServiceResult(false, "Member #{$approverId} has already provided a decision ('{$existing->decision}') for this gate.");
        }

        if ($existing) {
            $existing->decision = $decision;
            $existing->notes = $notes;
            $existing->responded_at = new \DateTime();
            $approvalsTable->save($existing);
            $approval = $existing;
        } else {
            $approval = $approvalsTable->newEntity([
                'workflow_instance_id' => $instanceId,
                'approval_gate_id' => $gateId,
                'approver_id' => $approverId,
                'decision' => $decision,
                'notes' => $notes,
                'requested_at' => new \DateTime(),
                'responded_at' => new \DateTime(),
            ]);
            $approvalsTable->save($approval);
        }

        $status = $this->getGateStatus($instanceId, $gateId, $context);

        $autoTransition = null;
        if ($status['satisfied'] && $gate->on_satisfied_transition_id) {
            $autoTransition = [
                'type' => 'satisfied',
                'transition_id' => $gate->on_satisfied_transition_id,
            ];
        } elseif ($status['denied'] && $gate->on_denied_transition_id) {
            $autoTransition = [
                'type' => 'denied',
                'transition_id' => $gate->on_denied_transition_id,
            ];
        }

        $resultData = [
            'approval' => $approval,
            'gate_status' => $status,
            'auto_transition' => $autoTransition,
        ];

        return new ServiceResult(true, null, $resultData);
    }

    /**
     * Get the current status of an approval gate.
     *
     * @return array{satisfied: bool, denied: bool, pending: bool, approved_count: int, denied_count: int, required: int}
     */
    public function getGateStatus(int $instanceId, int $gateId, array $context = []): array
    {
        $approvalsTable = TableRegistry::getTableLocator()->get('WorkflowApprovals');
        $gatesTable = TableRegistry::getTableLocator()->get('WorkflowApprovalGates');

        $gate = $gatesTable->get($gateId);
        $requiredCount = $this->resolveThreshold($gate, $context);

        $approvals = $approvalsTable->find()
            ->where([
                'workflow_instance_id' => $instanceId,
                'approval_gate_id' => $gateId,
            ])
            ->all()
            ->toArray();

        return $this->calculateGateStatus($approvals, $gate->approval_type, $requiredCount);
    }

    /**
     * Calculate gate status from approval records.
     *
     * Pure logic — no DB access. Used internally and by tests.
     */
    public function calculateGateStatus(array $approvals, string $approvalType, int $requiredCount): array
    {
        $getDecision = fn($a) => is_object($a) ? $a->decision : ($a['decision'] ?? null);
        $approvedCount = count(array_filter($approvals, fn($a) => $getDecision($a) === 'approved'));
        $deniedCount = count(array_filter($approvals, fn($a) => $getDecision($a) === 'denied'));
        $pendingCount = count(array_filter($approvals, fn($a) => $getDecision($a) === null));

        $satisfied = false;
        $denied = false;

        switch ($approvalType) {
            case 'threshold':
                $satisfied = $approvedCount >= $requiredCount;
                break;

            case 'unanimous':
                $satisfied = $approvedCount >= $requiredCount && $deniedCount === 0;
                $denied = $deniedCount > 0;
                break;

            case 'any_one':
                $satisfied = $approvedCount >= 1;
                $denied = $deniedCount > 0 && $approvedCount === 0;
                break;

            case 'chain':
                $satisfied = $approvedCount >= $requiredCount;
                $denied = $deniedCount > 0;
                break;
        }

        return [
            'satisfied' => $satisfied,
            'denied' => $denied,
            'pending' => !$satisfied && !$denied,
            'approved_count' => $approvedCount,
            'denied_count' => $deniedCount,
            'pending_count' => $pendingCount,
            'required' => $requiredCount,
            'approval_type' => $approvalType,
        ];
    }

    /**
     * Generate a secure approval token.
     */
    public function generateApprovalToken(int $instanceId, int $gateId, int $approverId, ?int $approvalOrder = null): ServiceResult
    {
        $approvalsTable = TableRegistry::getTableLocator()->get('WorkflowApprovals');

        $token = bin2hex(random_bytes(32));

        $approval = $approvalsTable->newEntity([
            'workflow_instance_id' => $instanceId,
            'approval_gate_id' => $gateId,
            'approver_id' => $approverId,
            'decision' => null,
            'token' => $token,
            'approval_order' => $approvalOrder,
            'requested_at' => new \DateTime(),
        ]);

        if (!$approvalsTable->save($approval)) {
            return new ServiceResult(false, "Failed to generate approval token.", $approval->getErrors());
        }

        return new ServiceResult(true, null, ['token' => $token, 'approval' => $approval]);
    }

    /**
     * Resolve an approval by its token.
     */
    public function resolveApprovalByToken(string $token, string $decision, ?string $notes = null): ServiceResult
    {
        $approvalsTable = TableRegistry::getTableLocator()->get('WorkflowApprovals');

        $approval = $approvalsTable->find()
            ->where(['token' => $token])
            ->first();

        if (!$approval) {
            return new ServiceResult(false, "Invalid approval token.");
        }

        if ($approval->decision !== null) {
            return new ServiceResult(false, "This approval has already been decided.");
        }

        // Check for token expiration based on gate timeout_hours
        if ($approval->requested_at) {
            $gatesTable = TableRegistry::getTableLocator()->get('WorkflowApprovalGates');
            try {
                $gate = $gatesTable->get($approval->approval_gate_id);
                if ($gate->timeout_hours) {
                    $expiresAt = (clone $approval->requested_at)->modify("+{$gate->timeout_hours} hours");
                    if (new \DateTime() > $expiresAt) {
                        return new ServiceResult(false, "Approval token has expired.");
                    }
                }
            } catch (\Exception $e) {
                Log::warning("Could not check token expiration for gate {$approval->approval_gate_id}: {$e->getMessage()}");
            }
        }

        $approval->decision = $decision;
        $approval->notes = $notes;
        $approval->responded_at = new \DateTime();

        if (!$approvalsTable->save($approval)) {
            return new ServiceResult(false, "Failed to save approval decision.");
        }

        $status = $this->getGateStatus($approval->workflow_instance_id, $approval->approval_gate_id);

        return new ServiceResult(true, null, [
            'approval' => $approval,
            'gate_status' => $status,
        ]);
    }

    /**
     * Delegate an approval to another user.
     */
    public function delegateApproval(int $approvalId, int $delegateId): ServiceResult
    {
        $approvalsTable = TableRegistry::getTableLocator()->get('WorkflowApprovals');
        $gatesTable = TableRegistry::getTableLocator()->get('WorkflowApprovalGates');

        try {
            $original = $approvalsTable->get($approvalId);
        } catch (\Exception $e) {
            return new ServiceResult(false, "Approval record not found.");
        }

        $gate = $gatesTable->get($original->approval_gate_id);
        if (!$gate->allow_delegation) {
            return new ServiceResult(false, "Delegation is not allowed for this approval gate.");
        }

        $token = bin2hex(random_bytes(32));
        $delegated = $approvalsTable->newEntity([
            'workflow_instance_id' => $original->workflow_instance_id,
            'approval_gate_id' => $original->approval_gate_id,
            'approver_id' => $delegateId,
            'decision' => null,
            'token' => $token,
            'delegated_from_id' => $approvalId,
            'requested_at' => new \DateTime(),
        ]);

        if (!$approvalsTable->save($delegated)) {
            return new ServiceResult(false, "Failed to create delegated approval.", $delegated->getErrors());
        }

        return new ServiceResult(true, null, ['token' => $token, 'approval' => $delegated]);
    }

    /**
     * Resolve the required approval count from gate configuration.
     * Supports: fixed value, app_setting lookup, entity field lookup.
     */
    protected function resolveThreshold(object $gate, array $context = []): int
    {
        $config = json_decode($gate->threshold_config ?? '{}', true);
        if (empty($config)) {
            return $gate->required_count;
        }

        $type = $config['type'] ?? 'fixed';
        $default = $config['default'] ?? $gate->required_count;

        switch ($type) {
            case 'fixed':
                return (int)($config['value'] ?? $default);

            case 'app_setting':
                try {
                    $key = $config['key'] ?? null;
                    if ($key) {
                        $value = \App\KMP\StaticHelpers::getAppSetting($key, (string)$default);
                        return (int)$value;
                    }
                } catch (\Exception $e) {
                    Log::warning("ApprovalGate: Failed to read app_setting: " . $e->getMessage());
                }
                return (int)$default;

            case 'entity_field':
                $field = $config['field'] ?? null;
                if ($field && isset($context['entity'])) {
                    $value = $this->resolveFieldFromEntity($context['entity'], $field);
                    if ($value !== null) {
                        return (int)$value;
                    }
                }
                return (int)$default;

            case 'conditional_entity_field':
                $condField = $config['condition_field'] ?? null;
                if ($condField && isset($context['entity'])) {
                    $condValue = $this->resolveFieldFromEntity($context['entity'], $condField);
                    $branch = $condValue ? ($config['when_true'] ?? []) : ($config['when_false'] ?? []);
                    $branchField = $branch['field'] ?? null;
                    if ($branchField) {
                        $value = $this->resolveFieldFromEntity($context['entity'], $branchField);
                        if ($value !== null) {
                            return (int)$value;
                        }
                    }
                }
                return (int)$default;

            default:
                return (int)$default;
        }
    }

    /**
     * Resolve a value from a nested entity array using dot-notation field path.
     */
    private function resolveFieldFromEntity(array $entity, string $fieldPath): mixed
    {
        $keys = explode('.', $fieldPath);
        $current = $entity;
        foreach ($keys as $key) {
            if (!is_array($current) || !array_key_exists($key, $current)) {
                return null;
            }
            $current = $current[$key];
        }
        return $current;
    }
}
