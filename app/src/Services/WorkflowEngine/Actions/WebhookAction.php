<?php
declare(strict_types=1);

namespace App\Services\WorkflowEngine\Actions;

use App\Services\ServiceResult;
use Cake\Log\Log;

/**
 * Sends an HTTP callback to an external URL.
 *
 * Stub implementation that logs intent. Full HTTP client integration
 * will be added when external notification workflows are needed.
 */
class WebhookAction implements ActionInterface
{
    public function execute(array $params, array $context): ServiceResult
    {
        $url = $params['url'] ?? null;
        $method = $params['method'] ?? 'POST';

        if ($url === null) {
            return new ServiceResult(false, "webhook: 'url' parameter is required");
        }

        Log::info("WorkflowEngine: webhook {$method} {$url} (stub - not sent)");

        return new ServiceResult(true, 'Webhook queued (stub)', [
            'url' => $url,
            'method' => $method,
        ]);
    }

    public function getName(): string
    {
        return 'webhook';
    }

    public function getDescription(): string
    {
        return 'Sends an HTTP callback to an external URL';
    }

    public function getParameterSchema(): array
    {
        return [
            'url' => ['type' => 'string', 'required' => true, 'description' => 'Webhook URL'],
            'method' => ['type' => 'string', 'required' => false, 'description' => 'HTTP method (default: POST)'],
        ];
    }
}
