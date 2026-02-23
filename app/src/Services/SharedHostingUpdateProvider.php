<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Update provider for restricted shared-hosting environments.
 */
class SharedHostingUpdateProvider implements UpdateProviderInterface
{
    private DeploymentUpdateCapabilityService $capabilityService;

    public function __construct()
    {
        $this->capabilityService = new DeploymentUpdateCapabilityService();
    }

    public function triggerUpdate(string $tag): array
    {
        return [
            'status' => 'error',
            'message' => "Web-triggered updates are disabled for shared hosting. Upload release {$tag} using your hosting control panel and run migration steps manually.",
        ];
    }

    public function getStatus(): array
    {
        return [
            'status' => 'manual',
            'message' => 'Shared hosting uses manual update workflow (no host-level automation).',
            'progress' => 0,
        ];
    }

    public function rollback(string $tag): array
    {
        return [
            'status' => 'error',
            'message' => "Automated rollback is not available for shared hosting. Re-deploy prior package/tag {$tag} manually.",
        ];
    }

    public function supportsWebUpdate(): bool
    {
        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function getCapabilities(): array
    {
        return $this->capabilityService->getCapabilitiesForProvider('shared');
    }
}
