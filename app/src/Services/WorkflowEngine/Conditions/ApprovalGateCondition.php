<?php

declare(strict_types=1);

namespace App\Services\WorkflowEngine\Conditions;

/**
 * Checks if an approval gate is satisfied.
 */
class ApprovalGateCondition implements ConditionInterface
{
    public function evaluate(array $params, array $context): bool
    {
        $mode = $params['approval_gate'] ?? null;
        if ($mode === null) {
            return false;
        }

        $gates = $context['approval_gates'] ?? [];
        $gateId = $params['gate_id'] ?? null;

        return match ($mode) {
            'satisfied' => $this->checkSatisfied($gates, $gateId),
            'pending' => $this->checkPending($gates, $gateId),
            'rejected' => $this->checkRejected($gates, $gateId),
            default => false,
        };
    }

    public function getName(): string
    {
        return 'approval_gate';
    }

    public function getDescription(): string
    {
        return 'Checks if an approval gate is satisfied';
    }

    public function getParameterSchema(): array
    {
        return [
            'approval_gate' => [
                'type' => 'string',
                'required' => true,
                'enum' => ['satisfied', 'pending', 'rejected'],
            ],
            'gate_id' => ['type' => 'integer', 'required' => false, 'description' => 'Specific gate ID to check'],
        ];
    }

    private function checkSatisfied(array $gates, ?int $gateId): bool
    {
        if ($gateId !== null) {
            return ($gates[$gateId]['status'] ?? null) === 'satisfied';
        }

        // All gates must be satisfied
        if (empty($gates)) {
            return false;
        }

        foreach ($gates as $gate) {
            if (($gate['status'] ?? null) !== 'satisfied') {
                return false;
            }
        }

        return true;
    }

    private function checkPending(array $gates, ?int $gateId): bool
    {
        if ($gateId !== null) {
            return ($gates[$gateId]['status'] ?? null) === 'pending';
        }

        foreach ($gates as $gate) {
            if (($gate['status'] ?? null) === 'pending') {
                return true;
            }
        }

        return false;
    }

    private function checkRejected(array $gates, ?int $gateId): bool
    {
        if ($gateId !== null) {
            return ($gates[$gateId]['status'] ?? null) === 'rejected';
        }

        foreach ($gates as $gate) {
            if (($gate['status'] ?? null) === 'rejected') {
                return true;
            }
        }

        return false;
    }
}
