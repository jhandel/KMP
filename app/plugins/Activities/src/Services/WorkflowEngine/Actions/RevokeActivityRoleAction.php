<?php
declare(strict_types=1);

namespace Activities\Services\WorkflowEngine\Actions;

use Activities\Model\Entity\Authorization;
use App\Services\ActiveWindowManager\DefaultActiveWindowManager;
use App\Services\ServiceResult;
use App\Services\WorkflowEngine\Actions\ActionInterface;
use Cake\I18n\DateTime;
use Cake\Log\Log;

/**
 * Revokes the activity role from a member when authorization transitions to revoked state.
 *
 * Delegates to ActiveWindowManager::stop() to terminate the active window
 * and revoke any granted roles.
 */
class RevokeActivityRoleAction implements ActionInterface
{
    public function execute(array $params, array $context): ServiceResult
    {
        $entityId = $context['entity_id'] ?? null;
        $triggeredBy = $context['triggered_by'] ?? null;
        $reason = $params['reason'] ?? 'Revoked via workflow action';

        if ($entityId === null) {
            return new ServiceResult(false, "revoke_activity_role: 'entity_id' is required in context");
        }

        try {
            $activeWindowManager = new DefaultActiveWindowManager();
            $awResult = $activeWindowManager->stop(
                'Activities.Authorizations',
                $entityId,
                $triggeredBy ?? 0,
                Authorization::REVOKED_STATUS,
                $reason,
                DateTime::now(),
            );

            if (!$awResult->success) {
                return new ServiceResult(false, 'Failed to stop active window: ' . ($awResult->message ?? ''));
            }

            Log::info("Activities: Revoked role for authorization #{$entityId}");

            return new ServiceResult(true, 'Activity role revoked', ['authorization_id' => $entityId]);
        } catch (\Exception $e) {
            Log::error("Activities: Failed to revoke role for authorization #{$entityId}: " . $e->getMessage());

            return new ServiceResult(false, 'Failed to revoke activity role: ' . $e->getMessage());
        }
    }

    public function getName(): string
    {
        return 'revoke_activity_role';
    }

    public function getDescription(): string
    {
        return 'Revoke the activity role from the member when authorization is revoked';
    }

    public function getParameterSchema(): array
    {
        return [
            'reason' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Reason for revocation (default: "Revoked via workflow action")',
            ],
        ];
    }
}
