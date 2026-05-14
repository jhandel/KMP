<?php
declare(strict_types=1);

namespace App\Controller;

use App\Model\Entity\PlatformAdmin;
use App\Model\Entity\Tenant;
use App\Services\BackupService;
use App\Services\BackupStorageService;
use App\Services\Platform\PlatformAdminAuthService;
use App\Services\Platform\PlatformAuditService;
use App\Services\Platform\PlatformRuntimeConfigService;
use App\Services\Platform\PlatformSecretService;
use App\Services\Tenant\TenantConnectionFactory;
use App\Services\Tenant\TenantContext;
use App\Services\Tenant\TenantMigrationService;
use App\Services\Tenant\TenantProvisioningService;
use Cake\Controller\Controller;
use Cake\Event\EventInterface;
use Cake\Http\Cookie\Cookie;
use Cake\Http\Response;
use Cake\I18n\DateTime;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;
use Throwable;

/**
 * Host-isolated platform tenant onboarding console.
 */
class PlatformAdminController extends Controller
{
    private const COOKIE_NAME = '__Host-KMPPlatformAdmin';

    /**
     * Initialize platform admin controller dependencies.
     *
     * @return void
     */
    public function initialize(): void
    {
        parent::initialize();
        $this->loadComponent('Authorization.Authorization');
        $this->Authorization->skipAuthorization();
        $this->loadComponent('Flash');
        $this->viewBuilder()->setLayout('platform_admin');
        (new PlatformRuntimeConfigService())->applyEmailConfig();
    }

    /**
     * Force first-login password rotation before allowing console access.
     *
     * @param \Cake\Event\EventInterface $event Event
     * @return \Cake\Http\Response|null
     */
    public function beforeFilter(EventInterface $event): ?Response
    {
        $response = parent::beforeFilter($event);
        if ($response instanceof Response) {
            return $response;
        }
        $action = (string)$this->request->getParam('action');
        if (in_array($action, ['login', 'logout', 'changePassword'], true)) {
            return null;
        }
        $admin = $this->platformAdmin();
        if ($admin instanceof PlatformAdmin && (bool)$admin->require_password_change) {
            $this->Flash->warning(__('Change your platform admin password before continuing.'));

            return $this->redirect(['action' => 'changePassword']);
        }

        return null;
    }

    /**
     * Authenticate a platform admin.
     *
     * @return \Cake\Http\Response|null
     */
    public function login(): ?Response
    {
        if ($this->platformAdmin() !== null) {
            return $this->redirect(['action' => 'index']);
        }
        if ($this->request->is('post')) {
            $auth = new PlatformAdminAuthService();
            $audit = new PlatformAuditService();
            try {
                $token = $auth->authenticate(
                    (string)$this->request->getData('email'),
                    (string)$this->request->getData('password'),
                    (string)$this->request->getData('mfa_code'),
                    $this->request,
                );
                $admin = $auth->adminFromToken($token);
                if ($admin instanceof PlatformAdmin) {
                    $audit->record('platform_admin.login', 'success', [], $admin, $this->request);
                }

                return $this->redirect(['action' => 'index'])
                    ->withCookie($this->sessionCookie($token));
            } catch (Throwable $e) {
                $audit->record(
                    'platform_admin.login',
                    'failure',
                    ['email' => (string)$this->request->getData('email')],
                    null,
                    $this->request,
                );
                $this->Flash->error(__('Invalid platform admin credentials.'));
            }
        }

        return null;
    }

    /**
     * Revoke the active platform admin session.
     *
     * @return \Cake\Http\Response
     */
    public function logout(): Response
    {
        $admin = $this->requirePlatformAdmin();
        if ($admin === null) {
            return $this->redirect(['action' => 'login']);
        }
        (new PlatformAdminAuthService())->logout($this->request->getCookie(self::COOKIE_NAME));
        (new PlatformAuditService())->record('platform_admin.logout', 'success', [], $admin, $this->request);

        return $this->redirect(['action' => 'login'])
            ->withExpiredCookie(new Cookie(self::COOKIE_NAME));
    }

    /**
     * Change a platform admin password and rotate one-time codes.
     *
     * @return \Cake\Http\Response|null
     */
    public function changePassword(): ?Response
    {
        $admin = $this->requirePlatformAdmin();
        if ($admin === null) {
            return $this->redirect(['action' => 'login']);
        }
        $recoveryCodes = [];
        if ($this->request->is('post')) {
            try {
                $newPassword = (string)$this->request->getData('new_password');
                $confirmPassword = (string)$this->request->getData('confirm_password');
                if ($newPassword !== $confirmPassword) {
                    throw new RuntimeException('New password confirmation does not match.');
                }
                $recoveryCodes = (new PlatformAdminAuthService())->changePassword(
                    $admin,
                    (string)$this->request->getData('current_password'),
                    $newPassword,
                );
                (new PlatformAuditService())->record(
                    'platform_admin.password_change',
                    'success',
                    [],
                    $admin,
                    $this->request,
                );
                $this->response = $this->response->withExpiredCookie(new Cookie(self::COOKIE_NAME));
                $this->Flash->success(__('Password changed. Sign in again with one of your new one-time codes.'));
            } catch (Throwable $e) {
                (new PlatformAuditService())->record(
                    'platform_admin.password_change',
                    'failure',
                    ['error' => $e->getMessage()],
                    $admin,
                    $this->request,
                );
                $this->Flash->error($e->getMessage());
            }
        }
        $this->set(compact('admin', 'recoveryCodes'));

        return null;
    }

    /**
     * Show platform dashboard with tenants and recent operations.
     *
     * @return void
     */
    public function index(): ?Response
    {
        $admin = $this->requirePlatformAdmin();
        if ($admin === null) {
            return $this->redirect(['action' => 'login']);
        }
        $tenants = (new TenantProvisioningService())->listTenants();
        $jobs = $this->fetchTable('TenantOperationJobs')->find()
            ->contain(['Tenants', 'PlatformAdmins'])
            ->orderByDesc('TenantOperationJobs.created')
            ->limit(10)
            ->all()
            ->toList();

        $this->set(compact('admin', 'tenants', 'jobs'));

        return null;
    }

    /**
     * Show tenant details, doctor checks, backups, and operations.
     *
     * @param string $slug Tenant slug
     * @return void
     */
    public function viewTenant(string $slug): ?Response
    {
        $admin = $this->requirePlatformAdmin();
        if ($admin === null) {
            return $this->redirect(['action' => 'login']);
        }
        $service = new TenantProvisioningService();
        $tenant = $service->getTenant($slug);
        $doctor = $service->doctor($tenant);
        $jobs = $this->fetchTable('TenantOperationJobs')->find()
            ->where(['tenant_id' => $tenant->id])
            ->orderByDesc('created')
            ->limit(10)
            ->all()
            ->toList();
        $backups = [];
        try {
            $this->configureTenant($tenant);
            $backups = $this->fetchTable('Backups')->find()
                ->orderByDesc('created')
                ->limit(10)
                ->all()
                ->toList();
        } finally {
            TenantContext::clearCurrent();
        }

        $this->set(compact('admin', 'tenant', 'doctor', 'jobs', 'backups'));

        return null;
    }

    /**
     * Create or update a tenant from the platform console.
     *
     * @return \Cake\Http\Response|null
     */
    public function createTenant(): ?Response
    {
        $admin = $this->requirePlatformAdmin();
        if ($admin === null) {
            return $this->redirect(['action' => 'login']);
        }
        if ($this->request->is('post')) {
            try {
                $this->assertManagedSecretsReadyForRequest();
                $this->verifyAction($admin);
                $data = $this->tenantPayloadFromRequest();
                $service = new TenantProvisioningService();
                if ((bool)$this->request->getData('create_database')) {
                    $service->createPhysicalDatabase($data);
                }
                $tenant = $service->createOrUpdateTenant($data);
                $this->storeTenantSecrets($admin, $tenant);
                $tenant = $service->getTenant((string)$tenant->slug);
                if ((bool)$this->request->getData('migrate')) {
                    $service->migrateTenant($tenant);
                    $tenant = $service->getTenant((string)$tenant->slug);
                }
                if ((bool)$this->request->getData('activate')) {
                    $tenant = $service->setStatus((string)$tenant->slug, Tenant::STATUS_ACTIVE);
                }
                $this->recordJob($admin, $tenant, 'tenant_create', 'completed', $data);
                (new PlatformAuditService())->record(
                    'tenant.create',
                    'success',
                    ['subject_type' => 'tenant', 'subject_id' => (string)$tenant->id],
                    $admin,
                    $this->request,
                    (int)$tenant->id,
                );
                $this->Flash->success(__('Tenant {0} is ready.', $tenant->slug));

                return $this->redirect(['action' => 'viewTenant', $tenant->slug]);
            } catch (Throwable $e) {
                (new PlatformAuditService())->record(
                    'tenant.create',
                    'failure',
                    ['error' => $e->getMessage()],
                    $admin,
                    $this->request,
                );
                $this->Flash->error($e->getMessage());
            }
        }

        return null;
    }

    /**
     * Set tenant lifecycle status after action verification.
     *
     * @param string $slug Tenant slug
     * @param string $status New status
     * @return \Cake\Http\Response
     */
    public function setTenantStatus(string $slug, string $status): Response
    {
        $admin = $this->requirePlatformAdmin();
        if ($admin === null) {
            return $this->redirect(['action' => 'login']);
        }
        $this->request->allowMethod(['post']);
        try {
            $this->verifyAction($admin);
            $tenant = (new TenantProvisioningService())->setStatus($slug, $status);
            $this->recordJob($admin, $tenant, 'tenant_status', 'completed', ['status' => $status]);
            (new PlatformAuditService())->record(
                'tenant.status',
                'success',
                ['subject_type' => 'tenant', 'subject_id' => (string)$tenant->id, 'status' => $status],
                $admin,
                $this->request,
                (int)$tenant->id,
            );
            $this->Flash->success(__('Tenant status updated.'));
        } catch (Throwable $e) {
            $this->Flash->error($e->getMessage());
        }

        return $this->redirect(['action' => 'viewTenant', $slug]);
    }

    /**
     * Store or replace tenant managed secrets.
     *
     * @param string $slug Tenant slug
     * @return \Cake\Http\Response
     */
    public function updateTenantSecrets(string $slug): Response
    {
        $admin = $this->requirePlatformAdmin();
        if ($admin === null) {
            return $this->redirect(['action' => 'login']);
        }
        $this->request->allowMethod(['post']);
        try {
            $this->assertManagedSecretsReadyForRequest();
            $this->verifyAction($admin);
            $tenant = (new TenantProvisioningService())->getTenant($slug);
            $changed = $this->storeTenantSecrets($admin, $tenant);
            if ($changed === []) {
                $this->Flash->info(__('No secret values were provided.'));
            } else {
                (new PlatformAuditService())->record(
                    'tenant.secret_update',
                    'success',
                    [
                        'subject_type' => 'tenant',
                        'subject_id' => (string)$tenant->id,
                        'secrets' => $changed,
                    ],
                    $admin,
                    $this->request,
                    (int)$tenant->id,
                );
                $this->Flash->success(__('Tenant secrets updated.'));
            }
        } catch (Throwable $e) {
            (new PlatformAuditService())->record(
                'tenant.secret_update',
                'failure',
                ['error' => $e->getMessage(), 'subject_id' => $slug],
                $admin,
                $this->request,
            );
            $this->Flash->error($e->getMessage());
        }

        return $this->redirect(['action' => 'viewTenant', $slug]);
    }

    /**
     * Create an encrypted backup for the selected tenant.
     *
     * @param string $slug Tenant slug
     * @return \Cake\Http\Response
     */
    public function createBackup(string $slug): Response
    {
        $admin = $this->requirePlatformAdmin();
        if ($admin === null) {
            return $this->redirect(['action' => 'login']);
        }
        $this->request->allowMethod(['post']);
        try {
            $this->verifyAction($admin);
            $tenant = (new TenantProvisioningService())->getTenant($slug);
            $this->configureTenant($tenant);
            $key = (string)$this->request->getData('backup_key');
            if ($key === '') {
                throw new RuntimeException('Backup encryption key is required.');
            }
            $storage = new BackupStorageService();
            $backupService = new BackupService();
            $backupsTable = $this->fetchTable('Backups');
            $filename = $storage->buildBackupFilename();
            $backup = $backupsTable->newEntity([
                'filename' => $filename,
                'storage_type' => $storage->getAdapterType(),
                'status' => 'running',
            ]);
            $backupsTable->saveOrFail($backup);
            $result = $backupService->export($key);
            $storage->write($filename, $result['data']);
            $backup->size_bytes = $result['meta']['size_bytes'];
            $backup->table_count = $result['meta']['table_count'];
            $backup->row_count = $result['meta']['row_count'];
            $backup->status = 'completed';
            $backupsTable->saveOrFail($backup);
            $this->recordJob($admin, $tenant, 'tenant_backup', 'completed', ['filename' => $filename]);
            (new PlatformAuditService())->record(
                'tenant.backup',
                'success',
                ['subject_type' => 'backup', 'subject_id' => (string)$backup->id, 'filename' => $filename],
                $admin,
                $this->request,
                (int)$tenant->id,
            );
            $this->Flash->success(__('Backup created: {0}', $filename));
        } catch (Throwable $e) {
            (new PlatformAuditService())->record(
                'tenant.backup',
                'failure',
                ['error' => $e->getMessage(), 'subject_id' => $slug],
                $admin,
                $this->request,
            );
            $this->Flash->error($e->getMessage());
        } finally {
            TenantContext::clearCurrent();
        }

        return $this->redirect(['action' => 'viewTenant', $slug]);
    }

    /**
     * Restore a backup into a new tenant database and cut over after success.
     *
     * @param string $slug Tenant slug
     * @return \Cake\Http\Response
     */
    public function restoreBackup(string $slug): Response
    {
        $admin = $this->requirePlatformAdmin();
        if ($admin === null) {
            return $this->redirect(['action' => 'login']);
        }
        $this->request->allowMethod(['post']);
        try {
            $this->verifyAction($admin);
            $tenant = (new TenantProvisioningService())->getTenant($slug);
            $newDatabase = trim((string)$this->request->getData('new_database_name'));
            if ($newDatabase === '' || !preg_match('/^[A-Za-z0-9_]+$/', $newDatabase)) {
                throw new RuntimeException('A simple alphanumeric restore database name is required.');
            }
            $primaryConfig = $this->primaryDatabaseConfig($tenant);
            if ($newDatabase === (string)$primaryConfig->database_name) {
                throw new RuntimeException('Restore database must be different from the current tenant database.');
            }
            $uploadedFile = $this->request->getData('backup_file');
            if (!$uploadedFile instanceof UploadedFileInterface || $uploadedFile->getError() !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Choose a valid backup file.');
            }
            $stream = $uploadedFile->getStream();
            $stream->rewind();
            $backupBytes = $stream->getContents();
            $key = (string)$this->request->getData('restore_key');
            if ($key === '') {
                throw new RuntimeException('Restore encryption key is required.');
            }

            $this->createRestoreDatabase($primaryConfig, $newDatabase);
            $this->configureRestoreTenant($tenant, $newDatabase);
            (new TenantMigrationService())->migrate('tenant');
            $result = (new BackupService())->import($backupBytes, $key);

            $primaryConfig->database_name = $newDatabase;
            $this->fetchTable('TenantDatabaseConfigs')->saveOrFail($primaryConfig);
            $this->recordJob($admin, $tenant, 'tenant_restore_cutover', 'completed', [
                'new_database_name' => $newDatabase,
                'row_count' => $result['row_count'],
            ]);
            (new PlatformAuditService())->record(
                'tenant.restore',
                'success',
                ['subject_type' => 'tenant', 'subject_id' => (string)$tenant->id, 'new_database_name' => $newDatabase],
                $admin,
                $this->request,
                (int)$tenant->id,
            );
            $this->Flash->success(__('Restore completed and tenant database cut over to {0}.', $newDatabase));
        } catch (Throwable $e) {
            (new PlatformAuditService())->record(
                'tenant.restore',
                'failure',
                ['error' => $e->getMessage(), 'subject_id' => $slug],
                $admin,
                $this->request,
            );
            $this->Flash->error($e->getMessage());
        } finally {
            TenantContext::clearCurrent();
        }

        return $this->redirect(['action' => 'viewTenant', $slug]);
    }

    /**
     * Display platform audit events.
     *
     * @return void
     */
    public function audit(): ?Response
    {
        $admin = $this->requirePlatformAdmin();
        if ($admin === null) {
            return $this->redirect(['action' => 'login']);
        }
        $events = $this->paginate($this->fetchTable('PlatformAuditEvents')->find()
            ->contain(['PlatformAdmins', 'Tenants'])
            ->orderByDesc('PlatformAuditEvents.created'));

        $this->set(compact('admin', 'events'));

        return null;
    }

    /**
     * Resolve platform admin from secure cookie.
     *
     * @return \App\Model\Entity\PlatformAdmin|null
     */
    private function platformAdmin(): ?PlatformAdmin
    {
        return (new PlatformAdminAuthService())->adminFromToken($this->request->getCookie(self::COOKIE_NAME));
    }

    /**
     * Require an authenticated platform admin.
     *
     * @return \App\Model\Entity\PlatformAdmin|null
     */
    private function requirePlatformAdmin(): ?PlatformAdmin
    {
        $admin = $this->platformAdmin();
        if (!$admin instanceof PlatformAdmin) {
            return null;
        }

        return $admin;
    }

    /**
     * Verify high-risk action with password plus one-time code.
     *
     * @param \App\Model\Entity\PlatformAdmin $admin Platform admin
     * @return void
     */
    private function verifyAction(PlatformAdmin $admin): void
    {
        (new PlatformAdminAuthService())->verifyAction(
            $admin,
            (string)$this->request->getData('verify_password'),
            (string)$this->request->getData('verify_mfa_code'),
        );
    }

    /**
     * Fail before consuming one-time action codes if submitted managed secrets cannot be stored.
     *
     * @return void
     */
    private function assertManagedSecretsReadyForRequest(): void
    {
        foreach (['database_secret_value', 'email_secret_value', 'storage_secret_value'] as $field) {
            if ((string)$this->request->getData($field, '') !== '') {
                (new PlatformSecretService())->assertReady();

                return;
            }
        }
    }

    /**
     * Configure tenant datasource for a platform operation.
     *
     * @param \App\Model\Entity\Tenant $tenant Tenant
     * @return void
     */
    private function configureTenant(Tenant $tenant): void
    {
        (new TenantConnectionFactory())->configure(TenantContext::fromTenant(
            $tenant,
            (string)($tenant->primary_host ?? $tenant->slug),
        ));
    }

    /**
     * Configure tenant context for a restore target database.
     *
     * @param \App\Model\Entity\Tenant $tenant Tenant
     * @param string $databaseName Restore database name
     * @return void
     */
    private function configureRestoreTenant(Tenant $tenant, string $databaseName): void
    {
        $context = TenantContext::fromTenant($tenant, (string)($tenant->primary_host ?? $tenant->slug));
        $configs = $context->databaseConfigs;
        foreach ($configs as &$config) {
            if (($config['connectionRole'] ?? 'primary') === 'primary') {
                $config['databaseName'] = $databaseName;
            }
        }
        unset($config);
        (new TenantConnectionFactory())->configure(new TenantContext(
            $context->id,
            $context->slug,
            $context->displayName,
            $context->status,
            $context->schemaVersion,
            $context->primaryHost,
            $context->resolvedHost,
            $configs,
            $context->serviceConfigs,
        ));
    }

    /**
     * Get the active primary database config.
     *
     * @param \App\Model\Entity\Tenant $tenant Tenant
     * @return \Cake\Datasource\EntityInterface
     */
    private function primaryDatabaseConfig(Tenant $tenant)
    {
        foreach ((array)($tenant->tenant_database_configs ?? []) as $config) {
            if ((string)$config->connection_role === 'primary' && (bool)$config->is_active) {
                return $config;
            }
        }

        throw new RuntimeException('Tenant does not have an active primary database config.');
    }

    /**
     * Create restore database from the primary database config.
     *
     * @param mixed $primaryConfig Primary config entity
     * @param string $databaseName Restore database name
     * @return void
     */
    private function createRestoreDatabase($primaryConfig, string $databaseName): void
    {
        (new TenantProvisioningService())->createPhysicalDatabase([
            'database_name' => $databaseName,
            'driver' => $primaryConfig->driver,
        ]);
    }

    /**
     * Build secure platform admin session cookie.
     *
     * @param string $token Opaque session token
     * @return \Cake\Http\Cookie\Cookie
     */
    private function sessionCookie(string $token): Cookie
    {
        return (new Cookie(self::COOKIE_NAME, $token, DateTime::now()->addHours(8)))
            ->withSecure(true)
            ->withHttpOnly(true)
            ->withPath('/')
            ->withSameSite('Strict');
    }

    /**
     * @return array<string, mixed>
     */
    private function tenantPayloadFromRequest(): array
    {
        $emailJson = (string)$this->request->getData('email_config_json', '');
        $storageJson = (string)$this->request->getData('storage_config_json', '');

        return [
            'slug' => $this->request->getData('slug'),
            'display_name' => $this->request->getData('display_name'),
            'status' => Tenant::STATUS_PROVISIONING,
            'primary_host' => $this->request->getData('primary_host'),
            'aliases' => array_filter(array_map('trim', explode("\n", (string)$this->request->getData('aliases', '')))),
            'database_name' => $this->request->getData('database_name'),
            'database_url' => null,
            'driver' => $this->request->getData('driver'),
            'host' => $this->request->getData('database_host'),
            'port' => $this->request->getData('database_port'),
            'username' => $this->request->getData('database_username'),
            'secret_reference' => $this->request->getData('database_secret_reference'),
            'password_env' => null,
            'email_config_json' => $emailJson !== '' ? $emailJson : null,
            'email_secret_reference' => $this->request->getData('email_secret_reference'),
            'storage_config_json' => $storageJson !== '' ? $storageJson : null,
            'storage_adapter' => $this->request->getData('storage_adapter'),
            'storage_secret_reference' => $this->request->getData('storage_secret_reference'),
        ];
    }

    /**
     * Store raw tenant secrets submitted through the platform console.
     *
     * @return array<int, string> Changed secret labels
     */
    private function storeTenantSecrets(PlatformAdmin $admin, Tenant $tenant): array
    {
        $secretService = new PlatformSecretService();
        $changed = [];
        $tenantId = (int)$tenant->id;

        $databaseSecret = (string)$this->request->getData('database_secret_value', '');
        if ($databaseSecret !== '') {
            $reference = $secretService->storeSecret(
                sprintf('tenant/%d/database/primary', $tenantId),
                $databaseSecret,
                sprintf('Primary database password for tenant %s', $tenant->slug),
                $admin,
            );
            $this->fetchTable('TenantDatabaseConfigs')->updateAll(
                ['secret_reference' => $reference],
                ['tenant_id' => $tenantId, 'connection_role' => 'primary'],
            );
            $changed[] = 'database';
        }

        $emailSecret = (string)$this->request->getData('email_secret_value', '');
        if ($emailSecret !== '') {
            $reference = $secretService->storeSecret(
                sprintf('tenant/%d/email/default', $tenantId),
                $emailSecret,
                sprintf('Email password for tenant %s', $tenant->slug),
                $admin,
            );
            $this->upsertTenantServiceSecretReference($tenant, 'email', null, $reference);
            $changed[] = 'email';
        }

        $storageSecret = (string)$this->request->getData('storage_secret_value', '');
        if ($storageSecret !== '') {
            $reference = $secretService->storeSecret(
                sprintf('tenant/%d/storage/default', $tenantId),
                $storageSecret,
                sprintf('Storage secret for tenant %s', $tenant->slug),
                $admin,
            );
            $this->upsertTenantServiceSecretReference(
                $tenant,
                'storage',
                (string)$this->request->getData('storage_adapter', ''),
                $reference,
            );
            $changed[] = 'storage';
        }

        return $changed;
    }

    /**
     * Ensure a tenant service config exists and points at a managed secret.
     */
    private function upsertTenantServiceSecretReference(
        Tenant $tenant,
        string $serviceName,
        ?string $adapter,
        string $reference,
    ): void {
        $configs = $this->fetchTable('TenantServiceConfigs');
        $config = $configs->find()->where([
            'tenant_id' => $tenant->id,
            'service_name' => $serviceName,
            'config_key' => 'default',
        ])->first();
        $payload = [
            'tenant_id' => $tenant->id,
            'service_name' => $serviceName,
            'config_key' => 'default',
            'secret_reference' => $reference,
            'is_active' => true,
        ];
        if ($adapter !== null && $adapter !== '') {
            $payload['adapter'] = $adapter;
        }
        if ($config === null) {
            $payload['metadata'] = '{}';
        }
        $config = $config === null ? $configs->newEntity($payload) : $configs->patchEntity($config, $payload);
        $configs->saveOrFail($config);
    }

    /**
     * @param array<string, mixed> $input Job input
     */
    private function recordJob(
        PlatformAdmin $admin,
        ?Tenant $tenant,
        string $operation,
        string $status,
        array $input,
    ): void {
        $jobs = $this->fetchTable('TenantOperationJobs');
        $jobs->saveOrFail($jobs->newEntity([
            'tenant_id' => $tenant?->id,
            'platform_admin_id' => $admin->id,
            'operation' => $operation,
            'status' => $status,
            'input' => $input,
            'completed_at' => DateTime::now(),
        ]));
    }
}
