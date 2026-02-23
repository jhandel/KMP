<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Fallback provider when automated web update is not implemented.
 */
class ManualUpdateProvider implements UpdateProviderInterface
{
    private DeploymentUpdateCapabilityService $capabilityService;

    public function __construct(private string $provider)
    {
        $this->capabilityService = new DeploymentUpdateCapabilityService();
    }

    public function triggerUpdate(string $tag): array
    {
        return [
            'status' => 'error',
            'message' => "Automated web updates are not implemented for provider '{$this->provider}'. Use deployment CLI/manual workflow for tag {$tag}.",
        ];
    }

    public function getStatus(): array
    {
        return [
            'status' => 'manual',
            'message' => "Provider '{$this->provider}' requires CLI/manual status checks.",
            'progress' => 0,
        ];
    }

    public function rollback(string $tag): array
    {
        return [
            'status' => 'error',
            'message' => "Automated rollback is not implemented for provider '{$this->provider}'. Roll back manually to {$tag}.",
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
        return $this->capabilityService->getCapabilitiesForProvider($this->provider);
    }
}
