<?php
declare(strict_types=1);

namespace App\Services\Platform;

use App\Model\Entity\PlatformAdmin;
use App\Model\Entity\Tenant;
use RuntimeException;

/**
 * Canonical catalog for gateway-approved tenant operations.
 */
class TenantOperationCommandCatalog
{
    public const APPROVAL_MODE_NONE = 'none';
    public const APPROVAL_MODE_N_OF_M = 'n_of_m';
    public const APPROVAL_MODE_TWO_PERSON = 'two_person';

    /**
     * @var array<string, array<string, mixed>>
     */
    private const OPERATIONS = [
        'tenant_status' => [
            'name' => 'Set tenant lifecycle status',
            'target_scope' => 'tenant',
            'gateway_enabled' => true,
            'worker_enabled' => true,
            'allowed_target_modes' => ['single', 'selected', 'all-tenant'],
            'required_parameters' => ['status'],
            'optional_parameters' => [],
            'approval_required' => true,
            'approval_policy' => [
                'mode' => self::APPROVAL_MODE_N_OF_M,
                'required_approvals' => 1,
                'require_distinct_approvers' => true,
                'require_requester_separation' => false,
            ],
            'idempotency_scope' => 'tenant',
            'required_capability' => PlatformAdmin::CAPABILITY_OPERATE_TENANTS,
            'allowed_values' => [
                'status' => [
                    Tenant::STATUS_ACTIVE,
                    Tenant::STATUS_DISABLED,
                    Tenant::STATUS_MAINTENANCE,
                    Tenant::STATUS_FAILED,
                    Tenant::STATUS_PROVISIONING,
                    Tenant::STATUS_DRAINING,
                ],
            ],
            'preflight_hints' => [
                'Status must be one of the allowed tenant lifecycle states.',
            ],
        ],
        'tenant_migrate' => [
            'name' => 'Run tenant migrations',
            'target_scope' => 'tenant',
            'gateway_enabled' => true,
            'worker_enabled' => true,
            'allowed_target_modes' => ['single', 'selected', 'all-tenant'],
            'required_parameters' => [],
            'optional_parameters' => ['plugin'],
            'approval_required' => true,
            'approval_policy' => [
                'mode' => self::APPROVAL_MODE_TWO_PERSON,
                'required_approvals' => 2,
                'require_distinct_approvers' => true,
                'require_requester_separation' => true,
            ],
            'idempotency_scope' => 'tenant',
            'required_capability' => PlatformAdmin::CAPABILITY_PROVISION_TENANTS,
            'allowed_values' => [],
            'preflight_hints' => [
                'Optional "plugin" should target one plugin namespace when provided.',
            ],
        ],
        'tenant_doctor' => [
            'name' => 'Run tenant doctor checks',
            'target_scope' => 'tenant',
            'gateway_enabled' => true,
            'worker_enabled' => true,
            'allowed_target_modes' => ['single', 'selected', 'all-tenant'],
            'required_parameters' => [],
            'optional_parameters' => [],
            'approval_required' => false,
            'approval_policy' => [
                'mode' => self::APPROVAL_MODE_NONE,
                'required_approvals' => 0,
                'require_distinct_approvers' => true,
                'require_requester_separation' => false,
            ],
            'idempotency_scope' => 'tenant',
            'required_capability' => PlatformAdmin::CAPABILITY_OPERATE_TENANTS,
            'allowed_values' => [],
            'preflight_hints' => [
                'Doctor checks can run without approval but still require operator capability.',
            ],
        ],
        'tenant_rotate_db_secret' => [
            'name' => 'Rotate tenant database credential',
            'target_scope' => 'tenant',
            'gateway_enabled' => true,
            'worker_enabled' => true,
            'allowed_target_modes' => ['single'],
            'required_parameters' => ['new_secret_reference'],
            'optional_parameters' => ['max_attempts'],
            'approval_required' => true,
            'approval_policy' => [
                'mode' => self::APPROVAL_MODE_TWO_PERSON,
                'required_approvals' => 2,
                'require_distinct_approvers' => true,
                'require_requester_separation' => true,
            ],
            'idempotency_scope' => 'tenant',
            'required_capability' => PlatformAdmin::CAPABILITY_MANAGE_SECRETS,
            'allowed_values' => [],
            'preflight_hints' => [
                '"new_secret_reference" must begin with managed: or env:.',
                '"max_attempts" must be between 1 and 10 when provided.',
            ],
        ],
    ];

    /**
     * @return array<int, string>
     */
    public static function allowedGatewayOperations(): array
    {
        $operations = [];
        foreach (self::OPERATIONS as $operation => $config) {
            if (($config['gateway_enabled'] ?? false) === true) {
                $operations[] = $operation;
            }
        }
        sort($operations);

        return $operations;
    }

    /**
     * @return array<int, string>
     */
    public static function allowedWorkerOperations(): array
    {
        $operations = [];
        foreach (self::OPERATIONS as $operation => $config) {
            if (($config['worker_enabled'] ?? false) === true) {
                $operations[] = $operation;
            }
        }
        sort($operations);

        return $operations;
    }

    /**
     * @param string $operation Operation identifier
     * @return bool
     */
    public static function isKnownOperation(string $operation): bool
    {
        return array_key_exists($operation, self::OPERATIONS);
    }

    /**
     * @param string $operation Operation identifier
     * @return bool
     */
    public static function approvalRequired(string $operation): bool
    {
        return self::approvalsRequired($operation) > 0;
    }

    /**
     * @param string $operation Operation identifier
     * @return int
     */
    public static function approvalsRequired(string $operation): int
    {
        $policy = self::approvalPolicy($operation);

        return max(0, (int)($policy['required_approvals'] ?? 0));
    }

    /**
     * @param string $operation Operation identifier
     * @return string
     */
    public static function idempotencyScope(string $operation): string
    {
        return (string)(self::operationConfig($operation)['idempotency_scope'] ?? 'tenant');
    }

    /**
     * Validate and normalize a gateway command request.
     *
     * @param string $operation Operation identifier
     * @param string $targetMode single|selected|all-tenant
     * @param array<string, mixed> $parameters Request parameters
     * @return array<string, mixed>
     */
    public static function validateGatewayRequest(string $operation, string $targetMode, array $parameters): array
    {
        $config = self::operationConfig($operation);
        if (($config['gateway_enabled'] ?? false) !== true) {
            throw new RuntimeException(sprintf(
                'Operation "%s" is not approved for gateway execution.',
                $operation,
            ));
        }

        self::assertTargetModeAllowed($operation, $targetMode, $config);
        self::assertRequiredParameters($operation, $parameters, $config);
        self::assertNoUnknownParameters($operation, $parameters, $config);

        $normalized = [];
        foreach (array_merge($config['required_parameters'], $config['optional_parameters']) as $key) {
            if (!array_key_exists($key, $parameters)) {
                if (in_array($key, $config['required_parameters'], true)) {
                    throw new RuntimeException(sprintf(
                        'Operation "%s" requires parameter "%s".',
                        $operation,
                        $key,
                    ));
                }

                $normalized[$key] = null;

                continue;
            }

            $value = $parameters[$key];
            if (is_string($value)) {
                $value = trim($value);
            }
            if (is_string($value) && $value === '' && in_array($key, $config['required_parameters'], true)) {
                throw new RuntimeException(sprintf(
                    'Operation "%s" requires non-empty parameter "%s".',
                    $operation,
                    $key,
                ));
            }
            if (is_string($value) && $value === '') {
                $value = null;
            }
            $normalized[$key] = $value;
        }

        foreach ((array)($config['allowed_values'] ?? []) as $key => $allowedValues) {
            if (!array_key_exists($key, $normalized) || $normalized[$key] === null) {
                continue;
            }
            if (!in_array($normalized[$key], (array)$allowedValues, true)) {
                throw new RuntimeException(sprintf(
                    'Operation "%s" parameter "%s" must be one of: %s.',
                    $operation,
                    (string)$key,
                    implode(', ', array_map('strval', (array)$allowedValues)),
                ));
            }
        }

        self::applyOperationSpecificValidation($operation, $normalized);

        return $normalized;
    }

    /**
     * @param string $operation Operation identifier
     * @param array<string, mixed> $normalized Normalized parameters
     * @return void
     */
    private static function applyOperationSpecificValidation(string $operation, array &$normalized): void
    {
        if ($operation !== 'tenant_rotate_db_secret') {
            return;
        }

        $reference = trim((string)($normalized['new_secret_reference'] ?? ''));
        if (!str_starts_with($reference, 'managed:') && !str_starts_with($reference, 'env:')) {
            throw new RuntimeException(
                'Operation "tenant_rotate_db_secret" parameter "new_secret_reference" must start with managed: or env:.',
            );
        }
        $normalized['new_secret_reference'] = $reference;

        $maxAttempts = $normalized['max_attempts'] ?? null;
        if ($maxAttempts === null || $maxAttempts === '') {
            $normalized['max_attempts'] = null;

            return;
        }

        $maxAttempts = (int)$maxAttempts;
        if ($maxAttempts < 1 || $maxAttempts > 10) {
            throw new RuntimeException(
                'Operation "tenant_rotate_db_secret" parameter "max_attempts" must be between 1 and 10.',
            );
        }
        $normalized['max_attempts'] = $maxAttempts;
    }

    /**
     * Validate idempotency scope against catalog policy.
     *
     * @param string $operation Operation identifier
     * @param string $idempotencyScope Requested scope
     * @return void
     */
    public static function validateIdempotencyScope(string $operation, string $idempotencyScope): void
    {
        $requiredScope = self::idempotencyScope($operation);
        if ($idempotencyScope !== $requiredScope) {
            throw new RuntimeException(sprintf(
                'Operation "%s" requires idempotency scope "%s" (received "%s").',
                $operation,
                $requiredScope,
                $idempotencyScope,
            ));
        }
    }

    /**
     * @param string $operation Operation identifier
     * @return array<string, mixed>
     */
    public static function operationConfig(string $operation): array
    {
        $normalized = trim($operation);
        if ($normalized === '' || !array_key_exists($normalized, self::OPERATIONS)) {
            throw new RuntimeException(sprintf(
                'Unknown operation "%s". Allowed operations: %s.',
                $operation,
                implode(', ', self::allowedGatewayOperations()),
            ));
        }

        /** @var array<string, mixed> $config */
        $config = self::OPERATIONS[$normalized];

        return $config;
    }

    /**
     * @param string $operation Operation identifier
     * @return string|null
     */
    public static function requiredCapability(string $operation): ?string
    {
        $capability = trim((string)(self::operationConfig($operation)['required_capability'] ?? ''));

        return $capability === '' ? null : $capability;
    }

    /**
     * @param string|null $capability Capability identifier
     * @return array<int, string>
     */
    public static function rolesForCapability(?string $capability): array
    {
        if ($capability === null || trim($capability) === '') {
            return [];
        }
        $roles = [
            PlatformAdmin::ROLE_VIEWER,
            PlatformAdmin::ROLE_OPERATOR,
            PlatformAdmin::ROLE_PROVISIONER,
            PlatformAdmin::ROLE_SECURITY_ADMIN,
            PlatformAdmin::ROLE_BREAK_GLASS,
        ];
        $allowedRoles = [];
        foreach ($roles as $role) {
            $admin = new PlatformAdmin(['role' => $role]);
            if ($admin->hasCapability($capability)) {
                $allowedRoles[] = $role;
            }
        }

        return $allowedRoles;
    }

    /**
     * Return gateway-enabled command metadata for operator UI/API surfaces.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function gatewayCatalog(): array
    {
        $catalog = [];
        foreach (self::allowedGatewayOperations() as $operation) {
            $config = self::operationConfig($operation);
            $requiredCapability = self::requiredCapability($operation);
            $approvalPolicy = self::approvalPolicy($operation);
            $catalog[] = [
                'id' => $operation,
                'name' => (string)($config['name'] ?? $operation),
                'target_scope' => (string)($config['target_scope'] ?? 'tenant'),
                'target_modes' => array_values(array_map('strval', (array)($config['allowed_target_modes'] ?? []))),
                'required_parameters' => array_values(array_map('strval', (array)($config['required_parameters'] ?? []))),
                'optional_parameters' => array_values(array_map('strval', (array)($config['optional_parameters'] ?? []))),
                'allowed_values' => (array)($config['allowed_values'] ?? []),
                'approval_policy' => $approvalPolicy,
                'idempotency_scope' => (string)($config['idempotency_scope'] ?? 'tenant'),
                'required_capability' => $requiredCapability,
                'allowed_roles' => self::rolesForCapability($requiredCapability),
                'preflight_hints' => array_values(array_map('strval', (array)($config['preflight_hints'] ?? []))),
            ];
        }

        return $catalog;
    }

    /**
     * @param string $operation Operation identifier
     * @return array{mode: string, required_approvals: int, require_distinct_approvers: bool, require_requester_separation: bool}
     */
    public static function approvalPolicy(string $operation): array
    {
        $config = self::operationConfig($operation);
        $policy = is_array($config['approval_policy'] ?? null) ? $config['approval_policy'] : [];
        $mode = (string)($policy['mode'] ?? self::APPROVAL_MODE_NONE);
        $requiredApprovals = max(0, (int)($policy['required_approvals'] ?? 0));
        if ($mode === self::APPROVAL_MODE_TWO_PERSON && $requiredApprovals < 2) {
            $requiredApprovals = 2;
        }
        if ($requiredApprovals === 0) {
            $mode = self::APPROVAL_MODE_NONE;
        }

        return [
            'mode' => $mode,
            'required_approvals' => $requiredApprovals,
            'require_distinct_approvers' => (bool)($policy['require_distinct_approvers'] ?? true),
            'require_requester_separation' => (bool)($policy['require_requester_separation'] ?? false),
        ];
    }

    /**
     * @param string $operation Operation identifier
     * @param string $targetMode Target mode
     * @param array<string, mixed> $config Catalog config
     * @return void
     */
    private static function assertTargetModeAllowed(string $operation, string $targetMode, array $config): void
    {
        $allowedModes = (array)($config['allowed_target_modes'] ?? []);
        if (!in_array($targetMode, $allowedModes, true)) {
            throw new RuntimeException(sprintf(
                'Operation "%s" does not support target mode "%s". Allowed modes: %s.',
                $operation,
                $targetMode,
                implode(', ', array_map('strval', $allowedModes)),
            ));
        }
    }

    /**
     * @param string $operation Operation identifier
     * @param array<string, mixed> $parameters Request parameters
     * @param array<string, mixed> $config Catalog config
     * @return void
     */
    private static function assertRequiredParameters(string $operation, array $parameters, array $config): void
    {
        foreach ((array)$config['required_parameters'] as $requiredKey) {
            $requiredKey = (string)$requiredKey;
            if (!array_key_exists($requiredKey, $parameters)) {
                throw new RuntimeException(sprintf(
                    'Operation "%s" requires parameter "%s".',
                    $operation,
                    $requiredKey,
                ));
            }
        }
    }

    /**
     * @param string $operation Operation identifier
     * @param array<string, mixed> $parameters Request parameters
     * @param array<string, mixed> $config Catalog config
     * @return void
     */
    private static function assertNoUnknownParameters(string $operation, array $parameters, array $config): void
    {
        $allowedKeys = array_merge(
            (array)$config['required_parameters'],
            (array)$config['optional_parameters'],
        );
        $unknown = array_values(array_diff(array_keys($parameters), $allowedKeys));
        if ($unknown !== []) {
            throw new RuntimeException(sprintf(
                'Operation "%s" received unsupported parameter(s): %s.',
                $operation,
                implode(', ', array_map('strval', $unknown)),
            ));
        }
    }
}
