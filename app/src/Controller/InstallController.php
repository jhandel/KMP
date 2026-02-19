<?php

declare(strict_types=1);

namespace App\Controller;

use App\KMP\StaticHelpers;
use App\Services\EmailTemplateSyncService;
use App\Services\Installer\InstallerLockService;
use App\Services\Installer\InstallerPreflightService;
use Cake\Core\Configure;
use Cake\Log\Log;
use Cake\Datasource\ConnectionManager;
use Cake\Event\EventInterface;
use Cake\Http\Response;
use PDO;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;
use Throwable;

/**
 * First-run web installer controller.
 */
class InstallController extends AppController
{
    private ?InstallerLockService $installerLockService = null;

    /**
     * Wizard step session key.
     */
    private const WIZARD_SESSION_KEY = 'Installer.WizardData';

    /**
     * Ordered wizard steps.
     */
    private const WIZARD_STEPS = ['preflight', 'database', 'communications', 'branding'];

    public function beforeFilter(EventInterface $event): void
    {
        parent::beforeFilter($event);
        $this->Authentication->allowUnauthenticated(['index', 'finalize']);
        $this->Authorization->skipAuthorization();
    }

    /**
     * Installer wizard step handler.
     *
     * @return \Cake\Http\Response|null|void
     */
    public function index()
    {
        if ($this->lockService()->isLocked()) {
            $this->Flash->error(__('Installer is already locked for this environment.'));

            return $this->redirect([
                'controller' => 'Members',
                'action' => 'login',
                'plugin' => null,
            ]);
        }

        if ($this->request->is('post')) {
            return $this->handleWizardStepPost();
        }

        $currentStep = $this->resolveWizardStep((string)$this->request->getQuery('step', 'preflight'));
        $preflightChecks = (new InstallerPreflightService())->runChecks();
        $defaults = array_merge(
            $this->buildDefaultFormData(),
            $this->getWizardData(),
        );

        $this->viewBuilder()->setLayout('TwitterBootstrap/signin');
        $this->set(compact('preflightChecks', 'defaults', 'currentStep'));
        $this->set('wizardSteps', $this->buildWizardSteps());
        $this->set('currentBannerLogo', (string)StaticHelpers::getAppSetting('KMP.BannerLogo', 'badge.png'));
    }

    /**
     * Finalize installer, persist settings, and lock installer.
     *
     * @return \Cake\Http\Response|null
     */
    public function finalize(): ?Response
    {
        $this->request->allowMethod(['post']);

        if ($this->lockService()->isLocked()) {
            $this->Flash->error(__('Installer is already locked for this environment.'));

            return $this->redirect([
                'controller' => 'Members',
                'action' => 'login',
                'plugin' => null,
            ]);
        }

        $data = array_merge(
            $this->buildDefaultFormData(),
            $this->getWizardData(),
            (array)$this->request->getData(),
        );

        $validationErrors = $this->validateInstallerData($data);
        if ($validationErrors !== []) {
            foreach ($validationErrors as $message) {
                $this->Flash->error($message);
            }

            return $this->redirect(['action' => 'index', '?' => ['step' => 'branding']]);
        }

        $logoConfig = $this->resolveBannerLogoConfig($data);
        if ($logoConfig['error'] !== null) {
            $this->Flash->error($logoConfig['error']);

            return $this->redirect(['action' => 'index', '?' => ['step' => 'branding']]);
        }

        $installResult = $this->performInstallation($data, $logoConfig);
        if ($installResult['ok'] === false) {
            $this->Flash->error($installResult['error'] ?? __('Installation failed.'));

            return $this->redirect(['action' => 'index', '?' => ['step' => 'branding']]);
        }

        $this->clearWizardData();
        $this->Flash->success(__('Installer finalized. You can now sign in.'));

        return $this->redirect([
            'controller' => 'Members',
            'action' => 'login',
            'plugin' => null,
        ]);
    }

    /**
     * Handle POST transitions between wizard steps.
     *
     * @return \Cake\Http\Response
     */
    private function handleWizardStepPost(): Response
    {
        $currentStep = $this->resolveWizardStep((string)$this->request->getData('current_step', 'preflight'));
        $nextStep = $this->resolveWizardStep((string)$this->request->getData('next_step', $currentStep));
        $data = (array)$this->request->getData();

        $errors = $this->validateStepData($currentStep, $data);
        if ($errors !== []) {
            foreach ($errors as $error) {
                $this->Flash->error($error);
            }

            return $this->redirect(['action' => 'index', '?' => ['step' => $currentStep]]);
        }

        $this->persistWizardDataForStep($currentStep, $data);

        return $this->redirect(['action' => 'index', '?' => ['step' => $nextStep]]);
    }

    /**
     * Persist form data for current step in session.
     *
     * @param string $step
     * @param array<string, mixed> $data
     * @return void
     */
    private function persistWizardDataForStep(string $step, array $data): void
    {
        $stepFields = [
            'database' => ['db_host', 'db_port', 'db_name', 'db_user', 'db_password', 'db_create_database'],
            'communications' => [
                'email_smtp_host',
                'email_smtp_port',
                'email_smtp_username',
                'email_smtp_password',
                'system_email_from',
                'storage_adapter',
                'azure_connection_string',
                'azure_container',
                'azure_prefix',
                's3_bucket',
                's3_region',
                's3_key',
                's3_secret',
                's3_session_token',
                's3_prefix',
                's3_endpoint',
                's3_use_path_style_endpoint',
            ],
            'branding' => [
                'kingdom_name',
                'long_site_title',
                'short_site_title',
                'default_timezone',
                'banner_logo_mode',
                'banner_logo_external_url',
            ],
        ];

        if (!isset($stepFields[$step])) {
            return;
        }

        $wizardData = $this->getWizardData();
        foreach ($stepFields[$step] as $field) {
            if (array_key_exists($field, $data)) {
                $value = $data[$field];
                $wizardData[$field] = is_string($value) ? trim($value) : (string)$value;
            }
        }

        // Ensure unchecked checkboxes are persisted as false-like values.
        if ($step === 'database' && !array_key_exists('db_create_database', $data)) {
            $wizardData['db_create_database'] = '0';
        }
        if ($step === 'communications' && !array_key_exists('s3_use_path_style_endpoint', $data)) {
            $wizardData['s3_use_path_style_endpoint'] = '0';
        }

        $this->request->getSession()->write(self::WIZARD_SESSION_KEY, $wizardData);
    }

    /**
     * Validate fields for the current wizard step.
     *
     * @param string $step
     * @param array<string, mixed> $data
     * @return array<int, string>
     */
    private function validateStepData(string $step, array $data): array
    {
        return match ($step) {
            'database' => $this->validateDatabaseStep($data),
            'communications' => $this->validateCommunicationsStorageStep($data),
            'branding' => $this->validateBrandingStep($data),
            default => [],
        };
    }

    /**
     * Perform installation and rollback local side-effects on failure.
     *
     * @param array<string, mixed> $data
     * @param array{mode:string,bannerLogo:string,externalUrl:string,uploadedPath:?string,error:?string} $logoConfig
     * @return array{ok: bool, error: ?string}
     */
    private function performInstallation(array $data, array $logoConfig): array
    {
        $envPath = (string)Configure::read('Installer.envFile', CONFIG . '.env');
        $envExisted = file_exists($envPath);
        $envOriginalContent = $envExisted ? (string)file_get_contents($envPath) : '';

        $settingsToPersist = [
            'KMP.KingdomName' => trim((string)$data['kingdom_name']),
            'KMP.LongSiteTitle' => trim((string)$data['long_site_title']),
            'KMP.ShortSiteTitle' => trim((string)$data['short_site_title']),
            'KMP.DefaultTimezone' => trim((string)$data['default_timezone']),
            'KMP.BannerLogoMode' => $logoConfig['mode'],
            'KMP.BannerLogo' => $logoConfig['bannerLogo'],
            'KMP.BannerLogoExternalUrl' => $logoConfig['externalUrl'],
            'Email.SystemEmailFromAddress' => trim((string)($data['system_email_from'] ?? '')),
        ];
        $settingsBackup = $this->captureCurrentSettingValues(array_keys($settingsToPersist));

        try {
            $dbConnectionError = $this->verifyDatabaseConfig($data);
            if ($dbConnectionError !== null) {
                throw new RuntimeException($dbConnectionError);
            }

            $envUpdates = $this->buildEnvUpdates($data);
            if (!$this->writeEnvFile($envUpdates)) {
                throw new RuntimeException((string)__('Unable to update config/.env with installer values.'));
            }

            $this->applyEnvUpdates($envUpdates);
            $this->reconfigureDatasourceConnections($envUpdates);

            $bootstrapResult = $this->runDatabaseBootstrapIfRequired();
            if ($bootstrapResult['ok'] === false) {
                throw new RuntimeException((string)($bootstrapResult['error'] ?? __('Database bootstrap failed.')));
            }

            $failedKeys = [];
            foreach ($settingsToPersist as $key => $value) {
                if (!StaticHelpers::setAppSetting($key, $value, null, true)) {
                    $failedKeys[] = $key;
                }
            }
            if ($failedKeys !== []) {
                throw new RuntimeException((string)__('Unable to save installer settings: {0}', implode(', ', $failedKeys)));
            }

            $lockWritten = $this->lockService()->writeLock([
                'version' => trim((string)Configure::read('App.version', '0.0.0')),
                'channel' => (string)StaticHelpers::getAppSetting(
                    'Updater.Channel',
                    (string)Configure::read('Updater.channel', 'stable')
                ),
            ]);
            if (!$lockWritten) {
                throw new RuntimeException((string)__('Failed to write installer lock file.'));
            }

            // Seed email templates from file-based defaults (non-fatal: logged only).
            try {
                (new EmailTemplateSyncService())->sync();
            } catch (Throwable $syncException) {
                Log::warning('Email template sync failed during install: ' . $syncException->getMessage());
            }
        } catch (Throwable $exception) {
            $this->restoreSettingValues($settingsBackup);
            $this->restoreEnvFile($envPath, $envExisted, $envOriginalContent);
            $this->removeUploadedLogoFile($logoConfig['uploadedPath'] ?? null);
            $this->lockService()->clearLock();

            return ['ok' => false, 'error' => $exception->getMessage()];
        }

        return ['ok' => true, 'error' => null];
    }

    /**
     * Validate all required installer fields.
     *
     * @param array<string, mixed> $data
     * @return array<int, string>
     */
    private function validateInstallerData(array $data): array
    {
        return array_merge(
            $this->validateDatabaseStep($data),
            $this->validateCommunicationsStorageStep($data),
            $this->validateBrandingStep($data),
        );
    }

    /**
     * Validate database-specific installer inputs.
     *
     * @param array<string, mixed> $data
     * @return array<int, string>
     */
    private function validateDatabaseStep(array $data): array
    {
        $errors = [];

        if (trim((string)($data['db_host'] ?? '')) === '') {
            $errors[] = __('Database host is required.');
        }
        if (trim((string)($data['db_name'] ?? '')) === '') {
            $errors[] = __('Database name is required.');
        }
        if (trim((string)($data['db_user'] ?? '')) === '') {
            $errors[] = __('Database username is required.');
        }

        $dbPort = trim((string)($data['db_port'] ?? '3306'));
        if ($dbPort === '' || !ctype_digit($dbPort) || (int)$dbPort <= 0) {
            $errors[] = __('Database port must be a positive integer.');
        }

        return $errors;
    }

    /**
     * Validate email and storage installer inputs.
     *
     * @param array<string, mixed> $data
     * @return array<int, string>
     */
    private function validateCommunicationsStorageStep(array $data): array
    {
        $errors = [];

        $emailPort = trim((string)($data['email_smtp_port'] ?? ''));
        if ($emailPort !== '' && !ctype_digit($emailPort)) {
            $errors[] = __('SMTP port must be numeric.');
        }

        $storageAdapter = strtolower(trim((string)($data['storage_adapter'] ?? 'local')));
        if (!in_array($storageAdapter, ['local', 'azure', 's3'], true)) {
            $errors[] = __('Select a valid storage adapter.');
        } elseif ($storageAdapter === 'azure' && trim((string)($data['azure_connection_string'] ?? '')) === '') {
            $errors[] = __('Azure storage connection string is required when adapter is Azure.');
        } elseif ($storageAdapter === 's3') {
            if (trim((string)($data['s3_bucket'] ?? '')) === '') {
                $errors[] = __('S3 bucket is required when adapter is S3.');
            }
            $region = trim((string)($data['s3_region'] ?? ''));
            if ($region === '') {
                $errors[] = __('S3 region is required when adapter is S3.');
            }
        }

        return $errors;
    }

    /**
     * Validate branding and wizard-specific inputs.
     *
     * @param array<string, mixed> $data
     * @return array<int, string>
     */
    private function validateBrandingStep(array $data): array
    {
        $errors = [];

        if (trim((string)($data['kingdom_name'] ?? '')) === '') {
            $errors[] = __('Kingdom name is required.');
        }
        if (trim((string)($data['long_site_title'] ?? '')) === '') {
            $errors[] = __('Long site title is required.');
        }
        if (trim((string)($data['short_site_title'] ?? '')) === '') {
            $errors[] = __('Short site title is required.');
        }

        $timezone = trim((string)($data['default_timezone'] ?? ''));
        if ($timezone === '' || !in_array($timezone, timezone_identifiers_list(), true)) {
            $errors[] = __('Select a valid timezone.');
        }

        $mode = strtolower(trim((string)($data['banner_logo_mode'] ?? 'packaged')));
        if (!in_array($mode, ['packaged', 'upload', 'external'], true)) {
            $errors[] = __('Select a valid logo mode.');
        }
        if ($mode === 'external' && trim((string)($data['banner_logo_external_url'] ?? '')) === '') {
            $errors[] = __('Provide a logo URL/path when using external logo mode.');
        }

        return $errors;
    }

    /**
     * Build resolved logo settings from form data.
     *
     * @param array<string, mixed> $data
     * @return array{mode:string,bannerLogo:string,externalUrl:string,uploadedPath:?string,error:?string}
     */
    private function resolveBannerLogoConfig(array $data): array
    {
        $mode = strtolower(trim((string)($data['banner_logo_mode'] ?? 'packaged')));
        $existingBanner = (string)StaticHelpers::getAppSetting('KMP.BannerLogo', 'badge.png');
        $externalUrl = trim((string)($data['banner_logo_external_url'] ?? ''));

        if ($mode === 'external') {
            if ($externalUrl === '') {
                return [
                    'mode' => $mode,
                    'bannerLogo' => $existingBanner,
                    'externalUrl' => '',
                    'uploadedPath' => null,
                    'error' => __('Provide a logo URL/path when using external logo mode.'),
                ];
            }

            return [
                'mode' => $mode,
                'bannerLogo' => $existingBanner,
                'externalUrl' => $externalUrl,
                'uploadedPath' => null,
                'error' => null,
            ];
        }

        if ($mode === 'upload') {
            $upload = $this->request->getUploadedFile('banner_logo_upload');
            if (!$upload instanceof UploadedFileInterface || $upload->getError() !== UPLOAD_ERR_OK) {
                return [
                    'mode' => $mode,
                    'bannerLogo' => $existingBanner,
                    'externalUrl' => '',
                    'uploadedPath' => null,
                    'error' => __('Upload a logo file to use upload mode.'),
                ];
            }

            $uploadResult = $this->persistBannerUpload($upload);
            if ($uploadResult['error'] !== null) {
                return [
                    'mode' => $mode,
                    'bannerLogo' => $existingBanner,
                    'externalUrl' => '',
                    'uploadedPath' => null,
                    'error' => $uploadResult['error'],
                ];
            }

            return [
                'mode' => $mode,
                'bannerLogo' => $uploadResult['path'],
                'externalUrl' => '',
                'uploadedPath' => $uploadResult['path'],
                'error' => null,
            ];
        }

        return [
            'mode' => 'packaged',
            'bannerLogo' => $existingBanner,
            'externalUrl' => '',
            'uploadedPath' => null,
            'error' => null,
        ];
    }

    /**
     * Verify database credentials and optional database creation.
     *
     * @param array<string, mixed> $data
     * @return string|null
     */
    private function verifyDatabaseConfig(array $data): ?string
    {
        $host = trim((string)($data['db_host'] ?? 'localhost'));
        $port = trim((string)($data['db_port'] ?? '3306'));
        $database = trim((string)($data['db_name'] ?? ''));
        $username = trim((string)($data['db_user'] ?? ''));
        $password = (string)($data['db_password'] ?? '');
        $createDatabase = filter_var((string)($data['db_create_database'] ?? '1'), FILTER_VALIDATE_BOOLEAN);

        try {
            $serverPdo = new PDO(
                "mysql:host={$host};port={$port};charset=utf8mb4",
                $username,
                $password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            if ($createDatabase && $database !== '') {
                $safeDatabase = str_replace('`', '``', $database);
                $serverPdo->exec("CREATE DATABASE IF NOT EXISTS `{$safeDatabase}` COLLATE utf8_unicode_ci");
            }

            if ($database !== '') {
                $dbPdo = new PDO(
                    "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
                    $username,
                    $password,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                );
                $dbPdo->query('SELECT 1');
            }
        } catch (Throwable $exception) {
            return __('Unable to connect to database: {0}', $exception->getMessage());
        }

        return null;
    }

    /**
     * Build environment updates from installer form data.
     *
     * @param array<string, mixed> $data
     * @return array<string, string>
     */
    private function buildEnvUpdates(array $data): array
    {
        return [
            'MYSQL_HOST' => trim((string)($data['db_host'] ?? 'localhost')),
            'MYSQL_PORT' => trim((string)($data['db_port'] ?? '3306')),
            'MYSQL_DB_NAME' => trim((string)($data['db_name'] ?? '')),
            'MYSQL_USERNAME' => trim((string)($data['db_user'] ?? '')),
            'MYSQL_PASSWORD' => (string)($data['db_password'] ?? ''),
            'EMAIL_SMTP_HOST' => trim((string)($data['email_smtp_host'] ?? '')),
            'EMAIL_SMTP_PORT' => trim((string)($data['email_smtp_port'] ?? '')),
            'EMAIL_SMTP_USERNAME' => trim((string)($data['email_smtp_username'] ?? '')),
            'EMAIL_SMTP_PASSWORD' => (string)($data['email_smtp_password'] ?? ''),
            'DOCUMENT_STORAGE_ADAPTER' => strtolower(trim((string)($data['storage_adapter'] ?? 'local'))),
            'AZURE_STORAGE_CONNECTION_STRING' => trim((string)($data['azure_connection_string'] ?? '')),
            'AZURE_STORAGE_CONTAINER' => trim((string)($data['azure_container'] ?? 'documents')),
            'AZURE_STORAGE_PREFIX' => trim((string)($data['azure_prefix'] ?? '')),
            'AWS_S3_BUCKET' => trim((string)($data['s3_bucket'] ?? '')),
            'AWS_DEFAULT_REGION' => trim((string)($data['s3_region'] ?? 'us-east-1')),
            'AWS_ACCESS_KEY_ID' => trim((string)($data['s3_key'] ?? '')),
            'AWS_SECRET_ACCESS_KEY' => (string)($data['s3_secret'] ?? ''),
            'AWS_SESSION_TOKEN' => trim((string)($data['s3_session_token'] ?? '')),
            'AWS_S3_PREFIX' => trim((string)($data['s3_prefix'] ?? '')),
            'AWS_S3_ENDPOINT' => trim((string)($data['s3_endpoint'] ?? '')),
            'AWS_S3_USE_PATH_STYLE_ENDPOINT' => filter_var((string)($data['s3_use_path_style_endpoint'] ?? 'false'), FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false',
        ];
    }

    /**
     * Persist installer environment updates to config/.env.
     *
     * @param array<string, string> $updates
     * @return bool
     */
    private function writeEnvFile(array $updates): bool
    {
        $envPath = (string)Configure::read('Installer.envFile', CONFIG . '.env');

        // Verify writability before attempting the write to avoid PHP E_WARNING output.
        if (file_exists($envPath)) {
            if (!is_writable($envPath)) {
                return false;
            }
        } elseif (!is_writable(CONFIG)) {
            return false;
        }

        $lines = file_exists($envPath) ? file($envPath, FILE_IGNORE_NEW_LINES) : [];
        if ($lines === false) {
            return false;
        }

        $written = [];
        foreach ($lines as $index => $line) {
            if (preg_match('/^\s*export\s+([A-Z0-9_]+)=/i', $line, $matches) === 1) {
                $key = $matches[1];
                if (array_key_exists($key, $updates)) {
                    $lines[$index] = $this->renderEnvLine($key, $updates[$key]);
                    $written[$key] = true;
                }
            }
        }

        foreach ($updates as $key => $value) {
            if (!isset($written[$key])) {
                $lines[] = $this->renderEnvLine($key, $value);
            }
        }

        return file_put_contents($envPath, implode(PHP_EOL, $lines) . PHP_EOL) !== false;
    }

    /**
     * Apply installer environment updates to current process.
     *
     * @param array<string, string> $updates
     * @return void
     */
    private function applyEnvUpdates(array $updates): void
    {
        foreach ($updates as $key => $value) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }

    /**
     * Reconfigure datasource connections using installer-provided DB values.
     *
     * @param array<string, string> $envUpdates
     * @return void
     */
    private function reconfigureDatasourceConnections(array $envUpdates): void
    {
        try {
            $defaultConfig = ConnectionManager::getConfig('default');
            $testConfig = ConnectionManager::getConfig('test');
        } catch (Throwable) {
            return;
        }

        if (is_array($defaultConfig)) {
            $defaultConfig['host'] = $envUpdates['MYSQL_HOST'] ?? $defaultConfig['host'] ?? 'localhost';
            $defaultConfig['port'] = (int)($envUpdates['MYSQL_PORT'] ?? $defaultConfig['port'] ?? 3306);
            $defaultConfig['username'] = $envUpdates['MYSQL_USERNAME'] ?? $defaultConfig['username'] ?? '';
            $defaultConfig['password'] = $envUpdates['MYSQL_PASSWORD'] ?? $defaultConfig['password'] ?? '';
            $defaultConfig['database'] = $envUpdates['MYSQL_DB_NAME'] ?? $defaultConfig['database'] ?? '';
            ConnectionManager::drop('default');
            ConnectionManager::setConfig('default', $defaultConfig);
        }

        if (is_array($testConfig)) {
            $baseDatabaseName = $envUpdates['MYSQL_DB_NAME'] ?? '';
            $testConfig['host'] = $envUpdates['MYSQL_HOST'] ?? $testConfig['host'] ?? 'localhost';
            $testConfig['port'] = (int)($envUpdates['MYSQL_PORT'] ?? $testConfig['port'] ?? 3306);
            $testConfig['username'] = $envUpdates['MYSQL_USERNAME'] ?? $testConfig['username'] ?? '';
            $testConfig['password'] = $envUpdates['MYSQL_PASSWORD'] ?? $testConfig['password'] ?? '';
            if ($baseDatabaseName !== '') {
                $testConfig['database'] = $baseDatabaseName . '_test';
            }
            ConnectionManager::drop('test');
            ConnectionManager::setConfig('test', $testConfig);
        }
    }

    /**
     * Run database bootstrap commands when installer is required.
     *
     * @return array{ok: bool, error: string|null}
     */
    private function runDatabaseBootstrapIfRequired(): array
    {
        if (!$this->lockService()->isInstallerRequired()) {
            return ['ok' => true, 'error' => null];
        }

        if (!function_exists('exec')) {
            return ['ok' => false, 'error' => __('PHP exec() is disabled; cannot run database bootstrap commands.')];
        }

        // Set APP_NAME so bootstrap.php skips dotenv re-loading.
        // The DB vars are already in the child's environment via putenv() calls above,
        // and toServer() would throw LogicException if it tries to re-define them.
        $appName = escapeshellarg((string)Configure::read('App.name', 'KMP'));
        $php = escapeshellcmd((string)PHP_BINARY);
        $root = escapeshellarg(ROOT);

        // Step 1: Run all database migrations.
        $migrateCmd = sprintf('cd %s && APP_NAME=%s %s bin/cake updateDatabase 2>&1', $root, $appName, $php);
        $output = [];
        $exitCode = 0;
        exec($migrateCmd, $output, $exitCode);
        if ($exitCode !== 0) {
            $meaningful = array_filter($output, fn($l) => !str_contains($l, 'Xdebug') && !str_contains($l, 'apcu'));
            $tail = implode(PHP_EOL, array_slice(array_values($meaningful), -20));

            return [
                'ok' => false,
                'error' => __('Database bootstrap failed: {0}', $tail !== '' ? $tail : 'unknown error'),
            ];
        }

        // Step 2: Boot the app again with tables now present so Application::bootstrap()
        // and all plugin bootstraps can seed their AppSettings into the database.
        // "cache clear_all" is a cheap no-op command; the real work is the bootstrap run.
        $seedCmd = sprintf('cd %s && APP_NAME=%s %s bin/cake cache clear_all 2>&1', $root, $appName, $php);
        exec($seedCmd, $seedOutput, $seedExit);
        // Non-zero exit here is non-fatal; seeding will also run on the first web request.

        return ['ok' => true, 'error' => null];
    }

    /**
     * Capture current setting values before installer mutation.
     *
     * @param array<int, string> $keys
     * @return array<string, array{exists: bool, value: mixed}>
     */
    private function captureCurrentSettingValues(array $keys): array
    {
        $result = [];
        foreach ($keys as $key) {
            try {
                $value = StaticHelpers::getAppSetting($key, null, null, false);
                $result[$key] = ['exists' => true, 'value' => $value];
            } catch (Throwable) {
                $result[$key] = ['exists' => false, 'value' => null];
            }
        }

        return $result;
    }

    /**
     * Restore settings from backup map.
     *
     * @param array<string, array{exists: bool, value: mixed}> $settingsBackup
     * @return void
     */
    private function restoreSettingValues(array $settingsBackup): void
    {
        foreach ($settingsBackup as $key => $entry) {
            if (($entry['exists'] ?? false) === true) {
                StaticHelpers::setAppSetting($key, $entry['value'], null, true);
            } else {
                StaticHelpers::deleteAppSetting($key, true);
            }
        }
    }

    /**
     * Restore .env file contents to pre-install state.
     *
     * @param string $envPath
     * @param bool $envExisted
     * @param string $envOriginalContent
     * @return void
     */
    private function restoreEnvFile(string $envPath, bool $envExisted, string $envOriginalContent): void
    {
        if ($envExisted) {
            @file_put_contents($envPath, $envOriginalContent);

            return;
        }

        if (file_exists($envPath)) {
            @unlink($envPath);
        }
    }

    /**
     * Remove uploaded logo file if installation fails.
     *
     * @param string|null $uploadedPath
     * @return void
     */
    private function removeUploadedLogoFile(?string $uploadedPath): void
    {
        if ($uploadedPath === null || $uploadedPath === '') {
            return;
        }

        $file = WWW_ROOT . 'img' . DS . str_replace('/', DS, $uploadedPath);
        if (is_file($file)) {
            @unlink($file);
        }
    }

    /**
     * Persist uploaded logo into webroot img/custom directory.
     *
     * @param \Psr\Http\Message\UploadedFileInterface $upload
     * @return array{path:string,error:?string}
     */
    private function persistBannerUpload(UploadedFileInterface $upload): array
    {
        $originalName = (string)$upload->getClientFilename();
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $allowedExtensions = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'];
        if (!in_array($extension, $allowedExtensions, true)) {
            return [
                'path' => '',
                'error' => __('Unsupported logo file type.'),
            ];
        }

        $destinationDir = WWW_ROOT . 'img' . DS . 'custom';
        if (!is_dir($destinationDir) && !mkdir($destinationDir, 0775, true) && !is_dir($destinationDir)) {
            return [
                'path' => '',
                'error' => __('Unable to create custom logo directory.'),
            ];
        }

        try {
            $filename = 'banner-logo-' . bin2hex(random_bytes(6)) . '.' . $extension;
        } catch (Throwable) {
            $filename = 'banner-logo-' . time() . '.' . $extension;
        }

        $destinationPath = $destinationDir . DS . $filename;
        $upload->moveTo($destinationPath);

        return [
            'path' => 'custom/' . $filename,
            'error' => null,
        ];
    }

    /**
     * Render .env export line for a key/value pair.
     *
     * @param string $key
     * @param string $value
     * @return string
     */
    private function renderEnvLine(string $key, string $value): string
    {
        $escapedValue = str_replace("'", "'\"'\"'", $value);

        return "export {$key}='{$escapedValue}'";
    }

    /**
     * Build default installer form values from env/config.
     *
     * @return array<string, string>
     */
    private function buildDefaultFormData(): array
    {
        return [
            'kingdom_name' => (string)StaticHelpers::getAppSetting('KMP.KingdomName', ''),
            'long_site_title' => (string)StaticHelpers::getAppSetting('KMP.LongSiteTitle', 'Kingdom Management Portal'),
            'short_site_title' => (string)StaticHelpers::getAppSetting('KMP.ShortSiteTitle', 'KMP'),
            'default_timezone' => (string)StaticHelpers::getAppSetting('KMP.DefaultTimezone', 'America/Chicago'),
            'banner_logo_mode' => (string)StaticHelpers::getAppSetting('KMP.BannerLogoMode', 'packaged'),
            'banner_logo_external_url' => (string)StaticHelpers::getAppSetting('KMP.BannerLogoExternalUrl', ''),
            'db_host' => (string)env('MYSQL_HOST', 'localhost'),
            'db_port' => (string)env('MYSQL_PORT', '3306'),
            'db_name' => (string)env('MYSQL_DB_NAME', ''),
            'db_user' => (string)env('MYSQL_USERNAME', ''),
            'db_password' => '',
            'db_create_database' => '1',
            'email_smtp_host' => (string)env('EMAIL_SMTP_HOST', ''),
            'email_smtp_port' => (string)env('EMAIL_SMTP_PORT', '1025'),
            'email_smtp_username' => (string)env('EMAIL_SMTP_USERNAME', ''),
            'email_smtp_password' => '',
            'system_email_from' => (string)StaticHelpers::getAppSetting('Email.SystemEmailFromAddress', (string)env('EMAIL_SMTP_USERNAME', '')),
            'storage_adapter' => (string)env('DOCUMENT_STORAGE_ADAPTER', 'local'),
            'azure_connection_string' => (string)env('AZURE_STORAGE_CONNECTION_STRING', ''),
            'azure_container' => (string)env('AZURE_STORAGE_CONTAINER', 'documents'),
            'azure_prefix' => (string)env('AZURE_STORAGE_PREFIX', ''),
            's3_bucket' => (string)env('AWS_S3_BUCKET', ''),
            's3_region' => (string)env('AWS_DEFAULT_REGION', 'us-east-1'),
            's3_key' => (string)env('AWS_ACCESS_KEY_ID', ''),
            's3_secret' => '',
            's3_session_token' => '',
            's3_prefix' => (string)env('AWS_S3_PREFIX', ''),
            's3_endpoint' => (string)env('AWS_S3_ENDPOINT', ''),
            's3_use_path_style_endpoint' => (string)env('AWS_S3_USE_PATH_STYLE_ENDPOINT', 'false'),
        ];
    }

    /**
     * Fetch installer wizard data from session.
     *
     * @return array<string, string>
     */
    private function getWizardData(): array
    {
        $state = $this->request->getSession()->read(self::WIZARD_SESSION_KEY, []);
        if (!is_array($state)) {
            return [];
        }

        $normalized = [];
        foreach ($state as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            $normalized[$key] = is_string($value) ? $value : (string)$value;
        }

        return $normalized;
    }

    /**
     * Clear wizard session data.
     *
     * @return void
     */
    private function clearWizardData(): void
    {
        $this->request->getSession()->delete(self::WIZARD_SESSION_KEY);
    }

    /**
     * Resolve wizard step against allowed list.
     *
     * @param string $step
     * @return string
     */
    private function resolveWizardStep(string $step): string
    {
        $normalized = strtolower(trim($step));
        if (!in_array($normalized, self::WIZARD_STEPS, true)) {
            return self::WIZARD_STEPS[0];
        }

        return $normalized;
    }

    /**
     * Build wizard step metadata for view rendering.
     *
     * @return array<int, array{key:string,label:string,index:int}>
     */
    private function buildWizardSteps(): array
    {
        $labels = [
            'preflight' => 'Preflight',
            'database' => 'Database',
            'communications' => 'Email & Storage',
            'branding' => 'Branding & Finalize',
        ];

        $steps = [];
        foreach (self::WIZARD_STEPS as $index => $key) {
            $steps[] = [
                'key' => $key,
                'label' => $labels[$key] ?? ucfirst($key),
                'index' => $index + 1,
            ];
        }

        return $steps;
    }

    /**
     * Get installer lock service singleton.
     *
     * @return \App\Services\Installer\InstallerLockService
     */
    private function lockService(): InstallerLockService
    {
        if ($this->installerLockService === null) {
            $this->installerLockService = new InstallerLockService();
        }

        return $this->installerLockService;
    }
}
