<?php
declare(strict_types=1);

namespace App\Services\WorkflowEngine;

use App\Services\ServiceResult;
use App\Services\WorkflowEngine\Actions\ActionInterface;
use Cake\Log\Log;

/**
 * Executes arrays of workflow actions in sequence.
 *
 * Actions are JSON objects with 'action' (type name) and 'params' keys.
 * Supports plugin registration of custom action types.
 */
class ActionExecutor
{
    /** @var array<string, ActionInterface> */
    private array $actionTypes = [];

    public function __construct()
    {
        $this->registerBuiltIns();
    }

    /**
     * Execute an array of action definitions.
     *
     * @param array $actions Array of action definitions [{action: string, params: array}, ...]
     * @param array $context Runtime context
     * @return array Array of ServiceResult, one per action
     */
    public function executeAll(array $actions, array $context): array
    {
        $results = [];
        foreach ($actions as $actionDef) {
            $type = $actionDef['action'] ?? null;
            $params = $actionDef['params'] ?? [];

            if ($type === null || !isset($this->actionTypes[$type])) {
                Log::warning("WorkflowEngine: Unknown action type '{$type}', skipping.");
                $results[] = new ServiceResult(false, "Unknown action type: {$type}");
                continue;
            }

            try {
                $results[] = $this->actionTypes[$type]->execute($params, $context);
            } catch (\Exception $e) {
                Log::error("WorkflowEngine: Action '{$type}' failed: " . $e->getMessage());
                $results[] = new ServiceResult(false, "Action '{$type}' failed: " . $e->getMessage());
            }
        }

        return $results;
    }

    /**
     * Register a custom action type.
     *
     * @param string $name Action type name
     * @param ActionInterface $action Action implementation
     */
    public function registerActionType(string $name, ActionInterface $action): void
    {
        $this->actionTypes[$name] = $action;
    }

    /**
     * @return string[] List of registered action type names
     */
    public function getRegisteredTypes(): array
    {
        return array_keys($this->actionTypes);
    }

    private function registerBuiltIns(): void
    {
        $this->registerActionType('set_field', new Actions\SetFieldAction());
        $this->registerActionType('set_context', new Actions\SetContextAction());
        $this->registerActionType('send_email', new Actions\SendEmailAction());
        $this->registerActionType('webhook', new Actions\WebhookAction());
        $this->registerActionType('request_approval', new Actions\RequestApprovalAction());
    }
}
