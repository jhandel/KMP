<?php
declare(strict_types=1);

namespace App\Services\Logging;

/**
 * Redacts known-sensitive values from structured log payloads.
 */
class SensitiveDataRedactor
{
    private const REDACTED_VALUE = '[redacted]';

    /**
     * @var array<int, string>
     */
    private const REDACTED_KEYS = [
        'password',
        'password_hash',
        'mfa_code',
        'email_code',
        'verify_email_code',
        'recovery_code',
        'backup_key',
        'restore_key',
        'secret',
        'database_secret_value',
        'email_secret_value',
        'storage_secret_value',
        'secret_reference_value',
        'token',
        'access_token',
        'refresh_token',
        'authorization',
        'cookie',
        'set-cookie',
        'api_key',
        'x-api-key',
        'connection_string',
        'private_key',
    ];

    /**
     * @var array<int, string>
     */
    private const REDACTED_KEY_PATTERNS = [
        '/pass(word)?/i',
        '/secret/i',
        '/token/i',
        '/authorization/i',
        '/cookie/i',
        '/credential/i',
        '/private[_-]?key/i',
        '/connection[_-]?string/i',
        '/dsn/i',
    ];

    /**
     * @param array<string, mixed> $value
     * @return array<string, mixed>
     */
    public function redact(array $value): array
    {
        foreach ($value as $key => $item) {
            if ($this->isSensitiveKey((string)$key)) {
                $value[$key] = self::REDACTED_VALUE;

                continue;
            }
            if (is_array($item)) {
                $value[$key] = $this->redact($item);
            }
        }

        return $value;
    }

    /**
     * @param string $key Metadata key
     * @return bool
     */
    private function isSensitiveKey(string $key): bool
    {
        if (in_array(strtolower($key), self::REDACTED_KEYS, true)) {
            return true;
        }

        foreach (self::REDACTED_KEY_PATTERNS as $pattern) {
            if (preg_match($pattern, $key) === 1) {
                return true;
            }
        }

        return false;
    }
}
