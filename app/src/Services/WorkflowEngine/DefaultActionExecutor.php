<?php

declare(strict_types=1);

namespace App\Services\WorkflowEngine;

use App\Services\ServiceResult;
use App\Services\WorkflowEngine\Actions\ActionInterface;

/**
 * Default Action Executor
 *
 * Executes arrays of action definitions during workflow transitions.
 * Built-in actions: set_field, send_email, set_context, webhook.
 * Plugins may register additional action types via registerActionType().
 */
class DefaultActionExecutor implements ActionExecutorInterface
{
    /** @var array<string, ActionInterface> */
    protected array $actionTypes = [];

    public function __construct()
    {
        $this->registerBuiltInActions();
    }

    /**
     * @inheritDoc
     */
    public function execute(array $actions, array $context): ServiceResult
    {
        $results = [];
        foreach ($actions as $actionDef) {
            $type = $actionDef['type'] ?? null;
            if ($type === null) {
                $results[] = ['type' => 'unknown', 'success' => false, 'reason' => 'No action type specified'];
                continue;
            }
            if (!isset($this->actionTypes[$type])) {
                $results[] = ['type' => $type, 'success' => false, 'reason' => "Unknown action type: {$type}"];
                continue;
            }

            $result = $this->actionTypes[$type]->execute($actionDef, $context);
            $results[] = [
                'type' => $type,
                'success' => $result->success,
                'reason' => $result->reason,
                'data' => $result->data,
            ];

            // Stop on non-optional failure
            if (!$result->success && !($actionDef['optional'] ?? false)) {
                return new ServiceResult(false, "Action '{$type}' failed: {$result->reason}", $results);
            }
        }

        return new ServiceResult(true, null, $results);
    }

    /**
     * @inheritDoc
     */
    public function registerActionType(string $name, ActionInterface $action): void
    {
        $this->actionTypes[$name] = $action;
    }

    /**
     * @inheritDoc
     */
    public function getRegisteredActionTypes(): array
    {
        return array_keys($this->actionTypes);
    }

    /**
     * Register the four built-in action types.
     */
    protected function registerBuiltInActions(): void
    {
        $this->registerActionType('set_field', new Actions\SetFieldAction());
        $this->registerActionType('send_email', new Actions\SendEmailAction());
        $this->registerActionType('set_context', new Actions\SetContextAction());
        $this->registerActionType('webhook', new Actions\WebhookAction());
    }
}
