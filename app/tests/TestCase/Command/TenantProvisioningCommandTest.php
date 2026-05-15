<?php
declare(strict_types=1);

namespace App\Test\TestCase\Command;

use App\Model\Entity\Tenant;
use Cake\Console\TestSuite\ConsoleIntegrationTestTrait;
use Cake\Database\SchemaCache;
use Cake\Datasource\ConnectionManager;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;
use Migrations\Migrations;

/**
 * Tests for tenant provisioning CLI commands.
 */
class TenantProvisioningCommandTest extends TestCase
{
    use ConsoleIntegrationTestTrait;

    /**
     * Ensure platform registry tables exist on the test platform alias.
     *
     * @return void
     */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $connection = ConnectionManager::get('test');
        $platformTables = [
            'tenant_operation_approvals',
            'tenant_operation_jobs',
            'tenant_operation_locks',
            'platform_audit_events',
            'platform_audit_retention_anchors',
            'platform_secrets',
            'platform_admin_sessions',
            'platform_admin_email_codes',
            'platform_admin_recovery_codes',
            'platform_admin_webauthn_credentials',
            'platform_service_configs',
            'platform_admins',
            'tenant_runtime_invalidation_versions',
            'tenant_service_configs',
            'tenant_database_configs',
            'tenant_aliases',
            'tenants',
        ];
        foreach ($platformTables as $table) {
            $connection->execute(sprintf('DROP TABLE IF EXISTS %s', $connection->getDriver()->quoteIdentifier($table)));
        }
        if (in_array('phinxlog', $connection->getSchemaCollection()->listTables(), true)) {
            $versions = [];
            foreach (glob(CONFIG . 'PlatformMigrations' . DS . '*.php') ?: [] as $file) {
                if (preg_match('/^(\d{14})_/', basename($file), $matches)) {
                    $versions[] = $matches[1];
                }
            }
            $connection->execute(
                sprintf('DELETE FROM phinxlog WHERE version IN (%s)', implode(',', array_fill(0, count($versions), '?'))),
                $versions,
            );
        }
        (new Migrations())->migrate([
            'connection' => 'test',
            'source' => 'PlatformMigrations',
        ]);
        (new SchemaCache($connection))->clear();
        TableRegistry::getTableLocator()->clear();
    }

    /**
     * Test tenant:create is idempotent and records database config.
     *
     * @return void
     */
    public function testTenantCreateIsIdempotent(): void
    {
        $slug = 'cli-' . strtolower(substr(str_replace('.', '', uniqid('', true)), 0, 12));
        $testConfig = (array)ConnectionManager::getConfig('test');
        $database = (string)$testConfig['database'];
        $command = sprintf(
            'tenant:create %s --display-name="CLI Tenant" --primary-host=%s.example.test ' .
            '--database-name=%s --driver=%s --host=%s --username=%s --activate',
            $slug,
            $slug,
            escapeshellarg($database),
            escapeshellarg((string)$testConfig['driver']),
            escapeshellarg((string)($testConfig['host'] ?? 'localhost')),
            escapeshellarg((string)($testConfig['username'] ?? '')),
        );

        $this->exec($command);
        $this->assertExitSuccess();
        $this->assertOutputContains('platform record is ready');

        $this->exec($command);
        $this->assertExitSuccess();

        $tenants = TableRegistry::getTableLocator()->get('Tenants');
        $tenant = $tenants->find()
            ->where(['slug' => $slug])
            ->contain(['TenantAliases', 'TenantDatabaseConfigs', 'TenantServiceConfigs'])
            ->firstOrFail();

        $this->assertSame(Tenant::STATUS_ACTIVE, $tenant->status);
        $this->assertCount(1, $tenant->tenant_database_configs);
        $this->assertCount(1, $tenant->tenant_aliases);
    }

    /**
     * Test tenant:create records email and storage service metadata.
     *
     * @return void
     */
    public function testTenantCreateStoresServiceConfigs(): void
    {
        $slug = 'svc-' . strtolower(substr(str_replace('.', '', uniqid('', true)), 0, 12));
        $testConfig = (array)ConnectionManager::getConfig('test');
        $database = (string)$testConfig['database'];
        $emailJson = escapeshellarg(json_encode([
            'transport' => ['host' => 'smtp.example.test'],
            'email' => ['from' => 'noreply@example.test'],
        ], JSON_THROW_ON_ERROR));
        $storageJson = escapeshellarg(json_encode([
            's3' => ['bucket' => 'tenant-docs', 'region' => 'us-east-1'],
        ], JSON_THROW_ON_ERROR));

        $this->exec(sprintf(
            'tenant:create %s --database-name=%s --driver=%s --host=%s --username=%s ' .
            '--email-config-json=%s --email-secret-reference=env:%s_SMTP_PASSWORD ' .
            '--storage-adapter=s3 --storage-config-json=%s --storage-secret-reference=env:%s_S3_SECRET --activate',
            $slug,
            escapeshellarg($database),
            escapeshellarg((string)$testConfig['driver']),
            escapeshellarg((string)($testConfig['host'] ?? 'localhost')),
            escapeshellarg((string)($testConfig['username'] ?? '')),
            $emailJson,
            strtoupper(str_replace('-', '_', $slug)),
            $storageJson,
            strtoupper(str_replace('-', '_', $slug)),
        ));
        $this->assertExitSuccess();

        $tenant = TableRegistry::getTableLocator()->get('Tenants')->find()
            ->where(['slug' => $slug])
            ->contain(['TenantServiceConfigs'])
            ->firstOrFail();
        $this->assertCount(2, $tenant->tenant_service_configs);
        $configs = [];
        foreach ($tenant->tenant_service_configs as $config) {
            $configs[$config->service_name] = $config;
        }
        $emailMetadata = json_decode((string)$configs['email']->metadata, true, flags: JSON_THROW_ON_ERROR);
        $storageMetadata = json_decode((string)$configs['storage']->metadata, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('smtp.example.test', $emailMetadata['transport']['host']);
        $this->assertSame('s3', $configs['storage']->adapter);
        $this->assertSame('tenant-docs', $storageMetadata['s3']['bucket']);
    }

    /**
     * Test tenant:create dry-run reports actions without writing platform metadata.
     *
     * @return void
     */
    public function testTenantCreateDryRunDoesNotPersistPlatformRecord(): void
    {
        $slug = 'dryrun-' . strtolower(substr(str_replace('.', '', uniqid('', true)), 0, 12));
        $testConfig = (array)ConnectionManager::getConfig('test');
        $database = (string)$testConfig['database'];

        $this->exec(sprintf(
            'tenant:create %s --display-name="Dry Run Tenant" --primary-host=%s.example.test ' .
            '--database-name=%s --driver=%s --create-database --migrate --activate --dry-run',
            $slug,
            $slug,
            escapeshellarg($database),
            escapeshellarg((string)$testConfig['driver']),
        ));

        $this->assertExitSuccess();
        $this->assertOutputContains('Dry run');
        $this->assertOutputContains('platform record would be created or updated');

        $tenants = TableRegistry::getTableLocator()->get('Tenants');
        $this->assertFalse($tenants->exists(['slug' => $slug]));
    }

    /**
     * Test tenant status operations and list output.
     *
     * @return void
     */
    public function testTenantStatusCommandsAndList(): void
    {
        $slug = 'status-' . strtolower(substr(str_replace('.', '', uniqid('', true)), 0, 12));
        $testConfig = (array)ConnectionManager::getConfig('test');
        $database = (string)$testConfig['database'];
        $this->exec(sprintf(
            'tenant:create %s --database-name=%s --driver=%s --host=%s --username=%s --activate',
            $slug,
            escapeshellarg($database),
            escapeshellarg((string)$testConfig['driver']),
            escapeshellarg((string)($testConfig['host'] ?? 'localhost')),
            escapeshellarg((string)($testConfig['username'] ?? '')),
        ));
        $this->assertExitSuccess();

        $this->exec('tenant:disable ' . $slug);
        $this->assertExitSuccess();
        $this->assertOutputContains('disabled');

        $this->exec('tenant:maintenance ' . $slug);
        $this->assertExitSuccess();
        $this->assertOutputContains('maintenance');

        $this->exec('tenant:drain ' . $slug);
        $this->assertExitSuccess();
        $this->assertOutputContains('draining');

        $this->exec('tenant:enable ' . $slug);
        $this->assertExitSuccess();
        $this->assertOutputContains('active');

        $this->exec('tenant:list');
        $this->assertExitSuccess();
        $this->assertOutputContains($slug);
    }

    /**
     * Test tenant doctor checks active reachable tenant database.
     *
     * @return void
     */
    public function testTenantDoctor(): void
    {
        $slug = 'doctor-' . strtolower(substr(str_replace('.', '', uniqid('', true)), 0, 12));
        $testConfig = (array)ConnectionManager::getConfig('test');
        $database = (string)$testConfig['database'];
        $this->exec(sprintf(
            'tenant:create %s --database-name=%s --driver=%s --host=%s --username=%s --activate',
            $slug,
            escapeshellarg($database),
            escapeshellarg((string)$testConfig['driver']),
            escapeshellarg((string)($testConfig['host'] ?? 'localhost')),
            escapeshellarg((string)($testConfig['username'] ?? '')),
        ));
        $this->assertExitSuccess();

        $this->exec('tenant:doctor --tenant=' . $slug);
        $this->assertExitSuccess();
        $this->assertOutputContains('database_reachable');
        $this->assertOutputContains('required_app_settings');
    }
}
