<?php

declare(strict_types=1);

namespace App\Services;

use Cake\Core\Configure;
use Cake\Http\Client;
use Cake\Log\Log;

/**
 * Railway update provider. Uses Railway's API to trigger redeployment with a new image.
 */
class RailwayUpdateProvider implements UpdateProviderInterface
{
    private string $apiToken;
    private string $projectId;
    private string $serviceId;
    private Client $httpClient;

    public function __construct()
    {
        $this->apiToken = (string)env('RAILWAY_API_TOKEN', '');
        $this->projectId = (string)env('RAILWAY_PROJECT_ID', '');
        $this->serviceId = (string)env('RAILWAY_SERVICE_ID', '');
        $this->httpClient = new Client(['timeout' => 30]);
    }

    public function triggerUpdate(string $tag): array
    {
        if (empty($this->apiToken) || empty($this->projectId) || empty($this->serviceId)) {
            return [
                'status' => 'error',
                'message' => 'Railway API credentials not configured. Set RAILWAY_API_TOKEN, RAILWAY_PROJECT_ID, and RAILWAY_SERVICE_ID.',
            ];
        }

        try {
            $registry = trim((string)Configure::read('App.containerRegistry', 'ghcr.io/jhandel/kmp'));
            $imageRef = "{$registry}:{$tag}";

            // Railway GraphQL API: update service source image
            $mutation = <<<'GRAPHQL'
mutation($serviceId: String!, $projectId: String!, $image: String!) {
    serviceInstanceUpdate(
        serviceId: $serviceId
        input: { source: { image: $image } }
    ) {
        id
    }
    serviceInstanceDeploy(
        serviceId: $serviceId
        input: { environmentId: null }
    ) {
        id
        status
    }
}
GRAPHQL;

            $response = $this->httpClient->post(
                'https://backboard.railway.app/graphql/v2',
                json_encode([
                    'query' => $mutation,
                    'variables' => [
                        'serviceId' => $this->serviceId,
                        'projectId' => $this->projectId,
                        'image' => $imageRef,
                    ],
                ]),
                [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'Authorization' => "Bearer {$this->apiToken}",
                    ],
                ],
            );

            if (!$response->isOk()) {
                return [
                    'status' => 'error',
                    'message' => "Railway API returned HTTP {$response->getStatusCode()}",
                ];
            }

            $data = $response->getJson();
            if (!empty($data['errors'])) {
                $errorMsg = $data['errors'][0]['message'] ?? 'Unknown Railway API error';

                return ['status' => 'error', 'message' => $errorMsg];
            }

            return ['status' => 'started', 'message' => "Deployment triggered for {$imageRef}"];
        } catch (\Throwable $e) {
            Log::error('Railway update trigger failed: ' . $e->getMessage());

            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    public function getStatus(): array
    {
        // Railway deployments are async; return a generic status
        return ['status' => 'check_health', 'message' => 'Check /health endpoint for deployment status', 'progress' => 0];
    }

    public function rollback(string $tag): array
    {
        // Rollback is just a new deployment with the old tag
        return $this->triggerUpdate($tag);
    }

    public function supportsWebUpdate(): bool
    {
        return !empty($this->apiToken) && !empty($this->projectId) && !empty($this->serviceId);
    }
}
