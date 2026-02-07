<?php

declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Seeds the approval gate for the activity-authorization workflow's pending-approval state.
 *
 * Configures a chain-type gate with conditional threshold resolution
 * based on whether the authorization is a renewal or new request.
 */
class SeedActivityAuthorizationApprovalGates extends BaseMigration
{
    public function up(): void
    {
        // Find the workflow definition
        $rows = $this->fetchAll(
            "SELECT id FROM workflow_definitions WHERE slug = 'activity-authorization' LIMIT 1"
        );
        if (empty($rows)) {
            return;
        }
        $defId = (int)$rows[0]['id'];

        // Find the pending-approval state
        $stateRows = $this->fetchAll(
            "SELECT id FROM workflow_states
             WHERE workflow_definition_id = {$defId} AND slug = 'pending-approval'
             LIMIT 1"
        );
        if (empty($stateRows)) {
            return;
        }
        $stateId = (int)$stateRows[0]['id'];

        // Find the approve transition (pending-approval → approved)
        $approveRows = $this->fetchAll(
            "SELECT id FROM workflow_transitions
             WHERE workflow_definition_id = {$defId}
               AND from_state_id = {$stateId}
               AND slug = 'approve'
             LIMIT 1"
        );
        $approveTransitionId = !empty($approveRows) ? (int)$approveRows[0]['id'] : 'NULL';

        // Find the deny transition (pending-approval → denied)
        $denyRows = $this->fetchAll(
            "SELECT id FROM workflow_transitions
             WHERE workflow_definition_id = {$defId}
               AND from_state_id = {$stateId}
               AND slug = 'deny'
             LIMIT 1"
        );
        $denyTransitionId = !empty($denyRows) ? (int)$denyRows[0]['id'] : 'NULL';

        // Check idempotency — skip if gate already exists for this state
        $existing = $this->fetchAll(
            "SELECT id FROM workflow_approval_gates WHERE workflow_state_id = {$stateId} LIMIT 1"
        );
        if (!empty($existing)) {
            return;
        }

        $thresholdConfig = addslashes(json_encode([
            'type' => 'conditional_entity_field',
            'condition_field' => 'is_renewal',
            'when_true' => ['field' => 'activity.num_required_renewers'],
            'when_false' => ['field' => 'activity.num_required_authorizors'],
            'default' => 2,
        ]));

        $approverRule = addslashes(json_encode([
            'type' => 'permission',
            'permission' => 'canApproveAuthorizations',
        ]));

        $this->execute(
            "INSERT INTO workflow_approval_gates
                (workflow_state_id, approval_type, required_count, threshold_config, approver_rule,
                 timeout_hours, on_satisfied_transition_id, on_denied_transition_id,
                 allow_delegation, created, modified)
             VALUES
                ({$stateId}, 'chain', 2, '{$thresholdConfig}', '{$approverRule}',
                 168, {$approveTransitionId}, {$denyTransitionId},
                 1, NOW(), NOW())"
        );
    }

    public function down(): void
    {
        $rows = $this->fetchAll(
            "SELECT id FROM workflow_definitions WHERE slug = 'activity-authorization' LIMIT 1"
        );
        if (empty($rows)) {
            return;
        }
        $defId = (int)$rows[0]['id'];

        $stateRows = $this->fetchAll(
            "SELECT id FROM workflow_states
             WHERE workflow_definition_id = {$defId} AND slug = 'pending-approval'
             LIMIT 1"
        );
        if (!empty($stateRows)) {
            $stateId = (int)$stateRows[0]['id'];
            $this->execute("DELETE FROM workflow_approval_gates WHERE workflow_state_id = {$stateId}");
        }
    }
}
