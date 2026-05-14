<?php
declare(strict_types=1);

namespace App\Services\Platform;

use Cake\Core\Configure;
use Cake\Mailer\Mailer;
use Cake\Mailer\TransportFactory;
use Cake\ORM\Locator\LocatorAwareTrait;

/**
 * Applies platform-owned runtime configuration for admin operations.
 */
class PlatformRuntimeConfigService
{
    use LocatorAwareTrait;

    /**
     * Apply the active platform email config, if one exists.
     */
    public function applyEmailConfig(): void
    {
        $config = $this->fetchTable('PlatformServiceConfigs')->find()
            ->where([
                'service_name' => 'email',
                'config_key' => 'default',
                'is_active' => true,
            ])
            ->first();
        if ($config === null) {
            return;
        }

        $metadata = $this->metadata($config->get('metadata'));
        $transport = array_replace_recursive(
            (array)Configure::read('EmailTransport.default', []),
            (array)($metadata['transport'] ?? []),
        );
        $email = array_replace_recursive(
            (array)Configure::read('Email.default', []),
            (array)($metadata['email'] ?? []),
        );
        $secret = (new PlatformSecretService())->resolveSecretReference(
            is_string($config->get('secret_reference')) ? $config->get('secret_reference') : null,
        );
        if ($secret !== null) {
            $transport['password'] = $secret;
        }

        Configure::write('EmailTransport.platform', $transport);
        Configure::write('Email.platform', $email);
        TransportFactory::drop('platform');
        TransportFactory::setConfig('platform', $transport);
        Mailer::drop('platform');
        Mailer::setConfig('platform', $email);
    }

    /**
     * @param mixed $metadata Metadata field
     * @return array<string, mixed>
     */
    private function metadata(mixed $metadata): array
    {
        if (is_array($metadata)) {
            return $metadata;
        }
        if (is_string($metadata) && $metadata !== '') {
            $decoded = json_decode($metadata, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
}
