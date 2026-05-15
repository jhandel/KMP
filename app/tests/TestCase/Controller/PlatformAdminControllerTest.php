<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Model\Entity\PlatformAdmin;
use App\Model\Entity\Tenant;
use App\Model\Entity\TenantOperationJob;
use App\Services\Platform\PlatformAdminAuthService;
use App\Services\Platform\PlatformAuditService;
use App\Services\Tenant\TenantContext;
use Cake\Core\Configure;
use Cake\Http\ServerRequest;
use Cake\I18n\DateTime;
use Cake\Mailer\Mailer;
use Cake\Mailer\TransportFactory;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use Cake\Utility\Text;
use Migrations\Migrations;

class PlatformAdminControllerTest extends TestCase
{
    use IntegrationTestTrait;

    private string|false $originalPlatformSecretKey;

    /**
     * @var array<int, string>
     */
    private array $originalPlatformAdminHosts = [];

    /**
     * @var array<int, string>
     */
    private array $originalPlatformRedirectHosts = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalPlatformSecretKey = getenv('PLATFORM_SECRET_KEY');
        $this->originalPlatformAdminHosts = (array)Configure::read('PlatformAdmin.hosts');
        $this->originalPlatformRedirectHosts = (array)Configure::read('PlatformAdmin.redirectFromHosts');
        Configure::write('PlatformAdmin.hosts', ['admin.localhost']);
        Configure::write('PlatformAdmin.redirectFromHosts', ['localhost', '127.0.0.1']);
        TenantContext::clearCurrent();
        $this->setPlatformSecretKey('test-platform-secret-key-32-chars-minimum');
        (new Migrations())->migrate([
            'connection' => 'test',
            'source' => 'PlatformMigrations',
        ]);
        $this->configureDebugEmail();
        $this->getTableLocator()->get('PlatformServiceConfigs')->deleteAll([]);
        $this->getTableLocator()->get('PlatformSecrets')->deleteAll([]);
        $this->getTableLocator()->get('PlatformAdminSessions')->deleteAll([]);
        $this->getTableLocator()->get('PlatformAdminEmailCodes')->deleteAll([]);
        $this->getTableLocator()->get('PlatformAdminRecoveryCodes')->deleteAll([]);
        $this->getTableLocator()->get('TenantOperationApprovals')->deleteAll([]);
        $this->getTableLocator()->get('TenantOperationJobs')->deleteAll([]);
        $this->getTableLocator()->get('TenantDatabaseConfigs')->deleteAll([]);
        $this->getTableLocator()->get('TenantServiceConfigs')->deleteAll([]);
        $this->getTableLocator()->get('TenantAliases')->deleteAll([]);
        $this->getTableLocator()->get('Tenants')->deleteAll([]);
        $this->getTableLocator()->get('PlatformAdmins')->deleteAll(['email LIKE' => '%@platform.test']);
    }

    protected function tearDown(): void
    {
        if ($this->originalPlatformSecretKey === false) {
            putenv('PLATFORM_SECRET_KEY');
            unset($_ENV['PLATFORM_SECRET_KEY'], $_SERVER['PLATFORM_SECRET_KEY']);
        } else {
            $this->setPlatformSecretKey($this->originalPlatformSecretKey);
        }
        Configure::write('PlatformAdmin.hosts', $this->originalPlatformAdminHosts);
        Configure::write('PlatformAdmin.redirectFromHosts', $this->originalPlatformRedirectHosts);
        parent::tearDown();
    }

    public function testLocalTenantHostRedirectsPlatformAdminRoutesToAdminHost(): void
    {
        $this->configRequest(['headers' => ['Host' => 'localhost']]);

        $this->get('/platform-admin/login');

        $this->assertRedirectContains('http://admin.localhost/platform-admin/login');
    }

    public function testAdminHostCanAccessLogin(): void
    {
        $this->configRequest(['headers' => ['Host' => 'admin.localhost']]);

        $this->get('/platform-admin/login');

        $this->assertResponseOk();
        $this->assertResponseContains('Platform Admin Login');
    }

    public function testAdminHostRootRedirectsToConsole(): void
    {
        $this->configRequest(['headers' => ['Host' => 'admin.localhost']]);

        $this->get('/');

        $this->assertRedirectContains('/platform-admin');
    }

    public function testFirstLoginAdminMustChangePasswordBeforeConsoleAccess(): void
    {
        $login = $this->createAuthenticatedAdmin(
            'first-login@platform.test',
            'First Login',
            'InitialPlatformPassword!',
            true,
        );
        $this->configRequest([
            'headers' => ['Host' => 'admin.localhost'],
            'cookies' => ['__Host-KMPPlatformAdmin' => $login['token']],
        ]);

        $this->get('/platform-admin');

        $this->assertRedirectContains('/platform-admin/change-password');
    }

    public function testChangePasswordPageIsAvailableDuringFirstLoginRotation(): void
    {
        $login = $this->createAuthenticatedAdmin(
            'rotate@platform.test',
            'Rotate Login',
            'InitialPlatformPassword!',
            true,
        );
        $this->configRequest([
            'headers' => ['Host' => 'admin.localhost'],
            'cookies' => ['__Host-KMPPlatformAdmin' => $login['token']],
        ]);

        $this->get('/platform-admin/change-password');

        $this->assertResponseOk();
        $this->assertResponseContains('This account must choose a new password');
    }

    public function testCreateTenantSecretConfigFailureDoesNotConsumeActionCode(): void
    {
        $login = $this->createAuthenticatedAdmin(
            'tenant-create@platform.test',
            'Tenant Create',
            'InitialPlatformPassword!',
        );
        $service = new PlatformAdminAuthService();
        $challenge = $service->requestActionCode(
            $login['admin'],
            new ServerRequest(['url' => '/platform-admin/action-code']),
            'Create tenant',
        );
        $this->setEmailCode((int)$challenge['challengeId'], '123456');
        $this->clearPlatformSecretKey();
        $this->configRequest([
            'headers' => ['Host' => 'admin.localhost'],
            'cookies' => ['__Host-KMPPlatformAdmin' => $login['token']],
        ]);
        $this->session([
            'PlatformAdmin' => [
                'actionChallengeIds' => [
                    'Create or update tenant' => $challenge['challengeId'],
                ],
            ],
        ]);
        $this->enableCsrfToken();
        $this->enableSecurityToken();

        $this->post('/platform-admin/tenants/create', [
            'slug' => 'missing-secret-key',
            'display_name' => 'Missing Secret Key',
            'primary_host' => 'missing-secret-key.localhost',
            'driver' => 'Cake\Database\Driver\Mysql',
            'database_host' => 'localhost',
            'database_name' => 'missing_secret_key',
            'database_username' => 'tenant_user',
            'database_secret_value' => 'tenant-db-secret',
            'migrate' => '0',
            'activate' => '0',
            'verify_password' => 'InitialPlatformPassword!',
            'verify_email_code' => '123456',
        ]);

        $this->assertResponseOk();
        $this->assertSame(
            1,
            $this->getTableLocator()->get('PlatformAdminEmailCodes')->find()
                ->where([
                    'platform_admin_id' => $login['admin']->id,
                    'purpose' => 'action',
                    'used_at IS' => null,
                ])
                ->count(),
        );
    }

    public function testLoginPasswordStepEmailsCodeWithoutCreatingSessionCookie(): void
    {
        (new PlatformAdminAuthService())->createAdmin(
            'browser-login@platform.test',
            'Browser Login',
            'InitialPlatformPassword!',
        );
        $this->configRequest(['headers' => ['Host' => 'admin.localhost']]);
        $this->enableCsrfToken();
        $this->enableSecurityToken();

        $this->post('/platform-admin/login', [
            'email' => 'browser-login@platform.test',
            'password' => 'InitialPlatformPassword!',
        ]);

        $this->assertResponseOk();
        $this->assertResponseContains('Enter the verification code emailed to browser-login@platform.test.');
        $this->assertResponseContains('name="email_code"');
        $this->assertResponseNotContains('After your password is verified');
        $this->assertSame(0, $this->getTableLocator()->get('PlatformAdminSessions')->find()->count());
        $this->assertSame(1, $this->getTableLocator()->get('PlatformAdminEmailCodes')->find()
            ->where(['purpose' => 'login', 'used_at IS' => null])
            ->count());
    }

    public function testAuditPageShowsOperationLinksForCorrelationFilter(): void
    {
        $login = $this->createAuthenticatedAdmin(
            'audit-link@platform.test',
            'Audit Link',
            'InitialPlatformPassword!',
        );
        $tenants = $this->getTableLocator()->get('Tenants');
        $tenant = $tenants->newEntity([
            'slug' => 'audit-link-' . uniqid(),
            'display_name' => 'Audit Link Tenant',
            'status' => 'active',
            'primary_host' => 'audit-link.localhost',
        ]);
        $tenants->saveOrFail($tenant);
        $jobs = $this->getTableLocator()->get('TenantOperationJobs');
        $job = $jobs->newEntity([
            'tenant_id' => (int)$tenant->id,
            'platform_admin_id' => (int)$login['admin']->id,
            'operation' => 'tenant_status',
            'state' => 'completed',
            'status' => 'completed',
            'idempotency_scope' => 'tenant',
            'idempotency_key' => 'audit-link-' . uniqid(),
            'input' => ['status' => 'active'],
            'operation_correlation_id' => 'corr-audit-link-1',
        ]);
        $jobs->saveOrFail($job);

        (new PlatformAuditService())->record(
            'tenant.status',
            'success',
            ['subject_type' => 'tenant', 'subject_id' => (string)$tenant->id],
            $login['admin'],
            (new ServerRequest(['url' => '/platform-admin/audit']))->withAttribute('requestId', 'req-audit-link-1'),
            (int)$tenant->id,
            'platform_admin',
            ['tenant_slug' => (string)$tenant->slug, 'operation_id' => (string)$job->id, 'correlation_id' => 'corr-audit-link-1'],
        );
        (new PlatformAuditService())->record(
            'tenant.status',
            'success',
            ['subject_type' => 'tenant', 'subject_id' => (string)$tenant->id],
            $login['admin'],
            (new ServerRequest(['url' => '/platform-admin/audit']))->withAttribute('requestId', 'req-audit-link-2'),
            (int)$tenant->id,
            'platform_admin',
            ['tenant_slug' => (string)$tenant->slug, 'operation_id' => '999999', 'correlation_id' => 'corr-other-2'],
        );

        $this->configRequest([
            'headers' => ['Host' => 'admin.localhost'],
            'cookies' => ['__Host-KMPPlatformAdmin' => $login['token']],
        ]);

        $this->get('/platform-admin/audit?correlation_id=corr-audit-link-1');

        $this->assertResponseOk();
        $this->assertResponseContains('corr-audit-link-1');
        $this->assertResponseContains('Job #' . (int)$job->id);
        $this->assertResponseNotContains('corr-other-2');
    }

    public function testOperationQueueFiltersByCorrelationId(): void
    {
        $login = $this->createAuthenticatedAdmin(
            'queue-correlation@platform.test',
            'Queue Correlation',
            'InitialPlatformPassword!',
        );
        $tenants = $this->getTableLocator()->get('Tenants');
        $tenant = $tenants->newEntity([
            'slug' => 'queue-link-' . uniqid(),
            'display_name' => 'Queue Tenant',
            'status' => 'active',
            'primary_host' => 'queue-link.localhost',
        ]);
        $tenants->saveOrFail($tenant);
        $jobs = $this->getTableLocator()->get('TenantOperationJobs');
        foreach (['corr-queue-1', 'corr-queue-2'] as $index => $correlationId) {
            $job = $jobs->newEntity([
                'tenant_id' => (int)$tenant->id,
                'platform_admin_id' => (int)$login['admin']->id,
                'operation' => 'tenant_status',
                'state' => 'completed',
                'status' => 'completed',
                'idempotency_scope' => 'tenant',
                'idempotency_key' => sprintf('queue-correlation-%d-%s', $index, uniqid()),
                'input' => ['status' => 'active'],
                'operation_correlation_id' => $correlationId,
            ]);
            $jobs->saveOrFail($job);
        }

        $this->configRequest([
            'headers' => ['Host' => 'admin.localhost'],
            'cookies' => ['__Host-KMPPlatformAdmin' => $login['token']],
        ]);

        $this->get('/platform-admin?correlation=corr-queue-1');

        $this->assertResponseOk();
        $this->assertResponseContains('corr-queue-1');
        $this->assertResponseNotContains('corr-queue-2');
    }

    public function testSetTenantStatusQueuesGatewayOperationJobWithMetadata(): void
    {
        $tenant = $this->createTenantRecord('gateway-status-tenant', 'Gateway Status Tenant');
        $login = $this->createAuthenticatedAdmin(
            'status-queue@platform.test',
            'Status Queue',
            'InitialPlatformPassword!',
        );
        $actionLabel = 'Set tenant ' . $tenant->slug . ' status to maintenance';
        $service = new PlatformAdminAuthService();
        $challenge = $service->requestActionCode(
            $login['admin'],
            new ServerRequest(['url' => '/platform-admin/action-code']),
            $actionLabel,
        );
        $this->setEmailCode((int)$challenge['challengeId'], '123456');
        $this->configRequest([
            'headers' => ['Host' => 'admin.localhost'],
            'cookies' => ['__Host-KMPPlatformAdmin' => $login['token']],
        ]);
        $this->session([
            'PlatformAdmin' => [
                'actionChallengeIds' => [
                    $actionLabel => $challenge['challengeId'],
                ],
            ],
        ]);
        $this->enableCsrfToken();
        $this->enableSecurityToken();

        $this->post('/platform-admin/tenants/' . $tenant->slug . '/status/maintenance', [
            'verify_password' => 'InitialPlatformPassword!',
            'verify_email_code' => '123456',
        ]);

        $this->assertRedirectContains('/platform-admin/tenants/' . $tenant->slug);
        $reloadedTenant = $this->getTableLocator()->get('Tenants')->get((int)$tenant->id);
        $this->assertSame(Tenant::STATUS_ACTIVE, (string)$reloadedTenant->status);
        $job = $this->getTableLocator()->get('TenantOperationJobs')->find()
            ->where([
                'tenant_id' => (int)$tenant->id,
                'operation' => 'tenant_status',
            ])
            ->firstOrFail();
        $this->assertSame(TenantOperationJob::STATUS_APPROVED, (string)$job->state);
        $this->assertSame((int)$login['admin']->id, (int)$job->platform_admin_id);
        $this->assertSame(Tenant::STATUS_MAINTENANCE, (string)$job->input['status']);
        $this->assertSame('single', (string)$job->input['gateway']['tenant_target_mode']);
        $this->assertSame((int)$login['admin']->id, (int)$job->input['gateway']['requested_by_admin_id']);
        $this->assertSame((int)$login['admin']->id, (int)$job->input['gateway']['approved_by_admin_id']);
        $this->assertNotEmpty((string)$job->operation_correlation_id);
    }

    public function testViewerCannotCreateTenant(): void
    {
        $login = $this->createAuthenticatedAdmin(
            'viewer-create@platform.test',
            'Viewer Create',
            'InitialPlatformPassword!',
            false,
            PlatformAdmin::ROLE_VIEWER,
        );
        $this->configRequest([
            'headers' => ['Host' => 'admin.localhost'],
            'cookies' => ['__Host-KMPPlatformAdmin' => $login['token']],
        ]);

        $this->get('/platform-admin/tenants/create');

        $this->assertResponseCode(403);
    }

    public function testViewerCannotSetTenantStatus(): void
    {
        $tenant = $this->createTenantRecord('viewer-status-tenant', 'Viewer Status Tenant');
        $login = $this->createAuthenticatedAdmin(
            'viewer-status@platform.test',
            'Viewer Status',
            'InitialPlatformPassword!',
            false,
            PlatformAdmin::ROLE_VIEWER,
        );
        $this->configRequest([
            'headers' => ['Host' => 'admin.localhost'],
            'cookies' => ['__Host-KMPPlatformAdmin' => $login['token']],
        ]);
        $this->enableCsrfToken();
        $this->enableSecurityToken();

        $this->post('/platform-admin/tenants/' . $tenant->slug . '/status/maintenance', [
            'verify_password' => 'InitialPlatformPassword!',
            'verify_email_code' => '123456',
        ]);

        $this->assertResponseCode(403);
        $this->assertSame(
            0,
            $this->getTableLocator()->get('TenantOperationJobs')->find()
                ->where([
                    'tenant_id' => (int)$tenant->id,
                    'operation' => 'tenant_status',
                ])
                ->count(),
        );
    }

    public function testOperatorCannotRequestSecretsActionCode(): void
    {
        $tenant = $this->createTenantRecord('operator-secret-tenant', 'Operator Secret Tenant');
        $login = $this->createAuthenticatedAdmin(
            'operator-secret@platform.test',
            'Operator Secret',
            'InitialPlatformPassword!',
            false,
            PlatformAdmin::ROLE_OPERATOR,
        );
        $this->configRequest([
            'headers' => ['Host' => 'admin.localhost'],
            'cookies' => ['__Host-KMPPlatformAdmin' => $login['token']],
        ]);
        $this->enableCsrfToken();
        $this->enableSecurityToken();

        $this->post('/platform-admin/action-code', [
            'action_label' => 'Update managed secrets for tenant ' . $tenant->slug,
        ]);

        $this->assertResponseCode(403);
    }

    public function testDeniedActionCodeCapabilityCheckWritesAuditEvent(): void
    {
        $tenant = $this->createTenantRecord('viewer-audit-tenant', 'Viewer Audit Tenant');
        $login = $this->createAuthenticatedAdmin(
            'viewer-audit@platform.test',
            'Viewer Audit',
            'InitialPlatformPassword!',
            false,
            PlatformAdmin::ROLE_VIEWER,
        );
        $this->configRequest([
            'headers' => ['Host' => 'admin.localhost'],
            'cookies' => ['__Host-KMPPlatformAdmin' => $login['token']],
        ]);
        $this->enableCsrfToken();
        $this->enableSecurityToken();

        $this->post('/platform-admin/action-code', [
            'action_label' => 'Update managed secrets for tenant ' . $tenant->slug,
        ]);

        $this->assertResponseCode(403);
        $event = $this->getTableLocator()->get('PlatformAuditEvents')->find()
            ->where([
                'action' => 'platform_admin.authorization_denied',
                'platform_admin_id' => (int)$login['admin']->id,
            ])
            ->orderByDesc('id')
            ->firstOrFail();
        $this->assertSame('failure', (string)$event->result);
    }

    public function testSecurityAdminCannotRequestBreakGlassRestoreActionCode(): void
    {
        $tenant = $this->createTenantRecord('break-glass-tenant', 'Break Glass Tenant');
        $login = $this->createAuthenticatedAdmin(
            'security-admin@platform.test',
            'Security Admin',
            'InitialPlatformPassword!',
            false,
            PlatformAdmin::ROLE_SECURITY_ADMIN,
        );
        $this->configRequest([
            'headers' => ['Host' => 'admin.localhost'],
            'cookies' => ['__Host-KMPPlatformAdmin' => $login['token']],
        ]);
        $this->enableCsrfToken();
        $this->enableSecurityToken();

        $this->post('/platform-admin/action-code', [
            'action_label' => 'Restore backup for tenant ' . $tenant->slug,
        ]);

        $this->assertResponseCode(403);
        $this->assertResponseContains('Break-glass role is required for this action.');
        $this->assertSame(0, $this->getTableLocator()->get('PlatformAdminEmailCodes')->find()
            ->where(['purpose' => 'action'])
            ->count());
    }

    public function testRequestActionCodeRequiresFreshSessionForSensitiveActions(): void
    {
        $login = $this->createAuthenticatedAdmin(
            'stale-action@platform.test',
            'Stale Action',
            'InitialPlatformPassword!',
        );
        $this->markSessionAsStale($login['token'], 31);
        $this->configRequest([
            'headers' => ['Host' => 'admin.localhost'],
            'cookies' => ['__Host-KMPPlatformAdmin' => $login['token']],
        ]);
        $this->enableCsrfToken();
        $this->enableSecurityToken();

        $this->post('/platform-admin/action-code', [
            'action_label' => 'Create backup for tenant stale-action',
        ]);

        $this->assertResponseCode(403);
        $this->assertResponseContains('Re-authenticate to continue with this sensitive action.');
        $event = $this->getTableLocator()->get('PlatformAuditEvents')->find()
            ->where([
                'action' => 'platform_admin.step_up_denied',
                'platform_admin_id' => (int)$login['admin']->id,
            ])
            ->orderByDesc('id')
            ->firstOrFail();
        $this->assertSame('failure', (string)$event->result);
    }

    public function testRestoreBackupQueuesCutoverJobWithRequestedConfigSnapshots(): void
    {
        $tenant = $this->createTenantRecord('restore-queue-tenant', 'Restore Queue Tenant');
        $this->createPrimaryDatabaseConfig((int)$tenant->id, 'restore_queue_current');
        $login = $this->createAuthenticatedAdmin(
            'restore-queue@platform.test',
            'Restore Queue',
            'InitialPlatformPassword!',
        );
        $actionLabel = 'Restore backup for tenant ' . $tenant->slug;
        $service = new PlatformAdminAuthService();
        $challenge = $service->requestActionCode(
            $login['admin'],
            new ServerRequest(['url' => '/platform-admin/action-code']),
            $actionLabel,
        );
        $this->setEmailCode((int)$challenge['challengeId'], '123456');
        $this->configRequest([
            'headers' => ['Host' => 'admin.localhost'],
            'cookies' => ['__Host-KMPPlatformAdmin' => $login['token']],
        ]);
        $this->session([
            'PlatformAdmin' => [
                'actionChallengeIds' => [
                    $actionLabel => $challenge['challengeId'],
                ],
            ],
        ]);
        $this->enableCsrfToken();
        $this->enableSecurityToken();

        $this->post('/platform-admin/tenants/' . $tenant->slug . '/restore', [
            'backup_file' => [
                'tmp_name' => __FILE__,
                'error' => UPLOAD_ERR_OK,
                'name' => 'tenant-backup.bin',
                'type' => 'application/octet-stream',
                'size' => filesize(__FILE__),
            ],
            'new_database_name' => 'restore_queue_target',
            'restore_key' => 'restore-key-123',
            'verify_password' => 'InitialPlatformPassword!',
            'verify_email_code' => '123456',
        ]);

        $this->assertRedirectContains('/platform-admin/tenants/' . $tenant->slug);
        $job = $this->getTableLocator()->get('TenantOperationJobs')->find()
            ->where([
                'tenant_id' => (int)$tenant->id,
                'operation' => 'tenant_restore_cutover',
            ])
            ->firstOrFail();
        $this->assertSame(TenantOperationJob::STATUS_APPROVED, (string)$job->state);
        $this->assertSame('restore_queue_target', (string)$job->input['new_database_name']);
        $this->assertNotEmpty((string)($job->input['backup_file_base64'] ?? ''));
        $this->assertSame('restore-key-123', (string)($job->input['restore_key'] ?? ''));
    }

    public function testRestoreBackupRejectsExistingKnownDatabaseName(): void
    {
        $tenant = $this->createTenantRecord('restore-known-db', 'Restore Known DB');
        $this->createPrimaryDatabaseConfig((int)$tenant->id, 'restore_known_current');
        $otherTenant = $this->createTenantRecord('restore-known-other', 'Restore Known Other');
        $this->createPrimaryDatabaseConfig((int)$otherTenant->id, 'restore_known_taken');
        $login = $this->createAuthenticatedAdmin(
            'restore-known@platform.test',
            'Restore Known',
            'InitialPlatformPassword!',
        );
        $actionLabel = 'Restore backup for tenant ' . $tenant->slug;
        $service = new PlatformAdminAuthService();
        $challenge = $service->requestActionCode(
            $login['admin'],
            new ServerRequest(['url' => '/platform-admin/action-code']),
            $actionLabel,
        );
        $this->setEmailCode((int)$challenge['challengeId'], '123456');
        $this->configRequest([
            'headers' => ['Host' => 'admin.localhost'],
            'cookies' => ['__Host-KMPPlatformAdmin' => $login['token']],
        ]);
        $this->session([
            'PlatformAdmin' => [
                'actionChallengeIds' => [
                    $actionLabel => $challenge['challengeId'],
                ],
            ],
        ]);
        $this->enableCsrfToken();
        $this->enableSecurityToken();

        $this->post('/platform-admin/tenants/' . $tenant->slug . '/restore', [
            'new_database_name' => 'restore_known_taken',
            'restore_key' => 'restore-key-123',
            'verify_password' => 'InitialPlatformPassword!',
            'verify_email_code' => '123456',
        ]);

        $this->assertResponseOk();
        $this->assertResponseContains('already exists in tenant config');
        $this->assertSame(
            0,
            $this->getTableLocator()->get('TenantOperationJobs')->find()
                ->where(['tenant_id' => (int)$tenant->id, 'operation' => 'tenant_restore_cutover'])
                ->count(),
        );
    }

    public function testRestoreBackupRejectsRecentlyUsedRestoreTarget(): void
    {
        $tenant = $this->createTenantRecord('restore-recent-db', 'Restore Recent DB');
        $this->createPrimaryDatabaseConfig((int)$tenant->id, 'restore_recent_current');
        $login = $this->createAuthenticatedAdmin(
            'restore-recent@platform.test',
            'Restore Recent',
            'InitialPlatformPassword!',
        );
        $this->createOperationJob((int)$tenant->id, (int)$login['admin']->id, [
            'operation' => 'tenant_restore_cutover',
            'state' => TenantOperationJob::STATUS_COMPLETED,
            'status' => TenantOperationJob::STATUS_COMPLETED,
            'input' => [
                'new_database_name' => 'restore_recent_taken',
            ],
            'completed_at' => DateTime::now()->subDays(1),
        ]);

        $actionLabel = 'Restore backup for tenant ' . $tenant->slug;
        $service = new PlatformAdminAuthService();
        $challenge = $service->requestActionCode(
            $login['admin'],
            new ServerRequest(['url' => '/platform-admin/action-code']),
            $actionLabel,
        );
        $this->setEmailCode((int)$challenge['challengeId'], '123456');
        $this->configRequest([
            'headers' => ['Host' => 'admin.localhost'],
            'cookies' => ['__Host-KMPPlatformAdmin' => $login['token']],
        ]);
        $this->session([
            'PlatformAdmin' => [
                'actionChallengeIds' => [
                    $actionLabel => $challenge['challengeId'],
                ],
            ],
        ]);
        $this->enableCsrfToken();
        $this->enableSecurityToken();

        $this->post('/platform-admin/tenants/' . $tenant->slug . '/restore', [
            'new_database_name' => 'restore_recent_taken',
            'restore_key' => 'restore-key-123',
            'verify_password' => 'InitialPlatformPassword!',
            'verify_email_code' => '123456',
        ]);

        $this->assertResponseOk();
        $this->assertResponseContains('already used');
        $this->assertSame(
            1,
            $this->getTableLocator()->get('TenantOperationJobs')->find()
                ->where(['tenant_id' => (int)$tenant->id, 'operation' => 'tenant_restore_cutover'])
                ->count(),
        );
    }

    public function testCreateTenantQueuesApprovedOperationJob(): void
    {
        $login = $this->createAuthenticatedAdmin(
            'tenant-create-queue@platform.test',
            'Tenant Queue',
            'InitialPlatformPassword!',
        );
        $service = new PlatformAdminAuthService();
        $challenge = $service->requestActionCode(
            $login['admin'],
            new ServerRequest(['url' => '/platform-admin/action-code']),
            'Create or update tenant',
        );
        $this->setEmailCode((int)$challenge['challengeId'], '123456');
        $this->configRequest([
            'headers' => ['Host' => 'admin.localhost'],
            'cookies' => ['__Host-KMPPlatformAdmin' => $login['token']],
        ]);
        $this->session([
            'PlatformAdmin' => [
                'actionChallengeIds' => [
                    'Create or update tenant' => $challenge['challengeId'],
                ],
            ],
        ]);
        $this->enableCsrfToken();
        $this->enableSecurityToken();

        $this->post('/platform-admin/tenants/create', [
            'slug' => 'queued-tenant-create',
            'display_name' => 'Queued Tenant Create',
            'primary_host' => 'queued-tenant-create.localhost',
            'driver' => 'Cake\Database\Driver\Mysql',
            'database_host' => 'localhost',
            'database_name' => 'queued_tenant_create',
            'database_username' => 'tenant_user',
            'migrate' => '1',
            'activate' => '1',
            'verify_password' => 'InitialPlatformPassword!',
            'verify_email_code' => '123456',
        ]);

        $this->assertRedirectContains('/platform-admin');
        $job = $this->getTableLocator()->get('TenantOperationJobs')->find()
            ->where([
                'operation' => 'tenant_create',
                'platform_admin_id' => (int)$login['admin']->id,
            ])
            ->orderByDesc('id')
            ->firstOrFail();
        $this->assertSame(TenantOperationJob::STATUS_APPROVED, (string)$job->state);
        $this->assertIsArray($job->input);
        $this->assertIsArray($job->input['tenant'] ?? null);
        $this->assertSame('queued-tenant-create', (string)($job->input['tenant']['slug'] ?? ''));
        $this->assertNotEmpty((string)$job->operation_correlation_id);
        $this->assertNull($job->completed_at);
    }

    public function testCreateBackupQueuesApprovedOperationJob(): void
    {
        $login = $this->createAuthenticatedAdmin(
            'backup-queue@platform.test',
            'Backup Queue',
            'InitialPlatformPassword!',
        );
        $tenant = $this->createTenantRecord('queued-backup-tenant', 'Queued Backup Tenant');
        $actionLabel = 'Create backup for tenant ' . $tenant->slug;
        $service = new PlatformAdminAuthService();
        $challenge = $service->requestActionCode(
            $login['admin'],
            new ServerRequest(['url' => '/platform-admin/action-code']),
            $actionLabel,
        );
        $this->setEmailCode((int)$challenge['challengeId'], '123456');
        $this->configRequest([
            'headers' => ['Host' => 'admin.localhost'],
            'cookies' => ['__Host-KMPPlatformAdmin' => $login['token']],
        ]);
        $this->session([
            'PlatformAdmin' => [
                'actionChallengeIds' => [
                    $actionLabel => $challenge['challengeId'],
                ],
            ],
        ]);
        $this->enableCsrfToken();
        $this->enableSecurityToken();

        $this->post('/platform-admin/tenants/' . $tenant->slug . '/backup', [
            'backup_key' => 'backup-secret-key',
            'verify_password' => 'InitialPlatformPassword!',
            'verify_email_code' => '123456',
        ]);

        $this->assertRedirectContains('/platform-admin/tenants/' . $tenant->slug);
        $job = $this->getTableLocator()->get('TenantOperationJobs')->find()
            ->where([
                'tenant_id' => (int)$tenant->id,
                'operation' => 'tenant_backup',
                'platform_admin_id' => (int)$login['admin']->id,
            ])
            ->orderByDesc('id')
            ->firstOrFail();
        $this->assertSame(TenantOperationJob::STATUS_APPROVED, (string)$job->state);
        $this->assertSame('backup-secret-key', (string)($job->input['backup_key'] ?? ''));
    }

    public function testRestoreBackupQueuesApprovedOperationJob(): void
    {
        $login = $this->createAuthenticatedAdmin(
            'restore-queue@platform.test',
            'Restore Queue',
            'InitialPlatformPassword!',
        );
        $tenant = $this->createTenantRecord('queued-restore-tenant', 'Queued Restore Tenant');
        $this->createPrimaryDatabaseConfig((int)$tenant->id, 'queued_restore_current');
        $actionLabel = 'Restore backup for tenant ' . $tenant->slug;
        $service = new PlatformAdminAuthService();
        $challenge = $service->requestActionCode(
            $login['admin'],
            new ServerRequest(['url' => '/platform-admin/action-code']),
            $actionLabel,
        );
        $this->setEmailCode((int)$challenge['challengeId'], '123456');
        $this->configRequest([
            'headers' => ['Host' => 'admin.localhost'],
            'cookies' => ['__Host-KMPPlatformAdmin' => $login['token']],
        ]);
        $this->session([
            'PlatformAdmin' => [
                'actionChallengeIds' => [
                    $actionLabel => $challenge['challengeId'],
                ],
            ],
        ]);
        $this->enableCsrfToken();
        $this->enableSecurityToken();
        $fixture = dirname(__DIR__, 2) . '/test_files/queued-job.json';

        $this->post('/platform-admin/tenants/' . $tenant->slug . '/restore', [
            'new_database_name' => 'queued_restore_target',
            'restore_key' => 'restore-secret-key',
            'backup_file' => [
                'tmp_name' => $fixture,
                'name' => 'queued-job.json',
                'type' => 'application/octet-stream',
                'size' => filesize($fixture),
                'error' => 0,
            ],
            'verify_password' => 'InitialPlatformPassword!',
            'verify_email_code' => '123456',
        ]);

        $this->assertRedirectContains('/platform-admin/tenants/' . $tenant->slug);
        $job = $this->getTableLocator()->get('TenantOperationJobs')->find()
            ->where([
                'tenant_id' => (int)$tenant->id,
                'operation' => 'tenant_restore_cutover',
                'platform_admin_id' => (int)$login['admin']->id,
            ])
            ->orderByDesc('id')
            ->firstOrFail();
        $this->assertSame(TenantOperationJob::STATUS_APPROVED, (string)$job->state);
        $this->assertSame('queued_restore_target', (string)($job->input['new_database_name'] ?? ''));
        $this->assertNotEmpty((string)($job->input['backup_file_base64'] ?? ''));
    }

    public function testIndexOperationQueueSupportsFilteringAndDetails(): void
    {
        $login = $this->createAuthenticatedAdmin(
            'ops-dashboard@platform.test',
            'Operations Dashboard',
            'InitialPlatformPassword!',
        );
        $tenantA = $this->createTenantRecord('ops-alpha', 'Ops Alpha');
        $tenantB = $this->createTenantRecord('ops-beta', 'Ops Beta');
        $parentJob = $this->createOperationJob((int)$tenantA->id, (int)$login['admin']->id, [
            'operation' => 'tenant_migrate_all',
            'state' => TenantOperationJob::STATUS_RUNNING,
            'status' => TenantOperationJob::STATUS_RUNNING,
            'operation_correlation_id' => 'corr-parent-alpha',
        ]);
        $failedJob = $this->createOperationJob((int)$tenantA->id, (int)$login['admin']->id, [
            'operation' => 'tenant_migrate',
            'state' => TenantOperationJob::STATUS_FAILED,
            'status' => TenantOperationJob::STATUS_FAILED,
            'error_message' => 'Doctor check failed',
            'operation_correlation_id' => 'corr-failed-alpha',
            'parent_tenant_operation_job_id' => (int)$parentJob->id,
        ]);
        $this->createOperationJob((int)$tenantB->id, (int)$login['admin']->id, [
            'operation' => 'tenant_migrate',
            'state' => TenantOperationJob::STATUS_RUNNING,
            'status' => TenantOperationJob::STATUS_RUNNING,
            'lease_owner' => 'worker-1',
            'lease_token' => 'token-1',
            'lease_expires_at' => DateTime::now()->addMinutes(5),
            'operation_correlation_id' => 'corr-running-beta',
        ]);
        $this->configRequest([
            'headers' => ['Host' => 'admin.localhost'],
            'cookies' => ['__Host-KMPPlatformAdmin' => $login['token']],
        ]);

        $this->get('/platform-admin?state=failed&tenant=ops-alpha&sort=created_desc&limit=25');

        $this->assertResponseOk();
        $this->assertResponseContains('Operation Queue');
        $this->assertResponseContains('Deployment Migration Dashboard');
        $this->assertResponseContains('Tenant Health');
        $this->assertResponseContains('Recent failures (24h)');
        $this->assertResponseContains((string)$failedJob->operation_correlation_id);
        $this->assertResponseContains((string)$failedJob->operation);
        $this->assertResponseContains('Child of #' . (int)$parentJob->id);
        $this->assertResponseNotContains('corr-running-beta');
        $this->assertResponseContains('Retry');
        $this->assertResponseContains('Cancel unavailable: Terminal operations cannot be cancelled.');
    }

    public function testIndexJsonIncludesDeploymentMigrationDashboardRows(): void
    {
        $login = $this->createAuthenticatedAdmin(
            'ops-dashboard-json@platform.test',
            'Operations Dashboard Json',
            'InitialPlatformPassword!',
        );
        $tenantA = $this->createTenantRecord('ops-json-alpha', 'Ops Json Alpha');
        $tenantB = $this->createTenantRecord('ops-json-beta', 'Ops Json Beta');
        $parentJob = $this->createOperationJob((int)$tenantA->id, (int)$login['admin']->id, [
            'operation' => 'tenant_migrate_all',
            'state' => TenantOperationJob::STATUS_HOLD,
            'status' => TenantOperationJob::STATUS_HOLD,
            'operation_correlation_id' => 'corr-parent-json',
            'input' => ['target_schema_version' => '20260601000000'],
            'progress_json' => ['phase' => 'queued'],
        ]);
        $this->createOperationJob((int)$tenantA->id, (int)$login['admin']->id, [
            'operation' => 'tenant_migrate',
            'state' => TenantOperationJob::STATUS_FAILED,
            'status' => TenantOperationJob::STATUS_FAILED,
            'parent_tenant_operation_job_id' => (int)$parentJob->id,
            'error_json' => ['message' => 'Tenant migration failed', 'retryable' => true],
            'input' => ['tenant_slug' => 'ops-json-alpha', 'schema_before' => '20240101000000', 'max_attempts' => 3],
            'progress_json' => ['attempt_count' => 3],
        ]);
        $this->createOperationJob((int)$tenantB->id, (int)$login['admin']->id, [
            'operation' => 'tenant_migrate',
            'state' => TenantOperationJob::STATUS_COMPLETED,
            'status' => TenantOperationJob::STATUS_COMPLETED,
            'parent_tenant_operation_job_id' => (int)$parentJob->id,
            'result_json' => [
                'slug' => 'ops-json-beta',
                'schema_before' => '20240101000000',
                'schema_after' => '20260601000000',
                'target_schema_version' => '20260601000000',
                'duration_ms' => 150,
            ],
        ]);
        $this->configRequest([
            'headers' => ['Host' => 'admin.localhost'],
            'cookies' => ['__Host-KMPPlatformAdmin' => $login['token']],
        ]);

        $this->get('/platform-admin.json?migration_state=hold');

        $this->assertResponseOk();
        $this->assertContentType('application/json');
        $payload = (array)json_decode((string)$this->_response->getBody(), true);
        $dashboard = (array)($payload['deployment_migrations'] ?? []);
        $rows = (array)($dashboard['rows'] ?? []);
        $this->assertCount(1, $rows);
        $row = (array)$rows[0];
        $this->assertSame((int)$parentJob->id, (int)($row['parent_job_id'] ?? 0));
        $this->assertSame('on_hold', (string)($row['stage'] ?? ''));
        $this->assertSame(2, (int)($row['child_total'] ?? 0));
        $this->assertSame(1, (int)($row['child_failed'] ?? 0));
        $this->assertSame(50, (int)($row['progress_percent'] ?? 0));
        $this->assertTrue((bool)($row['can_resume'] ?? false));
        $tenantRows = (array)($row['tenant_rows'] ?? []);
        $this->assertCount(2, $tenantRows);
        $failedTenant = null;
        foreach ($tenantRows as $tenantRow) {
            if ((string)($tenantRow['tenant_slug'] ?? '') === 'ops-json-alpha') {
                $failedTenant = (array)$tenantRow;
                break;
            }
        }
        $this->assertNotNull($failedTenant);
        $this->assertSame('parent_tenant_operation_job_id', (string)($failedTenant['linkage_source'] ?? ''));
        $this->assertStringContainsString('Tenant migration failed', (string)($failedTenant['error_summary'] ?? ''));
    }

    public function testIndexJsonDeploymentMigrationTenantStateFilterAppliesToTenantRows(): void
    {
        $login = $this->createAuthenticatedAdmin(
            'ops-dashboard-json-filter@platform.test',
            'Operations Dashboard Json Filter',
            'InitialPlatformPassword!',
        );
        $tenant = $this->createTenantRecord('ops-json-filter', 'Ops Json Filter');
        $parentJob = $this->createOperationJob((int)$tenant->id, (int)$login['admin']->id, [
            'operation' => 'tenant_migrate_all',
            'state' => TenantOperationJob::STATUS_RUNNING,
            'status' => TenantOperationJob::STATUS_RUNNING,
            'operation_correlation_id' => 'corr-parent-json-filter',
        ]);
        $this->createOperationJob((int)$tenant->id, (int)$login['admin']->id, [
            'operation' => 'tenant_migrate',
            'state' => TenantOperationJob::STATUS_FAILED,
            'status' => TenantOperationJob::STATUS_FAILED,
            'parent_tenant_operation_job_id' => (int)$parentJob->id,
            'input' => ['tenant_slug' => 'ops-json-filter'],
        ]);
        $this->createOperationJob((int)$tenant->id, (int)$login['admin']->id, [
            'operation' => 'tenant_migrate',
            'state' => TenantOperationJob::STATUS_COMPLETED,
            'status' => TenantOperationJob::STATUS_COMPLETED,
            'parent_tenant_operation_job_id' => (int)$parentJob->id,
            'input' => ['tenant_slug' => 'ops-json-filter'],
        ]);
        $this->configRequest([
            'headers' => ['Host' => 'admin.localhost'],
            'cookies' => ['__Host-KMPPlatformAdmin' => $login['token']],
        ]);

        $this->get('/platform-admin.json?migration_tenant_state=failed');

        $this->assertResponseOk();
        $payload = (array)json_decode((string)$this->_response->getBody(), true);
        $dashboard = (array)($payload['deployment_migrations'] ?? []);
        $rows = (array)($dashboard['rows'] ?? []);
        $this->assertCount(1, $rows);
        $tenantRows = (array)($rows[0]['tenant_rows'] ?? []);
        $this->assertCount(1, $tenantRows);
        $this->assertSame(TenantOperationJob::STATUS_FAILED, (string)($tenantRows[0]['state'] ?? ''));
    }

    public function testViewTenantOperationQueueShowsTenantOnlyAndStaleIndicator(): void
    {
        $login = $this->createAuthenticatedAdmin(
            'ops-tenant@platform.test',
            'Tenant Operations',
            'InitialPlatformPassword!',
        );
        $tenantA = $this->createTenantRecord('ops-tenant-alpha', 'Ops Tenant Alpha');
        $tenantB = $this->createTenantRecord('ops-tenant-beta', 'Ops Tenant Beta');
        $this->createOperationJob((int)$tenantA->id, (int)$login['admin']->id, [
            'operation' => 'tenant_status',
            'state' => TenantOperationJob::STATUS_RUNNING,
            'status' => TenantOperationJob::STATUS_RUNNING,
            'lease_owner' => 'worker-stale',
            'lease_token' => 'stale-token',
            'lease_expires_at' => DateTime::now()->subMinutes(1),
            'operation_correlation_id' => 'corr-stale-tenant',
        ]);
        $this->createOperationJob((int)$tenantB->id, (int)$login['admin']->id, [
            'operation' => 'tenant_backup',
            'state' => TenantOperationJob::STATUS_RUNNING,
            'status' => TenantOperationJob::STATUS_RUNNING,
            'operation_correlation_id' => 'corr-other-tenant',
        ]);
        $this->configRequest([
            'headers' => ['Host' => 'admin.localhost'],
            'cookies' => ['__Host-KMPPlatformAdmin' => $login['token']],
        ]);

        $this->get('/platform-admin/tenants/ops-tenant-alpha?state=running');

        $this->assertResponseOk();
        $this->assertResponseContains('corr-stale-tenant');
        $this->assertResponseContains('Stale lock');
        $this->assertResponseNotContains('corr-other-tenant');
    }

    public function testViewTenantShowsSecretRotationVerificationGuidance(): void
    {
        $login = $this->createAuthenticatedAdmin(
            'rotation-ui@platform.test',
            'Rotation UI',
            'InitialPlatformPassword!',
        );
        $tenant = $this->createTenantRecord('rotation-ui-tenant', 'Rotation UI Tenant');
        $this->createOperationJob((int)$tenant->id, (int)$login['admin']->id, [
            'operation' => 'tenant_rotate_db_secret',
            'state' => TenantOperationJob::STATUS_COMPLETED,
            'status' => TenantOperationJob::STATUS_COMPLETED,
            'operation_correlation_id' => 'corr-rotation-success',
            'input' => [
                'new_secret_reference' => 'managed:tenant/1/database/primary/rotation/success',
            ],
            'result_json' => [
                'rotated' => true,
                'rolled_back' => false,
            ],
        ]);
        $this->createOperationJob((int)$tenant->id, (int)$login['admin']->id, [
            'operation' => 'tenant_rotate_db_secret',
            'state' => TenantOperationJob::STATUS_FAILED,
            'status' => TenantOperationJob::STATUS_FAILED,
            'operation_correlation_id' => 'corr-rotation-rollback',
            'error_message' => 'Verification failed; rollback applied.',
            'input' => [
                'new_secret_reference' => 'managed:tenant/1/database/primary/rotation/rollback',
            ],
        ]);
        (new PlatformAuditService())->record(
            'tenant.secret_update',
            'success',
            ['subject_type' => 'tenant', 'subject_id' => (string)$tenant->id],
            $login['admin'],
            (new ServerRequest(['url' => '/platform-admin/tenants/' . $tenant->slug]))->withAttribute('requestId', 'req-rotation-ui'),
            (int)$tenant->id,
            'platform_admin',
            ['tenant_slug' => (string)$tenant->slug, 'correlation_id' => 'corr-rotation-success'],
        );

        $this->configRequest([
            'headers' => ['Host' => 'admin.localhost'],
            'cookies' => ['__Host-KMPPlatformAdmin' => $login['token']],
        ]);
        $this->get('/platform-admin/tenants/' . $tenant->slug);

        $this->assertResponseOk();
        $this->assertResponseContains('Secret Rotation Verification');
        $this->assertResponseContains('Success');
        $this->assertResponseContains('Rollback');
        $this->assertResponseContains('Run tenant doctor and smoke checks');
        $this->assertResponseContains('Rollback safety activated.');
        $this->assertResponseNotContains('managed:tenant/1/database/primary/rotation/');
    }

    public function testSecretRotationStatusApiReturnsExpectedShapeForViewerRole(): void
    {
        $login = $this->createAuthenticatedAdmin(
            'rotation-api@platform.test',
            'Rotation Api',
            'InitialPlatformPassword!',
            false,
            PlatformAdmin::ROLE_VIEWER,
        );
        $tenant = $this->createTenantRecord('rotation-api-tenant', 'Rotation Api Tenant');
        $job = $this->createOperationJob((int)$tenant->id, (int)$login['admin']->id, [
            'operation' => 'tenant_rotate_db_secret',
            'state' => TenantOperationJob::STATUS_COMPLETED,
            'status' => TenantOperationJob::STATUS_COMPLETED,
            'operation_correlation_id' => 'corr-rotation-api',
            'result_json' => ['rotated' => true, 'rolled_back' => false],
            'input' => ['new_secret_reference' => 'managed:tenant/1/database/primary/rotation/api'],
        ]);
        (new PlatformAuditService())->record(
            'tenant.secret_update',
            'success',
            ['subject_type' => 'tenant', 'subject_id' => (string)$tenant->id],
            $login['admin'],
            (new ServerRequest(['url' => '/platform-admin/tenants/' . $tenant->slug . '/secret-rotation-status']))->withAttribute('requestId', 'req-rotation-api'),
            (int)$tenant->id,
            'platform_admin',
            [
                'tenant_slug' => (string)$tenant->slug,
                'operation_id' => (string)$job->id,
                'correlation_id' => 'corr-rotation-api',
            ],
        );
        $this->configRequest([
            'headers' => ['Host' => 'admin.localhost'],
            'cookies' => ['__Host-KMPPlatformAdmin' => $login['token']],
        ]);

        $this->get('/platform-admin/tenants/' . $tenant->slug . '/secret-rotation-status.json');

        $this->assertResponseOk();
        $this->assertContentType('application/json');
        $payload = (array)json_decode((string)$this->_response->getBody(), true);
        $this->assertSame((string)$tenant->slug, (string)($payload['tenant']['slug'] ?? ''));
        $rows = (array)($payload['secret_rotation_verifications'] ?? []);
        $this->assertNotEmpty($rows);
        $row = (array)$rows[0];
        $this->assertArrayHasKey('verification_status', $row);
        $this->assertArrayHasKey('confidence', $row);
        $this->assertArrayHasKey('affected_scope', $row);
        $this->assertArrayHasKey('next_steps', $row);
        $this->assertSame('corr-rotation-api', (string)($row['correlation_id'] ?? ''));
        $this->assertContains('database.primary', (array)($row['affected_scope'] ?? []));
        $this->assertStringNotContainsString('managed:tenant/1/database/primary/rotation/api', (string)$this->_response->getBody());
    }

    public function testSecretRotationStatusApiRedirectsWhenNotAuthenticated(): void
    {
        $tenant = $this->createTenantRecord('rotation-api-anon', 'Rotation Api Anon');
        $this->configRequest([
            'headers' => ['Host' => 'admin.localhost'],
        ]);

        $this->get('/platform-admin/tenants/' . $tenant->slug . '/secret-rotation-status.json');

        $this->assertRedirectContains('/platform-admin/login');
    }

    public function testViewTenantJsonIncludesDoctorRemediationMetadata(): void
    {
        $requiredSchema = '2026.06.99';
        $previousRequiredSchema = Configure::read('Tenancy.requiredSchemaVersion');
        Configure::write('Tenancy.requiredSchemaVersion', $requiredSchema);
        try {
            $tenant = $this->createTenantRecord('doctor-json-tenant', 'Doctor Json Tenant');
            $tenant->status = Tenant::STATUS_DISABLED;
            $this->getTableLocator()->get('Tenants')->saveOrFail($tenant);
            $login = $this->createAuthenticatedAdmin(
                'doctor-json@platform.test',
                'Doctor Json',
                'InitialPlatformPassword!',
            );
            $this->configRequest([
                'headers' => ['Host' => 'admin.localhost'],
                'cookies' => ['__Host-KMPPlatformAdmin' => $login['token']],
            ]);

            $this->get('/platform-admin/tenants/' . $tenant->slug . '.json');

            $this->assertResponseOk();
            $payload = (array)json_decode((string)$this->_response->getBody(), true);
            $findings = (array)($payload['doctor_findings'] ?? []);
            $this->assertArrayHasKey('schema_version', $findings);
            $schemaFinding = (array)$findings['schema_version'];
            $schemaActions = (array)($schemaFinding['actions'] ?? []);
            $this->assertNotEmpty($schemaActions);
            $this->assertSame('tenant_migrate', (string)($schemaActions[0]['operation'] ?? ''));
            $this->assertNotEmpty((string)($schemaActions[0]['action_label'] ?? ''));
            $this->assertArrayHasKey('remediation_guidance', $schemaFinding);
        } finally {
            Configure::write('Tenancy.requiredSchemaVersion', $previousRequiredSchema);
        }
    }

    public function testViewTenantDoctorSectionRendersRemediationGuidanceAndActions(): void
    {
        $tenant = $this->createTenantRecord('doctor-remediate-ui', 'Doctor Remediate UI');
        $tenant->status = Tenant::STATUS_DISABLED;
        $this->getTableLocator()->get('Tenants')->saveOrFail($tenant);
        $login = $this->createAuthenticatedAdmin(
            'doctor-ui@platform.test',
            'Doctor UI',
            'InitialPlatformPassword!',
        );
        $this->configRequest([
            'headers' => ['Host' => 'admin.localhost'],
            'cookies' => ['__Host-KMPPlatformAdmin' => $login['token']],
        ]);

        $this->get('/platform-admin/tenants/' . $tenant->slug);

        $this->assertResponseOk();
        $this->assertResponseContains('Remediation guidance');
        $this->assertResponseContains('Set tenant status to active');
        $this->assertResponseContains('Email remediation code');
    }

    public function testViewerCannotRequestDoctorRemediationActionCodeWithoutCapability(): void
    {
        $tenant = $this->createTenantRecord('doctor-remediate-denied', 'Doctor Remediate Denied');
        $login = $this->createAuthenticatedAdmin(
            'doctor-denied@platform.test',
            'Doctor Denied',
            'InitialPlatformPassword!',
            false,
            PlatformAdmin::ROLE_VIEWER,
        );
        $this->configRequest([
            'headers' => ['Host' => 'admin.localhost'],
            'cookies' => ['__Host-KMPPlatformAdmin' => $login['token']],
        ]);
        $this->enableCsrfToken();
        $this->enableSecurityToken();
        $actionLabel = sprintf(
            'Remediate tenant %s finding schema_version via run_migrations [operation:tenant_migrate]',
            (string)$tenant->slug,
        );

        $this->post('/platform-admin/action-code', [
            'action_label' => $actionLabel,
        ]);

        $this->assertResponseCode(403);
    }

    public function testDoctorRemediationQueuesGatewayJobWithApprovalPolicy(): void
    {
        $requiredSchema = '2026.06.99';
        $previousRequiredSchema = Configure::read('Tenancy.requiredSchemaVersion');
        Configure::write('Tenancy.requiredSchemaVersion', $requiredSchema);
        try {
            $tenant = $this->createTenantRecord('doctor-remediate-queue', 'Doctor Remediate Queue');
            $login = $this->createAuthenticatedAdmin(
                'doctor-queue@platform.test',
                'Doctor Queue',
                'InitialPlatformPassword!',
                false,
                PlatformAdmin::ROLE_PROVISIONER,
            );
            $actionLabel = sprintf(
                'Remediate tenant %s finding schema_version via run_migrations [operation:tenant_migrate]',
                (string)$tenant->slug,
            );
            $service = new PlatformAdminAuthService();
            $challenge = $service->requestActionCode(
                $login['admin'],
                new ServerRequest(['url' => '/platform-admin/action-code']),
                $actionLabel,
            );
            $this->setEmailCode((int)$challenge['challengeId'], '123456');
            $this->configRequest([
                'headers' => ['Host' => 'admin.localhost'],
                'cookies' => ['__Host-KMPPlatformAdmin' => $login['token']],
            ]);
            $this->session([
                'PlatformAdmin' => [
                    'actionChallengeIds' => [
                        $actionLabel => $challenge['challengeId'],
                    ],
                ],
            ]);
            $this->enableCsrfToken();
            $this->enableSecurityToken();

            $this->post('/platform-admin/tenants/' . $tenant->slug . '/doctor/schema_version/remediate/run_migrations', [
                'verify_password' => 'InitialPlatformPassword!',
                'verify_email_code' => '123456',
            ]);

            $this->assertRedirectContains('/platform-admin/tenants/' . $tenant->slug);
            $job = $this->getTableLocator()->get('TenantOperationJobs')->find()
                ->where([
                    'tenant_id' => (int)$tenant->id,
                    'operation' => 'tenant_migrate',
                ])
                ->orderByDesc('id')
                ->firstOrFail();
            $this->assertSame(TenantOperationJob::STATUS_APPROVAL_REQUIRED, (string)$job->state);
            $this->assertSame((int)$login['admin']->id, (int)($job->input['gateway']['requested_by_admin_id'] ?? 0));
            $this->assertSame((int)$login['admin']->id, (int)($job->input['gateway']['approved_by_admin_id'] ?? 0));
        } finally {
            Configure::write('Tenancy.requiredSchemaVersion', $previousRequiredSchema);
        }
    }

    public function testCommandCatalogPageRendersCatalogDetailsAndValidationHints(): void
    {
        $login = $this->createAuthenticatedAdmin(
            'command-catalog@platform.test',
            'Command Catalog',
            'InitialPlatformPassword!',
        );
        $this->configRequest([
            'headers' => ['Host' => 'admin.localhost'],
            'cookies' => ['__Host-KMPPlatformAdmin' => $login['token']],
        ]);

        $this->get('/platform-admin/operations/catalog');

        $this->assertResponseOk();
        $this->assertResponseContains('Operation Command Catalog');
        $this->assertResponseContains('tenant_status');
        $this->assertResponseContains('Set tenant lifecycle status');
        $this->assertResponseContains('new_secret_reference');
        $this->assertResponseContains('max_attempts');
        $this->assertResponseContains('between 1 and 10');
    }

    public function testCommandCatalogJsonApiReturnsCatalogPayload(): void
    {
        $login = $this->createAuthenticatedAdmin(
            'command-catalog-json@platform.test',
            'Command Catalog Json',
            'InitialPlatformPassword!',
            false,
            PlatformAdmin::ROLE_VIEWER,
        );
        $this->configRequest([
            'headers' => ['Host' => 'admin.localhost'],
            'cookies' => ['__Host-KMPPlatformAdmin' => $login['token']],
        ]);

        $this->get('/platform-admin/operations/catalog.json');

        $this->assertResponseOk();
        $this->assertContentType('application/json');
        $payload = (array)json_decode((string)$this->_response->getBody(), true);
        $this->assertArrayHasKey('catalog', $payload);
        $this->assertIsArray($payload['catalog']);
        $statusCommand = null;
        foreach ((array)$payload['catalog'] as $command) {
            if ((string)($command['id'] ?? '') === 'tenant_status') {
                $statusCommand = $command;
                break;
            }
        }
        $this->assertNotNull($statusCommand);
        $approvalPolicy = (array)($statusCommand['approval_policy'] ?? []);
        $this->assertSame('n_of_m', (string)($approvalPolicy['mode'] ?? ''));
        $this->assertSame(1, (int)($approvalPolicy['required_approvals'] ?? 0));
        $this->assertContains('status', (array)($statusCommand['required_parameters'] ?? []));
        $this->assertContains('operator', (array)($statusCommand['allowed_roles'] ?? []));
    }

    public function testCommandCatalogRedirectsWhenNotAuthenticated(): void
    {
        $this->configRequest([
            'headers' => ['Host' => 'admin.localhost'],
        ]);

        $this->get('/platform-admin/operations/catalog');

        $this->assertRedirectContains('/platform-admin/login');
    }

    public function testRetryOperationCreatesNewApprovedJob(): void
    {
        $login = $this->createAuthenticatedAdmin(
            'ops-retry@platform.test',
            'Retry Operations',
            'InitialPlatformPassword!',
        );
        $tenant = $this->createTenantRecord('ops-retry-tenant', 'Ops Retry Tenant');
        $job = $this->createOperationJob((int)$tenant->id, (int)$login['admin']->id, [
            'operation' => 'tenant_doctor',
            'state' => TenantOperationJob::STATUS_FAILED,
            'status' => TenantOperationJob::STATUS_FAILED,
            'operation_correlation_id' => 'corr-retry-source',
        ]);
        $this->configRequest([
            'headers' => ['Host' => 'admin.localhost'],
            'cookies' => ['__Host-KMPPlatformAdmin' => $login['token']],
        ]);
        $this->enableCsrfToken();
        $this->enableSecurityToken();

        $this->post('/platform-admin/operations/' . (int)$job->id . '/retry');

        $this->assertRedirectContains('/platform-admin');
        $retryJob = $this->getTableLocator()->get('TenantOperationJobs')->find()
            ->where([
                'tenant_id' => (int)$tenant->id,
                'state' => TenantOperationJob::STATUS_APPROVED,
            ])
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($retryJob);
        $this->assertNotSame((int)$job->id, (int)$retryJob->id);
        $this->assertSame((int)$job->id, (int)($retryJob->progress_json['retried_from_operation_id'] ?? 0));
    }

    public function testCancelOperationRequestsCancellationForRunningJob(): void
    {
        $login = $this->createAuthenticatedAdmin(
            'ops-cancel@platform.test',
            'Cancel Operations',
            'InitialPlatformPassword!',
        );
        $tenant = $this->createTenantRecord('ops-cancel-tenant', 'Ops Cancel Tenant');
        $job = $this->createOperationJob((int)$tenant->id, (int)$login['admin']->id, [
            'operation' => 'tenant_migrate',
            'state' => TenantOperationJob::STATUS_RUNNING,
            'status' => TenantOperationJob::STATUS_RUNNING,
            'lease_owner' => 'cancel-worker',
            'lease_token' => 'cancel-token',
            'lease_expires_at' => DateTime::now()->addMinutes(2),
        ]);
        $this->configRequest([
            'headers' => ['Host' => 'admin.localhost'],
            'cookies' => ['__Host-KMPPlatformAdmin' => $login['token']],
        ]);
        $this->enableCsrfToken();
        $this->enableSecurityToken();

        $this->post('/platform-admin/operations/' . (int)$job->id . '/cancel');

        $this->assertRedirectContains('/platform-admin');
        $updated = $this->getTableLocator()->get('TenantOperationJobs')->get((int)$job->id);
        $this->assertNotNull($updated->cancelled_at);
        $this->assertSame(TenantOperationJob::STATUS_RUNNING, (string)$updated->state);
        $this->assertSame('Cancellation requested by platform admin.', (string)$updated->status_message);
    }

    public function testApproveOperationRecordsDecisionAndUnblocksWhenThresholdMet(): void
    {
        $requesterLogin = $this->createAuthenticatedAdmin(
            'ops-approve-requester@platform.test',
            'Approve Requester',
            'InitialPlatformPassword!',
            false,
            PlatformAdmin::ROLE_PROVISIONER,
        );
        $approverLogin = $this->createAuthenticatedAdmin(
            'ops-approve-approver@platform.test',
            'Approve Approver',
            'InitialPlatformPassword!',
            false,
            PlatformAdmin::ROLE_PROVISIONER,
        );
        $tenant = $this->createTenantRecord('ops-approve-tenant', 'Ops Approve Tenant');
        $job = $this->createOperationJob((int)$tenant->id, (int)$requesterLogin['admin']->id, [
            'operation' => 'tenant_migrate',
            'state' => TenantOperationJob::STATUS_APPROVAL_REQUIRED,
            'status' => TenantOperationJob::STATUS_APPROVAL_REQUIRED,
            'approval_policy_json' => [
                'mode' => 'two_person',
                'required_approvals' => 2,
                'require_distinct_approvers' => true,
                'require_requester_separation' => true,
            ],
            'approvals_required' => 2,
            'approvals_received' => 1,
            'input' => [
                'gateway' => [
                    'requested_by_admin_id' => (int)$requesterLogin['admin']->id,
                ],
            ],
        ]);
        $approvals = $this->getTableLocator()->get('TenantOperationApprovals');
        $approvals->saveOrFail($approvals->newEntity([
            'tenant_operation_job_id' => (int)$job->id,
            'platform_admin_id' => (int)$requesterLogin['admin']->id,
            'approval_type' => 'gateway_approved',
            'decision' => 'approved',
            'approved_at' => DateTime::now(),
            'decided_at' => DateTime::now(),
        ]));

        $this->configRequest([
            'headers' => ['Host' => 'admin.localhost'],
            'cookies' => ['__Host-KMPPlatformAdmin' => $approverLogin['token']],
        ]);
        $this->enableCsrfToken();
        $this->enableSecurityToken();

        $this->post('/platform-admin/operations/' . (int)$job->id . '/approve', [
            'decision_note' => 'Reviewed migration blast radius and approved.',
        ]);

        $this->assertRedirectContains('/platform-admin');
        $updated = $this->getTableLocator()->get('TenantOperationJobs')->get((int)$job->id);
        $this->assertSame(TenantOperationJob::STATUS_APPROVED, (string)$updated->state);
        $this->assertSame(2, (int)($updated->approvals_received ?? 0));
        $latestApproval = $approvals->find()
            ->where([
                'tenant_operation_job_id' => (int)$job->id,
                'platform_admin_id' => (int)$approverLogin['admin']->id,
            ])
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($latestApproval);
        $this->assertSame('approved', (string)$latestApproval->decision);
    }

    public function testRejectOperationRequiresCapability(): void
    {
        $requesterLogin = $this->createAuthenticatedAdmin(
            'ops-reject-requester@platform.test',
            'Reject Requester',
            'InitialPlatformPassword!',
            false,
            PlatformAdmin::ROLE_OPERATOR,
        );
        $viewerLogin = $this->createAuthenticatedAdmin(
            'ops-reject-viewer@platform.test',
            'Reject Viewer',
            'InitialPlatformPassword!',
            false,
            PlatformAdmin::ROLE_VIEWER,
        );
        $tenant = $this->createTenantRecord('ops-reject-tenant', 'Ops Reject Tenant');
        $job = $this->createOperationJob((int)$tenant->id, (int)$requesterLogin['admin']->id, [
            'operation' => 'tenant_status',
            'state' => TenantOperationJob::STATUS_APPROVAL_REQUIRED,
            'status' => TenantOperationJob::STATUS_APPROVAL_REQUIRED,
            'approvals_required' => 1,
            'approvals_received' => 0,
            'input' => [
                'gateway' => [
                    'requested_by_admin_id' => (int)$requesterLogin['admin']->id,
                ],
            ],
        ]);

        $this->configRequest([
            'headers' => ['Host' => 'admin.localhost'],
            'cookies' => ['__Host-KMPPlatformAdmin' => $viewerLogin['token']],
        ]);
        $this->enableCsrfToken();
        $this->enableSecurityToken();

        $this->post('/platform-admin/operations/' . (int)$job->id . '/reject', [
            'decision_note' => 'Not authorized',
        ]);

        $this->assertResponseCode(403);
        $unchanged = $this->getTableLocator()->get('TenantOperationJobs')->get((int)$job->id);
        $this->assertSame(TenantOperationJob::STATUS_APPROVAL_REQUIRED, (string)$unchanged->state);
        $this->assertSame(
            0,
            $this->getTableLocator()->get('TenantOperationApprovals')->find()
                ->where([
                    'tenant_operation_job_id' => (int)$job->id,
                    'platform_admin_id' => (int)$viewerLogin['admin']->id,
                ])
                ->count(),
        );
    }

    public function testResumeOperationRequeuesHeldDeploymentMigrationParent(): void
    {
        $login = $this->createAuthenticatedAdmin(
            'ops-resume@platform.test',
            'Resume Operations',
            'InitialPlatformPassword!',
        );
        $tenant = $this->createTenantRecord('ops-resume-tenant', 'Ops Resume Tenant');
        $parent = $this->createOperationJob((int)$tenant->id, (int)$login['admin']->id, [
            'operation' => 'tenant_migrate_all',
            'state' => TenantOperationJob::STATUS_HOLD,
            'status' => TenantOperationJob::STATUS_HOLD,
            'operation_correlation_id' => 'corr-resume-parent',
        ]);
        $child = $this->createOperationJob((int)$tenant->id, (int)$login['admin']->id, [
            'operation' => 'tenant_migrate',
            'state' => TenantOperationJob::STATUS_FAILED,
            'status' => TenantOperationJob::STATUS_FAILED,
            'parent_tenant_operation_job_id' => (int)$parent->id,
            'input' => ['resume_count' => 1],
            'error_json' => ['message' => 'failed before resume'],
            'error_message' => 'failed before resume',
            'completed_at' => DateTime::now(),
            'lease_owner' => 'worker-before-resume',
            'lease_token' => 'lease-before-resume',
        ]);
        $this->configRequest([
            'headers' => ['Host' => 'admin.localhost'],
            'cookies' => ['__Host-KMPPlatformAdmin' => $login['token']],
        ]);
        $this->enableCsrfToken();
        $this->enableSecurityToken();

        $this->post('/platform-admin/operations/' . (int)$parent->id . '/resume');

        $this->assertRedirectContains('/platform-admin');
        $jobs = $this->getTableLocator()->get('TenantOperationJobs');
        $updatedParent = $jobs->get((int)$parent->id);
        $updatedChild = $jobs->get((int)$child->id);
        $this->assertSame(TenantOperationJob::STATUS_RUNNING, (string)$updatedParent->state);
        $this->assertSame(TenantOperationJob::STATUS_APPROVED, (string)$updatedChild->state);
        $this->assertSame(2, (int)($updatedChild->input['resume_count'] ?? 0));
        $this->assertNull($updatedChild->error_json);
        $this->assertNull($updatedChild->error_message);
    }

    private function setPlatformSecretKey(string $value): void
    {
        putenv('PLATFORM_SECRET_KEY=' . $value);
        $_ENV['PLATFORM_SECRET_KEY'] = $value;
        $_SERVER['PLATFORM_SECRET_KEY'] = $value;
    }

    private function clearPlatformSecretKey(): void
    {
        putenv('PLATFORM_SECRET_KEY');
        unset($_ENV['PLATFORM_SECRET_KEY'], $_SERVER['PLATFORM_SECRET_KEY']);
    }

    /**
     * @return array{admin: \App\Model\Entity\PlatformAdmin, token: string}
     */
    private function createAuthenticatedAdmin(
        string $email,
        string $displayName,
        string $password,
        bool $requirePasswordChange = false,
        string $role = PlatformAdmin::ROLE_BREAK_GLASS,
    ): array {
        $service = new PlatformAdminAuthService();
        $created = $service->createAdmin($email, $displayName, $password, $requirePasswordChange, $role);
        $request = new ServerRequest(['url' => '/platform-admin/login']);
        $challenge = $service->beginLogin($email, $password, $request);
        $this->setEmailCode((int)$challenge['challengeId'], '123456');
        $token = $service->completeLogin((int)$challenge['challengeId'], '123456', $request);

        return ['admin' => $created['admin'], 'token' => $token];
    }

    private function setEmailCode(int $challengeId, string $code, ?DateTime $expiresAt = null): void
    {
        $codesTable = $this->getTableLocator()->get('PlatformAdminEmailCodes');
        $record = $codesTable->get($challengeId);
        $record->code_hash = password_hash($code, PASSWORD_DEFAULT);
        if ($expiresAt !== null) {
            $record->expires_at = $expiresAt;
        }
        $codesTable->saveOrFail($record);
    }

    private function markSessionAsStale(string $token, int $minutesAgo): void
    {
        $this->getTableLocator()->get('PlatformAdminSessions')->updateAll(
            ['created' => DateTime::now()->subMinutes($minutesAgo)],
            ['token_hash' => hash('sha256', $token)],
        );
    }

    /**
     * @return \Cake\Datasource\EntityInterface
     */
    private function createTenantRecord(string $slug, string $displayName)
    {
        $tenants = $this->getTableLocator()->get('Tenants');
        $tenant = $tenants->newEntity([
            'slug' => $slug,
            'display_name' => $displayName,
            'status' => 'active',
            'primary_host' => $slug . '.localhost',
        ]);
        $tenants->saveOrFail($tenant);

        return $tenant;
    }

    private function createOperationJob(int $tenantId, int $adminId, array $overrides = []): TenantOperationJob
    {
        $jobs = $this->getTableLocator()->get('TenantOperationJobs');
        $state = (string)($overrides['state'] ?? TenantOperationJob::STATUS_QUEUED);
        $job = $jobs->newEntity(array_merge([
            'tenant_id' => $tenantId,
            'platform_admin_id' => $adminId,
            'operation' => 'tenant_status',
            'state' => $state,
            'status' => (string)($overrides['status'] ?? $state),
            'idempotency_scope' => 'tenant',
            'idempotency_key' => uniqid('platform-admin-job-', true),
            'input' => [],
            'progress_json' => [],
            'status_message' => 'queued',
            'operation_correlation_id' => Text::uuid(),
        ], $overrides));
        $jobs->saveOrFail($job);

        return $job;
    }

    /**
     * @return \Cake\Datasource\EntityInterface
     */
    private function createPrimaryDatabaseConfig(int $tenantId, string $databaseName)
    {
        $configs = $this->getTableLocator()->get('TenantDatabaseConfigs');
        $config = $configs->newEntity([
            'tenant_id' => $tenantId,
            'connection_role' => 'primary',
            'driver' => 'Cake\Database\Driver\Sqlite',
            'host' => 'localhost',
            'port' => null,
            'database_name' => $databaseName,
            'username' => null,
            'secret_reference' => null,
            'encrypted_dsn' => null,
            'read_enabled' => true,
            'write_enabled' => true,
            'is_active' => true,
            'metadata' => [],
        ]);
        $configs->saveOrFail($config);

        return $config;
    }

    private function configureDebugEmail(): void
    {
        TransportFactory::drop('default');
        TransportFactory::setConfig('default', ['className' => 'Debug']);
        Mailer::drop('default');
        Mailer::setConfig('default', [
            'transport' => 'default',
            'from' => 'platform-admin@platform.test',
        ]);
    }
}
