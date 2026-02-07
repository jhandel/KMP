<?php

declare(strict_types=1);

namespace App\Services\WorkflowEngine\Conditions;

/**
 * Checks whether an approval gate's threshold has been met or not met.
 */
class ApprovalGateCondition implements ConditionInterface
{
    public function evaluate(array $params, array $context): bool
    {
        $gateName = $params['approval_gate'] ?? null;
        $status = $params['status'] ?? 'met';

        if ($gateName === null) {
            return false;
        }

        $gates = $context['approval_gates'] ?? [];
        if (!isset($gates[$gateName])) {
            return false;
        }

        $isMet = (bool)($gates[$gateName]['is_met'] ?? false);

        return $status === 'met' ? $isMet : !$isMet;
    }

    public function getName(): string
    {
        return 'approval_gate';
    }

    public function getDescription(): string
    {
        return 'Checks if an approval gate threshold is met or not met';
    }

    public function getParameterSchema(): array
    {
        return [
            'approval_gate' => [
                'type' => 'string',
                'required' => true,
                'description' => 'The approval gate name to check',
            ],
            'status' => [
                'type' => 'string',
                'required' => true,
                'enum' => ['met', 'not_met'],
                'description' => 'Whether to check if the gate is met or not met',
            ],
        ];
    }
}
