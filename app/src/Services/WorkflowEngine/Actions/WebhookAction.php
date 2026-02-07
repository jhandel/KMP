<?php

declare(strict_types=1);

namespace App\Services\WorkflowEngine\Actions;

use App\Services\ServiceResult;
use App\Services\WorkflowEngine\ContextResolverTrait;
use Cake\Http\Client;
use Cake\Log\Log;

/**
 * Sends a fire-and-forget HTTP webhook as part of a workflow transition.
 *
 * Failures are logged but do not block the transition by default.
 */
class WebhookAction implements ActionInterface
{
    use ContextResolverTrait;

    /**
     * @inheritDoc
     */
    public function execute(array $params, array $context): ServiceResult
    {
        $url = $params['url'] ?? null;
        $method = strtoupper($params['method'] ?? 'POST');

        if ($url === null) {
            return new ServiceResult(false, 'No URL specified for webhook action');
        }

        $url = $this->resolveValue($url, $context);

        // Resolve payload templates
        $payload = $params['payload'] ?? [];
        if (is_array($payload)) {
            $payload = $this->resolvePayload($payload, $context);
        } else {
            $payload = $this->resolveValue($payload, $context);
        }

        try {
            $client = new Client();
            $options = [
                'headers' => ['Content-Type' => 'application/json'],
                'timeout' => 10,
            ];

            if ($method === 'POST') {
                $response = $client->post($url, json_encode($payload), $options);
            } elseif ($method === 'PUT') {
                $response = $client->put($url, json_encode($payload), $options);
            } else {
                $response = $client->get($url, [], $options);
            }

            $statusCode = $response->getStatusCode();
            if ($statusCode >= 200 && $statusCode < 300) {
                return new ServiceResult(true, null, ['status' => $statusCode, 'url' => $url]);
            }

            Log::warning("Webhook returned HTTP {$statusCode} for {$url}");

            return new ServiceResult(false, "Webhook returned HTTP {$statusCode}", ['status' => $statusCode, 'url' => $url]);
        } catch (\Exception $e) {
            Log::warning("Webhook failed for {$url}: {$e->getMessage()}");

            return new ServiceResult(false, "Webhook failed: {$e->getMessage()}", ['url' => $url]);
        }
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'webhook';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Sends a fire-and-forget HTTP webhook';
    }

    /**
     * @inheritDoc
     */
    public function getParameterSchema(): array
    {
        return [
            'url' => [
                'type' => 'string',
                'required' => true,
                'description' => 'Webhook URL (supports {{template}} variables)',
            ],
            'method' => [
                'type' => 'string',
                'required' => false,
                'description' => 'HTTP method (default: POST)',
                'enum' => ['GET', 'POST', 'PUT'],
            ],
            'payload' => [
                'type' => 'object',
                'required' => false,
                'description' => 'JSON payload (values support {{template}} variables)',
            ],
        ];
    }

    /**
     * Recursively resolve template variables in a payload array.
     */
    private function resolvePayload(array $payload, array $context): array
    {
        $resolved = [];
        foreach ($payload as $key => $val) {
            if (is_array($val)) {
                $resolved[$key] = $this->resolvePayload($val, $context);
            } else {
                $resolved[$key] = $this->resolveValue($val, $context);
            }
        }

        return $resolved;
    }
}
