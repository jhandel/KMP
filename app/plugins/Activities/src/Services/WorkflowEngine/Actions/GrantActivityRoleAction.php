<?php

declare(strict_types=1);

namespace Activities\Services\WorkflowEngine\Actions;

use App\Services\WorkflowEngine\Actions\ActionInterface;
use App\Services\WorkflowEngine\ContextResolverTrait;
use App\Services\ServiceResult;
use Cake\ORM\TableRegistry;
use Cake\Log\Log;

/**
 * Grants (or removes) the role associated with an activity authorization.
 *
 * On approval: calls ActiveWindowManager->start() to assign the role and set expiration.
 * On denial/revocation: calls ActiveWindowManager->stop() to remove the granted role.
 */
class GrantActivityRoleAction implements ActionInterface
{
    use ContextResolverTrait;

    /**
     * @inheritDoc
     */
    public function execute(array $params, array $context): ServiceResult
    {
        $mode = $params['mode'] ?? 'grant'; // "grant" or "revoke"
        $entityId = $context['entity_id'] ?? null;
        $userId = $context['user_id'] ?? null;

        if ($entityId === null) {
            return new ServiceResult(false, 'Missing entity_id in context for grant_activity_role');
        }

        $authTable = TableRegistry::getTableLocator()->get('Activities.Authorizations');
        $authorization = $authTable->get($entityId, contain: ['Activities']);

        if (!$authorization || !$authorization->activity) {
            return new ServiceResult(false, 'Authorization or activity not found');
        }

        $activity = $authorization->activity;
        $awManager = \Cake\Core\Container::create()->get(
            \App\Services\ActiveWindowManager\ActiveWindowManagerInterface::class
        );

        if ($mode === 'grant') {
            $result = $awManager->start(
                'Activities.Authorizations',
                $authorization->id,
                $userId,
                new \Cake\I18n\DateTime(),
                null,
                $activity->term_length,
                $activity->grants_role_id,
            );

            if (!$result->success) {
                Log::error("GrantActivityRoleAction: failed to start active window for auth {$entityId}");
                return new ServiceResult(false, 'Failed to grant activity role');
            }

            return new ServiceResult(true, null, ['mode' => 'grant', 'role_id' => $activity->grants_role_id]);
        }

        // Revoke mode
        $reason = $this->resolveValue($params['reason'] ?? 'Workflow revocation', $context);
        $status = $params['target_status'] ?? 'Revoked';

        $result = $awManager->stop(
            'Activities.Authorizations',
            $authorization->id,
            $userId,
            $status,
            (string)$reason,
            new \Cake\I18n\DateTime(),
        );

        if (!$result->success) {
            Log::error("GrantActivityRoleAction: failed to stop active window for auth {$entityId}");
            return new ServiceResult(false, 'Failed to revoke activity role');
        }

        return new ServiceResult(true, null, ['mode' => 'revoke', 'status' => $status]);
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'grant_activity_role';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Grants or removes the role associated with an activity authorization via ActiveWindowManager';
    }

    /**
     * @inheritDoc
     */
    public function getParameterSchema(): array
    {
        return [
            'mode' => [
                'type' => 'string',
                'required' => true,
                'description' => '"grant" to assign role on approval, "revoke" to remove on denial/revocation',
                'enum' => ['grant', 'revoke'],
            ],
            'reason' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Revocation reason (supports {{template}} variables, used in revoke mode)',
            ],
            'target_status' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Status to set on revocation (e.g. Denied, Revoked)',
            ],
        ];
    }
}
