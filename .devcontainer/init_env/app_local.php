<?php
declare(strict_types=1);

use App\Mailer\Transport\AzureCommunicationTransport;
use App\Mailer\Transport\ResendApiTransport;
use App\Mailer\Transport\SendGridApiTransport;
use Cake\Database\Connection;
use Cake\Database\Driver\Mysql;

/*
 * Local configuration file to provide any overrides to your app.php configuration.
 * Copy and save this file as app_local.php and make changes as required.
 * Note: It is not recommended to commit files with credentials such as app_local.php
 * into source code version control.
 */
$databaseUrl = env('DATABASE_URL') ?: null;
$databaseTestUrl = env('DATABASE_TEST_URL') ?: null;
$platformDatabaseUrl = env('PLATFORM_DATABASE_URL') ?: null;
$tenantDatabaseUrl = env('TENANT_DATABASE_URL') ?: $databaseUrl;
$isPostgresUrl = str_starts_with(strtolower((string)$databaseUrl), 'postgres');
$isTenantPostgresUrl = str_starts_with(strtolower((string)$tenantDatabaseUrl), 'postgres');
$mysqlSsl = filter_var(env('MYSQL_SSL', false), FILTER_VALIDATE_BOOLEAN);

// Build PDO connection flags based on driver and SSL requirements
$pdoFlags = [];
if ($isPostgresUrl) {
    $pdoFlags[PDO::ATTR_EMULATE_PREPARES] = true;
} elseif ($mysqlSsl) {
    $pdoFlags[PDO::MYSQL_ATTR_SSL_CA] = env('MYSQL_SSL_CA', '');
    $pdoFlags[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = filter_var(
        env('MYSQL_SSL_VERIFY', false),
        FILTER_VALIDATE_BOOLEAN,
    );
}

return [
    /*
     * Debug Level:
     *
     * Production Mode:
     * false: No error messages, errors, or warnings shown.
     *
     * Development Mode:
     * true: Errors and warnings shown.
     */
    'debug' => filter_var(env('DEBUG', true), FILTER_VALIDATE_BOOLEAN),

    'DebugKit' => [
        'ignoreAuthorization' => true,
        'variablesPanelMaxDepth' => 10,
    ],

    'PlatformAdmin' => [
        'hosts' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string)env('PLATFORM_ADMIN_HOSTS', 'admin.localhost')),
        ))),
        'redirectFromHosts' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string)env('PLATFORM_ADMIN_REDIRECT_FROM_HOSTS', 'localhost,127.0.0.1')),
        ))),
    ],

    /*
     * Security and encryption configuration
     *
     * - salt - A random string used in security hashing methods.
     *   The salt value is also used as the encryption key.
     *   You should treat it as extremely sensitive data.
     */
    'Security' => [
        'salt' => env('SECURITY_SALT', '7479eaa68e57fc0bf648af17da085950a16c47f3b31af4eb1548a661b545a3fb'),
    ],

    /*
     * Connection information used by the ORM to connect
     * to your application's datastores.
     *
     * See app.php for more configuration options.
     */
    'Datasources' => [
        'default' => [
            'className' => Connection::class,
            'driver' => Mysql::class,
            'host' => env('TENANT_DB_HOST', env('MYSQL_HOST', 'localhost')),
            /*
             * CakePHP will use the default DB port based on the driver selected
             * MySQL on MAMP uses port 8889, MAMP users will want to uncomment
             * the following line and set the port accordingly
             */
            //'port' => 'non_standard_port_number',

            'username' => env('TENANT_DB_USERNAME', env('MYSQL_USERNAME')),
            'password' => env('TENANT_DB_PASSWORD', env('MYSQL_PASSWORD')),

            'database' => env('TENANT_DB_DATABASE', env('MYSQL_DB_NAME')),
            /*
             * If not using the default 'public' schema with the PostgreSQL driver
             * set it here.
             */
            //'schema' => 'myapp',

            /*
             * You can use a DSN string to set the entire configuration
             */
            'url' => $tenantDatabaseUrl,
            'flags' => $pdoFlags,
        ],

        'platform' => [
            'className' => Connection::class,
            'driver' => Mysql::class,
            'host' => env('PLATFORM_DB_HOST', 'localhost'),
            'port' => env('PLATFORM_DB_PORT', 3306),
            'username' => env('PLATFORM_DB_USERNAME', env('MYSQL_USERNAME')),
            'password' => env('PLATFORM_DB_PASSWORD', env('MYSQL_PASSWORD')),
            'database' => env('PLATFORM_DB_DATABASE', 'KMP_PLATFORM'),
            'url' => $platformDatabaseUrl,
            'flags' => $pdoFlags,
        ],

        'tenant' => [
            'className' => Connection::class,
            'driver' => Mysql::class,
            'host' => env('TENANT_DB_HOST', env('MYSQL_HOST', 'localhost')),
            'port' => env('TENANT_DB_PORT', env('MYSQL_PORT', 3306)),
            'username' => env('TENANT_DB_USERNAME', env('MYSQL_USERNAME')),
            'password' => env('TENANT_DB_PASSWORD', env('MYSQL_PASSWORD')),
            'database' => env('TENANT_DB_DATABASE', env('MYSQL_DB_NAME')),
            'url' => $tenantDatabaseUrl,
            'flags' => $pdoFlags,
        ],

        /*
         * The test connection is used during the test suite.
         */
        'test' => [
            'className' => Connection::class,
            'driver' => Mysql::class,
            'host' => env('TENANT_DB_HOST', env('MYSQL_HOST', 'localhost')),
            /*
             * CakePHP will use the default DB port based on the driver selected
             * MySQL on MAMP uses port 8889, MAMP users will want to uncomment
             * the following line and set the port accordingly
             */
            //'port' => 'non_standard_port_number',

            'username' => env('TENANT_DB_USERNAME', env('MYSQL_USERNAME')),
            'password' => env('TENANT_DB_PASSWORD', env('MYSQL_PASSWORD')),

            'database' => env('TENANT_TEST_DB_DATABASE', env('TENANT_DB_DATABASE', env('MYSQL_DB_NAME')) . '_test'),
            /*
             * If not using the default 'public' schema with the PostgreSQL driver
             * set it here.
             */
            //'schema' => 'myapp',

            /*
             * You can use a DSN string to set the entire configuration
             */
            'url' => $databaseTestUrl ?? ($isTenantPostgresUrl ? $tenantDatabaseUrl : null),
        ],
    ],

    /*
     * Email configuration.
     *
     * Host and credential configuration in case you are using SmtpTransport
     *
     * See app.php for more configuration options.
     */
    'EmailTransport' => [
        'default' => match (strtolower(env('EMAIL_DRIVER', 'smtp'))) {
            'azure' => [
                'className' => AzureCommunicationTransport::class,
                'connectionString' => env('AZURE_COMMUNICATION_CONNECTION_STRING'),
                'apiVersion' => env('AZURE_COMMUNICATION_API_VERSION', '2023-03-31'),
            ],
            'sendgrid' => [
                'className' => SendGridApiTransport::class,
                'apiKey' => env('EMAIL_API_KEY'),
            ],
            'resend' => [
                'className' => ResendApiTransport::class,
                'apiKey' => env('EMAIL_API_KEY'),
            ],
            default => [
                'className' => 'Smtp',
                'host' => env('EMAIL_SMTP_HOST', 'localhost'),
                'port' => (int)env('EMAIL_SMTP_PORT', 1025),
                'username' => env('EMAIL_SMTP_USERNAME', ''),
                'password' => env('EMAIL_SMTP_PASSWORD', ''),
                'client' => null,
                'tls' => filter_var(env('EMAIL_SMTP_TLS', false), FILTER_VALIDATE_BOOLEAN),
                'url' => env('EMAIL_TRANSPORT_DEFAULT_URL', null),
            ],
        },
    ],
    'Email' => [
        'default' => [
            'transport' => 'default',
            'from' => env('EMAIL_FROM', 'noreply@localhost'),
        ],
    ],
    'Documents' => [
        'storage' => [
            'adapter' => 'local', //azure to use azure and set the connection string.
            'azure' => [
                'connectionString' => env('AZURE_STORAGE_CONNECTION_STRING'),
                'container' => 'documents',
                'prefix' => '',
            ],
        ],
    ],
];
