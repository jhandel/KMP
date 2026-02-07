<?php
declare(strict_types=1);

namespace App\Services\WorkflowEngine\Actions;

use App\Services\ServiceResult;
use Cake\Log\Log;

/**
 * Queues an email notification as part of a workflow transition.
 *
 * Stub implementation that logs intent. Full integration with KMP's
 * mailer system will come when specific workflows are ported.
 */
class SendEmailAction implements ActionInterface
{
    public function execute(array $params, array $context): ServiceResult
    {
        $to = $params['to'] ?? null;
        $template = $params['template'] ?? null;
        $plugin = $params['plugin'] ?? null;

        if ($template === null) {
            return new ServiceResult(false, "send_email: 'template' parameter is required");
        }

        // Resolve "entity." references from context
        $resolvedTo = $this->resolveReference($to, $context);

        Log::info("WorkflowEngine: send_email to='{$resolvedTo}' template='{$template}' plugin='{$plugin}' (stub - not sent)");

        return new ServiceResult(true, 'Email queued (stub)', [
            'to' => $resolvedTo,
            'template' => $template,
            'plugin' => $plugin,
        ]);
    }

    /**
     * Resolve dot-notation references from the context.
     */
    private function resolveReference(?string $ref, array $context): ?string
    {
        if ($ref === null || !str_contains($ref, '.')) {
            return $ref;
        }

        $parts = explode('.', $ref);
        $current = $context;
        foreach ($parts as $part) {
            if (is_array($current) && isset($current[$part])) {
                $current = $current[$part];
            } elseif (is_object($current) && isset($current->{$part})) {
                $current = $current->{$part};
            } else {
                return $ref; // Return unresolved reference as-is
            }
        }

        return is_string($current) ? $current : $ref;
    }

    public function getName(): string
    {
        return 'send_email';
    }

    public function getDescription(): string
    {
        return 'Queues an email notification via KMP mailer system';
    }

    public function getParameterSchema(): array
    {
        return [
            'to' => ['type' => 'string', 'required' => false, 'description' => 'Recipient address or entity reference'],
            'template' => ['type' => 'string', 'required' => true, 'description' => 'Email template name'],
            'plugin' => ['type' => 'string', 'required' => false, 'description' => 'Plugin owning the template'],
        ];
    }
}
