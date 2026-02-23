<?php

declare(strict_types=1);

namespace App\Services;

use Cake\Core\Configure;
use Cake\Http\Client;
use Cake\Log\Log;

/**
 * Docker sidecar update provider. Communicates with the kmp-updater sidecar
 * via internal HTTP API to pull images and recreate the app container.
 */
class DockerUpdateProvider implements UpdateProviderInterface
{
    private string $updaterUrl;
    private Client $httpClient;
    private DeploymentUpdateCapabilityService $capabilityService;

    public function __construct()
    {
        $this->updaterUrl = rtrim((string)Configure::read('App.updaterUrl', 'http://kmp-updater:8484'), '/');
        $this->httpClient = new Client(['timeout' => 30]);
        $this->capabilityService = new DeploymentUpdateCapabilityService();
    }

    public function triggerUpdate(string $tag): array
    {
        try {
            $response = $this->httpClient->post(
                "{$this->updaterUrl}/updater/update",
                json_encode(['targetTag' => $tag]),
                ['headers' => ['Content-Type' => 'application/json']],
            );

            if (!$response->isOk()) {
                return [
                    'status' => 'error',
                    'message' => "Updater returned HTTP {$response->getStatusCode()}: {$response->getStringBody()}",
                ];
            }

            return $response->getJson() ?: ['status' => 'started', 'message' => 'Update initiated'];
        } catch (\Throwable $e) {
            Log::error('Docker update trigger failed: ' . $e->getMessage());

            return ['status' => 'error', 'message' => 'Cannot reach updater sidecar: ' . $e->getMessage()];
        }
    }

    public function getStatus(): array
    {
        try {
            $response = $this->httpClient->get("{$this->updaterUrl}/updater/status");
            if ($response->isOk()) {
                return $response->getJson() ?: ['status' => 'unknown', 'message' => '', 'progress' => 0];
            }

            return ['status' => 'error', 'message' => "HTTP {$response->getStatusCode()}", 'progress' => 0];
        } catch (\Throwable $e) {
            return ['status' => 'unreachable', 'message' => $e->getMessage(), 'progress' => 0];
        }
    }

    public function rollback(string $tag): array
    {
        try {
            $response = $this->httpClient->post(
                "{$this->updaterUrl}/updater/rollback",
                json_encode(['previousTag' => $tag]),
                ['headers' => ['Content-Type' => 'application/json']],
            );

            if (!$response->isOk()) {
                return [
                    'status' => 'error',
                    'message' => "Rollback failed: HTTP {$response->getStatusCode()}",
                ];
            }

            return $response->getJson() ?: ['status' => 'started', 'message' => 'Rollback initiated'];
        } catch (\Throwable $e) {
            Log::error('Docker rollback failed: ' . $e->getMessage());

            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    public function supportsWebUpdate(): bool
    {
        try {
            $response = $this->httpClient->get("{$this->updaterUrl}/updater/status");

            return $response->isOk();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function getCapabilities(): array
    {
        $capabilities = $this->capabilityService->getCapabilitiesForProvider('docker');
        $capabilities['web_update_runtime_available'] = $this->supportsWebUpdate();

        return $capabilities;
    }
}
