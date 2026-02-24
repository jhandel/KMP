<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Docker update provider intentionally routes operators to CLI/manual updates.
 *
 * Sidecar-driven in-app updates are disabled until a simpler, lower-risk
 * strategy is adopted.
 */
class DockerUpdateProvider implements UpdateProviderInterface
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
            'message' => "Web-triggered Docker updates are disabled. Use 'kmp update' or 'docker compose pull && docker compose up -d' for tag {$tag}.",
        ];
    }

    public function getStatus(): array
    {
        return [
            'status' => 'manual',
            'message' => "Docker update execution is CLI-managed. Run 'kmp status' or 'docker compose ps' for runtime status.",
            'progress' => 0,
        ];
    }

    public function rollback(string $tag): array
    {
        return [
            'status' => 'error',
            'message' => "Web-triggered Docker rollback is disabled. Roll back manually to {$tag} using compose/CLI workflow.",
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
        $capabilities = $this->capabilityService->getCapabilitiesForProvider('docker');
        $capabilities['web_update_runtime_available'] = false;

        return $capabilities;
    }
}
