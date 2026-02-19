<?php
/*
 * Local configuration file to provide any overrides to your app.php configuration.
 * Copy and save this file as app_local.php and make changes as required.
 * Note: It is not recommended to commit files with credentials such as app_local.php
 * into source code version control.
 */
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
            'host' => 'localhost',
            /*
             * CakePHP will use the default DB port based on the driver selected
             * MySQL on MAMP uses port 8889, MAMP users will want to uncomment
             * the following line and set the port accordingly
             */
            //'port' => 'non_standard_port_number',

            'username' => env('MYSQL_USERNAME'),
            'password' => env('MYSQL_PASSWORD'),

            'database' => env('MYSQL_DB_NAME'),
            /*
             * If not using the default 'public' schema with the PostgreSQL driver
             * set it here.
             */
            //'schema' => 'myapp',

            /*
             * You can use a DSN string to set the entire configuration
             */
            'url' => env('DATABASE_URL', null),
        ],

        /*
         * The test connection is used during the test suite.
         */
        'test' => [
            'host' => 'localhost',
            /*
             * CakePHP will use the default DB port based on the driver selected
             * MySQL on MAMP uses port 8889, MAMP users will want to uncomment
             * the following line and set the port accordingly
             */
            //'port' => 'non_standard_port_number',

            'username' => env('MYSQL_USERNAME'),
            'password' => env('MYSQL_PASSWORD'),

            'database' => env('MYSQL_DB_NAME') . '_test',
            /*
             * If not using the default 'public' schema with the PostgreSQL driver
             * set it here.
             */
            //'schema' => 'myapp',

            /*
             * You can use a DSN string to set the entire configuration
             */
            'url' => env('DATABASE_URL', null),
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
        'default' => [
            'host' => env('EMAIL_SMTP_HOST'),
            'port' => env('EMAIL_SMTP_PORT'),
            'username' => env('EMAIL_SMTP_USERNAME'),
            'password' => env('EMAIL_SMTP_PASSWORD'),
            'client' => null,
            'url' => env('EMAIL_TRANSPORT_DEFAULT_URL', null),
        ],
    ],
    'Documents' => [
        'storage' => [
            'adapter' => env('DOCUMENT_STORAGE_ADAPTER', 'local'),
            'local' => [
                'path' => WWW_ROOT . '../images/uploaded/',
            ],
            'azure' => [
                'connectionString' => env('AZURE_STORAGE_CONNECTION_STRING'),
                'container' => env('AZURE_STORAGE_CONTAINER', 'documents'),
                'prefix' => env('AZURE_STORAGE_PREFIX', ''),
            ],
            's3' => [
                'bucket' => env('AWS_S3_BUCKET', null),
                'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
                'prefix' => env('AWS_S3_PREFIX', ''),
                'key' => env('AWS_ACCESS_KEY_ID', null),
                'secret' => env('AWS_SECRET_ACCESS_KEY', null),
                'sessionToken' => env('AWS_SESSION_TOKEN', null),
                'endpoint' => env('AWS_S3_ENDPOINT', null),
                'usePathStyleEndpoint' => filter_var(env('AWS_S3_USE_PATH_STYLE_ENDPOINT', false), FILTER_VALIDATE_BOOLEAN),
            ],
        ],
    ],
];
