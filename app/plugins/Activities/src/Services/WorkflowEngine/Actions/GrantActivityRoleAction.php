<?php
declare(strict_types=1);

namespace Activities\Services\WorkflowEngine\Actions;

use App\Services\ActiveWindowManager\DefaultActiveWindowManager;
use App\Services\ServiceResult;
use App\Services\WorkflowEngine\Actions\ActionInterface;
use Cake\I18n\DateTime;
use Cake\Log\Log;
use Cake\ORM\TableRegistry;

/**
 * Grants the activity role to a member when authorization transitions to approved state.
 *
 * Loads the authorization with its activity, then delegates to ActiveWindowManager
 * to start the active window (role assignment with term length).
 */
class GrantActivityRoleAction implements ActionInterface
{
    public function execute(array $params, array $context): ServiceResult
    {
        $entityId = $context['entity_id'] ?? null;
        $triggeredBy = $context['triggered_by'] ?? null;

        if ($entityId === null) {
            return new ServiceResult(false, "grant_activity_role: 'entity_id' is required in context");
        }

        try {
            $authTable = TableRegistry::getTableLocator()->get('Activities.Authorizations');
            $authorization = $authTable->get($entityId, contain: ['Activities']);

            $activeWindowManager = new DefaultActiveWindowManager();
            $awResult = $activeWindowManager->start(
                'Activities.Authorizations',
                $authorization->id,
                $triggeredBy ?? $authorization->member_id,
                DateTime::now(),
                null,
                $authorization->activity->term_length,
                $authorization->activity->grants_role_id,
            );

            if (!$awResult->success) {
                return new ServiceResult(false, 'Failed to start active window: ' . ($awResult->message ?? ''));
            }

            Log::info("Activities: Granted role for authorization #{$entityId}");

            return new ServiceResult(true, 'Activity role granted', ['authorization_id' => $entityId]);
        } catch (\Exception $e) {
            Log::error("Activities: Failed to grant role for authorization #{$entityId}: " . $e->getMessage());

            return new ServiceResult(false, 'Failed to grant activity role: ' . $e->getMessage());
        }
    }

    public function getName(): string
    {
        return 'grant_activity_role';
    }

    public function getDescription(): string
    {
        return 'Grant the activity role to the member when authorization is approved';
    }

    public function getParameterSchema(): array
    {
        return [];
    }
}
