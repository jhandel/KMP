<?php
declare(strict_types=1);

namespace App\Model\Entity;

/**
 * Platform job record for tenant operations.
 */
class TenantOperationJob extends BaseEntity
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_APPROVAL_REQUIRED = 'approval_required';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_RUNNING = 'running';
    public const STATUS_HOLD = 'hold';
    public const STATUS_BLOCKED = 'blocked';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';
    public const TERMINAL_STATES = [
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
        self::STATUS_CANCELLED,
    ];

    protected array $_virtual = [
        'lifecycle_state',
    ];

    protected array $_accessible = [
        'tenant_id' => true,
        'platform_admin_id' => true,
        'parent_tenant_operation_job_id' => true,
        'operation' => true,
        'status' => true,
        'state' => true,
        'idempotency_scope' => true,
        'idempotency_key' => true,
        'approval_policy_json' => true,
        'approvals_required' => true,
        'approvals_received' => true,
        'approval_rejected_at' => true,
        'approval_rejection_reason' => true,
        'lease_owner' => true,
        'lease_token' => true,
        'lease_acquired_at' => true,
        'lease_expires_at' => true,
        'heartbeat_at' => true,
        'progress_percent' => true,
        'status_message' => true,
        'progress_json' => true,
        'input' => true,
        'result' => true,
        'result_json' => true,
        'error_json' => true,
        'error_message' => true,
        'operation_correlation_id' => true,
        'operation_image' => true,
        'operation_version' => true,
        'webauthn_assertion_id' => true,
        'started_at' => true,
        'completed_at' => true,
        'cancelled_at' => true,
    ];

    /**
     * Decode JSON payloads stored through atomic update queries.
     *
     * @param mixed $value Stored value
     * @return array<string, mixed>
     */
    protected function _getInput(mixed $value): array
    {
        return $this->decodeJsonPayload($value);
    }

    /**
     * @param mixed $value Stored value
     * @return array<string, mixed>
     */
    protected function _getResult(mixed $value): array
    {
        return $this->decodeJsonPayload($value);
    }

    /**
     * @param mixed $value Stored value
     * @return array<string, mixed>
     */
    protected function _getProgressJson(mixed $value): array
    {
        return $this->decodeJsonPayload($value);
    }

    /**
     * @param mixed $value Stored value
     * @return array<string, mixed>
     */
    protected function _getResultJson(mixed $value): array
    {
        return $this->decodeJsonPayload($value);
    }

    /**
     * @param mixed $value Stored value
     * @return array<string, mixed>|null
     */
    protected function _getErrorJson(mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->decodeJsonPayload($value);
    }

    /**
     * @param mixed $value Stored value
     * @return array<string, mixed>
     */
    protected function _getApprovalPolicyJson(mixed $value): array
    {
        return $this->decodeJsonPayload($value);
    }

    /**
     * Backward-compatible lifecycle state.
     */
    protected function _getLifecycleState(): string
    {
        return (string)($this->state ?? $this->status ?? self::STATUS_QUEUED);
    }

    /**
     * Return true for immutable terminal states.
     */
    public function isTerminalState(): bool
    {
        return in_array($this->lifecycle_state, self::TERMINAL_STATES, true);
    }

    /**
     * @param mixed $value Stored JSON value
     * @return array<string, mixed>
     */
    private function decodeJsonPayload(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || $value === '') {
            return [];
        }
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
}
