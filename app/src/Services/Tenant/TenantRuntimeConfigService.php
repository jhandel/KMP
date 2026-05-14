<?php
declare(strict_types=1);

namespace App\Services\Tenant;

use App\Services\Platform\PlatformSecretService;
use Cake\Core\Configure;
use Cake\Mailer\Mailer;
use Cake\Mailer\TransportFactory;

/**
 * Applies platform-held per-tenant runtime configuration for services.
 */
class TenantRuntimeConfigService
{
    /**
     * @var array<string, mixed>|null
     */
    private static ?array $baseDocumentsStorage = null;

    /**
     * @var array<string, mixed>|null
     */
    private static ?array $baseEmailTransport = null;

    /**
     * @var array<string, mixed>|null
     */
    private static ?array $baseEmail = null;

    /**
     * Apply active tenant runtime service configuration.
     *
     * @param \App\Services\Tenant\TenantContext $context Tenant context
     * @return void
     */
    public function apply(TenantContext $context): void
    {
        self::$baseDocumentsStorage ??= (array)Configure::read('Documents.storage', []);
        self::$baseEmailTransport ??= (array)Configure::read('EmailTransport.default', []);
        self::$baseEmail ??= (array)Configure::read('Email.default', []);

        foreach ($context->serviceConfigs as $config) {
            if (($config['isActive'] ?? false) !== true) {
                continue;
            }
            match ($config['serviceName'] ?? '') {
                'storage' => $this->applyStorageConfig($config),
                'email' => $this->applyEmailConfig($config),
                default => null,
            };
        }
    }

    /**
     * Restore base runtime service configuration.
     *
     * @return void
     */
    public function reset(): void
    {
        if (self::$baseDocumentsStorage !== null) {
            Configure::write('Documents.storage', self::$baseDocumentsStorage);
        }
        if (self::$baseEmailTransport !== null) {
            Configure::write('EmailTransport.default', self::$baseEmailTransport);
            TransportFactory::drop('default');
            TransportFactory::setConfig('default', self::$baseEmailTransport);
        }
        if (self::$baseEmail !== null) {
            Configure::write('Email.default', self::$baseEmail);
            Mailer::drop('default');
            Mailer::setConfig('default', self::$baseEmail);
        }
    }

    /**
     * @param array<string, mixed> $config Service config metadata
     * @return void
     */
    private function applyStorageConfig(array $config): void
    {
        $metadata = $this->metadata($config);
        $storage = array_replace_recursive(self::$baseDocumentsStorage ?? [], $metadata);
        if (!empty($config['adapter']) && is_string($config['adapter'])) {
            $storage['adapter'] = $config['adapter'];
        }
        $secret = $this->secretValue($config);
        if ($secret !== null) {
            if (($storage['adapter'] ?? '') === 'azure') {
                $storage['azure']['connectionString'] = $secret;
            } elseif (($storage['adapter'] ?? '') === 's3') {
                $storage['s3']['secret'] = $secret;
            }
        }
        Configure::write('Documents.storage', $storage);
    }

    /**
     * @param array<string, mixed> $config Service config metadata
     * @return void
     */
    private function applyEmailConfig(array $config): void
    {
        $metadata = $this->metadata($config);
        $transport = array_replace_recursive(self::$baseEmailTransport ?? [], (array)($metadata['transport'] ?? []));
        $email = array_replace_recursive(self::$baseEmail ?? [], (array)($metadata['email'] ?? []));
        $secret = $this->secretValue($config);
        if ($secret !== null) {
            $transport['password'] = $secret;
        }
        Configure::write('EmailTransport.default', $transport);
        Configure::write('Email.default', $email);
        TransportFactory::drop('default');
        TransportFactory::setConfig('default', $transport);
        Mailer::drop('default');
        Mailer::setConfig('default', $email);
    }

    /**
     * @param array<string, mixed> $config Service config metadata
     * @return array<string, mixed>
     */
    private function metadata(array $config): array
    {
        return is_array($config['metadata'] ?? null) ? $config['metadata'] : [];
    }

    /**
     * @param array<string, mixed> $config Service config metadata
     * @return string|null
     */
    private function secretValue(array $config): ?string
    {
        return (new PlatformSecretService())->resolveSecretReference(
            is_string($config['secretReference'] ?? null) ? $config['secretReference'] : null,
        );
    }
}
