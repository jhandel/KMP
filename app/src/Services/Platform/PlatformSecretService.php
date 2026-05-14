<?php
declare(strict_types=1);

namespace App\Services\Platform;

use App\Model\Entity\PlatformAdmin;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\Utility\Security;
use RuntimeException;

/**
 * Stores and resolves encrypted platform-managed secrets.
 */
class PlatformSecretService
{
    use LocatorAwareTrait;

    public const REFERENCE_PREFIX = 'managed:';

    /**
     * @var array<string, string>
     */
    private static array $resolvedCache = [];

    /**
     * Store or replace a managed secret.
     *
     * @return string Secret reference
     */
    public function storeSecret(
        string $name,
        string $value,
        ?string $description = null,
        ?PlatformAdmin $admin = null,
    ): string {
        $name = $this->normalizeName($name);
        if (trim($value) === '') {
            throw new RuntimeException('Secret value cannot be blank.');
        }
        $secrets = $this->fetchTable('PlatformSecrets');
        $secret = $secrets->find()
            ->where(['name' => $name])
            ->first();
        $payload = [
            'name' => $name,
            'encrypted_value' => $this->encrypt($value),
            'key_version' => $this->keyVersion(),
            'description' => $description,
            'created_by_platform_admin_id' => $admin?->id,
        ];
        $secret = $secret === null ? $secrets->newEntity($payload) : $secrets->patchEntity($secret, $payload);
        $secrets->saveOrFail($secret);
        self::$resolvedCache[$name] = $value;

        return self::REFERENCE_PREFIX . $name;
    }

    /**
     * Resolve env: and managed: secret references.
     */
    public function resolveSecretReference(?string $reference): ?string
    {
        if ($reference === null || trim($reference) === '') {
            return null;
        }
        $reference = trim($reference);
        if (str_starts_with($reference, 'env:')) {
            $value = env(substr($reference, 4), null);

            return is_string($value) && $value !== '' ? $value : null;
        }
        if (!str_starts_with($reference, self::REFERENCE_PREFIX)) {
            return null;
        }
        $name = $this->normalizeName(substr($reference, strlen(self::REFERENCE_PREFIX)));
        if (array_key_exists($name, self::$resolvedCache)) {
            return self::$resolvedCache[$name];
        }
        $secret = $this->fetchTable('PlatformSecrets')->find()
            ->where(['name' => $name])
            ->first();
        if ($secret === null) {
            throw new RuntimeException(sprintf('Managed secret "%s" was not found.', $name));
        }
        $value = $this->decrypt((string)$secret->encrypted_value);
        self::$resolvedCache[$name] = $value;

        return $value;
    }

    /**
     * Clear per-process resolved secret cache.
     *
     * @return void
     */
    public function clearCache(): void
    {
        self::$resolvedCache = [];
    }

    /**
     * Verify managed-secret encryption is configured before consuming action MFA codes.
     *
     * @return void
     */
    public function assertReady(): void
    {
        $this->encryptionKey();
    }

    /**
     * @return string Normalized secret name
     */
    private function normalizeName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || !preg_match('/^[A-Za-z0-9_.\/:-]+$/', $name)) {
            throw new RuntimeException(
                'Managed secret names may only contain letters, numbers, slash, colon, dot, underscore, and hyphen.',
            );
        }

        return $name;
    }

    /**
     * Encrypt plaintext for storage.
     */
    private function encrypt(string $value): string
    {
        return base64_encode(Security::encrypt($value, $this->encryptionKey()));
    }

    /**
     * Decrypt stored ciphertext.
     */
    private function decrypt(string $value): string
    {
        $decoded = base64_decode($value, true);
        if ($decoded === false) {
            throw new RuntimeException('Managed secret ciphertext is invalid.');
        }
        $decrypted = Security::decrypt($decoded, $this->encryptionKey());
        if ($decrypted === null) {
            throw new RuntimeException('Managed secret could not be decrypted with the configured platform key.');
        }

        return $decrypted;
    }

    /**
     * Derive a fixed-length encryption key from the deployment secret.
     */
    private function encryptionKey(): string
    {
        $key = (string)env('PLATFORM_SECRET_KEY', '');
        if (strlen($key) < 32) {
            throw new RuntimeException(
                'PLATFORM_SECRET_KEY must be set to at least 32 characters to use managed secrets.',
            );
        }

        return hash_hkdf('sha256', $key, 32, 'kmp-platform-secrets:' . $this->keyVersion());
    }

    /**
     * Current platform secret key version.
     */
    private function keyVersion(): string
    {
        $version = trim((string)env('PLATFORM_SECRET_KEY_VERSION', 'v1'));

        return $version === '' ? 'v1' : $version;
    }
}
