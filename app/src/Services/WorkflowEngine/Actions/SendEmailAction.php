<?php

declare(strict_types=1);

namespace App\Services\WorkflowEngine\Actions;

use App\Services\ServiceResult;
use App\Services\WorkflowEngine\ContextResolverTrait;
use Cake\Log\Log;

/**
 * Queues an email notification as part of a workflow transition.
 *
 * Resolves recipient address and template variables from context.
 * Actual sending is logged; mailer integration varies per workflow.
 */
class SendEmailAction implements ActionInterface
{
    use ContextResolverTrait;

    /**
     * @inheritDoc
     */
    public function execute(array $params, array $context): ServiceResult
    {
        $mailer = $params['mailer'] ?? null;
        $method = $params['method'] ?? null;
        $to = $params['to'] ?? null;

        if ($mailer === null || $method === null) {
            return new ServiceResult(false, 'Mailer and method are required for send_email action');
        }

        // Resolve the recipient address from context
        $toAddress = $to !== null ? $this->resolveValue($to, $context) : null;

        // Resolve any extra vars
        $vars = [];
        if (isset($params['vars']) && is_array($params['vars'])) {
            foreach ($params['vars'] as $key => $val) {
                $vars[$key] = $this->resolveValue($val, $context);
            }
        }

        Log::info("WorkflowAction send_email: mailer={$mailer} method={$method} to={$toAddress}");

        return new ServiceResult(true, null, [
            'mailer' => $mailer,
            'method' => $method,
            'to' => $toAddress,
            'vars' => $vars,
        ]);
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'send_email';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Queues an email notification via the configured mailer';
    }

    /**
     * @inheritDoc
     */
    public function getParameterSchema(): array
    {
        return [
            'mailer' => [
                'type' => 'string',
                'required' => true,
                'description' => 'CakePHP mailer class (e.g. "Awards.Awards")',
            ],
            'method' => [
                'type' => 'string',
                'required' => true,
                'description' => 'Mailer method to invoke',
            ],
            'to' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Recipient address or context path (e.g. "entity.requester.email")',
            ],
            'vars' => [
                'type' => 'object',
                'required' => false,
                'description' => 'Extra variables to pass to the mailer (values support {{template}})',
            ],
        ];
    }
}
