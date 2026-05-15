<?php
declare(strict_types=1);

namespace App\Test\TestCase;

use App\Middleware\TenantSessionMiddleware;
use App\Model\Entity\Tenant;
use App\Services\Tenant\TenantProvisioningService;
use App\Services\Tenant\TenantInvalidationService;
use App\Services\Tenant\TenantRegistry;
use Cake\Database\SchemaCache;
use Cake\Datasource\ConnectionManager;
use Cake\Datasource\EntityInterface;
use Cake\ORM\TableRegistry;
use Migrations\Migrations;

/**
 * TestAuthenticationHelperTrait
 *
 * Helper trait for authenticating test users in integration tests.
 * Provides convenient methods to authenticate as the test super user
 * or other predefined test accounts.
 *
 * Sessions must contain a Member entity (not a plain array) because
 * the authorization middleware expects KmpIdentityInterface.
 */
trait TestAuthenticationHelperTrait
{
    /**
     * Authenticate as the test super user
     *
     * Loads the admin member from the database and sets it in the session.
     * The authorization layer will check permissions via PermissionsLoader.
     *
     * @return void
     */
    protected function authenticateAsSuperUser(): void
    {
        $membersTable = TableRegistry::getTableLocator()->get('Members');
        // MySQL seed uses admin@amp.ansteorra.org; Postgres migration seed uses admin@test.com
        $member = $membersTable->find()
            ->where(['email_address IN' => ['admin@amp.ansteorra.org', 'admin@test.com']])
            ->firstOrFail();
        $this->session($this->tenantSessionData(['Auth' => $member]));
    }

    /**
     * Authenticate as the admin user
     *
     * Alias for authenticateAsSuperUser() — same admin account.
     *
     * @return void
     */
    protected function authenticateAsAdmin(): void
    {
        $this->authenticateAsSuperUser();
    }

    /**
     * Authenticate as a custom user by member ID
     *
     * Loads the member from the database. The member must exist in seed data.
     *
     * @param int $memberId The ID of the member to authenticate as
     * @return void
     */
    protected function authenticateAsMember(int $memberId): void
    {
        $membersTable = TableRegistry::getTableLocator()->get('Members');
        $syntheticMembers = $this->syntheticTestMembers();
        $email = $syntheticMembers[$memberId][3] ?? null;
        $conditions = ['id' => $memberId];
        if ($email !== null) {
            $conditions = ['OR' => [['id' => $memberId], ['email_address' => $email]]];
        }
        $member = $membersTable->find()->where(['id' => $memberId])->first()
            ?? $membersTable->find()->where($conditions)->first()
            ?? $this->createSyntheticTestMember($memberId);
        $this->session($this->tenantSessionData(['Auth' => $member]));
    }

    /**
     * Create a synthetic member when a test seed has been rebuilt without helper records.
     *
     * @param int $memberId Member id
     * @return \Cake\Datasource\EntityInterface
     */
    protected function createSyntheticTestMember(int $memberId): EntityInterface
    {
        $members = $this->syntheticTestMembers();
        [$scaName, $firstName, $lastName, $email, $birthYear, $warrantable] = $members[$memberId]
            ?? ["Test Member {$memberId}", 'Test', 'Member', "member{$memberId}@example.test", 2000, true];

        $membersTable = TableRegistry::getTableLocator()->get('Members');
        $connection = ConnectionManager::get('test');
        $exists = (int)$connection
            ->execute('SELECT COUNT(*) FROM members WHERE id = ?', [$memberId])
            ->fetchColumn(0);
        if ($exists > 0) {
            $connection->execute(
                'UPDATE members SET deleted = NULL, email_address = ?, status = ?, membership_expires_on = ?, warrantable = ? WHERE id = ?',
                [$email, 'verified', '2100-01-01', (int)$warrantable, $memberId],
            );

            return $membersTable->find()->where(['id' => $memberId])->firstOrFail();
        }
        $member = $membersTable->newEntity([
            'id' => $memberId,
            'password' => 'TestPassword',
            'sca_name' => $scaName,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email_address' => $email,
            'street_address' => '1 Test Way',
            'city' => 'Testville',
            'state' => 'TS',
            'zip' => '00000',
            'phone_number' => '555-0100',
            'status' => 'verified',
            'membership_expires_on' => '2100-01-01',
            'warrantable' => $warrantable,
            'birth_month' => 1,
            'birth_year' => $birthYear,
            'created_by' => 1,
            'modified_by' => 1,
        ]);
        $member->set('id', $memberId, ['guard' => false]);
        $membersTable->saveOrFail($member, ['checkRules' => false]);

        return $member;
    }

    /**
     * Synthetic member data keyed by conventional test member id.
     *
     * @return array<int, array{string, string, string, string, int, bool}>
     */
    protected function syntheticTestMembers(): array
    {
        return [
            2871 => ['Agatha Local MoAS Demoer', 'Agatha', 'Demoer', 'agatha@ampdemo.com', 2000, true],
            2872 => ['Bryce Local Seneschal Demoer', 'Bryce', 'Demoer', 'bryce@ampdemo.com', 2001, true],
            2874 => ['Devon Regional Armored Demoer', 'Devon', 'Demoer', 'devon@ampdemo.com', 2002, false],
            2875 => ['Eirik Kingdom Seneschal Demoer', 'Eirik', 'Demoer', 'eirik@ampdemo.com', 2004, true],
        ];
    }

    /**
     * Log out the current user
     *
     * @return void
     */
    protected function logout(): void
    {
        $this->session(['Auth' => null]);
    }

    /**
     * Get the currently authenticated member ID
     *
     * @return int|null
     */
    protected function getAuthenticatedMemberId(): ?int
    {
        // Check post-request session first
        $session = $this->_requestSession ?? null;
        if ($session && $session->check('Auth')) {
            $auth = $session->read('Auth');
            if (is_object($auth) && isset($auth->id)) {
                return (int)$auth->id;
            }
            if (is_array($auth) && !empty($auth['id'])) {
                return (int)$auth['id'];
            }
        }
        // Fall back to pre-request session data
        if (!empty($this->_session['Auth'])) {
            $auth = $this->_session['Auth'];
            if (is_object($auth) && isset($auth->id)) {
                return (int)$auth->id;
            }
            if (is_array($auth) && !empty($auth['id'])) {
                return (int)$auth['id'];
            }
        }

        return null;
    }

    /**
     * Assert that a user is authenticated
     *
     * @param string|null $message Custom assertion message
     * @return void
     */
    protected function assertAuthenticated(?string $message = null): void
    {
        $message = $message ?? 'Expected a user to be authenticated';
        $this->assertNotNull($this->getAuthenticatedMemberId(), $message);
    }

    /**
     * Assert that no user is authenticated
     *
     * @param string|null $message Custom assertion message
     * @return void
     */
    protected function assertNotAuthenticated(?string $message = null): void
    {
        $message = $message ?? 'Expected no user to be authenticated';
        $this->assertNull($this->getAuthenticatedMemberId(), $message);
    }

    /**
     * Assert that a specific member is authenticated
     *
     * @param int $memberId Expected member ID
     * @param string|null $message Custom assertion message
     * @return void
     */
    protected function assertAuthenticatedAs(int $memberId, ?string $message = null): void
    {
        $message = $message ?? "Expected member {$memberId} to be authenticated";
        $this->assertEquals($memberId, $this->getAuthenticatedMemberId(), $message);
    }

    /**
     * Add tenant session markers required by TenantSessionMiddleware.
     *
     * @param array<string, mixed> $session Session data
     * @return array<string, mixed>
     */
    protected function tenantSessionData(array $session): array
    {
        $tenant = TableRegistry::getTableLocator()->get('Tenants')->find()
            ->where(['slug' => 'test'])
            ->first();
        if ($tenant === null) {
            $this->ensureTestTenantForSession();
            $tenant = TableRegistry::getTableLocator()->get('Tenants')->find()
                ->where(['slug' => 'test'])
                ->first();
        }
        if ($tenant === null) {
            return $session;
        }

        $session[TenantSessionMiddleware::TENANT_ID_SESSION_KEY] = (int)$tenant->id;
        $session[TenantSessionMiddleware::TENANT_SLUG_SESSION_KEY] = (string)$tenant->slug;

        return $session;
    }

    /**
     * Seed the default HTTP test tenant after tests that rebuild platform tables.
     *
     * @return void
     */
    protected function ensureTestTenantForSession(): void
    {
        $connection = ConnectionManager::get('test');
        (new Migrations())->migrate([
            'connection' => 'test',
            'source' => 'PlatformMigrations',
        ]);
        (new SchemaCache($connection))->clear();
        TableRegistry::getTableLocator()->clear();

        $testConfig = (array)ConnectionManager::getConfig('test');
        (new TenantProvisioningService())->createOrUpdateTenant([
            'slug' => 'test',
            'display_name' => 'Test Tenant',
            'status' => Tenant::STATUS_ACTIVE,
            'primary_host' => 'localhost',
            'aliases' => ['127.0.0.1'],
            'path_prefix' => null,
            'database_name' => (string)($testConfig['database'] ?? 'test'),
            'database_url' => null,
            'driver' => (string)($testConfig['driver'] ?? 'Cake\\Database\\Driver\\Mysql'),
            'host' => (string)($testConfig['host'] ?? 'localhost'),
            'port' => $testConfig['port'] ?? null,
            'username' => (string)($testConfig['username'] ?? ''),
            'secret_reference' => null,
            'password_env' => null,
            'email_config_json' => null,
            'email_secret_reference' => null,
            'storage_config_json' => json_encode([
                'local' => [
                    'path' => TMP . 'uploaded',
                ],
            ], JSON_THROW_ON_ERROR),
            'storage_adapter' => 'local',
            'storage_secret_reference' => null,
        ]);
        TenantInvalidationService::clearLocalCache();
        TenantRegistry::clearLocalCache();
        TableRegistry::getTableLocator()->clear();
    }
}
