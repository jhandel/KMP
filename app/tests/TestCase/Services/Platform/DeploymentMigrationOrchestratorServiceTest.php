<?php
declare(strict_types=1);

namespace App\Test\TestCase\Services\Platform;

use App\Model\Entity\Tenant;
use App\Model\Entity\TenantOperationJob;
use App\Services\Platform\DeploymentMigrationOrchestratorService;
use App\Services\Tenant\TenantMigrationService;
use Cake\Datasource\ConnectionManager;
use Cake\I18n\DateTime;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;
use Migrations\Migrations;
use RuntimeException;

class DeploymentMigrationOrchestratorServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (new Migrations())->migrate([
            'connection' => 'test',
            'source' => 'PlatformMigrations',
        ]);
        $this->truncatePlatformTables();
    }

    public function testOrchestrateQueuesOnlyActiveTenants(): void
    {
        $this->createTenant('active-a', Tenant::STATUS_ACTIVE);
        $this->createTenant('active-b', Tenant::STATUS_ACTIVE);
        $this->createTenant('maintenance-a', Tenant::STATUS_MAINTENANCE);
        $this->createTenant('disabled-a', Tenant::STATUS_DISABLED);

        $service = new DeploymentMigrationOrchestratorService(
            migrationService: new class extends TenantMigrationService {
                public function migratePlatform(): array
                {
                    return ['platform'];
                }

                public function targetSchemaVersion(): string
                {
                    return '20260601000000';
                }
            },
        );

        $result = $service->orchestrate([
            'wait' => false,
            'skip_platform_gate' => false,
            'max_attempts' => 2,
        ]);

        $this->assertSame(TenantOperationJob::STATUS_RUNNING, $result['state']);
        $this->assertSame(2, (int)($result['counts'][TenantOperationJob::STATUS_APPROVED] ?? 0));
        $this->assertCount(2, (array)($result['children'] ?? []));

        $children = $this->getTableLocator()->get('TenantOperationJobs')->find()
            ->where(['operation' => 'tenant_migrate'])
            ->orderByAsc('id')
            ->all()
            ->toList();
        $this->assertCount(2, $children);
        $this->assertSame('active-a', $children[0]->input['tenant_slug']);
        $this->assertSame('active-b', $children[1]->input['tenant_slug']);
        $this->assertSame(2, (int)$children[0]->input['max_attempts']);
        $this->assertSame((int)$result['parent_job_id'], (int)$children[0]->parent_tenant_operation_job_id);
        $this->assertSame((int)$result['parent_job_id'], (int)$children[1]->parent_tenant_operation_job_id);
        $this->assertSame('tenant_schema', (string)$children[0]->input['migration_scope']);
        $this->assertSame(['core', 'plugins'], (array)$children[0]->input['migration_scopes']);
    }

    public function testOrchestrateHoldThenResumeCompletes(): void
    {
        $tenantA = $this->createTenant('resume-a', Tenant::STATUS_ACTIVE);
        $tenantB = $this->createTenant('resume-b', Tenant::STATUS_ACTIVE);

        $phase = 0;
        $runner = function () use (&$phase, $tenantA, $tenantB): int {
            $jobs = TableRegistry::getTableLocator()->get('TenantOperationJobs');
            $children = $jobs->find()
                ->where(['operation' => 'tenant_migrate'])
                ->orderByAsc('id')
                ->all()
                ->toList();
            if ($phase === 0) {
                foreach ($children as $child) {
                    if ((int)$child->tenant_id === (int)$tenantA->id) {
                        $jobs->updateAll([
                            'state' => TenantOperationJob::STATUS_COMPLETED,
                            'status' => TenantOperationJob::STATUS_COMPLETED,
                            'result_json' => [
                                'slug' => 'resume-a',
                                'schema_before' => '20240101000000',
                                'schema_after' => '20260601000000',
                                'duration_ms' => 150,
                            ],
                            'completed_at' => new DateTime('now'),
                        ], ['id' => (int)$child->id]);
                        continue;
                    }
                    if ((int)$child->tenant_id === (int)$tenantB->id) {
                        $jobs->updateAll([
                            'state' => TenantOperationJob::STATUS_FAILED,
                            'status' => TenantOperationJob::STATUS_FAILED,
                            'error_json' => ['message' => 'simulated failure'],
                            'completed_at' => new DateTime('now'),
                        ], ['id' => (int)$child->id]);
                    }
                }
                $phase = 1;

                return 2;
            }

            foreach ($children as $child) {
                if ((string)$child->state !== TenantOperationJob::STATUS_APPROVED) {
                    continue;
                }
                $jobs->updateAll([
                    'state' => TenantOperationJob::STATUS_COMPLETED,
                    'status' => TenantOperationJob::STATUS_COMPLETED,
                    'result_json' => [
                        'slug' => 'resume-b',
                        'schema_before' => '20240101000000',
                        'schema_after' => '20260601000000',
                        'duration_ms' => 90,
                    ],
                    'completed_at' => new DateTime('now'),
                ], ['id' => (int)$child->id]);
            }

            return 1;
        };

        $service = new DeploymentMigrationOrchestratorService(
            migrationService: new class extends TenantMigrationService {
                public function migratePlatform(): array
                {
                    return ['platform'];
                }

                public function targetSchemaVersion(): string
                {
                    return '20260601000000';
                }
            },
            workerBatchRunner: $runner,
            sleepFn: static function (): void {
            },
        );

        $first = $service->orchestrate([
            'wait' => true,
            'drive_worker' => true,
            'on_failure' => 'hold',
            'poll_interval_seconds' => 1,
            'timeout_seconds' => 10,
        ]);

        $this->assertSame(TenantOperationJob::STATUS_HOLD, $first['state']);
        $this->assertSame(1, (int)($first['counts'][TenantOperationJob::STATUS_FAILED] ?? 0));
        $firstChildren = (array)($first['children'] ?? []);
        $this->assertNotEmpty($firstChildren);
        $this->assertSame((int)$first['parent_job_id'], (int)($firstChildren[0]['parent_job_id'] ?? 0));

        $resumed = $service->orchestrate([
            'wait' => true,
            'drive_worker' => true,
            'resume_parent_id' => (int)$first['parent_job_id'],
            'on_failure' => 'hold',
            'poll_interval_seconds' => 1,
            'timeout_seconds' => 10,
            'skip_platform_gate' => true,
        ]);

        $this->assertSame(TenantOperationJob::STATUS_COMPLETED, $resumed['state']);
        $this->assertSame(2, (int)($resumed['counts'][TenantOperationJob::STATUS_COMPLETED] ?? 0));
        $resumedChildren = (array)($resumed['children'] ?? []);
        $this->assertCount(2, $resumedChildren);
        $this->assertSame((int)$first['parent_job_id'], (int)($resumedChildren[0]['parent_job_id'] ?? 0));
    }

    public function testOrchestrateRunsPlatformGateBeforeSchemaSignalResolution(): void
    {
        $this->createTenant('gate-a', Tenant::STATUS_ACTIVE);
        $migrationService = new class extends TenantMigrationService {
            public bool $platformGateRan = false;

            public int $platformCalls = 0;

            public function migratePlatform(): array
            {
                $this->platformCalls++;
                $this->platformGateRan = true;

                return ['platform-gated'];
            }

            public function targetSchemaVersion(): string
            {
                if (!$this->platformGateRan) {
                    throw new RuntimeException('targetSchemaVersion called before platform gate.');
                }

                return '20260601000000';
            }
        };
        $service = new DeploymentMigrationOrchestratorService(migrationService: $migrationService);

        $result = $service->orchestrate([
            'wait' => false,
        ]);

        $this->assertSame(1, $migrationService->platformCalls);
        $this->assertSame(TenantOperationJob::STATUS_RUNNING, (string)$result['state']);
        $this->assertSame(1, (int)($result['counts'][TenantOperationJob::STATUS_APPROVED] ?? 0));
    }

    public function testOrchestrateCreatesTenantSnapshotChildJobsWithSchemaSignals(): void
    {
        $active = $this->createTenant('snapshot-active', Tenant::STATUS_ACTIVE);
        $maintenance = $this->createTenant('snapshot-maint', Tenant::STATUS_MAINTENANCE);
        $disabled = $this->createTenant('snapshot-disabled', Tenant::STATUS_DISABLED);
        $tenants = $this->getTableLocator()->get('Tenants');
        $active->schema_version = '20240102000000';
        $maintenance->schema_version = '20240203000000';
        $disabled->schema_version = '20240304000000';
        $tenants->saveOrFail($active);
        $tenants->saveOrFail($maintenance);
        $tenants->saveOrFail($disabled);

        $service = new DeploymentMigrationOrchestratorService(
            migrationService: new class extends TenantMigrationService {
                public function migratePlatform(): array
                {
                    return ['ok'];
                }

                public function targetSchemaVersion(): string
                {
                    return '20260601000000';
                }
            },
        );
        $result = $service->orchestrate([
            'wait' => false,
            'run_id' => 'snapshot-run',
            'include_maintenance' => true,
            'max_attempts' => 4,
        ]);

        $jobs = $this->getTableLocator()->get('TenantOperationJobs');
        $parent = $jobs->get((int)$result['parent_job_id']);
        $children = $jobs->find()
            ->where([
                'operation' => 'tenant_migrate',
                'operation_correlation_id' => (string)$parent->operation_correlation_id,
            ])
            ->orderByAsc('id')
            ->all()
            ->toList();

        $this->assertSame('deployment-migrate:snapshot-run', (string)$parent->idempotency_key);
        $this->assertSame('20260601000000', (string)$parent->input['target_schema_version']);
        $this->assertCount(2, $children);
        $this->assertSame((int)$parent->id, (int)$children[0]->input['parent_operation_id']);
        $this->assertSame((int)$parent->id, (int)$children[1]->input['parent_operation_id']);
        $this->assertSame('snapshot-active', (string)$children[0]->input['tenant_slug']);
        $this->assertSame('20240102000000', (string)$children[0]->input['schema_before']);
        $this->assertSame('20260601000000', (string)$children[0]->input['target_schema_version']);
        $this->assertSame(4, (int)$children[0]->input['max_attempts']);
        $this->assertSame('snapshot-maint', (string)$children[1]->input['tenant_slug']);
        $this->assertSame('20240203000000', (string)$children[1]->input['schema_before']);
        $this->assertSame('20260601000000', (string)$children[1]->input['target_schema_version']);
    }

    public function testOrchestratePlacesParentOnHoldForPartialFailures(): void
    {
        $tenantA = $this->createTenant('partial-a', Tenant::STATUS_ACTIVE);
        $tenantB = $this->createTenant('partial-b', Tenant::STATUS_ACTIVE);
        $tenantC = $this->createTenant('partial-c', Tenant::STATUS_ACTIVE);
        $runner = function () use ($tenantA, $tenantB, $tenantC): int {
            $jobs = TableRegistry::getTableLocator()->get('TenantOperationJobs');
            $children = $jobs->find()
                ->where(['operation' => 'tenant_migrate'])
                ->orderByAsc('id')
                ->all()
                ->toList();
            foreach ($children as $child) {
                if ((int)$child->tenant_id === (int)$tenantA->id) {
                    $jobs->updateAll([
                        'state' => TenantOperationJob::STATUS_COMPLETED,
                        'status' => TenantOperationJob::STATUS_COMPLETED,
                        'result_json' => ['slug' => 'partial-a', 'schema_after' => '20260601000000'],
                        'completed_at' => new DateTime('now'),
                    ], ['id' => (int)$child->id]);
                    continue;
                }
                if ((int)$child->tenant_id === (int)$tenantB->id) {
                    $jobs->updateAll([
                        'state' => TenantOperationJob::STATUS_FAILED,
                        'status' => TenantOperationJob::STATUS_FAILED,
                        'error_json' => ['message' => 'failing child'],
                        'completed_at' => new DateTime('now'),
                    ], ['id' => (int)$child->id]);
                    continue;
                }
                if ((int)$child->tenant_id === (int)$tenantC->id) {
                    $jobs->updateAll([
                        'state' => TenantOperationJob::STATUS_APPROVED,
                        'status' => TenantOperationJob::STATUS_APPROVED,
                    ], ['id' => (int)$child->id]);
                }
            }

            return 3;
        };
        $service = new DeploymentMigrationOrchestratorService(
            migrationService: new class extends TenantMigrationService {
                public function migratePlatform(): array
                {
                    return ['ok'];
                }
            },
            workerBatchRunner: $runner,
            sleepFn: static function (): void {
            },
        );

        $result = $service->orchestrate([
            'wait' => true,
            'drive_worker' => true,
            'on_failure' => 'hold',
            'poll_interval_seconds' => 1,
            'timeout_seconds' => 5,
        ]);
        $parent = $this->getTableLocator()->get('TenantOperationJobs')->get((int)$result['parent_job_id']);

        $this->assertSame(TenantOperationJob::STATUS_HOLD, (string)$result['state']);
        $this->assertSame(1, (int)($result['counts'][TenantOperationJob::STATUS_FAILED] ?? 0));
        $this->assertSame(1, (int)($result['counts'][TenantOperationJob::STATUS_COMPLETED] ?? 0));
        $this->assertSame(1, (int)($result['counts'][TenantOperationJob::STATUS_APPROVED] ?? 0));
        $this->assertNull($parent->completed_at);
        $this->assertSame(TenantOperationJob::STATUS_HOLD, (string)$parent->state);
    }

    public function testResumeRequeuesFailedBlockedAndHoldChildrenWithResumeCount(): void
    {
        $this->createTenant('resume-state-a', Tenant::STATUS_ACTIVE);
        $this->createTenant('resume-state-b', Tenant::STATUS_ACTIVE);
        $this->createTenant('resume-state-c', Tenant::STATUS_ACTIVE);
        $service = new DeploymentMigrationOrchestratorService(
            migrationService: new class extends TenantMigrationService {
                public function migratePlatform(): array
                {
                    return ['ok'];
                }
            },
        );
        $created = $service->orchestrate(['wait' => false]);

        $jobs = $this->getTableLocator()->get('TenantOperationJobs');
        $children = $jobs->find()
            ->where(['operation' => 'tenant_migrate'])
            ->orderByAsc('id')
            ->all()
            ->toList();
        $states = [
            TenantOperationJob::STATUS_FAILED,
            TenantOperationJob::STATUS_BLOCKED,
            TenantOperationJob::STATUS_HOLD,
        ];
        foreach ($children as $index => $child) {
            $jobs->updateAll([
                'state' => $states[$index],
                'status' => $states[$index],
                'input' => ['resume_count' => 2],
                'error_json' => ['message' => 'before resume'],
                'error_message' => 'before resume',
                'completed_at' => new DateTime('now'),
                'lease_owner' => 'worker-before-resume',
                'lease_token' => 'lease-before-resume',
            ], ['id' => (int)$child->id]);
        }

        $resumed = $service->orchestrate([
            'resume_parent_id' => (int)$created['parent_job_id'],
            'wait' => false,
        ]);
        $resumedChildren = $jobs->find()
            ->where(['operation' => 'tenant_migrate'])
            ->orderByAsc('id')
            ->all()
            ->toList();

        $this->assertSame(TenantOperationJob::STATUS_RUNNING, (string)$resumed['state']);
        $this->assertSame(3, (int)($resumed['counts'][TenantOperationJob::STATUS_APPROVED] ?? 0));
        foreach ($resumedChildren as $child) {
            $this->assertSame(TenantOperationJob::STATUS_APPROVED, (string)$child->state);
            $this->assertSame(3, (int)($child->input['resume_count'] ?? 0));
            $this->assertNull($child->error_json);
            $this->assertNull($child->error_message);
            $this->assertNull($child->completed_at);
            $this->assertNull($child->lease_owner);
            $this->assertNull($child->lease_token);
        }
    }

    public function testFinalizeUsesSchemaVersionFallbackSignalInTenantResults(): void
    {
        $this->createTenant('schema-signal-a', Tenant::STATUS_ACTIVE);
        $runner = function (): int {
            $jobs = TableRegistry::getTableLocator()->get('TenantOperationJobs');
            $children = $jobs->find()
                ->where(['operation' => 'tenant_migrate'])
                ->orderByAsc('id')
                ->all()
                ->toList();
            foreach ($children as $child) {
                $jobs->updateAll([
                    'state' => TenantOperationJob::STATUS_COMPLETED,
                    'status' => TenantOperationJob::STATUS_COMPLETED,
                    'result_json' => [
                        'slug' => 'schema-signal-a',
                        'schema_before' => (string)($child->input['schema_before'] ?? ''),
                        'schema_version' => '20260601000000',
                        'duration_ms' => 88,
                    ],
                    'completed_at' => new DateTime('now'),
                ], ['id' => (int)$child->id]);
            }

            return count($children);
        };
        $service = new DeploymentMigrationOrchestratorService(
            migrationService: new class extends TenantMigrationService {
                public function migratePlatform(): array
                {
                    return ['ok'];
                }
            },
            workerBatchRunner: $runner,
            sleepFn: static function (): void {
            },
        );

        $result = $service->orchestrate([
            'wait' => true,
            'drive_worker' => true,
            'poll_interval_seconds' => 1,
            'timeout_seconds' => 5,
        ]);

        $this->assertSame(TenantOperationJob::STATUS_COMPLETED, (string)$result['state']);
        $this->assertCount(1, $result['tenant_results']);
        $this->assertSame('20260601000000', (string)$result['tenant_results'][0]['schema_after']);
        $this->assertSame('20240101000000', (string)$result['tenant_results'][0]['schema_before']);
    }

    /**
     * @return void
     */
    private function truncatePlatformTables(): void
    {
        $connection = ConnectionManager::get('test');
        foreach (
            [
            'tenant_operation_approvals',
            'tenant_operation_jobs',
            'tenant_operation_locks',
            'tenant_database_configs',
            'tenant_service_configs',
            'tenant_aliases',
            'tenants',
            'platform_admins',
            ] as $table
        ) {
            $connection->execute(sprintf('DELETE FROM %s', $connection->getDriver()->quoteIdentifier($table)));
        }
        TableRegistry::getTableLocator()->clear();
    }

    /**
     * @param string $slug Tenant slug
     * @param string $status Status
     * @return \App\Model\Entity\Tenant
     */
    private function createTenant(string $slug, string $status): Tenant
    {
        $tenant = $this->getTableLocator()->get('Tenants')->newEntity([
            'slug' => $slug,
            'display_name' => strtoupper($slug),
            'status' => $status,
            'schema_version' => '20240101000000',
            'primary_host' => $slug . '.example.test',
        ]);
        $this->getTableLocator()->get('Tenants')->saveOrFail($tenant);

        return $tenant;
    }
}
