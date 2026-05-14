<?php
declare(strict_types=1);

namespace App\Services\Platform;

use App\Model\Entity\PlatformAdmin;
use Cake\Datasource\EntityInterface;
use Cake\Http\ServerRequest;
use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;
use RuntimeException;

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
    private const ACTION_VERIFICATION_MESSAGE = 'Action verification failed. Use your current platform admin password '
        . 'and an unused one-time action code.';

    /**
     * Create a platform admin and return one-time MFA recovery codes.
     *
     * @return array{admin: \Cake\Datasource\EntityInterface, recoveryCodes: array<int, string>}
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
        $codes = $this->replaceRecoveryCodes((int)$admin->id);

        return ['admin' => $admin, 'recoveryCodes' => $codes];
    }

    /**
     * Ensure a seed platform admin exists, optionally replacing its password and codes.
     *
     * @return array{
     *   admin: \App\Model\Entity\PlatformAdmin,
     *   recoveryCodes: array<int, string>,
     *   created: bool,
     *   updated: bool
     * }
     */
    public function seedAdmin(string $email, string $displayName, string $password, bool $force = false): array
    {
        $admins = $this->fetchTable('PlatformAdmins');
        $normalizedEmail = strtolower(trim($email));
        $admin = $admins->find()
            ->where(['email' => $normalizedEmail])
            ->first();
        if (!$admin instanceof PlatformAdmin) {
            $created = $this->createAdmin($normalizedEmail, $displayName, $password, true);

            return [
                'admin' => $created['admin'],
                'recoveryCodes' => $created['recoveryCodes'],
                'created' => true,
                'updated' => false,
            ];
        }
        if (!$force) {
            return ['admin' => $admin, 'recoveryCodes' => [], 'created' => false, 'updated' => false];
        }
        $this->assertPasswordPolicy($password);
        $admin->display_name = $displayName;
        $admin->password_hash = password_hash($password, PASSWORD_DEFAULT);
        $admin->status = PlatformAdmin::STATUS_ACTIVE;
        $admin->require_password_change = true;
        $admin->failed_attempts = 0;
        $admin->locked_until = null;
        $admins->saveOrFail($admin);
        $this->revokeSessions((int)$admin->id);

        return [
            'admin' => $admin,
            'recoveryCodes' => $this->replaceRecoveryCodes((int)$admin->id),
            'created' => false,
            'updated' => true,
        ];
    }

    /**
     * Change an authenticated admin password and rotate recovery codes.
     *
     * @return array<int, string>
     */
    public function changePassword(PlatformAdmin $admin, string $currentPassword, string $newPassword): array
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

        return $this->replaceRecoveryCodes((int)$admin->id);
    }

    /**
     * Reset a platform admin password from a trusted CLI context.
     *
     * @return array<int, string>
     */
    public function resetPassword(string $email, string $newPassword, bool $requirePasswordChange = true): array
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

        return $this->replaceRecoveryCodes((int)$admin->id);
    }

    /**
     * Replace all unused recovery codes for an admin.
     *
     * @return array<int, string>
     */
    public function replaceRecoveryCodes(int $adminId): array
    {
        $codesTable = $this->fetchTable('PlatformAdminRecoveryCodes');
        $codesTable->deleteAll(['platform_admin_id' => $adminId, 'used_at IS' => null]);
        $codes = [];
        for ($i = 0; $i < 10; $i++) {
            $code = strtoupper(bin2hex(random_bytes(4)) . '-' . bin2hex(random_bytes(4)));
            $codes[] = $code;
            $codesTable->saveOrFail($codesTable->newEntity([
                'platform_admin_id' => $adminId,
                'code_hash' => password_hash($code, PASSWORD_DEFAULT),
            ]));
        }

        return $codes;
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
     * Authenticate with password plus one-time MFA recovery code.
     */
    public function authenticate(string $email, string $password, string $mfaCode, ServerRequest $request): string
    {
        $admins = $this->fetchTable('PlatformAdmins');
        $admin = $admins->find()
            ->where(['email' => strtolower(trim($email))])
            ->first();
        if (!$admin instanceof PlatformAdmin || !$admin->isActive() || $this->isLocked($admin)) {
            throw new RuntimeException('Invalid platform admin credentials.');
        }
        if (!password_verify($password, (string)$admin->password_hash)) {
            $this->recordFailure($admin);
            throw new RuntimeException('Invalid platform admin credentials.');
        }
        if (!$this->consumeRecoveryCode((int)$admin->id, trim($mfaCode))) {
            $this->recordFailure($admin);
            throw new RuntimeException('Invalid platform admin credentials.');
        }

        $admin->failed_attempts = 0;
        $admin->locked_until = null;
        $admin->last_login_at = DateTime::now();
        $admins->saveOrFail($admin);

        return $this->createSession($admin, $request);
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
     * Require password plus one-time code before destructive operations.
     */
    public function verifyAction(PlatformAdmin $admin, string $password, string $mfaCode): void
    {
        if (!password_verify($password, (string)$admin->password_hash)) {
            throw new RuntimeException(self::ACTION_VERIFICATION_MESSAGE);
        }
        if (!$this->consumeRecoveryCode((int)$admin->id, trim($mfaCode))) {
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
     * Consume one unused recovery/MFA code.
     *
     * @param int $adminId Platform admin id
     * @param string $code Submitted code
     * @return bool
     */
    private function consumeRecoveryCode(int $adminId, string $code): bool
    {
        if ($code === '') {
            return false;
        }
        $codesTable = $this->fetchTable('PlatformAdminRecoveryCodes');
        $codes = $codesTable->find()
            ->where(['platform_admin_id' => $adminId, 'used_at IS' => null])
            ->all();
        foreach ($codes as $record) {
            if (password_verify($code, (string)$record->code_hash)) {
                $record->used_at = DateTime::now();
                $codesTable->saveOrFail($record);

                return true;
            }
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
