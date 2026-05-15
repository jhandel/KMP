<?php
declare(strict_types=1);

namespace App\Test\TestCase\Services\Tenant;

use App\Services\Platform\PlatformSecretService;
use App\Services\Tenant\TenantContext;
use App\Services\Tenant\TenantInvalidationApplier;
use App\Services\Tenant\TenantInvalidationService;
use Cake\TestSuite\TestCase;
use Migrations\Migrations;

class TenantInvalidationApplierTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        putenv('PLATFORM_SECRET_KEY=test-platform-secret-key-32-chars-minimum');
        $_ENV['PLATFORM_SECRET_KEY'] = 'test-platform-secret-key-32-chars-minimum';
        (new Migrations())->migrate([
            'connection' => 'test',
            'source' => 'PlatformMigrations',
        ]);
        $this->getTableLocator()->get('PlatformSecrets')->deleteAll([]);
        $this->getTableLocator()->get('TenantRuntimeInvalidationVersions')->deleteAll([]);
        TenantInvalidationService::clearLocalCache();
        TenantInvalidationApplier::clearAppliedVersions();
        (new PlatformSecretService())->clearCache();
    }

    protected function tearDown(): void
    {
        putenv('PLATFORM_SECRET_KEY');
        unset($_ENV['PLATFORM_SECRET_KEY']);
        TenantInvalidationService::clearLocalCache();
        TenantInvalidationApplier::clearAppliedVersions();
        (new PlatformSecretService())->clearCache();
        parent::tearDown();
    }

    public function testApplyClearsManagedSecretCacheWhenVersionAdvances(): void
    {
        $secretService = new PlatformSecretService();
        $invalidationService = new TenantInvalidationService(pollIntervalSeconds: 1);
        $applier = new TenantInvalidationApplier(
            invalidationService: $invalidationService,
            secretService: $secretService,
        );
        $context = new TenantContext(
            333,
            'cache-applier',
            'Cache Applier',
            'active',
            null,
            'cache-applier.example.org',
            'cache-applier.example.org',
        );

        $secretService->storeSecret('tenant/333/database/primary', 'first-password');
        $applier->apply($context);
        $this->assertSame(
            'first-password',
            $secretService->resolveSecretReference('managed:tenant/333/database/primary'),
        );

        $secretService->storeSecret('tenant/333/database/primary', 'rotated-password');
        $this->assertSame(
            'rotated-password',
            $secretService->resolveSecretReference('managed:tenant/333/database/primary'),
        );

        $invalidationService->bumpTenant(333, 'tenant_secret_rotated');
        $applier->apply($context);
        $this->assertSame(
            'rotated-password',
            $secretService->resolveSecretReference('managed:tenant/333/database/primary'),
        );
    }
}
