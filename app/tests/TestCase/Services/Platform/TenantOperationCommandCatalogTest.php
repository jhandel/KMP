<?php
declare(strict_types=1);

namespace App\Test\TestCase\Services\Platform;

use App\Model\Entity\Tenant;
use App\Services\Platform\TenantOperationCommandCatalog;
use Cake\TestSuite\TestCase;
use RuntimeException;

class TenantOperationCommandCatalogTest extends TestCase
{
    public function testValidateGatewayRequestAcceptsRequiredAndOptionalParameters(): void
    {
        $normalized = TenantOperationCommandCatalog::validateGatewayRequest(
            operation: 'tenant_rotate_db_secret',
            targetMode: 'single',
            parameters: [
                'new_secret_reference' => ' managed:tenant/42/database/primary/rotation/abc123 ',
                'max_attempts' => '3',
            ],
        );

        $this->assertSame('managed:tenant/42/database/primary/rotation/abc123', $normalized['new_secret_reference']);
        $this->assertSame(3, $normalized['max_attempts']);
    }

    public function testValidateGatewayRequestSetsOptionalParametersToNullWhenOmitted(): void
    {
        $normalized = TenantOperationCommandCatalog::validateGatewayRequest(
            operation: 'tenant_migrate',
            targetMode: 'single',
            parameters: [],
        );

        $this->assertArrayHasKey('plugin', $normalized);
        $this->assertNull($normalized['plugin']);
    }

    public function testValidateGatewayRequestRejectsMissingRequiredParameter(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Operation "tenant_status" requires parameter "status".');

        TenantOperationCommandCatalog::validateGatewayRequest(
            operation: 'tenant_status',
            targetMode: 'single',
            parameters: [],
        );
    }

    public function testValidateGatewayRequestRejectsUnsupportedTargetMode(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Operation "tenant_rotate_db_secret" does not support target mode "selected".');

        TenantOperationCommandCatalog::validateGatewayRequest(
            operation: 'tenant_rotate_db_secret',
            targetMode: 'selected',
            parameters: ['new_secret_reference' => 'managed:tenant/2/database/primary/rotation/xyz'],
        );
    }

    public function testValidateGatewayRequestRejectsInvalidAllowedValues(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Operation "tenant_status" parameter "status" must be one of:');

        TenantOperationCommandCatalog::validateGatewayRequest(
            operation: 'tenant_status',
            targetMode: 'single',
            parameters: ['status' => 'unknown-state'],
        );
    }

    public function testOperationConfigRejectsUnknownOperationsWithOperatorFriendlyMessage(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown operation "tenant_backup". Allowed operations: tenant_doctor, tenant_migrate, tenant_rotate_db_secret, tenant_status.');

        TenantOperationCommandCatalog::operationConfig('tenant_backup');
    }

    public function testApprovalPolicyMetadataMatchesCatalogDefinitions(): void
    {
        $this->assertTrue(TenantOperationCommandCatalog::approvalRequired('tenant_status'));
        $this->assertFalse(TenantOperationCommandCatalog::approvalRequired('tenant_doctor'));
        $this->assertSame('tenant', TenantOperationCommandCatalog::idempotencyScope('tenant_status'));
        $this->assertContains('single', TenantOperationCommandCatalog::operationConfig('tenant_status')['allowed_target_modes']);
        $this->assertContains(Tenant::STATUS_MAINTENANCE, TenantOperationCommandCatalog::operationConfig('tenant_status')['allowed_values']['status']);
    }

    public function testGatewayCatalogIncludesOperatorUiMetadata(): void
    {
        $catalog = TenantOperationCommandCatalog::gatewayCatalog();
        $status = null;
        foreach ($catalog as $command) {
            if (($command['id'] ?? '') === 'tenant_status') {
                $status = $command;
                break;
            }
        }
        $this->assertNotNull($status);
        $this->assertSame('Set tenant lifecycle status', (string)($status['name'] ?? ''));
        $this->assertSame('tenant', (string)($status['target_scope'] ?? ''));
        $approvalPolicy = (array)($status['approval_policy'] ?? []);
        $this->assertSame('n_of_m', (string)($approvalPolicy['mode'] ?? ''));
        $this->assertSame(1, (int)($approvalPolicy['required_approvals'] ?? 0));
        $this->assertContains('status', (array)($status['required_parameters'] ?? []));
        $this->assertContains('operator', (array)($status['allowed_roles'] ?? []));
        $this->assertContains('break_glass', (array)($status['allowed_roles'] ?? []));
        $this->assertContains('single', (array)($status['target_modes'] ?? []));
    }
}
