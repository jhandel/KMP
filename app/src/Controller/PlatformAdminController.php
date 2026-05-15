<?php
declare(strict_types=1);

namespace App\Controller;

use App\Model\Entity\PlatformAdmin;
use App\Model\Entity\Tenant;
use App\Model\Entity\TenantOperationApproval;
use App\Model\Entity\TenantOperationJob;
use App\Services\Platform\DeploymentMigrationOrchestratorService;
use App\Services\Platform\PlatformAdminAuthService;
use App\Services\Platform\PlatformAuditService;
use App\Services\Platform\PlatformRuntimeConfigService;
use App\Services\Platform\PlatformSecretService;
use App\Services\Platform\TenantHealthSurfaceService;
use App\Services\Platform\TenantOperationCommandCatalog;
use App\Services\Platform\TenantOperationGatewayService;
use App\Services\Tenant\TenantConnectionFactory;
use App\Services\Tenant\TenantContext;
use App\Services\Tenant\TenantInvalidationService;
use App\Services\Tenant\TenantProvisioningService;
use Cake\Controller\Controller;
use Cake\Event\EventInterface;
use Cake\Http\Cookie\Cookie;
use Cake\Http\Exception\ForbiddenException;
use Cake\Http\Response;
use Cake\I18n\DateTime;
use Cake\Utility\Text;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;
use Throwable;

/**
 * Host-isolated platform tenant onboarding console.
 */
class PlatformAdminController extends Controller
{
    private const COOKIE_NAME = '__Host-KMPPlatformAdmin';
    private const LOGIN_CHALLENGE_KEY = 'PlatformAdmin.loginChallengeId';
    private const LOGIN_EMAIL_KEY = 'PlatformAdmin.loginEmail';
    private const ACTION_CHALLENGES_KEY = 'PlatformAdmin.actionChallengeIds';
    private const SENSITIVE_ACTION_FRESHNESS_MINUTES = 15;

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
     * Authenticate a platform admin with password then emailed code.
     *
     * @return \Cake\Http\Response|null
     */
    public function login(): ?Response
    {
        if ($this->platformAdmin() !== null) {
            return $this->redirect(['action' => 'index']);
        }
        $session = $this->request->getSession();
        $pendingEmail = $session->read(self::LOGIN_EMAIL_KEY);
        if ($this->request->is('post')) {
            $auth = new PlatformAdminAuthService();
            $audit = new PlatformAuditService();
            try {
                if ($this->request->getData('restart_login') !== null) {
                    $session->delete(self::LOGIN_CHALLENGE_KEY);
                    $session->delete(self::LOGIN_EMAIL_KEY);

                    return $this->redirect(['action' => 'login']);
                }
                if ($this->request->getData('email_code') !== null) {
                    $challengeId = $session->read(self::LOGIN_CHALLENGE_KEY);
                    if (!is_numeric($challengeId)) {
                        throw new RuntimeException('Start sign-in again to request a new email code.');
                    }
                    $token = $auth->completeLogin(
                        (int)$challengeId,
                        (string)$this->request->getData('email_code'),
                        $this->request,
                    );
                    $session->delete(self::LOGIN_CHALLENGE_KEY);
                    $session->delete(self::LOGIN_EMAIL_KEY);
                    $session->renew();
                } else {
                    $challenge = $auth->beginLogin(
                        (string)$this->request->getData('email'),
                        (string)$this->request->getData('password'),
                        $this->request,
                    );
                    $session->write(self::LOGIN_CHALLENGE_KEY, $challenge['challengeId']);
                    $session->write(self::LOGIN_EMAIL_KEY, $challenge['email']);
                    $pendingEmail = $challenge['email'];
                    $this->Flash->success(__('Verification code sent to {0}.', $challenge['email']));
                    $this->set(compact('pendingEmail'));

                    return null;
                }
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
        $this->set(compact('pendingEmail'));

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
     * Change a platform admin password.
     *
     * @return \Cake\Http\Response|null
     */
    public function changePassword(): ?Response
    {
        $admin = $this->requirePlatformAdmin();
        if ($admin === null) {
            return $this->redirect(['action' => 'login']);
        }
        if ($this->request->is('post')) {
            try {
                $newPassword = (string)$this->request->getData('new_password');
                $confirmPassword = (string)$this->request->getData('confirm_password');
                if ($newPassword !== $confirmPassword) {
                    throw new RuntimeException('New password confirmation does not match.');
                }
                (new PlatformAdminAuthService())->changePassword(
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
                $this->Flash->success(__('Password changed. Sign in again; verification codes will be emailed.'));

                return $this->redirect(['action' => 'login'])
                    ->withExpiredCookie(new Cookie(self::COOKIE_NAME));
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
        $this->set(compact('admin'));

        return null;
    }

    /**
     * Email a one-time code for high-risk platform admin actions.
     */
    public function requestActionCode(): Response
    {
        $admin = $this->requirePlatformAdmin();
        if ($admin === null) {
            return $this->redirect(['action' => 'login']);
        }
        $this->request->allowMethod(['post']);
        $actionLabel = trim((string)$this->request->getData('action_label', 'Platform admin action'));
        try {
            $this->assertCanRequestActionCode($admin, $actionLabel);
            $challenge = (new PlatformAdminAuthService())->requestActionCode(
                $admin,
                $this->request,
                $actionLabel,
            );
            $session = $this->request->getSession();
            $challenges = (array)$session->read(self::ACTION_CHALLENGES_KEY);
            $challenges[$actionLabel] = $challenge['challengeId'];
            $session->write(self::ACTION_CHALLENGES_KEY, $challenges);
            (new PlatformAuditService())->record(
                'platform_admin.action_code',
                'success',
                ['purpose' => 'action', 'action_label' => $actionLabel],
                $admin,
                $this->request,
            );
            $this->Flash->success(__('Action verification code sent to {0}.', $challenge['email']));
        } catch (ForbiddenException $e) {
            return $this->response
                ->withStatus(403)
                ->withType('text/plain')
                ->withStringBody($e->getMessage());
        } catch (Throwable $e) {
            (new PlatformAuditService())->record(
                'platform_admin.action_code',
                'failure',
                ['error' => $e->getMessage()],
                $admin,
                $this->request,
            );
            $this->Flash->error(__('Unable to send an action verification code.'));
        }

        return $this->redirect($this->referer(['action' => 'index'], true));
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
        $this->assertAdminCapability($admin, PlatformAdmin::CAPABILITY_VIEW_DASHBOARD);
        $tenants = (new TenantProvisioningService())->listTenants();
        $filters = $this->operationFilters();
        $tenantBySlug = [];
        foreach ($tenants as $tenant) {
            $tenantBySlug[(string)$tenant->slug] = $tenant;
        }
        $selectedTenant = null;
        if ($filters['tenant'] !== '') {
            $selectedTenant = $tenantBySlug[$filters['tenant']] ?? null;
        }
        $sortMap = $this->operationSortMap();
        $jobsQuery = $this->fetchTable('TenantOperationJobs')->find()
            ->contain(['Tenants', 'PlatformAdmins'])
            ->limit($filters['limit']);
        if ($filters['state'] !== '') {
            $jobsQuery->where(['TenantOperationJobs.state' => $filters['state']]);
        }
        if ($selectedTenant instanceof Tenant) {
            $jobsQuery->where(['TenantOperationJobs.tenant_id' => (int)$selectedTenant->id]);
        }
        if ($filters['correlation'] !== '') {
            $jobsQuery->where(['TenantOperationJobs.operation_correlation_id' => $filters['correlation']]);
        }
        $jobs = $jobsQuery
            ->orderBy($sortMap[$filters['sort']] ?? $sortMap['created_desc'])
            ->all()
            ->toList();
        $relationshipSummary = $this->operationRelationshipSummary($jobs);
        $operationRows = array_map(
            fn(TenantOperationJob $job): array => $this->buildOperationRow($job, $admin, $relationshipSummary),
            $jobs,
        );
        $operationTenantOptions = ['' => __('All tenants')];
        foreach ($tenants as $tenant) {
            $operationTenantOptions[(string)$tenant->slug] = (string)$tenant->display_name;
        }
        $operationStateOptions = ['' => __('All states')] + $this->operationStateLabels();
        $operationSortOptions = $this->operationSortLabels();
        $capabilities = $this->platformAdminCapabilities($admin);
        $tenantHealth = (new TenantHealthSurfaceService())->buildAdminHealthSummary();
        $deploymentMigrationFilters = $this->deploymentMigrationFilters();
        $deploymentMigrationDashboard = $this->buildDeploymentMigrationDashboard($admin, $deploymentMigrationFilters);

        if ($this->wantsJsonResponse()) {
            $payload = json_encode([
                'filters' => $filters,
                'deployment_migrations' => $deploymentMigrationDashboard,
            ], JSON_UNESCAPED_SLASHES);
            if ($payload === false) {
                throw new RuntimeException('Unable to serialize deployment migration dashboard payload.');
            }

            return $this->response
                ->withType('application/json')
                ->withStringBody($payload);
        }

        $this->set(compact(
            'admin',
            'capabilities',
            'tenants',
            'jobs',
            'operationRows',
            'operationTenantOptions',
            'operationStateOptions',
            'operationSortOptions',
            'filters',
            'tenantHealth',
            'deploymentMigrationFilters',
            'deploymentMigrationDashboard',
        ));

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
        $this->assertAdminCapability($admin, PlatformAdmin::CAPABILITY_VIEW_DASHBOARD);
        $service = new TenantProvisioningService();
        $tenant = $service->getTenant($slug);
        $doctor = $service->doctor($tenant);
        $doctorFindings = $this->buildDoctorFindings($doctor, $tenant, $admin);
        $filters = $this->operationFilters((string)$tenant->slug);
        $sortMap = $this->operationSortMap();
        $jobsQuery = $this->fetchTable('TenantOperationJobs')->find()
            ->where(['tenant_id' => $tenant->id])
            ->limit($filters['limit']);
        if ($filters['state'] !== '') {
            $jobsQuery->where(['state' => $filters['state']]);
        }
        if ($filters['correlation'] !== '') {
            $jobsQuery->where(['operation_correlation_id' => $filters['correlation']]);
        }
        $jobs = $jobsQuery
            ->orderBy($sortMap[$filters['sort']] ?? $sortMap['created_desc'])
            ->all()
            ->toList();
        $relationshipSummary = $this->operationRelationshipSummary($jobs);
        $operationRows = array_map(
            fn(TenantOperationJob $job): array => $this->buildOperationRow($job, $admin, $relationshipSummary),
            $jobs,
        );
        $operationTenantOptions = [(string)$tenant->slug => (string)$tenant->display_name];
        $operationStateOptions = ['' => __('All states')] + $this->operationStateLabels();
        $operationSortOptions = $this->operationSortLabels();
        $capabilities = $this->platformAdminCapabilities($admin);
        $secretRotationRows = $this->buildSecretRotationVerificationRows($tenant, $admin);
        $backups = [];
        try {
            if ($this->hasActivePrimaryDatabaseConfig($tenant)) {
                $this->configureTenant($tenant);
                $backups = $this->fetchTable('Backups')->find()
                    ->orderByDesc('created')
                    ->limit(10)
                    ->all()
                    ->toList();
            }
        } finally {
            TenantContext::clearCurrent();
        }

        $this->set(compact(
            'admin',
            'capabilities',
            'tenant',
            'doctor',
            'doctorFindings',
            'jobs',
            'operationRows',
            'operationTenantOptions',
            'operationStateOptions',
            'operationSortOptions',
            'filters',
            'secretRotationRows',
            'backups',
        ));

        if ($this->request->is('json') || $this->request->accepts('application/json')) {
            $payload = json_encode([
                'tenant' => [
                    'id' => (int)$tenant->id,
                    'slug' => (string)$tenant->slug,
                    'display_name' => (string)$tenant->display_name,
                ],
                'doctor' => $doctor,
                'doctor_findings' => $doctorFindings,
                'secret_rotation_verifications' => $secretRotationRows,
            ], JSON_UNESCAPED_SLASHES);
            if ($payload === false) {
                throw new RuntimeException('Unable to serialize tenant response.');
            }

            return $this->response
                ->withType('application/json')
                ->withStringBody($payload);
        }

        return null;
    }

    /**
     * Secret-rotation verification API for Platform Admin operations.
     *
     * @param string $slug Tenant slug
     * @return \Cake\Http\Response|null
     */
    public function secretRotationStatus(string $slug): ?Response
    {
        $admin = $this->requirePlatformAdmin();
        if ($admin === null) {
            return $this->redirect(['action' => 'login']);
        }
        $this->assertAdminCapability($admin, PlatformAdmin::CAPABILITY_VIEW_DASHBOARD);
        $tenant = (new TenantProvisioningService())->getTenant($slug);
        $payload = json_encode([
            'tenant' => [
                'id' => (int)$tenant->id,
                'slug' => (string)$tenant->slug,
                'display_name' => (string)$tenant->display_name,
            ],
            'secret_rotation_verifications' => $this->buildSecretRotationVerificationRows($tenant, $admin),
        ], JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            throw new RuntimeException('Unable to serialize secret rotation status response.');
        }

        return $this->response
            ->withType('application/json')
            ->withStringBody($payload);
    }

    /**
     * Render gateway-approved operation command catalog for operators.
     */
    public function commandCatalog(): ?Response
    {
        $admin = $this->requirePlatformAdmin();
        if ($admin === null) {
            return $this->redirect(['action' => 'login']);
        }
        $this->assertAdminCapability($admin, PlatformAdmin::CAPABILITY_VIEW_DASHBOARD);
        $capabilities = $this->platformAdminCapabilities($admin);
        $catalogRows = $this->buildCommandCatalogRows($admin);
        $catalog = array_map(
            static fn(array $row): array => (array)$row['command'],
            $catalogRows,
        );
        $this->set(compact('admin', 'capabilities', 'catalogRows', 'catalog'));

        if ($this->request->is('json') || $this->request->accepts('application/json')) {
            $payload = json_encode(['catalog' => $catalog], JSON_UNESCAPED_SLASHES);
            if ($payload === false) {
                throw new RuntimeException('Unable to serialize command catalog response.');
            }

            return $this->response
                ->withType('application/json')
                ->withStringBody($payload);
        }

        return null;
    }

    /**
     * Retry a terminal or blocked operation by creating a new approved job.
     *
     * @param int $id Operation id
     * @return \Cake\Http\Response
     */
    public function retryOperation(int $id): Response
    {
        $admin = $this->requirePlatformAdmin();
        if ($admin === null) {
            return $this->redirect(['action' => 'login']);
        }
        try {
            $this->assertAdminCapability($admin, PlatformAdmin::CAPABILITY_OPERATE_TENANTS);
        } catch (ForbiddenException $e) {
            return $this->forbiddenResponse($e);
        }
        $this->request->allowMethod(['post']);
        $jobs = $this->fetchTable('TenantOperationJobs');
        $job = $jobs->find()->contain(['Tenants'])->where(['TenantOperationJobs.id' => $id])->firstOrFail();
        if (!$job instanceof TenantOperationJob) {
            throw new RuntimeException('Operation was not found.');
        }
        $actions = $this->operationActionAvailability($job, $admin);
        if (!$actions['retry']['enabled']) {
            $this->Flash->error((string)$actions['retry']['reason']);

            return $this->redirect($this->referer(['action' => 'index'], true));
        }

        $retryCorrelationId = $this->operationCorrelationId();
        $approvalPolicy = $this->operationApprovalPolicy($job);
        $approvalsRequired = max(0, (int)($approvalPolicy['required_approvals'] ?? 0));
        $retryState = $approvalsRequired > 0
            ? TenantOperationJob::STATUS_APPROVAL_REQUIRED
            : TenantOperationJob::STATUS_APPROVED;
        $retryJob = $jobs->newEntity([
            'tenant_id' => $job->tenant_id,
            'platform_admin_id' => (int)$admin->id,
            'operation' => (string)$job->operation,
            'state' => $retryState,
            'status' => $retryState,
            'approval_policy_json' => $approvalPolicy,
            'approvals_required' => $approvalsRequired,
            'approvals_received' => 0,
            'approval_rejected_at' => null,
            'approval_rejection_reason' => null,
            'idempotency_scope' => (string)($job->idempotency_scope ?? 'tenant'),
            'idempotency_key' => Text::uuid(),
            'input' => is_array($job->input ?? null) ? $job->input : [],
            'progress_percent' => null,
            'progress_json' => [
                'phase' => 'retry',
                'message' => sprintf('Retry requested from operation #%d', (int)$job->id),
                'retried_from_operation_id' => (int)$job->id,
                'retried_from_correlation_id' => (string)($job->operation_correlation_id ?? ''),
                'updated_at' => DateTime::now()->toIso8601String(),
            ],
            'status_message' => $retryState === TenantOperationJob::STATUS_APPROVAL_REQUIRED
                ? sprintf('Retry queued from operation #%d and waiting for approvals.', (int)$job->id)
                : sprintf('Queued for retry from operation #%d.', (int)$job->id),
            'operation_correlation_id' => $retryCorrelationId,
            'operation_image' => $job->operation_image,
            'operation_version' => $job->operation_version,
        ]);
        $jobs->saveOrFail($retryJob);
        $auditContext = $job->tenant instanceof Tenant
            ? $this->jobAuditContext($job->tenant, $retryJob)
            : [
                'operation_id' => (string)$retryJob->id,
                'correlation_id' => (string)$retryJob->operation_correlation_id,
            ];
        (new PlatformAuditService())->record(
            'tenant.operation_retry',
            'success',
            [
                'subject_type' => 'tenant_operation_job',
                'subject_id' => (string)$retryJob->id,
                'retried_from_operation_id' => (string)$job->id,
                'retried_from_correlation_id' => (string)($job->operation_correlation_id ?? ''),
            ],
            $admin,
            $this->request,
            $retryJob->tenant_id !== null ? (int)$retryJob->tenant_id : null,
            'platform_admin',
            $auditContext,
        );
        $this->Flash->success(__('Retry queued as operation #{0}.', (int)$retryJob->id));

        return $this->redirect($this->referer(['action' => 'index'], true));
    }

    /**
     * Request cancellation for an operation.
     *
     * @param int $id Operation id
     * @return \Cake\Http\Response
     */
    public function cancelOperation(int $id): Response
    {
        $admin = $this->requirePlatformAdmin();
        if ($admin === null) {
            return $this->redirect(['action' => 'login']);
        }
        try {
            $this->assertAdminCapability($admin, PlatformAdmin::CAPABILITY_OPERATE_TENANTS);
        } catch (ForbiddenException $e) {
            return $this->forbiddenResponse($e);
        }
        $this->request->allowMethod(['post']);
        $jobs = $this->fetchTable('TenantOperationJobs');
        $job = $jobs->find()->contain(['Tenants'])->where(['TenantOperationJobs.id' => $id])->firstOrFail();
        if (!$job instanceof TenantOperationJob) {
            throw new RuntimeException('Operation was not found.');
        }
        $actions = $this->operationActionAvailability($job, $admin);
        if (!$actions['cancel']['enabled']) {
            $this->Flash->error((string)$actions['cancel']['reason']);

            return $this->redirect($this->referer(['action' => 'index'], true));
        }

        $job->cancelled_at = DateTime::now();
        if ((string)$job->lifecycle_state === TenantOperationJob::STATUS_RUNNING) {
            $job->status_message = 'Cancellation requested by platform admin.';
        } else {
            $job->state = TenantOperationJob::STATUS_CANCELLED;
            $job->status = TenantOperationJob::STATUS_CANCELLED;
            $job->completed_at = DateTime::now();
            $job->status_message = 'Cancelled by platform admin.';
        }
        $jobs->saveOrFail($job);
        $auditContext = $job->tenant instanceof Tenant
            ? $this->jobAuditContext($job->tenant, $job)
            : [
                'operation_id' => (string)$job->id,
                'correlation_id' => (string)$job->operation_correlation_id,
            ];
        (new PlatformAuditService())->record(
            'tenant.operation_cancel',
            'success',
            [
                'subject_type' => 'tenant_operation_job',
                'subject_id' => (string)$job->id,
                'cancelled_state' => (string)$job->lifecycle_state,
            ],
            $admin,
            $this->request,
            $job->tenant_id !== null ? (int)$job->tenant_id : null,
            'platform_admin',
            $auditContext,
        );
        $this->Flash->success(__('Cancellation request recorded for operation #{0}.', (int)$job->id));

        return $this->redirect($this->referer(['action' => 'index'], true));
    }

    /**
     * Resume a held or blocked deployment migration parent operation.
     *
     * @param int $id Operation id
     * @return \Cake\Http\Response
     */
    public function resumeOperation(int $id): Response
    {
        $admin = $this->requirePlatformAdmin();
        if ($admin === null) {
            return $this->redirect(['action' => 'login']);
        }
        try {
            $this->assertAdminCapability($admin, PlatformAdmin::CAPABILITY_OPERATE_TENANTS);
        } catch (ForbiddenException $e) {
            return $this->forbiddenResponse($e);
        }
        $this->request->allowMethod(['post']);

        $service = new DeploymentMigrationOrchestratorService();
        $result = $service->orchestrate([
            'resume_parent_id' => $id,
            'wait' => false,
            'skip_platform_gate' => true,
            'on_failure' => 'hold',
        ]);
        (new PlatformAuditService())->record(
            'tenant.operation_resume',
            'success',
            [
                'subject_type' => 'tenant_operation_job',
                'subject_id' => (string)$id,
                'resume_parent_id' => $id,
                'state' => (string)($result['state'] ?? ''),
                'counts' => (array)($result['counts'] ?? []),
            ],
            $admin,
            $this->request,
            null,
            'platform_admin',
            [
                'operation_id' => (string)$id,
                'correlation_id' => (string)($result['correlation_id'] ?? ''),
            ],
        );

        if ($this->wantsJsonResponse()) {
            $payload = json_encode([
                'ok' => true,
                'resume_parent_id' => $id,
                'state' => (string)($result['state'] ?? ''),
                'counts' => (array)($result['counts'] ?? []),
                'correlation_id' => (string)($result['correlation_id'] ?? ''),
            ], JSON_UNESCAPED_SLASHES);
            if ($payload === false) {
                throw new RuntimeException('Unable to serialize resume operation response.');
            }

            return $this->response
                ->withType('application/json')
                ->withStringBody($payload);
        }

        $this->Flash->success(__('Resume queued for deployment migration parent #{0}.', $id));

        return $this->redirect($this->referer(['action' => 'index'], true));
    }

    /**
     * Record an approval decision for a queued sensitive operation.
     *
     * @param int $id Operation id
     * @return \Cake\Http\Response
     */
    public function approveOperation(int $id): Response
    {
        return $this->decideOperationApproval($id, TenantOperationApproval::DECISION_APPROVED);
    }

    /**
     * Record a rejection decision for a queued sensitive operation.
     *
     * @param int $id Operation id
     * @return \Cake\Http\Response
     */
    public function rejectOperation(int $id): Response
    {
        return $this->decideOperationApproval($id, TenantOperationApproval::DECISION_REJECTED);
    }

    /**
     * @param int $id Operation id
     * @param string $decision approved|rejected
     * @return \Cake\Http\Response
     */
    private function decideOperationApproval(int $id, string $decision): Response
    {
        $admin = $this->requirePlatformAdmin();
        if ($admin === null) {
            return $this->redirect(['action' => 'login']);
        }
        $this->request->allowMethod(['post']);
        $jobs = $this->fetchTable('TenantOperationJobs');
        $job = $jobs->find()->contain(['Tenants'])->where(['TenantOperationJobs.id' => $id])->firstOrFail();
        if (!$job instanceof TenantOperationJob) {
            throw new RuntimeException('Operation was not found.');
        }
        $actions = $this->operationActionAvailability($job, $admin);
        $actionKey = $decision === TenantOperationApproval::DECISION_REJECTED ? 'reject' : 'approve';
        if (!($actions[$actionKey]['enabled'] ?? false)) {
            return $this->forbiddenResponse(new ForbiddenException(
                (string)($actions[$actionKey]['reason'] ?? 'Decision not allowed.'),
            ));
        }

        $now = DateTime::now();
        $note = trim((string)$this->request->getData('decision_note', ''));
        $approvals = $this->fetchTable('TenantOperationApprovals');
        $approval = $approvals->newEntity([
            'tenant_operation_job_id' => (int)$job->id,
            'platform_admin_id' => (int)$admin->id,
            'approval_type' => 'gateway_approved',
            'decision' => $decision,
            'decision_note' => $note === '' ? null : $note,
            'decided_at' => $now,
            'approved_at' => $decision === TenantOperationApproval::DECISION_APPROVED ? $now : null,
        ]);
        $approvals->saveOrFail($approval);

        if ($decision === TenantOperationApproval::DECISION_REJECTED) {
            $job->state = TenantOperationJob::STATUS_CANCELLED;
            $job->status = TenantOperationJob::STATUS_CANCELLED;
            $job->approval_rejected_at = $now;
            $job->approval_rejection_reason = $note === '' ? 'Rejected by platform admin.' : $note;
            $job->status_message = $note === '' ? 'Rejected by platform admin.' : $note;
            $job->completed_at = $now;
        } else {
            $approvalsReceived = (int)$approvals->find()
                ->where([
                    'tenant_operation_job_id' => (int)$job->id,
                    'decision' => TenantOperationApproval::DECISION_APPROVED,
                ])
                ->count();
            $job->approvals_received = $approvalsReceived;
            $required = max(0, (int)($job->approvals_required ?? 0));
            if ($approvalsReceived >= $required) {
                $job->state = TenantOperationJob::STATUS_APPROVED;
                $job->status = TenantOperationJob::STATUS_APPROVED;
                $job->status_message = 'Approval threshold met. Operation queued for worker execution.';
            } else {
                $job->state = TenantOperationJob::STATUS_APPROVAL_REQUIRED;
                $job->status = TenantOperationJob::STATUS_APPROVAL_REQUIRED;
                $job->status_message = sprintf(
                    'Waiting for %d additional approval(s).',
                    max(0, $required - $approvalsReceived),
                );
            }
        }
        $jobs->saveOrFail($job);

        $auditContext = $job->tenant instanceof Tenant
            ? $this->jobAuditContext($job->tenant, $job)
            : ['operation_id' => (string)$job->id, 'correlation_id' => (string)$job->operation_correlation_id];
        (new PlatformAuditService())->record(
            'tenant.operation_approval',
            'success',
            [
                'subject_type' => 'tenant_operation_job',
                'subject_id' => (string)$job->id,
                'decision' => $decision,
                'approvals_received' => (int)($job->approvals_received ?? 0),
                'approvals_required' => (int)($job->approvals_required ?? 0),
            ],
            $admin,
            $this->request,
            $job->tenant_id !== null ? (int)$job->tenant_id : null,
            'platform_admin',
            $auditContext,
        );

        if ($this->wantsJsonResponse()) {
            $payload = [
                'ok' => true,
                'operation_id' => (int)$job->id,
                'decision' => $decision,
                'state' => (string)$job->state,
                'approvals_required' => (int)($job->approvals_required ?? 0),
                'approvals_received' => (int)($job->approvals_received ?? 0),
            ];

            return $this->response->withType('application/json')->withStringBody((string)json_encode($payload));
        }

        $this->Flash->success(
            $decision === TenantOperationApproval::DECISION_REJECTED
                ? __('Operation #{0} rejected.', (int)$job->id)
                : __('Approval recorded for operation #{0}.', (int)$job->id),
        );

        return $this->redirect($this->referer(['action' => 'index'], true));
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
        try {
            $this->assertAdminCapability($admin, PlatformAdmin::CAPABILITY_PROVISION_TENANTS);
        } catch (ForbiddenException $e) {
            return $this->forbiddenResponse($e);
        }
        if ($this->request->is('post')) {
            try {
                $this->assertManagedSecretsReadyForRequest();
                $this->verifyAction($admin, 'Create or update tenant');
                $data = $this->tenantPayloadFromRequest();
                $tenantPayload = $data;
                $tenantPayload['create_database'] = (bool)$this->request->getData('create_database');
                $tenantPayload['migrate'] = (bool)$this->request->getData('migrate');
                $tenantPayload['activate'] = (bool)$this->request->getData('activate');
                $tenantPayload['database_secret_value'] = (string)$this->request->getData('database_secret_value', '');
                $tenantPayload['email_secret_value'] = (string)$this->request->getData('email_secret_value', '');
                $tenantPayload['storage_secret_value'] = (string)$this->request->getData('storage_secret_value', '');
                $job = $this->recordJob(
                    $admin,
                    null,
                    'tenant_create',
                    TenantOperationJob::STATUS_APPROVED,
                    [
                        'tenant' => $tenantPayload,
                        'tenant_slug' => (string)($data['slug'] ?? ''),
                    ],
                );
                (new PlatformAuditService())->record(
                    'tenant.create',
                    'success',
                    ['subject_type' => 'tenant', 'subject_id' => (string)($data['slug'] ?? '')],
                    $admin,
                    $this->request,
                    null,
                    'platform_admin',
                    [
                        'tenant_slug' => (string)($data['slug'] ?? ''),
                        'operation_id' => (string)$job->id,
                        'correlation_id' => (string)$job->operation_correlation_id,
                        'operation_image' => $job->operation_image,
                        'operation_version' => $job->operation_version,
                    ],
                );
                $this->Flash->success(__('Tenant create/update queued as job #{0}.', (int)$job->id));

                return $this->redirect(['action' => 'index']);
            } catch (Throwable $e) {
                (new PlatformAuditService())->record(
                    'tenant.create',
                    'failure',
                    ['error' => $e->getMessage()],
                    $admin,
                    $this->request,
                    null,
                    'platform_admin',
                    ['tenant_slug' => (string)$this->request->getData('slug', '')],
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
        try {
            $this->assertAdminCapability($admin, PlatformAdmin::CAPABILITY_OPERATE_TENANTS);
        } catch (ForbiddenException $e) {
            return $this->forbiddenResponse($e);
        }
        $this->request->allowMethod(['post']);
        try {
            $normalizedParameters = $this->preflightGatewayOperation(
                $admin,
                'tenant_status',
                'single',
                ['status' => $status],
            );
            $this->verifyAction($admin, 'Set tenant ' . $slug . ' status to ' . $status);
            $tenant = (new TenantProvisioningService())->getTenant($slug);
            $submission = (new TenantOperationGatewayService())->submitApprovedRequest(
                operation: 'tenant_status',
                requester: $admin,
                tenantTargetMode: 'single',
                parameters: $normalizedParameters,
                tenantSlugs: [(string)$tenant->slug],
                approvedBy: $admin,
                correlationId: $this->operationCorrelationId(),
                idempotencyKey: sprintf('platform-admin:tenant-status:%s:%s', $slug, $status),
            );
            $job = $submission['jobs'][0] ?? null;
            if (!$job instanceof TenantOperationJob) {
                throw new RuntimeException('Failed to queue tenant status operation.');
            }
            (new PlatformAuditService())->record(
                'tenant.status',
                'success',
                [
                    'subject_type' => 'tenant',
                    'subject_id' => (string)$tenant->id,
                    'status' => $status,
                    'queued_job_id' => (int)$job->id,
                    'deduplicated_count' => (int)$submission['deduplicated_count'],
                ],
                $admin,
                $this->request,
                (int)$tenant->id,
                'platform_admin',
                $this->jobAuditContext($tenant, $job),
            );
            $this->Flash->success(__('Tenant status operation queued as job #{0}.', (int)$job->id));
        } catch (Throwable $e) {
            $this->Flash->error($e->getMessage());
        }

        return $this->redirect(['action' => 'viewTenant', $slug]);
    }

    /**
     * Queue a gateway-backed remediation action for a tenant doctor finding.
     *
     * @param string $slug Tenant slug
     * @param string $finding Doctor finding key
     * @param string $action Remediation action key
     * @return \Cake\Http\Response
     */
    public function remediateDoctorFinding(string $slug, string $finding, string $action): Response
    {
        $admin = $this->requirePlatformAdmin();
        if ($admin === null) {
            return $this->redirect(['action' => 'login']);
        }
        $this->assertAdminCapability($admin, PlatformAdmin::CAPABILITY_VIEW_DASHBOARD);
        $this->request->allowMethod(['post']);
        try {
            $service = new TenantProvisioningService();
            $tenant = $service->getTenant($slug);
            $doctorChecks = $service->doctor($tenant);
            $check = is_array($doctorChecks[$finding] ?? null) ? $doctorChecks[$finding] : [];
            $remediation = $this->resolveDoctorRemediationAction($tenant, $admin, $finding, $action, $check);
            if (!(bool)($remediation['enabled'] ?? false)) {
                throw new RuntimeException((string)(
                    $remediation['reason'] ?? 'This remediation action is not available.'
                ));
            }
            $operation = (string)$remediation['operation'];
            /** @var array<string, mixed> $parameters */
            $parameters = is_array($remediation['parameters'] ?? null) ? $remediation['parameters'] : [];
            $normalizedParameters = $this->preflightGatewayOperation($admin, $operation, 'single', $parameters);
            $actionLabel = (string)($remediation['action_label'] ?? '');
            if ($actionLabel === '') {
                throw new RuntimeException('Remediation action label is missing.');
            }
            $this->verifyAction($admin, $actionLabel);
            $submission = (new TenantOperationGatewayService())->submitApprovedRequest(
                operation: $operation,
                requester: $admin,
                tenantTargetMode: 'single',
                parameters: $normalizedParameters,
                tenantSlugs: [(string)$tenant->slug],
                approvedBy: $admin,
                correlationId: $this->operationCorrelationId(),
                idempotencyKey: sprintf(
                    'platform-admin:doctor-remediation:%s:%s:%s',
                    (string)$tenant->slug,
                    $finding,
                    Text::uuid(),
                ),
            );
            $job = $submission['jobs'][0] ?? null;
            if (!$job instanceof TenantOperationJob) {
                throw new RuntimeException('Failed to queue doctor remediation operation.');
            }
            (new PlatformAuditService())->record(
                'tenant.doctor_remediation',
                'success',
                [
                    'subject_type' => 'tenant',
                    'subject_id' => (string)$tenant->id,
                    'finding' => $finding,
                    'action' => $action,
                    'operation' => $operation,
                    'queued_job_id' => (int)$job->id,
                    'deduplicated_count' => (int)$submission['deduplicated_count'],
                ],
                $admin,
                $this->request,
                (int)$tenant->id,
                'platform_admin',
                $this->jobAuditContext($tenant, $job),
            );
            if ((string)$job->lifecycle_state === TenantOperationJob::STATUS_APPROVAL_REQUIRED) {
                $this->Flash->success(__(
                    'Remediation queued as job #{0} and is awaiting additional approval.',
                    (int)$job->id,
                ));
            } else {
                $this->Flash->success(__('Remediation queued as job #{0}.', (int)$job->id));
            }
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
        $this->assertAdminCapability($admin, PlatformAdmin::CAPABILITY_MANAGE_SECRETS);
        $this->request->allowMethod(['post']);
        try {
            $this->assertManagedSecretsReadyForRequest();
            $this->verifyAction($admin, 'Update managed secrets for tenant ' . $slug);
            $tenant = (new TenantProvisioningService())->getTenant($slug);
            $changed = $this->storeTenantSecrets($admin, $tenant, includeDatabase: false);
            $databaseRotationJob = null;
            $databaseSecretValue = trim((string)$this->request->getData('database_secret_value', ''));
            if ($databaseSecretValue !== '') {
                $newReference = $this->storeTenantDatabaseRotationSecret($admin, $tenant, $databaseSecretValue);
                $normalizedParameters = $this->preflightGatewayOperation(
                    $admin,
                    'tenant_rotate_db_secret',
                    'single',
                    [
                        'new_secret_reference' => $newReference,
                        'max_attempts' => 1,
                    ],
                );
                $submission = (new TenantOperationGatewayService())->submitApprovedRequest(
                    operation: 'tenant_rotate_db_secret',
                    requester: $admin,
                    tenantTargetMode: 'single',
                    parameters: $normalizedParameters,
                    tenantSlugs: [(string)$tenant->slug],
                    approvedBy: $admin,
                    correlationId: $this->operationCorrelationId(),
                    idempotencyKey: sprintf(
                        'platform-admin:tenant-rotate-db-secret:%s:%s',
                        (string)$tenant->slug,
                        substr(hash('sha256', $newReference), 0, 16),
                    ),
                );
                $databaseRotationJob = $submission['jobs'][0] ?? null;
                if (!$databaseRotationJob instanceof TenantOperationJob) {
                    throw new RuntimeException('Failed to queue tenant database secret rotation operation.');
                }
            }

            if ($changed === [] && $databaseRotationJob === null) {
                $this->Flash->info(__('No secret values were provided.'));
            } else {
                if ($changed !== []) {
                    (new TenantInvalidationService())->bumpTenant((int)$tenant->id, 'tenant_secret_rotated', [
                        'secrets' => $changed,
                    ]);
                }
                $auditSecrets = $changed;
                if ($databaseRotationJob !== null) {
                    $auditSecrets[] = 'database';
                }
                (new PlatformAuditService())->record(
                    'tenant.secret_update',
                    'success',
                    [
                        'subject_type' => 'tenant',
                        'subject_id' => (string)$tenant->id,
                        'secrets' => $auditSecrets,
                        'database_rotation_operation_id' => $databaseRotationJob?->id,
                    ],
                    $admin,
                    $this->request,
                    (int)$tenant->id,
                    'platform_admin',
                    $databaseRotationJob instanceof TenantOperationJob
                        ? $this->jobAuditContext($tenant, $databaseRotationJob)
                        : ['tenant_slug' => (string)$tenant->slug],
                );
                if ($databaseRotationJob instanceof TenantOperationJob) {
                    $this->Flash->success(
                        __(
                            'Tenant managed secrets updated. Database credential rotation queued as operation #{0}.',
                            (int)$databaseRotationJob->id,
                        ),
                    );
                } else {
                    $this->Flash->success(__('Tenant secrets updated.'));
                }
            }
        } catch (Throwable $e) {
            (new PlatformAuditService())->record(
                'tenant.secret_update',
                'failure',
                ['error' => $e->getMessage(), 'subject_id' => $slug],
                $admin,
                $this->request,
                null,
                'platform_admin',
                ['tenant_slug' => $slug],
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
        $this->assertAdminCapability($admin, PlatformAdmin::CAPABILITY_MANAGE_RECOVERY);
        $this->request->allowMethod(['post']);
        try {
            $this->verifyAction($admin, 'Create backup for tenant ' . $slug);
            $tenant = (new TenantProvisioningService())->getTenant($slug);
            $key = (string)$this->request->getData('backup_key');
            if ($key === '') {
                throw new RuntimeException('Backup encryption key is required.');
            }
            $job = $this->recordJob($admin, $tenant, 'tenant_backup', TenantOperationJob::STATUS_APPROVED, [
                'tenant_slug' => (string)$tenant->slug,
                'backup_key' => $key,
            ]);
            (new PlatformAuditService())->record(
                'tenant.backup',
                'success',
                ['subject_type' => 'tenant', 'subject_id' => (string)$tenant->id],
                $admin,
                $this->request,
                (int)$tenant->id,
                'platform_admin',
                $this->jobAuditContext($tenant, $job),
            );
            $this->Flash->success(__('Backup queued as job #{0}.', (int)$job->id));
        } catch (Throwable $e) {
            (new PlatformAuditService())->record(
                'tenant.backup',
                'failure',
                ['error' => $e->getMessage(), 'subject_id' => $slug],
                $admin,
                $this->request,
                null,
                'platform_admin',
                ['tenant_slug' => $slug],
            );
            $this->Flash->error($e->getMessage());
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
        $this->assertAdminCapability($admin, PlatformAdmin::CAPABILITY_MANAGE_RECOVERY);
        $this->request->allowMethod(['post']);
        try {
            $this->verifyAction($admin, 'Restore backup for tenant ' . $slug);
            $tenant = (new TenantProvisioningService())->getTenant($slug);
            $newDatabase = trim((string)$this->request->getData('new_database_name'));
            if ($newDatabase === '' || !preg_match('/^[A-Za-z0-9_]+$/', $newDatabase)) {
                throw new RuntimeException('A simple alphanumeric restore database name is required.');
            }
            $primaryConfig = $this->primaryDatabaseConfig($tenant);
            $this->assertRestoreTargetDatabaseIsSafe(
                $tenant,
                $newDatabase,
                (string)$primaryConfig->database_name,
            );
            $backupUpload = $this->backupUploadPayload($this->request->getData('backup_file'));
            $backupBytes = $backupUpload['bytes'];
            $key = (string)$this->request->getData('restore_key');
            if ($key === '') {
                throw new RuntimeException('Restore encryption key is required.');
            }
            $job = $this->recordJob($admin, $tenant, 'tenant_restore_cutover', TenantOperationJob::STATUS_APPROVED, [
                'tenant_slug' => (string)$tenant->slug,
                'new_database_name' => $newDatabase,
                'restore_key' => $key,
                'backup_file_name' => $backupUpload['filename'],
                'backup_file_base64' => base64_encode($backupBytes),
                'requested_primary_config_state' => $this->databaseConfigSnapshot($primaryConfig),
                'requested_target_primary_config_state' => $this->databaseConfigSnapshot(
                    $primaryConfig,
                    ['database_name' => $newDatabase],
                ),
            ]);
            (new PlatformAuditService())->record(
                'tenant.restore',
                'success',
                [
                    'subject_type' => 'tenant',
                    'subject_id' => (string)$tenant->id,
                    'new_database_name' => $newDatabase,
                    'queue_operation_id' => (int)$job->id,
                ],
                $admin,
                $this->request,
                (int)$tenant->id,
                'platform_admin',
                $this->jobAuditContext($tenant, $job),
            );
            $this->Flash->success(__(
                'Restore queued as job #{0}. Worker will enforce drain mode, run migrate/import/integrity '
                . 'checks, then cut over.',
                (int)$job->id,
            ));
        } catch (Throwable $e) {
            (new PlatformAuditService())->record(
                'tenant.restore',
                'failure',
                ['error' => $e->getMessage(), 'subject_id' => $slug],
                $admin,
                $this->request,
                null,
                'platform_admin',
                ['tenant_slug' => $slug],
            );
            $this->Flash->error($e->getMessage());
            if (str_starts_with($e->getMessage(), 'Restore target database')) {
                return $this->response
                    ->withStatus(200)
                    ->withType('text/plain')
                    ->withStringBody($e->getMessage());
            }
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
        $this->assertAdminCapability($admin, PlatformAdmin::CAPABILITY_VIEW_DASHBOARD);
        $filters = [
            'correlation_id' => trim((string)$this->request->getQuery('correlation_id', '')),
            'operation_id' => trim((string)$this->request->getQuery('operation_id', '')),
        ];
        $events = $this->fetchTable('PlatformAuditEvents')->find()
            ->contain(['PlatformAdmins', 'Tenants'])
            ->orderByDesc('PlatformAuditEvents.created')
            ->limit(300)
            ->all()
            ->toList();
        $correlationIds = [];
        $operationIds = [];
        $auditRows = [];
        foreach ($events as $event) {
            $linkage = $this->auditLinkageFromMetadata($event->metadata);
            if ($filters['correlation_id'] !== '' && $linkage['correlation_id'] !== $filters['correlation_id']) {
                continue;
            }
            if ($filters['operation_id'] !== '' && $linkage['operation_id'] !== $filters['operation_id']) {
                continue;
            }
            if ($linkage['correlation_id'] !== '') {
                $correlationIds[] = $linkage['correlation_id'];
            }
            if ($linkage['operation_id'] !== '') {
                $operationIds[] = (int)$linkage['operation_id'];
            }
            $auditRows[] = [
                'event' => $event,
                'correlation_id' => $linkage['correlation_id'],
                'operation_id' => $linkage['operation_id'],
                'jobs' => [],
            ];
        }
        $jobsByCorrelation = [];
        $jobsById = [];
        $correlationIds = array_values(array_unique($correlationIds));
        $operationIds = array_values(array_unique(array_filter(
            $operationIds,
            static fn(int $value): bool => $value > 0,
        )));
        if ($correlationIds !== [] || $operationIds !== []) {
            $query = $this->fetchTable('TenantOperationJobs')->find()->contain(['Tenants']);
            if ($correlationIds !== [] && $operationIds !== []) {
                $query->where([
                    'OR' => [
                        'TenantOperationJobs.operation_correlation_id IN' => $correlationIds,
                        'TenantOperationJobs.id IN' => $operationIds,
                    ],
                ]);
            } elseif ($correlationIds !== []) {
                $query->where(['TenantOperationJobs.operation_correlation_id IN' => $correlationIds]);
            } else {
                $query->where(['TenantOperationJobs.id IN' => $operationIds]);
            }
            foreach ($query->all() as $job) {
                if ($job->operation_correlation_id !== null && $job->operation_correlation_id !== '') {
                    $jobsByCorrelation[(string)$job->operation_correlation_id][(int)$job->id] = $job;
                }
                $jobsById[(int)$job->id] = $job;
            }
        }
        foreach ($auditRows as &$row) {
            $linked = [];
            if ($row['correlation_id'] !== '' && isset($jobsByCorrelation[$row['correlation_id']])) {
                foreach ($jobsByCorrelation[$row['correlation_id']] as $jobId => $job) {
                    $linked[(int)$jobId] = $job;
                }
            }
            if ($row['operation_id'] !== '') {
                $operationId = (int)$row['operation_id'];
                if (isset($jobsById[$operationId])) {
                    $linked[$operationId] = $jobsById[$operationId];
                }
            }
            $row['jobs'] = array_values($linked);
        }
        unset($row);

        $this->set(compact('admin', 'auditRows', 'filters'));

        return null;
    }

    /**
     * @param mixed $metadata Audit metadata payload
     * @return array{operation_id: string, correlation_id: string}
     */
    private function auditLinkageFromMetadata(mixed $metadata): array
    {
        if (is_string($metadata) && $metadata !== '') {
            $decoded = json_decode($metadata, true);
            $metadata = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($metadata)) {
            $metadata = [];
        }
        $operationId = trim((string)($metadata['operation_id'] ?? ''));
        $correlationId = trim((string)($metadata['correlation_id'] ?? ''));

        return [
            'operation_id' => $operationId,
            'correlation_id' => $correlationId,
        ];
    }

    /**
     * @param string|null $forcedTenantSlug Tenant slug forced by page context
     * @return array{state: string, tenant: string, sort: string, limit: int, correlation: string}
     */
    private function operationFilters(?string $forcedTenantSlug = null): array
    {
        $states = array_keys($this->operationStateLabels());
        $state = trim((string)$this->request->getQuery('state', ''));
        if (!in_array($state, $states, true)) {
            $state = '';
        }
        $tenant = $forcedTenantSlug ?? trim((string)$this->request->getQuery('tenant', ''));
        $sort = trim((string)$this->request->getQuery('sort', 'created_desc'));
        if (!array_key_exists($sort, $this->operationSortMap())) {
            $sort = 'created_desc';
        }
        $correlation = trim((string)$this->request->getQuery('correlation', ''));
        $limit = (int)$this->request->getQuery('limit', 25);
        $limit = max(10, min(100, $limit));

        return [
            'state' => $state,
            'tenant' => $tenant,
            'sort' => $sort,
            'limit' => $limit,
            'correlation' => $correlation,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function operationStateLabels(): array
    {
        return [
            TenantOperationJob::STATUS_QUEUED => __('Queued'),
            TenantOperationJob::STATUS_APPROVAL_REQUIRED => __('Approval required'),
            TenantOperationJob::STATUS_APPROVED => __('Approved'),
            TenantOperationJob::STATUS_RUNNING => __('Running'),
            TenantOperationJob::STATUS_HOLD => __('Hold'),
            TenantOperationJob::STATUS_BLOCKED => __('Blocked'),
            TenantOperationJob::STATUS_COMPLETED => __('Completed'),
            TenantOperationJob::STATUS_FAILED => __('Failed'),
            TenantOperationJob::STATUS_CANCELLED => __('Cancelled'),
        ];
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function operationSortMap(): array
    {
        return [
            'created_desc' => ['TenantOperationJobs.created' => 'DESC'],
            'created_asc' => ['TenantOperationJobs.created' => 'ASC'],
            'updated_desc' => ['TenantOperationJobs.modified' => 'DESC'],
            'updated_asc' => ['TenantOperationJobs.modified' => 'ASC'],
            'state_asc' => ['TenantOperationJobs.state' => 'ASC', 'TenantOperationJobs.created' => 'DESC'],
            'state_desc' => ['TenantOperationJobs.state' => 'DESC', 'TenantOperationJobs.created' => 'DESC'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function operationSortLabels(): array
    {
        return [
            'created_desc' => __('Newest first'),
            'created_asc' => __('Oldest first'),
            'updated_desc' => __('Recently updated'),
            'updated_asc' => __('Least recently updated'),
            'state_asc' => __('State A-Z'),
            'state_desc' => __('State Z-A'),
        ];
    }

    /**
     * @param \App\Model\Entity\TenantOperationJob $job Operation job
     * @param \App\Model\Entity\PlatformAdmin $admin Active admin
     * @param array<string, mixed> $relationshipSummary Parent/child relationship map
     * @return array<string, mixed>
     */
    private function buildOperationRow(
        TenantOperationJob $job,
        PlatformAdmin $admin,
        array $relationshipSummary = [],
    ): array {
        $progress = is_array($job->progress_json ?? null) ? $job->progress_json : [];
        $resultSummary = $this->summarizeOperationResult($job);
        $errorSummary = $this->summarizeOperationError($job);
        $state = (string)$job->lifecycle_state;
        $parentJobId = $this->jobParentId($job);
        $childSummary = (array)($relationshipSummary['children_by_parent'][(int)$job->id] ?? []);
        $parentJob = $parentJobId > 0
            ? (($relationshipSummary['parents_by_id'][$parentJobId] ?? null) instanceof TenantOperationJob
                ? $relationshipSummary['parents_by_id'][$parentJobId]
                : null)
            : null;
        $leaseExpiresAt = $job->lease_expires_at;
        $leaseExpiryTimestamp = is_object($leaseExpiresAt) && method_exists($leaseExpiresAt, 'getTimestamp')
            ? (int)$leaseExpiresAt->getTimestamp()
            : null;
        $isRunning = $state === TenantOperationJob::STATUS_RUNNING;
        $hasLease = $job->lease_owner !== null || $job->lease_token !== null;
        $approvalsRequired = max(0, (int)($job->approvals_required ?? 0));
        $approvalsReceived = max(0, (int)($job->approvals_received ?? 0));
        $approvalPolicy = $this->operationApprovalPolicy($job);
        $isStaleLease = $isRunning
            && $leaseExpiryTimestamp !== null
            && $leaseExpiryTimestamp <= time();
        $hasActiveLease = $isRunning
            && $leaseExpiryTimestamp !== null
            && $leaseExpiryTimestamp > time();

        return [
            'job' => $job,
            'id' => (int)$job->id,
            'state' => $state,
            'status_message' => (string)($job->status_message ?? ''),
            'progress_percent' => $job->progress_percent,
            'progress_phase' => (string)($progress['phase'] ?? ''),
            'progress_checkpoint' => (string)($progress['checkpoint'] ?? ''),
            'progress_updated_at' => (string)($progress['updated_at'] ?? ''),
            'result_summary' => $resultSummary,
            'error_summary' => $errorSummary,
            'correlation_id' => (string)($job->operation_correlation_id ?? ''),
            'parent_job_id' => $parentJobId > 0 ? $parentJobId : null,
            'parent_operation' => $parentJob instanceof TenantOperationJob ? (string)$parentJob->operation : '',
            'is_child_operation' => $parentJobId > 0,
            'child_total' => isset($childSummary['total']) ? (int)$childSummary['total'] : 0,
            'child_state_counts' => is_array($childSummary['states'] ?? null) ? $childSummary['states'] : [],
            'tenant_slug' => (string)($job->tenant->slug ?? ''),
            'tenant_name' => (string)($job->tenant->display_name ?? ''),
            'lock_owner' => (string)($job->lease_owner ?? ''),
            'has_lease' => $hasLease,
            'has_active_lease' => $hasActiveLease,
            'is_stale_lease' => $isStaleLease,
            'approvals_required' => $approvalsRequired,
            'approvals_received' => $approvalsReceived,
            'approvals_pending' => max(0, $approvalsRequired - $approvalsReceived),
            'approval_policy_mode' => (string)($approvalPolicy['mode'] ?? TenantOperationCommandCatalog::APPROVAL_MODE_NONE),
            'actions' => $this->operationActionAvailability($job, $admin),
        ];
    }

    /**
     * @param array<int, \App\Model\Entity\TenantOperationJob> $jobs
     * @return array{children_by_parent: array<int, array{total: int, states: array<string, int>}>, parents_by_id: array<int, \App\Model\Entity\TenantOperationJob>}
     */
    private function operationRelationshipSummary(array $jobs): array
    {
        $jobIds = array_map(fn(TenantOperationJob $job): int => (int)$job->id, $jobs);
        $parentIds = [];
        foreach ($jobs as $job) {
            $parentId = $this->jobParentId($job);
            if ($parentId > 0) {
                $parentIds[] = $parentId;
            }
        }
        $parentIds = array_values(array_unique($parentIds));
        $lookupParentIds = array_values(array_unique(array_merge($jobIds, $parentIds)));
        $childrenByParent = [];
        if ($lookupParentIds !== []) {
            $children = $this->fetchTable('TenantOperationJobs')->find()
                ->select(['parent_tenant_operation_job_id', 'state'])
                ->where(['parent_tenant_operation_job_id IN' => $lookupParentIds])
                ->all();
            foreach ($children as $child) {
                $parentId = (int)($child->parent_tenant_operation_job_id ?? 0);
                if ($parentId < 1) {
                    continue;
                }
                if (!isset($childrenByParent[$parentId])) {
                    $childrenByParent[$parentId] = [
                        'total' => 0,
                        'states' => [],
                    ];
                }
                $childrenByParent[$parentId]['total']++;
                $childState = (string)$child->state;
                if (!isset($childrenByParent[$parentId]['states'][$childState])) {
                    $childrenByParent[$parentId]['states'][$childState] = 0;
                }
                $childrenByParent[$parentId]['states'][$childState]++;
            }
        }
        $parentsById = [];
        if ($parentIds !== []) {
            $parents = $this->fetchTable('TenantOperationJobs')->find()
                ->select(['id', 'operation'])
                ->where(['id IN' => $parentIds])
                ->all();
            foreach ($parents as $parent) {
                $parentsById[(int)$parent->id] = $parent;
            }
        }

        return [
            'children_by_parent' => $childrenByParent,
            'parents_by_id' => $parentsById,
        ];
    }

    /**
     * @return array{state: string, tenant_state: string, correlation: string, limit: int}
     */
    private function deploymentMigrationFilters(): array
    {
        $query = $this->request->getQueryParams();
        $state = trim((string)($query['migration_state'] ?? ''));
        $tenantState = trim((string)($query['migration_tenant_state'] ?? ''));
        $correlation = trim((string)($query['migration_correlation'] ?? ''));
        $limit = (int)($query['migration_limit'] ?? 5);
        $limit = max(1, min(25, $limit));
        $validStates = array_keys($this->operationStateLabels());
        if ($state !== '' && !in_array($state, $validStates, true)) {
            $state = '';
        }
        if ($tenantState !== '' && !in_array($tenantState, $validStates, true)) {
            $tenantState = '';
        }

        return [
            'state' => $state,
            'tenant_state' => $tenantState,
            'correlation' => $correlation,
            'limit' => $limit,
        ];
    }

    /**
     * @param \App\Model\Entity\PlatformAdmin $admin Active admin
     * @param array{state: string, tenant_state: string, correlation: string, limit: int} $filters
     * @return array<string, mixed>
     */
    private function buildDeploymentMigrationDashboard(PlatformAdmin $admin, array $filters): array
    {
        $jobsTable = $this->fetchTable('TenantOperationJobs');
        $parentsQuery = $jobsTable->find()
            ->where(['operation' => 'tenant_migrate_all'])
            ->orderByDesc('id')
            ->limit($filters['limit']);
        if ($filters['state'] !== '') {
            $parentsQuery->where(['state' => $filters['state']]);
        }
        if ($filters['correlation'] !== '') {
            $parentsQuery->where(['operation_correlation_id' => $filters['correlation']]);
        }
        $parents = $parentsQuery->all()->toList();
        $parentIds = array_map(fn(TenantOperationJob $job): int => (int)$job->id, $parents);
        $parentCorrelationIndex = [];
        foreach ($parents as $parent) {
            $correlationId = (string)($parent->operation_correlation_id ?? '');
            if ($correlationId === '') {
                continue;
            }
            if (!isset($parentCorrelationIndex[$correlationId])) {
                $parentCorrelationIndex[$correlationId] = [];
            }
            $parentCorrelationIndex[$correlationId][] = (int)$parent->id;
        }
        $childrenByParent = [];
        if ($parentIds !== []) {
            $childQuery = $jobsTable->find()
                ->contain(['Tenants'])
                ->where(['operation' => 'tenant_migrate'])
                ->where([
                    'OR' => [
                        ['parent_tenant_operation_job_id IN' => $parentIds],
                        [
                            'parent_tenant_operation_job_id IS' => null,
                            'operation_correlation_id IN' => array_keys($parentCorrelationIndex),
                        ],
                    ],
                ])
                ->orderByAsc('TenantOperationJobs.id');
            foreach ($childQuery->all() as $child) {
                $parentId = $this->jobParentId($child);
                if ($parentId < 1) {
                    $correlation = (string)($child->operation_correlation_id ?? '');
                    $candidates = $parentCorrelationIndex[$correlation] ?? [];
                    if (count($candidates) === 1) {
                        $parentId = (int)$candidates[0];
                    }
                }
                if (!in_array($parentId, $parentIds, true)) {
                    continue;
                }
                if (!isset($childrenByParent[$parentId])) {
                    $childrenByParent[$parentId] = [];
                }
                $childrenByParent[$parentId][] = $child;
            }
        }

        $rows = [];
        foreach ($parents as $parent) {
            $children = $childrenByParent[(int)$parent->id] ?? [];
            $stateCounts = [];
            foreach ($children as $child) {
                $childState = (string)$child->lifecycle_state;
                if (!isset($stateCounts[$childState])) {
                    $stateCounts[$childState] = 0;
                }
                $stateCounts[$childState]++;
            }
            ksort($stateCounts);
            $totalChildren = count($children);
            $completedCount = (int)($stateCounts[TenantOperationJob::STATUS_COMPLETED] ?? 0);
            $failedCount = (int)($stateCounts[TenantOperationJob::STATUS_FAILED] ?? 0);
            $holdCount = (int)($stateCounts[TenantOperationJob::STATUS_HOLD] ?? 0) + (int)($stateCounts[TenantOperationJob::STATUS_BLOCKED] ?? 0);
            $runningCount = (int)($stateCounts[TenantOperationJob::STATUS_RUNNING] ?? 0);
            $progressPercent = $totalChildren > 0 ? (int)floor($completedCount / $totalChildren * 100) : null;
            $tenantRows = [];
            foreach ($children as $child) {
                $childState = (string)$child->lifecycle_state;
                if ($filters['tenant_state'] !== '' && $childState !== $filters['tenant_state']) {
                    continue;
                }
                $input = is_array($child->input ?? null) ? $child->input : [];
                $progress = is_array($child->progress_json ?? null) ? $child->progress_json : [];
                $result = is_array($child->result_json ?? null) ? $child->result_json : [];
                $error = is_array($child->error_json ?? null) ? $child->error_json : [];
                $attemptCount = (int)($progress['attempt_count'] ?? 0);
                $maxAttempts = (int)($progress['max_attempts'] ?? $input['max_attempts'] ?? 0);
                $retryable = isset($error['retryable']) ? (bool)$error['retryable'] : null;
                $tenantRows[] = [
                    'job_id' => (int)$child->id,
                    'tenant_id' => (int)($child->tenant_id ?? 0),
                    'tenant_slug' => (string)($child->tenant->slug ?? $input['tenant_slug'] ?? ''),
                    'state' => $childState,
                    'schema_before' => (string)($result['schema_before'] ?? $input['schema_before'] ?? ''),
                    'schema_after' => (string)($result['schema_after'] ?? $result['schema_version'] ?? ''),
                    'target_schema_version' => (string)($result['target_schema_version'] ?? $input['target_schema_version'] ?? ''),
                    'duration_ms' => isset($result['duration_ms']) ? (int)$result['duration_ms'] : null,
                    'attempt_count' => $attemptCount,
                    'max_attempts' => $maxAttempts > 0 ? $maxAttempts : null,
                    'retryable' => $retryable,
                    'retry_exhausted' => $retryable === true && $maxAttempts > 0 && $attemptCount >= $maxAttempts,
                    'hold_state' => in_array($childState, [TenantOperationJob::STATUS_HOLD, TenantOperationJob::STATUS_BLOCKED], true),
                    'error_summary' => $this->summarizeOperationError($child),
                    'result_summary' => $this->summarizeOperationResult($child),
                    'status_message' => (string)($child->status_message ?? ''),
                    'linkage_source' => $child->parent_tenant_operation_job_id !== null
                        ? 'parent_tenant_operation_job_id'
                        : 'legacy_input_or_correlation',
                ];
            }
            $parentState = (string)$parent->lifecycle_state;
            $resumeAvailable = in_array($parentState, [TenantOperationJob::STATUS_HOLD, TenantOperationJob::STATUS_BLOCKED], true)
                && ($failedCount > 0 || $holdCount > 0);
            $rows[] = [
                'parent_job_id' => (int)$parent->id,
                'state' => $parentState,
                'stage' => $this->deploymentMigrationStage($parent),
                'status_message' => (string)($parent->status_message ?? ''),
                'correlation_id' => (string)($parent->operation_correlation_id ?? ''),
                'target_schema_version' => (string)(((array)($parent->input ?? []))['target_schema_version'] ?? ''),
                'child_total' => $totalChildren,
                'child_completed' => $completedCount,
                'child_running' => $runningCount,
                'child_failed' => $failedCount,
                'child_hold_or_blocked' => $holdCount,
                'child_state_counts' => $stateCounts,
                'progress_percent' => $progressPercent,
                'has_failure' => $failedCount > 0 || $parentState === TenantOperationJob::STATUS_FAILED,
                'is_held' => in_array($parentState, [TenantOperationJob::STATUS_HOLD, TenantOperationJob::STATUS_BLOCKED], true),
                'can_resume' => $resumeAvailable,
                'resume_enabled' => $resumeAvailable && $admin->hasCapability(PlatformAdmin::CAPABILITY_OPERATE_TENANTS),
                'tenant_rows' => $tenantRows,
                'created' => (string)($parent->created ?? ''),
                'started_at' => (string)($parent->started_at ?? ''),
                'completed_at' => (string)($parent->completed_at ?? ''),
            ];
        }

        return [
            'filters' => $filters,
            'state_options' => ['' => __('All states')] + $this->operationStateLabels(),
            'tenant_state_options' => ['' => __('All tenant states')] + $this->operationStateLabels(),
            'rows' => $rows,
        ];
    }

    /**
     * @param \App\Model\Entity\TenantOperationJob $parent Parent job
     * @return string
     */
    private function deploymentMigrationStage(TenantOperationJob $parent): string
    {
        $progress = is_array($parent->progress_json ?? null) ? $parent->progress_json : [];
        $phase = strtolower(trim((string)($progress['phase'] ?? '')));
        $state = (string)$parent->lifecycle_state;
        if (in_array($state, [TenantOperationJob::STATUS_HOLD, TenantOperationJob::STATUS_BLOCKED], true)) {
            return 'on_hold';
        }
        if (in_array($state, [TenantOperationJob::STATUS_COMPLETED, TenantOperationJob::STATUS_FAILED, TenantOperationJob::STATUS_CANCELLED], true)) {
            return $state;
        }
        if ($phase !== '') {
            return $phase;
        }

        return $state;
    }

    /**
     * @param \App\Model\Entity\TenantOperationJob $job Job
     * @return int
     */
    private function jobParentId(TenantOperationJob $job): int
    {
        $input = is_array($job->input ?? null) ? $job->input : [];
        $parentId = (int)($job->parent_tenant_operation_job_id
            ?? $input['parent_tenant_operation_job_id']
            ?? $input['parent_operation_id']
            ?? 0);

        return max(0, $parentId);
    }

    /**
     * @param \App\Model\Entity\TenantOperationJob $job Operation job
     * @param \App\Model\Entity\PlatformAdmin $admin Active admin
     * @return array<string, array{enabled: bool, reason: string}>
     */
    private function operationActionAvailability(TenantOperationJob $job, PlatformAdmin $admin): array
    {
        $state = (string)$job->lifecycle_state;
        $requiredCapability = $this->requiredCapabilityForJob($job);
        $hasRequiredCapability = $requiredCapability === null || $admin->hasCapability($requiredCapability);
        $retryEnabled = in_array($state, [
            TenantOperationJob::STATUS_FAILED,
            TenantOperationJob::STATUS_CANCELLED,
            TenantOperationJob::STATUS_BLOCKED,
        ], true) && $hasRequiredCapability;
        $cancelEnabled = in_array($state, [
            TenantOperationJob::STATUS_QUEUED,
            TenantOperationJob::STATUS_APPROVAL_REQUIRED,
            TenantOperationJob::STATUS_APPROVED,
            TenantOperationJob::STATUS_RUNNING,
            TenantOperationJob::STATUS_HOLD,
            TenantOperationJob::STATUS_BLOCKED,
        ], true) && $hasRequiredCapability;
        $approvalPolicy = $this->operationApprovalPolicy($job);
        $alreadyDecided = $this->hasDecisionByAdmin($job, $admin);
        $requesterId = (int)($this->operationInput($job)['gateway']['requested_by_admin_id'] ?? 0);
        $requiresRequesterSeparation = (bool)($approvalPolicy['require_requester_separation'] ?? false);
        $approveEnabled = $state === TenantOperationJob::STATUS_APPROVAL_REQUIRED
            && $hasRequiredCapability
            && !$alreadyDecided
            && !($requiresRequesterSeparation && (int)$admin->id === $requesterId);
        $rejectEnabled = $approveEnabled;

        return [
            'retry' => [
                'enabled' => $retryEnabled,
                'reason' => $retryEnabled
                    ? ''
                    : (!$hasRequiredCapability
                        ? __('Your role cannot control this operation type.')
                        : match ($state) {
                            TenantOperationJob::STATUS_COMPLETED => __('Completed operations do not support retry.'),
                            TenantOperationJob::STATUS_RUNNING => __('Wait for execution to finish before retrying.'),
                            TenantOperationJob::STATUS_QUEUED,
                            TenantOperationJob::STATUS_APPROVED,
                            TenantOperationJob::STATUS_APPROVAL_REQUIRED => __('Operation is already queued to run.'),
                            default => __('Retry is not supported for this operation state.'),
                        }),
            ],
            'cancel' => [
                'enabled' => $cancelEnabled,
                'reason' => $cancelEnabled
                    ? ''
                    : (!$hasRequiredCapability
                        ? __('Your role cannot control this operation type.')
                        : match ($state) {
                            TenantOperationJob::STATUS_COMPLETED,
                            TenantOperationJob::STATUS_FAILED,
                            TenantOperationJob::STATUS_CANCELLED => __('Terminal operations cannot be cancelled.'),
                            default => __('Cancel is not supported for this operation state.'),
                        }),
            ],
            'approve' => [
                'enabled' => $approveEnabled,
                'reason' => $approveEnabled
                    ? ''
                    : (!$hasRequiredCapability
                        ? __('Your role cannot approve this operation.')
                        : ($state !== TenantOperationJob::STATUS_APPROVAL_REQUIRED
                            ? __('Operation is not waiting for approvals.')
                            : ($alreadyDecided
                                ? __('You already recorded a decision for this operation.')
                                : __('Requester must be different from approver for this operation.')))),
            ],
            'reject' => [
                'enabled' => $rejectEnabled,
                'reason' => $rejectEnabled
                    ? ''
                    : (!$hasRequiredCapability
                        ? __('Your role cannot reject this operation.')
                        : ($state !== TenantOperationJob::STATUS_APPROVAL_REQUIRED
                            ? __('Operation is not waiting for approvals.')
                            : ($alreadyDecided
                                ? __('You already recorded a decision for this operation.')
                                : __('Requester must be different from approver for this operation.')))),
            ],
        ];
    }

    /**
     * @param \App\Model\Entity\TenantOperationJob $job Operation job
     * @return array<string, mixed>
     */
    private function operationApprovalPolicy(TenantOperationJob $job): array
    {
        $policy = is_array($job->approval_policy_json ?? null) ? $job->approval_policy_json : [];
        if ($policy !== []) {
            return $policy;
        }
        try {
            return TenantOperationCommandCatalog::approvalPolicy((string)$job->operation);
        } catch (RuntimeException) {
            return [
                'mode' => TenantOperationCommandCatalog::APPROVAL_MODE_NONE,
                'required_approvals' => 0,
                'require_distinct_approvers' => true,
                'require_requester_separation' => false,
            ];
        }
    }

    /**
     * @param \App\Model\Entity\TenantOperationJob $job Operation job
     * @return string|null
     */
    private function requiredCapabilityForJob(TenantOperationJob $job): ?string
    {
        try {
            return TenantOperationCommandCatalog::requiredCapability((string)$job->operation);
        } catch (RuntimeException) {
            return PlatformAdmin::CAPABILITY_OPERATE_TENANTS;
        }
    }

    /**
     * @param \App\Model\Entity\TenantOperationJob $job Operation job
     * @return array<string, mixed>
     */
    private function operationInput(TenantOperationJob $job): array
    {
        return is_array($job->input ?? null) ? $job->input : [];
    }

    /**
     * @param \App\Model\Entity\TenantOperationJob $job Operation job
     * @param \App\Model\Entity\PlatformAdmin $admin Platform admin
     * @return bool
     */
    private function hasDecisionByAdmin(TenantOperationJob $job, PlatformAdmin $admin): bool
    {
        return $this->fetchTable('TenantOperationApprovals')->find()
            ->where([
                'tenant_operation_job_id' => (int)$job->id,
                'platform_admin_id' => (int)$admin->id,
            ])
            ->count() > 0;
    }

    /**
     * @param \App\Model\Entity\TenantOperationJob $job Operation job
     * @return string
     */
    private function summarizeOperationResult(TenantOperationJob $job): string
    {
        $payload = is_array($job->result_json ?? null)
            ? $job->result_json
            : (is_array($job->result ?? null) ? $job->result : []);
        if ($payload === []) {
            return '';
        }
        foreach (['summary', 'message', 'status', 'slug'] as $key) {
            if (isset($payload[$key]) && is_scalar($payload[$key])) {
                return trim((string)$payload[$key]);
            }
        }
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES);

        return $encoded === false ? '' : Text::truncate($encoded, 180, ['ellipsis' => '…', 'exact' => false]);
    }

    /**
     * @param \App\Model\Entity\TenantOperationJob $job Operation job
     * @return string
     */
    private function summarizeOperationError(TenantOperationJob $job): string
    {
        $message = trim((string)($job->error_message ?? ''));
        if ($message !== '') {
            return $message;
        }
        $payload = is_array($job->error_json ?? null) ? $job->error_json : [];
        if ($payload === []) {
            return '';
        }
        $parts = [];
        if (isset($payload['code']) && is_scalar($payload['code'])) {
            $parts[] = strtoupper((string)$payload['code']);
        }
        if (isset($payload['message']) && is_scalar($payload['message'])) {
            $parts[] = (string)$payload['message'];
        }
        if ($parts !== []) {
            return implode(': ', $parts);
        }
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES);

        return $encoded === false ? '' : Text::truncate($encoded, 180, ['ellipsis' => '…', 'exact' => false]);
    }

    /**
     * @param \App\Model\Entity\Tenant $tenant Tenant
     * @param \App\Model\Entity\PlatformAdmin $admin Active admin
     * @param int $limit Max rows
     * @return array<int, array<string, mixed>>
     */
    private function buildSecretRotationVerificationRows(
        Tenant $tenant,
        PlatformAdmin $admin,
        int $limit = 10,
    ): array {
        $rotationJobs = $this->fetchTable('TenantOperationJobs')->find()
            ->contain(['PlatformAdmins'])
            ->where([
                'tenant_id' => (int)$tenant->id,
                'operation' => 'tenant_rotate_db_secret',
            ])
            ->orderByDesc('TenantOperationJobs.created')
            ->limit($limit)
            ->all()
            ->toList();
        if ($rotationJobs === []) {
            return [];
        }

        $auditEvents = $this->fetchTable('PlatformAuditEvents')->find()
            ->contain(['PlatformAdmins'])
            ->where([
                'tenant_id' => (int)$tenant->id,
                'action IN' => ['tenant.secret_update', 'tenant.secret_rotation_verification'],
            ])
            ->orderByDesc('PlatformAuditEvents.created')
            ->limit(400)
            ->all()
            ->toList();
        $auditByOperationId = [];
        $auditByCorrelation = [];
        foreach ($auditEvents as $event) {
            $metadata = $this->normalizeOperationInput($event->metadata);
            $operationId = trim((string)($metadata['operation_id'] ?? $metadata['database_rotation_operation_id'] ?? ''));
            $correlationId = trim((string)($metadata['correlation_id'] ?? ''));
            if ($operationId !== '') {
                $auditByOperationId[$operationId][] = $event;
            }
            if ($correlationId !== '') {
                $auditByCorrelation[$correlationId][] = $event;
            }
        }

        $rows = [];
        foreach ($rotationJobs as $job) {
            $jobId = (int)$job->id;
            $result = $this->normalizeOperationInput($job->result_json ?? $job->result ?? []);
            $error = $this->normalizeOperationInput($job->error_json ?? []);
            $progress = $this->normalizeOperationInput($job->progress_json ?? []);
            $correlationId = trim((string)($job->operation_correlation_id ?? ''));
            $linkedEvents = [];
            foreach (($auditByOperationId[(string)$jobId] ?? []) as $event) {
                $linkedEvents[(int)$event->id] = $event;
            }
            foreach (($auditByCorrelation[$correlationId] ?? []) as $event) {
                $linkedEvents[(int)$event->id] = $event;
            }
            $linkedEvents = array_values($linkedEvents);
            $status = $this->secretRotationVerificationStatus($job, $result, $error);
            $actions = $this->operationActionAvailability($job, $admin);
            $actor = trim((string)($job->platform_admin->email ?? ''));
            foreach ($linkedEvents as $event) {
                $actorFromEvent = trim((string)($event->platform_admin->email ?? ''));
                if ($actorFromEvent !== '') {
                    $actor = $actorFromEvent;
                    break;
                }
            }

            $rows[] = [
                'operation_id' => $jobId,
                'state' => (string)$job->lifecycle_state,
                'verification_status' => $status,
                'confidence' => $this->secretRotationVerificationConfidence($status, $job, $result, $linkedEvents),
                'queued_at' => $job->created === null ? null : (string)$job->created,
                'started_at' => $job->started_at === null ? null : (string)$job->started_at,
                'completed_at' => $job->completed_at === null ? null : (string)$job->completed_at,
                'actor' => $actor,
                'correlation_id' => $correlationId,
                'affected_scope' => ['database.primary'],
                'progress_phase' => (string)($progress['phase'] ?? ''),
                'status_message' => (string)($job->status_message ?? ''),
                'result_flags' => [
                    'rotated' => (bool)($result['rotated'] ?? false),
                    'rolled_back' => (bool)($result['rolled_back'] ?? false),
                ],
                'error_summary' => $this->summarizeOperationError($job),
                'audit_actions' => array_values(array_unique(array_filter(array_map(
                    static fn($event): string => trim((string)($event->action ?? '')),
                    $linkedEvents,
                )))),
                'next_steps' => $this->secretRotationNextSteps($status, (bool)$actions['retry']['enabled']),
            ];
        }

        return $rows;
    }

    /**
     * @param \App\Model\Entity\TenantOperationJob $job Rotation job
     * @param array<string, mixed> $result Result payload
     * @param array<string, mixed> $error Error payload
     * @return string
     */
    private function secretRotationVerificationStatus(TenantOperationJob $job, array $result, array $error): string
    {
        if ((bool)($result['rolled_back'] ?? false)) {
            return 'rollback';
        }
        if ((string)$job->lifecycle_state === TenantOperationJob::STATUS_COMPLETED && (bool)($result['rotated'] ?? false)) {
            return 'success';
        }
        $errorMessage = strtolower(trim((string)($job->error_message ?? $error['message'] ?? '')));
        if ($errorMessage !== '' && str_contains($errorMessage, 'rollback')) {
            return 'rollback';
        }
        if (
            in_array((string)$job->lifecycle_state, [
            TenantOperationJob::STATUS_FAILED,
            TenantOperationJob::STATUS_CANCELLED,
            TenantOperationJob::STATUS_BLOCKED,
            ], true)
        ) {
            return 'failure';
        }

        return 'in_progress';
    }

    /**
     * @param string $status Verification status
     * @param \App\Model\Entity\TenantOperationJob $job Rotation job
     * @param array<string, mixed> $result Result payload
     * @param array<int, mixed> $linkedEvents Linked audit events
     * @return string
     */
    private function secretRotationVerificationConfidence(
        string $status,
        TenantOperationJob $job,
        array $result,
        array $linkedEvents,
    ): string {
        if ($status === 'success' && (bool)($result['rotated'] ?? false) && $linkedEvents !== []) {
            return 'high';
        }
        if ($status === 'rollback' && $linkedEvents !== []) {
            return 'medium';
        }
        if ($status === 'in_progress' && (string)$job->lifecycle_state === TenantOperationJob::STATUS_RUNNING) {
            return 'medium';
        }

        return 'low';
    }

    /**
     * @param string $status Verification status
     * @param bool $retryEnabled Whether retry is available
     * @return string
     */
    private function secretRotationNextSteps(string $status, bool $retryEnabled): string
    {
        return match ($status) {
            'success' => 'Run tenant doctor and smoke checks, then close the change ticket with audit evidence.',
            'rollback' => 'Rollback safety activated. Confirm prior secret reference health, re-run doctor checks, then retry with a new idempotency key after remediation.',
            'failure' => $retryEnabled
                ? 'Review failure details and retry from Operation Queue after fixing reference/access issues.'
                : 'Review failure details and associated audit trail before attempting another rotation.',
            default => 'Await terminal status. If execution stalls, inspect lease/heartbeat data and use queue controls as needed.',
        };
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
     * Build capability flags for platform admin templates.
     *
     * @return array<string, bool>
     */
    private function platformAdminCapabilities(PlatformAdmin $admin): array
    {
        return [
            'view_dashboard' => $admin->hasCapability(PlatformAdmin::CAPABILITY_VIEW_DASHBOARD),
            'operate_tenants' => $admin->hasCapability(PlatformAdmin::CAPABILITY_OPERATE_TENANTS),
            'provision_tenants' => $admin->hasCapability(PlatformAdmin::CAPABILITY_PROVISION_TENANTS),
            'manage_secrets' => $admin->hasCapability(PlatformAdmin::CAPABILITY_MANAGE_SECRETS),
            'manage_recovery' => $admin->hasCapability(PlatformAdmin::CAPABILITY_MANAGE_RECOVERY),
        ];
    }

    /**
     * @return array<int, array{command: array<string, mixed>, can_invoke: bool, unmet_capability: string|null}>
     */
    private function buildCommandCatalogRows(PlatformAdmin $admin): array
    {
        $rows = [];
        foreach (TenantOperationCommandCatalog::gatewayCatalog() as $command) {
            $requiredCapability = $command['required_capability'] ?? null;
            $capability = is_string($requiredCapability) ? trim($requiredCapability) : '';
            $canInvoke = $capability === '' || $admin->hasCapability($capability);
            $rows[] = [
                'command' => $command,
                'can_invoke' => $canInvoke,
                'unmet_capability' => $canInvoke ? null : $capability,
            ];
        }

        return $rows;
    }

    /**
     * Build doctor findings with guidance and remediation options.
     *
     * @param array<string, array<string, mixed>> $doctorChecks Raw doctor checks
     * @return array<string, array<string, mixed>>
     */
    private function buildDoctorFindings(array $doctorChecks, Tenant $tenant, PlatformAdmin $admin): array
    {
        $findings = [];
        foreach ($doctorChecks as $finding => $check) {
            $findingKey = trim((string)$finding);
            if ($findingKey === '') {
                continue;
            }
            $check = is_array($check) ? $check : [];
            $guidance = $this->doctorRemediationGuidance($findingKey, $check);
            $actions = $this->doctorRemediationActions($tenant, $admin, $findingKey, $check);
            $findings[$findingKey] = [
                'name' => $findingKey,
                'ok' => (bool)($check['ok'] ?? false),
                'message' => (string)($check['message'] ?? ''),
                'remediation_guidance' => $guidance,
                'actions' => $actions,
            ];
        }

        return $findings;
    }

    /**
     * @param array<string, mixed> $check
     * @return array<string, mixed>
     */
    private function resolveDoctorRemediationAction(
        Tenant $tenant,
        PlatformAdmin $admin,
        string $finding,
        string $action,
        array $check,
    ): array {
        foreach ($this->doctorRemediationActions($tenant, $admin, $finding, $check) as $candidate) {
            if ((string)($candidate['id'] ?? '') === $action) {
                return $candidate;
            }
        }

        throw new RuntimeException('Unknown remediation action for doctor finding.');
    }

    /**
     * @param array<string, mixed> $check
     * @return string
     */
    private function doctorRemediationGuidance(string $finding, array $check): string
    {
        return match ($finding) {
            'tenant_status' => (bool)($check['ok'] ?? false)
                ? 'Tenant status is active.'
                : 'Tenant is not active. Move to active after maintenance windows and incident controls are complete.',
            'schema_version' =>
                'If schema versions drift, queue tenant_migrate and wait for the operation to complete.',
            'database_config' =>
                'No automatic remediation is available. Update tenant primary database metadata through approved provisioning workflows.',
            'database_reachable' =>
                'If connectivity fails, investigate credentials/network path, then rerun doctor checks to verify recovery.',
            'required_app_settings' => 'Seed missing settings using migration/provisioning workflows, then rerun doctor checks.',
            default => 'Review the finding details and queue an approved operation when one is available.',
        };
    }

    /**
     * @param array<string, mixed> $check
     * @return array<int, array<string, mixed>>
     */
    private function doctorRemediationActions(
        Tenant $tenant,
        PlatformAdmin $admin,
        string $finding,
        array $check,
    ): array {
        $actions = [];
        $isFailing = !(bool)($check['ok'] ?? false);
        if ($finding === 'tenant_status' && $isFailing) {
            $actions[] = $this->doctorRemediationActionDefinition(
                tenant: $tenant,
                admin: $admin,
                finding: $finding,
                actionId: 'set_active',
                operation: 'tenant_status',
                parameters: ['status' => Tenant::STATUS_ACTIVE],
                label: 'Set tenant status to active',
            );
        }
        if ($finding === 'schema_version' && $isFailing) {
            $actions[] = $this->doctorRemediationActionDefinition(
                tenant: $tenant,
                admin: $admin,
                finding: $finding,
                actionId: 'run_migrations',
                operation: 'tenant_migrate',
                parameters: [],
                label: 'Run tenant migrations',
            );
        }
        if ($finding === 'required_app_settings' && $isFailing) {
            $actions[] = $this->doctorRemediationActionDefinition(
                tenant: $tenant,
                admin: $admin,
                finding: $finding,
                actionId: 'run_migrations',
                operation: 'tenant_migrate',
                parameters: [],
                label: 'Run tenant migrations',
            );
        }
        if (in_array($finding, ['database_reachable', 'required_app_settings'], true) && $isFailing) {
            $actions[] = $this->doctorRemediationActionDefinition(
                tenant: $tenant,
                admin: $admin,
                finding: $finding,
                actionId: 'run_doctor',
                operation: 'tenant_doctor',
                parameters: [],
                label: 'Rerun tenant doctor checks',
            );
        }

        return $actions;
    }

    /**
     * @param array<string, mixed> $parameters
     * @return array<string, mixed>
     */
    private function doctorRemediationActionDefinition(
        Tenant $tenant,
        PlatformAdmin $admin,
        string $finding,
        string $actionId,
        string $operation,
        array $parameters,
        string $label,
    ): array {
        $requiredCapability = TenantOperationCommandCatalog::requiredCapability($operation);
        $enabled = $requiredCapability === null || $admin->hasCapability($requiredCapability);
        $approvalPolicy = TenantOperationCommandCatalog::approvalPolicy($operation);
        $actionLabel = $this->doctorRemediationActionLabel($tenant, $finding, $actionId, $operation);

        return [
            'id' => $actionId,
            'label' => $label,
            'operation' => $operation,
            'parameters' => $parameters,
            'required_capability' => $requiredCapability,
            'approval_policy' => $approvalPolicy,
            'enabled' => $enabled,
            'reason' => $enabled
                ? ''
                : __('Your role cannot invoke this remediation action.'),
            'action_label' => $actionLabel,
        ];
    }

    /**
     * Build deterministic step-up label for doctor remediation actions.
     */
    private function doctorRemediationActionLabel(
        Tenant $tenant,
        string $finding,
        string $actionId,
        string $operation,
    ): string {
        return sprintf(
            'Remediate tenant %s finding %s via %s [operation:%s]',
            (string)$tenant->slug,
            $finding,
            $actionId,
            $operation,
        );
    }

    /**
     * Resolve required capability for a remediation action-code label.
     */
    private function remediationCapabilityFromActionLabel(string $actionLabel): ?string
    {
        if (!preg_match('/\[operation:([a-z0-9_]+)\]$/', $actionLabel, $matches)) {
            return null;
        }
        $operation = trim((string)($matches[1] ?? ''));
        if ($operation === '') {
            return null;
        }
        try {
            return TenantOperationCommandCatalog::requiredCapability($operation);
        } catch (RuntimeException) {
            return null;
        }
    }

    /**
     * Validate operation/catalog compatibility before enqueueing a gateway job.
     *
     * @param array<string, mixed> $parameters
     * @return array<string, mixed>
     */
    private function preflightGatewayOperation(
        PlatformAdmin $admin,
        string $operation,
        string $targetMode,
        array $parameters,
    ): array {
        $requiredCapability = TenantOperationCommandCatalog::requiredCapability($operation);
        if ($requiredCapability !== null && !$admin->hasCapability($requiredCapability)) {
            throw new RuntimeException(sprintf(
                'Operation "%s" requires capability "%s". Review /platform-admin/operations/catalog for role guidance.',
                $operation,
                $requiredCapability,
            ));
        }
        try {
            return TenantOperationCommandCatalog::validateGatewayRequest(
                operation: $operation,
                targetMode: $targetMode,
                parameters: $parameters,
            );
        } catch (RuntimeException $e) {
            throw new RuntimeException($e->getMessage() . ' Review /platform-admin/operations/catalog for validation hints.', 0, $e);
        }
    }

    /**
     * Throw when the active platform admin lacks a required capability.
     */
    private function assertAdminCapability(PlatformAdmin $admin, string $capability): void
    {
        if ($admin->hasCapability($capability)) {
            return;
        }
        (new PlatformAuditService())->record(
            'platform_admin.authorization_denied',
            'failure',
            [
                'required_capability' => $capability,
                'controller_action' => (string)$this->request->getParam('action'),
            ],
            $admin,
            $this->request,
        );

        throw new ForbiddenException('You are not permitted to perform this action.');
    }

    /**
     * Validate request-action-code permissions based on the target action label.
     */
    private function assertCanRequestActionCode(PlatformAdmin $admin, string $actionLabel): void
    {
        $requiredCapability = match (true) {
            str_starts_with($actionLabel, 'Create or update tenant') => PlatformAdmin::CAPABILITY_PROVISION_TENANTS,
            str_starts_with($actionLabel, 'Set tenant ') => PlatformAdmin::CAPABILITY_OPERATE_TENANTS,
            str_starts_with($actionLabel, 'Update managed secrets for tenant ')
                => PlatformAdmin::CAPABILITY_MANAGE_SECRETS,
            str_starts_with($actionLabel, 'Create backup for tenant '),
            str_starts_with($actionLabel, 'Restore backup for tenant ') => PlatformAdmin::CAPABILITY_MANAGE_RECOVERY,
            str_starts_with($actionLabel, 'Remediate tenant ')
                => $this->remediationCapabilityFromActionLabel($actionLabel)
                    ?? PlatformAdmin::CAPABILITY_OPERATE_TENANTS,
            default => PlatformAdmin::CAPABILITY_VIEW_DASHBOARD,
        };
        $this->assertAdminCapability($admin, $requiredCapability);
        $this->assertSensitiveActionSessionFresh($admin, $actionLabel);
        $this->assertBreakGlassForSensitiveAction($admin, $actionLabel);
    }

    /**
     * Verify high-risk action with password plus emailed one-time code.
     *
     * @param \App\Model\Entity\PlatformAdmin $admin Platform admin
     * @return void
     */
    private function verifyAction(PlatformAdmin $admin, string $actionLabel): void
    {
        $this->assertSensitiveActionSessionFresh($admin, $actionLabel);
        $this->assertBreakGlassForSensitiveAction($admin, $actionLabel);
        $session = $this->request->getSession();
        $challenges = (array)$session->read(self::ACTION_CHALLENGES_KEY);
        $challengeId = $challenges[$actionLabel] ?? null;
        if (!is_numeric($challengeId)) {
            (new PlatformAuditService())->record(
                'platform_admin.step_up_denied',
                'failure',
                [
                    'reason' => 'missing_action_challenge',
                    'action_label' => $actionLabel,
                ],
                $admin,
                $this->request,
            );
            throw new RuntimeException('Request a new emailed action verification code before continuing.');
        }
        (new PlatformAdminAuthService())->verifyAction(
            $admin,
            (string)$this->request->getData('verify_password'),
            (string)$this->request->getData('verify_email_code'),
            (int)$challengeId,
        );
        unset($challenges[$actionLabel]);
        $session->write(self::ACTION_CHALLENGES_KEY, $challenges);
    }

    /**
     * Require a fresh login session before issuing or consuming step-up challenges.
     */
    private function assertSensitiveActionSessionFresh(PlatformAdmin $admin, string $actionLabel): void
    {
        if (!$this->isSensitiveAction($actionLabel)) {
            return;
        }
        if (
            (new PlatformAdminAuthService())->isSessionFresh(
                (string)$this->request->getCookie(self::COOKIE_NAME),
                self::SENSITIVE_ACTION_FRESHNESS_MINUTES,
            )
        ) {
            return;
        }
        (new PlatformAuditService())->record(
            'platform_admin.step_up_denied',
            'failure',
            [
                'reason' => 'stale_session',
                'action_label' => $actionLabel,
                'freshness_minutes_required' => self::SENSITIVE_ACTION_FRESHNESS_MINUTES,
            ],
            $admin,
            $this->request,
        );

        throw new ForbiddenException('Re-authenticate to continue with this sensitive action.');
    }

    /**
     * Restrict break-glass-only operations to break-glass admins.
     */
    private function assertBreakGlassForSensitiveAction(PlatformAdmin $admin, string $actionLabel): void
    {
        if (!$this->isBreakGlassOnlyAction($actionLabel)) {
            return;
        }
        if ((string)$admin->role === PlatformAdmin::ROLE_BREAK_GLASS) {
            return;
        }
        (new PlatformAuditService())->record(
            'platform_admin.break_glass_denied',
            'failure',
            [
                'action_label' => $actionLabel,
                'required_role' => PlatformAdmin::ROLE_BREAK_GLASS,
                'actual_role' => (string)$admin->role,
            ],
            $admin,
            $this->request,
        );

        throw new ForbiddenException('Break-glass role is required for this action.');
    }

    /**
     * Determine whether an action label requires step-up freshness checks.
     */
    private function isSensitiveAction(string $actionLabel): bool
    {
        return str_starts_with($actionLabel, 'Create or update tenant')
            || str_starts_with($actionLabel, 'Set tenant ')
            || str_starts_with($actionLabel, 'Update managed secrets for tenant ')
            || str_starts_with($actionLabel, 'Create backup for tenant ')
            || str_starts_with($actionLabel, 'Restore backup for tenant ')
            || str_starts_with($actionLabel, 'Remediate tenant ');
    }

    /**
     * Determine whether an action label is restricted to break-glass admins.
     */
    private function isBreakGlassOnlyAction(string $actionLabel): bool
    {
        return str_starts_with($actionLabel, 'Update managed secrets for tenant ')
            || str_starts_with($actionLabel, 'Restore backup for tenant ');
    }

    /**
     * Fail before consuming emailed action codes if submitted managed secrets cannot be stored.
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
     * Return a plain forbidden response without invoking debug error layouts.
     *
     * @param \Cake\Http\Exception\ForbiddenException $exception Forbidden exception
     * @return \Cake\Http\Response
     */
    private function forbiddenResponse(ForbiddenException $exception): Response
    {
        return $this->response
            ->withStatus(403)
            ->withType('text/plain')
            ->withStringBody($exception->getMessage());
    }

    /**
     * Read uploaded backup data from PSR-7 uploads or CakePHP integration-test arrays.
     *
     * @param mixed $uploadedFile Uploaded file value
     * @return array{filename: string, bytes: string}
     */
    private function backupUploadPayload(mixed $uploadedFile): array
    {
        if ($uploadedFile instanceof UploadedFileInterface) {
            if ($uploadedFile->getError() !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Choose a valid backup file.');
            }
            $stream = $uploadedFile->getStream();
            $stream->rewind();
            $bytes = $stream->getContents();
            if ($bytes === '') {
                throw new RuntimeException('Choose a valid backup file.');
            }

            return [
                'filename' => (string)($uploadedFile->getClientFilename() ?: 'uploaded-backup'),
                'bytes' => $bytes,
            ];
        }

        if (is_array($uploadedFile) && (int)($uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $path = (string)($uploadedFile['tmp_name'] ?? '');
            if ($path === '' || !is_file($path)) {
                throw new RuntimeException('Choose a valid backup file.');
            }
            $bytes = file_get_contents($path);
            if (!is_string($bytes) || $bytes === '') {
                throw new RuntimeException('Choose a valid backup file.');
            }

            return [
                'filename' => (string)($uploadedFile['name'] ?? 'uploaded-backup'),
                'bytes' => $bytes,
            ];
        }

        throw new RuntimeException('Choose a valid backup file.');
    }

    /**
     * Check whether the tenant has an active primary database config.
     *
     * @param \App\Model\Entity\Tenant $tenant Tenant
     * @return bool
     */
    private function hasActivePrimaryDatabaseConfig(Tenant $tenant): bool
    {
        foreach ((array)($tenant->tenant_database_configs ?? []) as $config) {
            if ((string)$config->connection_role === 'primary' && (bool)$config->is_active) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reject unsafe restore target names before queueing cutover work.
     */
    private function assertRestoreTargetDatabaseIsSafe(
        Tenant $tenant,
        string $newDatabase,
        string $currentDatabase,
    ): void {
        if (strcasecmp($newDatabase, $currentDatabase) === 0) {
            throw new RuntimeException(
                'Restore database must be different from the current tenant database.',
            );
        }

        $knownConfigs = $this->fetchTable('TenantDatabaseConfigs')->find()
            ->select(['tenant_id', 'database_name'])
            ->all();
        foreach ($knownConfigs as $knownConfig) {
            if (strcasecmp((string)$knownConfig->database_name, $newDatabase) !== 0) {
                continue;
            }
            $ownerSlug = $this->tenantSlugForId((int)$knownConfig->tenant_id);
            throw new RuntimeException(sprintf(
                'Restore target database "%s" already exists in tenant config%s. Choose a unique name.',
                $newDatabase,
                $ownerSlug === null ? '' : sprintf(' for "%s"', $ownerSlug),
            ));
        }

        $recentJobs = $this->fetchTable('TenantOperationJobs')->find()
            ->select(['tenant_id', 'input', 'created'])
            ->where([
                'operation' => 'tenant_restore_cutover',
                'created >=' => DateTime::now()->subDays(30),
            ])
            ->orderByDesc('created')
            ->limit(200)
            ->all();
        foreach ($recentJobs as $recentJob) {
            $input = $this->normalizeOperationInput($recentJob->input ?? []);
            $recentTarget = trim((string)($input['new_database_name'] ?? ''));
            if ($recentTarget === '' || strcasecmp($recentTarget, $newDatabase) !== 0) {
                continue;
            }
            $ownerSlug = $this->tenantSlugForId((int)($recentJob->tenant_id ?? 0));
            throw new RuntimeException(sprintf(
                'Restore target database "%s" was already used by%s in the last 30 days. Choose a fresh name.',
                $newDatabase,
                $ownerSlug === null ? ' another restore cutover' : sprintf(' "%s"', $ownerSlug),
            ));
        }
    }

    /**
     * @param mixed $input
     * @return array<string, mixed>
     */
    private function normalizeOperationInput(mixed $input): array
    {
        if (is_array($input)) {
            return $input;
        }
        if (is_string($input) && $input !== '') {
            $decoded = json_decode($input, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    /**
     * @param int $tenantId Tenant id
     * @return string|null
     */
    private function tenantSlugForId(int $tenantId): ?string
    {
        if ($tenantId < 1) {
            return null;
        }
        $tenant = $this->fetchTable('Tenants')->find()
            ->select(['id', 'slug'])
            ->where(['id' => $tenantId])
            ->first();

        return $tenant instanceof Tenant ? (string)$tenant->slug : null;
    }

    /**
     * @param object $primaryConfig
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function databaseConfigSnapshot(object $primaryConfig, array $overrides = []): array
    {
        $snapshot = [
            'id' => (int)$primaryConfig->id,
            'tenant_id' => (int)$primaryConfig->tenant_id,
            'connection_role' => (string)$primaryConfig->connection_role,
            'driver' => (string)$primaryConfig->driver,
            'host' => (string)$primaryConfig->host,
            'port' => $primaryConfig->port === null ? null : (int)$primaryConfig->port,
            'database_name' => (string)$primaryConfig->database_name,
            'username' => $primaryConfig->username === null ? null : (string)$primaryConfig->username,
            'secret_reference' => $primaryConfig->secret_reference === null
                ? null
                : (string)$primaryConfig->secret_reference,
            'encrypted_dsn' => $primaryConfig->encrypted_dsn === null ? null : (string)$primaryConfig->encrypted_dsn,
            'read_enabled' => (bool)$primaryConfig->read_enabled,
            'write_enabled' => (bool)$primaryConfig->write_enabled,
            'is_active' => (bool)$primaryConfig->is_active,
            'metadata' => is_array($primaryConfig->metadata ?? null) ? $primaryConfig->metadata : [],
        ];

        return $overrides === [] ? $snapshot : array_merge($snapshot, $overrides);
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
    private function storeTenantSecrets(PlatformAdmin $admin, Tenant $tenant, bool $includeDatabase = true): array
    {
        $secretService = new PlatformSecretService();
        $changed = [];
        $tenantId = (int)$tenant->id;

        $databaseSecret = (string)$this->request->getData('database_secret_value', '');
        if ($includeDatabase && $databaseSecret !== '') {
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
     * @param \App\Model\Entity\PlatformAdmin $admin Admin actor
     * @param \App\Model\Entity\Tenant $tenant Tenant
     * @param string $databaseSecret Raw database credential
     * @return string
     */
    private function storeTenantDatabaseRotationSecret(
        PlatformAdmin $admin,
        Tenant $tenant,
        string $databaseSecret,
    ): string {
        return (new PlatformSecretService())->storeSecret(
            sprintf('tenant/%d/database/primary/rotation/%s', (int)$tenant->id, str_replace('-', '', Text::uuid())),
            $databaseSecret,
            sprintf('Primary database password rotation candidate for tenant %s', (string)$tenant->slug),
            $admin,
        );
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
    ): TenantOperationJob {
        $jobs = $this->fetchTable('TenantOperationJobs');
        $correlationId = $this->operationCorrelationId();
        $terminalStates = [
            TenantOperationJob::STATUS_COMPLETED,
            TenantOperationJob::STATUS_FAILED,
            TenantOperationJob::STATUS_CANCELLED,
        ];
        $isTerminal = in_array($status, $terminalStates, true);
        $normalizedInput = $this->normalizeJobPayload($input);
        $job = $jobs->newEntity([
            'tenant_id' => $tenant?->id,
            'platform_admin_id' => $admin->id,
            'operation' => $operation,
            'state' => $status,
            'status' => $status,
            'idempotency_scope' => 'web_request',
            'idempotency_key' => $correlationId,
            'input' => $normalizedInput,
            'result_json' => $isTerminal ? $normalizedInput : null,
            'progress_percent' => $isTerminal ? 100 : null,
            'status_message' => $isTerminal
                ? sprintf('%s %s', $operation, $status)
                : sprintf('%s queued for worker execution', $operation),
            'operation_correlation_id' => $correlationId,
            'operation_image' => $this->operationImage(),
            'operation_version' => $this->operationVersion(),
            'completed_at' => $isTerminal ? DateTime::now() : null,
        ]);
        $jobs->saveOrFail($job);

        return $job;
    }

    /**
     * Normalize job payload values into JSON-safe primitives.
     *
     * @param array<string, mixed> $payload Payload
     * @return array<string, mixed>
     */
    private function normalizeJobPayload(array $payload): array
    {
        foreach ($payload as $key => $value) {
            $payload[$key] = $this->normalizeJobValue($value);
        }

        return $payload;
    }

    /**
     * @param mixed $value Payload value
     * @return mixed
     */
    private function normalizeJobValue(mixed $value): mixed
    {
        if (is_array($value)) {
            foreach ($value as $index => $item) {
                $value[$index] = $this->normalizeJobValue($item);
            }

            return $value;
        }
        if ($value instanceof DateTime) {
            return $value->toIso8601String();
        }
        if (is_scalar($value) || $value === null) {
            return $value;
        }

        return (string)$value;
    }

    /**
     * Resolve operation correlation id from request context.
     */
    private function operationCorrelationId(): string
    {
        $requestId = $this->request->getAttribute('requestId');
        if (is_string($requestId) && $requestId !== '') {
            return $requestId;
        }

        return Text::uuid();
    }

    /**
     * @return string|null
     */
    private function operationImage(): ?string
    {
        $image = getenv('KMP_IMAGE_REPO');
        if ($image === false || $image === '') {
            $image = getenv('IMAGE_REPO');
        }
        if ($image === false) {
            return null;
        }
        $image = trim((string)$image);

        return $image === '' ? null : $image;
    }

    /**
     * @return string|null
     */
    private function operationVersion(): ?string
    {
        $version = getenv('KMP_IMAGE_TAG');
        if ($version === false || $version === '') {
            $version = getenv('APP_VERSION');
        }
        if ($version === false) {
            return null;
        }
        $version = trim((string)$version);

        return $version === '' ? null : $version;
    }

    /**
     * @return bool
     */
    private function wantsJsonResponse(): bool
    {
        $accept = strtolower((string)$this->request->getHeaderLine('Accept'));

        return (string)$this->request->getParam('_ext') === 'json'
            || str_contains($accept, 'application/json');
    }

    /**
     * @param \App\Model\Entity\Tenant|null $tenant Tenant
     * @param \App\Model\Entity\TenantOperationJob $job Operation job
     * @return array<string, mixed>
     */
    private function jobAuditContext(?Tenant $tenant, TenantOperationJob $job): array
    {
        return [
            'tenant_slug' => (string)($tenant?->slug ?? ''),
            'operation_id' => (string)$job->id,
            'correlation_id' => (string)$job->operation_correlation_id,
            'operation_image' => $job->operation_image,
            'operation_version' => $job->operation_version,
        ];
    }
}
