<?php
declare(strict_types=1);

namespace App\Services\Platform;

use App\Mailer\PlatformAdminMailer;
use App\Model\Entity\PlatformAdmin;
use Cake\Datasource\EntityInterface;
use Cake\Http\ServerRequest;
use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;
use RuntimeException;
use Throwable;

/**
 * Authenticates dedicated platform admin accounts.
 */
class PlatformAdminAuthService
{
    use LocatorAwareTrait;

    public const SESSION_KEY = 'PlatformAdmin.sessionToken';
    private const MAX_FAILED_ATTEMPTS = 5;
    private const LOCK_MINUTES = 15;
    private const SESSION_HOURS = 8;
    private const EMAIL_CODE_MINUTES = 10;
    private const EMAIL_CODE_ATTEMPTS = 5;
    private const LOGIN_CODE_PURPOSE = 'login';
    private const ACTION_CODE_PURPOSE = 'action';
    private const INVALID_CREDENTIALS_MESSAGE = 'Invalid platform admin credentials.';
    private const ACTION_VERIFICATION_MESSAGE = 'Action verification failed. Use your current platform admin password '
        . 'and the emailed action verification code.';

    /**
     * Create a platform admin.
     *
     * @return array{admin: \Cake\Datasource\EntityInterface}
     */
    public function createAdmin(
        string $email,
        string $displayName,
        string $password,
        bool $requirePasswordChange = false,
    ): array {
        $this->assertPasswordPolicy($password);
        $admins = $this->fetchTable('PlatformAdmins');
        $admin = $admins->newEntity([
            'email' => strtolower(trim($email)),
            'display_name' => $displayName,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'status' => PlatformAdmin::STATUS_ACTIVE,
            'require_password_change' => $requirePasswordChange,
            'failed_attempts' => 0,
        ]);
        $admins->saveOrFail($admin);

        return ['admin' => $admin];
    }

    /**
     * Ensure a seed platform admin exists, optionally replacing its password.
     *
     * @return array{
     *   admin: \App\Model\Entity\PlatformAdmin,
     *   created: bool,
     *   updated: bool
     * }
     */
    public function seedAdmin(
        string $email,
        string $displayName,
        string $password,
        bool $force = false,
        bool $requirePasswordChange = true,
        bool $enforcePasswordPolicy = true,
    ): array {
        if ($enforcePasswordPolicy) {
            $this->assertPasswordPolicy($password);
        }
        $admins = $this->fetchTable('PlatformAdmins');
        $normalizedEmail = strtolower(trim($email));
        $admin = $admins->find()
            ->where(['email' => $normalizedEmail])
            ->first();
        if (!$admin instanceof PlatformAdmin) {
            $admin = $admins->newEntity([
                'email' => $normalizedEmail,
                'display_name' => $displayName,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'status' => PlatformAdmin::STATUS_ACTIVE,
                'require_password_change' => $requirePasswordChange,
                'failed_attempts' => 0,
            ]);
            $admins->saveOrFail($admin);

            return [
                'admin' => $admin,
                'created' => true,
                'updated' => false,
            ];
        }
        if (!$force) {
            return ['admin' => $admin, 'created' => false, 'updated' => false];
        }
        $admin->display_name = $displayName;
        $admin->password_hash = password_hash($password, PASSWORD_DEFAULT);
        $admin->status = PlatformAdmin::STATUS_ACTIVE;
        $admin->require_password_change = $requirePasswordChange;
        $admin->failed_attempts = 0;
        $admin->locked_until = null;
        $admins->saveOrFail($admin);
        $this->revokeSessions((int)$admin->id);

        return [
            'admin' => $admin,
            'created' => false,
            'updated' => true,
        ];
    }

    /**
     * Change an authenticated admin password.
     */
    public function changePassword(PlatformAdmin $admin, string $currentPassword, string $newPassword): void
    {
        if (!password_verify($currentPassword, (string)$admin->password_hash)) {
            throw new RuntimeException('Current password is incorrect.');
        }
        $this->assertPasswordPolicy($newPassword);
        $admins = $this->fetchTable('PlatformAdmins');
        $admin->password_hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $admin->require_password_change = false;
        $admin->failed_attempts = 0;
        $admin->locked_until = null;
        $admins->saveOrFail($admin);
        $this->revokeSessions((int)$admin->id);
    }

    /**
     * Reset a platform admin password from a trusted CLI context.
     */
    public function resetPassword(string $email, string $newPassword, bool $requirePasswordChange = true): void
    {
        $this->assertPasswordPolicy($newPassword);
        $admins = $this->fetchTable('PlatformAdmins');
        $admin = $admins->find()
            ->where(['email' => strtolower(trim($email))])
            ->first();
        if (!$admin instanceof PlatformAdmin) {
            throw new RuntimeException('Platform admin was not found.');
        }
        $admin->password_hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $admin->status = PlatformAdmin::STATUS_ACTIVE;
        $admin->require_password_change = $requirePasswordChange;
        $admin->failed_attempts = 0;
        $admin->locked_until = null;
        $admins->saveOrFail($admin);
        $this->revokeSessions((int)$admin->id);
    }

    /**
     * Enforce platform admin password minimums.
     */
    private function assertPasswordPolicy(string $password): void
    {
        if (strlen($password) < 14) {
            throw new RuntimeException('Platform admin passwords must be at least 14 characters.');
        }
    }

    /**
     * Verify password and email a one-time login code.
     *
     * @return array{challengeId: int, email: string, expiresAt: \Cake\I18n\DateTime}
     */
    public function beginLogin(string $email, string $password, ServerRequest $request): array
    {
        $admin = $this->adminForLogin($email);
        if (!$admin instanceof PlatformAdmin || !$admin->isActive() || $this->isLocked($admin)) {
            throw new RuntimeException(self::INVALID_CREDENTIALS_MESSAGE);
        }
        if (!password_verify($password, (string)$admin->password_hash)) {
            $this->recordFailure($admin);
            throw new RuntimeException(self::INVALID_CREDENTIALS_MESSAGE);
        }

        return $this->issueEmailCode($admin, self::LOGIN_CODE_PURPOSE, $request, 'Platform admin sign-in');
    }

    /**
     * Verify an emailed login code and create a platform admin session.
     */
    public function completeLogin(int $challengeId, string $emailCode, ServerRequest $request): string
    {
        $challenge = $this->fetchTable('PlatformAdminEmailCodes')->find()
            ->where(['id' => $challengeId, 'purpose' => self::LOGIN_CODE_PURPOSE])
            ->first();
        $admin = null;
        if ($challenge !== null) {
            $admin = $this->fetchTable('PlatformAdmins')->get((int)$challenge->platform_admin_id);
        }
        if (!$admin instanceof PlatformAdmin || !$admin->isActive() || $this->isLocked($admin)) {
            throw new RuntimeException(self::INVALID_CREDENTIALS_MESSAGE);
        }
        if (!$this->consumeEmailCode((int)$admin->id, self::LOGIN_CODE_PURPOSE, trim($emailCode), $challengeId)) {
            $this->recordFailure($admin);
            throw new RuntimeException(self::INVALID_CREDENTIALS_MESSAGE);
        }
        $admins = $this->fetchTable('PlatformAdmins');
        $admin->failed_attempts = 0;
        $admin->locked_until = null;
        $admin->last_login_at = DateTime::now();
        $admins->saveOrFail($admin);

        return $this->createSession($admin, $request);
    }

    /**
     * Email a one-time code for a high-risk platform admin action.
     *
     * @return array{challengeId: int, email: string, expiresAt: \Cake\I18n\DateTime}
     */
    public function requestActionCode(
        PlatformAdmin $admin,
        ServerRequest $request,
        string $actionLabel = 'Platform admin action',
    ): array {
        return $this->issueEmailCode($admin, self::ACTION_CODE_PURPOSE, $request, $actionLabel);
    }

    /**
     * Validate a session token and refresh last-seen timestamp.
     */
    public function adminFromToken(?string $token): ?PlatformAdmin
    {
        if ($token === null || $token === '') {
            return null;
        }
        $sessions = $this->fetchTable('PlatformAdminSessions');
        $session = $sessions->find()
            ->where([
                'token_hash' => hash('sha256', $token),
                'revoked_at IS' => null,
                'expires_at >=' => DateTime::now(),
            ])
            ->contain(['PlatformAdmins'])
            ->first();
        if (!$session || !$session->platform_admin instanceof PlatformAdmin) {
            return null;
        }
        if (!$session->platform_admin->isActive() || $this->isLocked($session->platform_admin)) {
            return null;
        }
        $session->last_seen_at = DateTime::now();
        $sessions->saveOrFail($session);

        return $session->platform_admin;
    }

    /**
     * Revoke a platform admin session token.
     */
    public function logout(?string $token): void
    {
        if ($token === null || $token === '') {
            return;
        }
        $this->fetchTable('PlatformAdminSessions')->updateAll(
            ['revoked_at' => DateTime::now()],
            ['token_hash' => hash('sha256', $token), 'revoked_at IS' => null],
        );
    }

    /**
     * Require password plus emailed one-time code before destructive operations.
     */
    public function verifyAction(PlatformAdmin $admin, string $password, string $emailCode, int $challengeId): void
    {
        if (!password_verify($password, (string)$admin->password_hash)) {
            throw new RuntimeException(self::ACTION_VERIFICATION_MESSAGE);
        }
        if (!$this->consumeEmailCode((int)$admin->id, self::ACTION_CODE_PURPOSE, trim($emailCode), $challengeId)) {
            throw new RuntimeException(self::ACTION_VERIFICATION_MESSAGE);
        }
    }

    /**
     * Create a server-side platform admin session.
     *
     * @param \Cake\Datasource\EntityInterface $admin Platform admin entity
     * @param \Cake\Http\ServerRequest $request Request context
     * @return string Opaque session token
     */
    private function createSession(EntityInterface $admin, ServerRequest $request): string
    {
        $token = bin2hex(random_bytes(32));
        $sessions = $this->fetchTable('PlatformAdminSessions');
        $sessions->saveOrFail($sessions->newEntity([
            'platform_admin_id' => (int)$admin->id,
            'token_hash' => hash('sha256', $token),
            'ip_address' => $request->clientIp(),
            'user_agent' => substr($request->getHeaderLine('User-Agent'), 0, 512),
            'last_seen_at' => DateTime::now(),
            'expires_at' => DateTime::now()->addHours(self::SESSION_HOURS),
        ]));

        return $token;
    }

    /**
     * Revoke all sessions for an admin after password rotation.
     */
    private function revokeSessions(int $adminId): void
    {
        $this->fetchTable('PlatformAdminSessions')->updateAll(
            ['revoked_at' => DateTime::now()],
            ['platform_admin_id' => $adminId, 'revoked_at IS' => null],
        );
    }

    /**
     * Look up a platform admin by normalized login email.
     */
    private function adminForLogin(string $email): ?PlatformAdmin
    {
        $admin = $this->fetchTable('PlatformAdmins')->find()
            ->where(['email' => strtolower(trim($email))])
            ->first();

        return $admin instanceof PlatformAdmin ? $admin : null;
    }

    /**
     * @return array{challengeId: int, email: string, expiresAt: \Cake\I18n\DateTime}
     */
    private function issueEmailCode(
        PlatformAdmin $admin,
        string $purpose,
        ServerRequest $request,
        string $actionLabel,
    ): array {
        $codesTable = $this->fetchTable('PlatformAdminEmailCodes');
        $now = DateTime::now();
        $codesTable->updateAll(
            ['used_at' => $now],
            [
                'platform_admin_id' => (int)$admin->id,
                'purpose' => $purpose,
                'used_at IS' => null,
            ],
        );
        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expiresAt = $now->addMinutes(self::EMAIL_CODE_MINUTES);
        $record = $codesTable->newEntity([
            'platform_admin_id' => (int)$admin->id,
            'purpose' => $purpose,
            'code_hash' => password_hash($code, PASSWORD_DEFAULT),
            'expires_at' => $expiresAt,
            'attempts' => 0,
            'max_attempts' => self::EMAIL_CODE_ATTEMPTS,
            'ip_address' => $request->clientIp(),
            'user_agent' => substr($request->getHeaderLine('User-Agent'), 0, 512),
        ]);
        $codesTable->saveOrFail($record);

        try {
            $this->sendEmailCode($admin, $code, $purpose, $actionLabel, $expiresAt, $request);
        } catch (Throwable $e) {
            $record->used_at = DateTime::now();
            $codesTable->saveOrFail($record);

            throw $e;
        }

        return [
            'challengeId' => (int)$record->id,
            'email' => (string)$admin->email,
            'expiresAt' => $expiresAt,
        ];
    }

    /**
     * Deliver the verification code synchronously using platform email config.
     */
    private function sendEmailCode(
        PlatformAdmin $admin,
        string $code,
        string $purpose,
        string $actionLabel,
        DateTime $expiresAt,
        ServerRequest $request,
    ): void {
        (new PlatformRuntimeConfigService())->applyEmailConfig();
        $mailer = new PlatformAdminMailer();
        $mailer->send('emailCode', [
            (string)$admin->email,
            (string)$admin->display_name,
            $code,
            $purpose,
            $actionLabel,
            $expiresAt,
            $request->clientIp(),
            substr($request->getHeaderLine('User-Agent'), 0, 512),
        ]);
    }

    /**
     * Consume an unused, unexpired email code for the requested purpose.
     */
    private function consumeEmailCode(int $adminId, string $purpose, string $code, ?int $challengeId = null): bool
    {
        if ($code === '') {
            return false;
        }
        $codesTable = $this->fetchTable('PlatformAdminEmailCodes');
        $conditions = [
            'platform_admin_id' => $adminId,
            'purpose' => $purpose,
            'used_at IS' => null,
        ];
        if ($challengeId !== null) {
            $conditions['id'] = $challengeId;
        }
        $codes = $codesTable->find()
            ->where($conditions)
            ->orderByDesc('created')
            ->all();
        foreach ($codes as $record) {
            if ($record->expires_at < DateTime::now() || (int)$record->attempts >= (int)$record->max_attempts) {
                continue;
            }
            if (password_verify($code, (string)$record->code_hash)) {
                $record->used_at = DateTime::now();
                $codesTable->saveOrFail($record);

                return true;
            }
            $record->attempts = ((int)$record->attempts) + 1;
            if ((int)$record->attempts >= (int)$record->max_attempts) {
                $record->used_at = DateTime::now();
            }
            $codesTable->saveOrFail($record);
        }

        return false;
    }

    /**
     * Check account lockout window.
     *
     * @param \App\Model\Entity\PlatformAdmin $admin Platform admin
     * @return bool
     */
    private function isLocked(PlatformAdmin $admin): bool
    {
        return $admin->locked_until !== null && $admin->locked_until >= DateTime::now();
    }

    /**
     * Record failed authentication and lock account when necessary.
     *
     * @param \App\Model\Entity\PlatformAdmin $admin Platform admin
     * @return void
     */
    private function recordFailure(PlatformAdmin $admin): void
    {
        $admin->failed_attempts = ((int)$admin->failed_attempts) + 1;
        if ($admin->failed_attempts >= self::MAX_FAILED_ATTEMPTS) {
            $admin->locked_until = DateTime::now()->addMinutes(self::LOCK_MINUTES);
        }
        $this->fetchTable('PlatformAdmins')->saveOrFail($admin);
    }
}
