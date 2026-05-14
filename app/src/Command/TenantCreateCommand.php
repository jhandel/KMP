<?php
declare(strict_types=1);

namespace App\Command;

use App\Model\Entity\Tenant;
use App\Services\Tenant\TenantProvisioningService;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Database\Driver\Mysql;
use Throwable;

/**
 * Create or update platform tenant records and optional tenant database setup.
 */
class TenantCreateCommand extends Command
{
    /**
     * Get the default command name.
     *
     * @return string
     */
    public static function defaultName(): string
    {
        return 'tenant:create';
    }

    /**
     * Configure command options.
     *
     * @param \Cake\Console\ConsoleOptionParser $parser Option parser
     * @return \Cake\Console\ConsoleOptionParser
     */
    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return parent::buildOptionParser($parser)
            ->setDescription('Create or update a tenant platform record and database configuration.')
            ->addArgument('slug', ['help' => 'Tenant slug.', 'required' => true])
            ->addOption('display-name', ['help' => 'Human-readable tenant name.'])
            ->addOption('primary-host', ['help' => 'Primary host for tenant routing.'])
            ->addOption('alias', ['help' => 'Additional host alias. Repeatable.', 'multiple' => true])
            ->addOption('path-prefix', ['help' => 'Reserved path prefix for future path routing.'])
            ->addOption('database-name', ['help' => 'Tenant database name.'])
            ->addOption('database-url', ['help' => 'Tenant database URL stored in config metadata.'])
            ->addOption('driver', ['help' => 'Tenant database driver class.', 'default' => Mysql::class])
            ->addOption('host', ['help' => 'Tenant database host.'])
            ->addOption('port', ['help' => 'Tenant database port.'])
            ->addOption('username', ['help' => 'Tenant database username.'])
            ->addOption('secret-reference', ['help' => 'Secret reference, e.g. env:TENANT_PASSWORD.'])
            ->addOption('password-env', ['help' => 'Environment variable that contains the tenant DB password.'])
            ->addOption('email-config-json', [
                'help' => 'JSON metadata for tenant email transport/profile settings.',
            ])
            ->addOption('email-secret-reference', [
                'help' => 'Secret reference for tenant email transport password, e.g. env:ANSTEORRA_SMTP_PASSWORD.',
            ])
            ->addOption('storage-config-json', [
                'help' => 'JSON metadata for tenant storage config, e.g. {"s3":{"bucket":"...","region":"..."}}',
            ])
            ->addOption('storage-adapter', ['help' => 'Tenant storage adapter: local, azure, or s3.'])
            ->addOption('storage-secret-reference', [
                'help' => 'Secret reference for tenant storage credentials.',
            ])
            ->addOption('create-database', [
                'help' => 'Best-effort physical database creation. Requires driver/server support and privileges.',
                'boolean' => true,
                'default' => false,
            ])
            ->addOption('dry-run', [
                'help' => 'Preview platform registry and tenant database actions without writing anything.',
                'boolean' => true,
                'default' => false,
            ])
            ->addOption('migrate', [
                'help' => 'Run tenant migrations after creating/updating platform records.',
                'boolean' => true,
                'default' => false,
            ])
            ->addOption('activate', [
                'help' => 'Activate tenant after successful create/migrate.',
                'boolean' => true,
                'default' => false,
            ]);
    }

    /**
     * Execute the command.
     *
     * @param \Cake\Console\Arguments $args Arguments
     * @param \Cake\Console\ConsoleIo $io Console IO
     * @return int|null
     */
    public function execute(Arguments $args, ConsoleIo $io): ?int
    {
        $service = new TenantProvisioningService();
        $data = [
            'slug' => $args->getArgument('slug'),
            'display_name' => $args->getOption('display-name') ?: $args->getArgument('slug'),
            'status' => Tenant::STATUS_PROVISIONING,
            'primary_host' => $args->getOption('primary-host'),
            'aliases' => $args->getArrayOption('alias'),
            'path_prefix' => $args->getOption('path-prefix'),
            'database_name' => $args->getOption('database-name'),
            'database_url' => $args->getOption('database-url'),
            'driver' => $args->getOption('driver'),
            'host' => $args->getOption('host'),
            'port' => $args->getOption('port'),
            'username' => $args->getOption('username'),
            'secret_reference' => $args->getOption('secret-reference'),
            'password_env' => $args->getOption('password-env'),
            'email_config_json' => $args->getOption('email-config-json'),
            'email_secret_reference' => $args->getOption('email-secret-reference'),
            'storage_config_json' => $args->getOption('storage-config-json'),
            'storage_adapter' => $args->getOption('storage-adapter'),
            'storage_secret_reference' => $args->getOption('storage-secret-reference'),
        ];

        try {
            if ($args->getOption('dry-run')) {
                $io->out(sprintf('Dry run: tenant "%s" platform record would be created or updated.', $data['slug']));
                if ($args->getOption('create-database')) {
                    $io->out(sprintf(
                        'Dry run: tenant database "%s" would be created if needed.',
                        $data['database_name'] ?: '(from URL)',
                    ));
                }
                if ($args->getOption('migrate')) {
                    $io->out('Dry run: tenant migrations would run on the tenant datasource only.');
                }
                if ($args->getOption('activate')) {
                    $io->out('Dry run: tenant would be activated after successful provisioning.');
                }

                return Command::CODE_SUCCESS;
            }

            if ($args->getOption('create-database')) {
                $message = $service->createPhysicalDatabase($data);
                if ($message !== null) {
                    $io->warning($message);
                } else {
                    $io->success('Physical database exists or was created.');
                }
            }

            $tenant = $service->createOrUpdateTenant($data);
            $io->success(sprintf('Tenant "%s" platform record is ready.', $tenant->slug));

            if ($args->getOption('migrate')) {
                $schemaVersion = $service->migrateTenant($tenant);
                $tenant = $service->getTenant((string)$tenant->slug);
                $io->success(sprintf('Tenant migrations complete. schema_version=%s', $schemaVersion));
            }

            if ($args->getOption('activate')) {
                $tenant = $service->setStatus((string)$tenant->slug, Tenant::STATUS_ACTIVE);
                $io->success(sprintf('Tenant "%s" activated.', $tenant->slug));
            }
        } catch (Throwable $e) {
            $io->error($e->getMessage());

            return Command::CODE_ERROR;
        }

        return Command::CODE_SUCCESS;
    }
}
