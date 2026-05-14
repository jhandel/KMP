<?php
declare(strict_types=1);

namespace App\Test\TestCase\Services;

use App\Services\BackupService;
use App\Services\Tenant\TenantContext;
use Cake\TestSuite\TestCase;
use ReflectionMethod;
use RuntimeException;

/**
 * @covers \App\Services\BackupService
 */
class BackupServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        TenantContext::clearCurrent();
        parent::tearDown();
    }

    public function testRestorePostgresForeignKeysUsesSavepointForValidateFailures(): void
    {
        $service = new BackupService();
        $connection = new class {
            /**
             * @var array<int, string>
             */
            public array $queries = [];

            private bool $abortedTransaction = false;

            private int $validateCalls = 0;

            public function execute(string $sql): object
            {
                $this->queries[] = $sql;

                if ($this->abortedTransaction && !str_starts_with($sql, 'ROLLBACK TO SAVEPOINT')) {
                    throw new RuntimeException('SQLSTATE[25P02]: In failed sql transaction');
                }

                if (str_starts_with($sql, 'ALTER TABLE') && str_contains($sql, 'VALIDATE CONSTRAINT')) {
                    $this->validateCalls++;
                    if ($this->validateCalls === 1) {
                        $this->abortedTransaction = true;

                        throw new RuntimeException('SQLSTATE[23503]: foreign key violation');
                    }
                }

                if (str_starts_with($sql, 'ROLLBACK TO SAVEPOINT')) {
                    $this->abortedTransaction = false;
                }

                return new class {
                };
            }
        };
        $driver = new class {
            public function quoteIdentifier(string $identifier): string
            {
                return "\"{$identifier}\"";
            }
        };
        $foreignKeys = [
            [
                'table' => 'awards_recommendations_events',
                'name' => 'awards_recommendations_events_event_id_fkey',
                'definition' => 'FOREIGN KEY (event_id) REFERENCES awards_events(id)',
            ],
            [
                'table' => 'awards_recommendations_events',
                'name' => 'awards_recommendations_events_recommendation_id_fkey',
                'definition' => 'FOREIGN KEY (recommendation_id) REFERENCES awards_recommendations(id)',
            ],
        ];

        $method = new ReflectionMethod(BackupService::class, 'restorePostgresForeignKeys');
        $method->setAccessible(true);
        $notValidatedConstraintCount = $method->invoke($service, $connection, $driver, $foreignKeys);

        $this->assertSame(1, $notValidatedConstraintCount);
        $this->assertContains('ROLLBACK TO SAVEPOINT kmp_fk_validate_0', $connection->queries);
        $this->assertContains(
            'ALTER TABLE "awards_recommendations_events" ADD CONSTRAINT "awards_recommendations_events_recommendation_id_fkey" FOREIGN KEY (recommendation_id) REFERENCES awards_recommendations(id) NOT VALID',
            $connection->queries,
        );
    }

    public function testTenantTaggedBackupCannotRestoreIntoDifferentTenant(): void
    {
        TenantContext::setCurrent(new TenantContext(
            50,
            'tenant-b',
            'Tenant B',
            'active',
            null,
            'tenant-b.example.org',
            'tenant-b.example.org',
        ));
        $service = new BackupService();

        $method = new ReflectionMethod(BackupService::class, 'assertBackupTenantMatches');
        $method->setAccessible(true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Backup belongs to tenant "tenant-a"');
        $method->invoke($service, [
            'tenant' => [
                'slug' => 'tenant-a',
            ],
        ]);
    }
}
