<?php

declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Backfills workflow_instances for all existing activities_authorizations
 * that don't yet have one. Also populates workflow_approvals from
 * activities_authorization_approvals records.
 */
class BackfillAuthorizationWorkflowInstances extends BaseMigration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        // Get workflow definition
        $rows = $this->fetchAll(
            "SELECT id FROM workflow_definitions WHERE slug = 'activity-authorization' LIMIT 1"
        );
        if (empty($rows)) {
            return;
        }
        $defId = (int)$rows[0]['id'];

        // Get all workflow state IDs keyed by slug
        $stateRows = $this->fetchAll(
            "SELECT id, slug FROM workflow_states WHERE workflow_definition_id = {$defId}"
        );
        $states = [];
        foreach ($stateRows as $row) {
            $states[$row['slug']] = (int)$row['id'];
        }

        // Get approval gate ID for pending-approval state
        $gateRows = $this->fetchAll(
            "SELECT id FROM workflow_approval_gates WHERE workflow_state_id = {$states['pending-approval']} LIMIT 1"
        );
        $gateId = !empty($gateRows) ? (int)$gateRows[0]['id'] : null;

        // Fetch authorizations that don't have a workflow_instance (idempotent)
        $auths = $this->fetchAll(
            "SELECT aa.id, aa.member_id, aa.activity_id, aa.status, aa.is_renewal,
                    aa.approval_count, aa.start_on, aa.expires_on, aa.created,
                    act.name AS activity_name, act.num_required_authorizors,
                    act.num_required_renewers, act.term_length, act.grants_role_id
             FROM activities_authorizations aa
             JOIN activities_activities act ON aa.activity_id = act.id
             WHERE NOT EXISTS (
                 SELECT 1 FROM workflow_instances wi
                 WHERE wi.entity_id = aa.id
                   AND wi.entity_type = 'Activities.Authorizations'
             )
             ORDER BY aa.id"
        );

        if (empty($auths)) {
            return;
        }

        foreach ($auths as $auth) {
            $authId = (int)$auth['id'];

            // Fetch approval records for this authorization
            $approvals = $this->fetchAll(
                "SELECT id, approver_id, authorization_token, requested_on,
                        responded_on, approved, approver_notes
                 FROM activities_authorization_approvals
                 WHERE authorization_id = {$authId}
                 ORDER BY requested_on ASC"
            );

            $status = $auth['status'];
            $currentStateSlug = $this->mapStatusToState($status, $auth['expires_on']);
            $currentStateId = $states[$currentStateSlug] ?? $states['requested'];

            // Determine previous state
            $previousStateId = $this->getPreviousStateId($currentStateSlug, $status, $states);
            $prevSql = $previousStateId !== null ? (string)$previousStateId : 'NULL';

            // Determine instance status and completed_at
            $instanceStatus = 'active';
            $completedAt = 'NULL';

            switch ($status) {
                case 'Pending':
                    break;
                case 'Approved':
                    if ($auth['expires_on'] && strtotime($auth['expires_on']) <= time()) {
                        $instanceStatus = 'completed';
                        $completedAt = "'" . addslashes($auth['expires_on']) . "'";
                    }
                    break;
                case 'Denied':
                    $instanceStatus = 'completed';
                    $ts = $this->getLastRespondedOn($approvals) ?? $auth['created'];
                    $completedAt = "'" . addslashes($ts) . "'";
                    break;
                case 'Revoked':
                case 'Replaced':
                case 'replaced':
                    $instanceStatus = 'completed';
                    $ts = $auth['expires_on'] ?? $auth['created'];
                    $completedAt = "'" . addslashes($ts) . "'";
                    break;
                case 'Retracted':
                    $instanceStatus = 'completed';
                    $ts = $this->getLastRequestedOn($approvals) ?? $auth['created'];
                    $completedAt = "'" . addslashes($ts) . "'";
                    break;
                case 'Expired':
                    $instanceStatus = 'completed';
                    $ts = $auth['expires_on'] ?? $auth['created'];
                    $completedAt = "'" . addslashes($ts) . "'";
                    break;
            }

            // Build transitions
            $transitions = $this->buildTransitions($auth, $approvals);

            // Build context JSON
            $context = [
                'entity' => [
                    'id' => (int)$auth['id'],
                    'member_id' => (int)$auth['member_id'],
                    'activity_id' => (int)$auth['activity_id'],
                    'status' => $auth['status'],
                    'is_renewal' => (bool)(int)$auth['is_renewal'],
                    'approval_count' => (int)$auth['approval_count'],
                    'activity' => [
                        'name' => $auth['activity_name'],
                        'num_required_authorizors' => (int)$auth['num_required_authorizors'],
                        'num_required_renewers' => (int)$auth['num_required_renewers'],
                        'term_length' => (int)$auth['term_length'],
                        'grants_role_id' => $auth['grants_role_id'] !== null ? (int)$auth['grants_role_id'] : null,
                    ],
                ],
                'transitions' => $transitions,
                'migrated' => true,
                'migrated_at' => $now,
            ];

            $contextJson = addslashes((string)json_encode($context));
            $startedAt = addslashes($auth['created']);

            $this->execute(
                "INSERT INTO workflow_instances
                    (workflow_definition_id, entity_type, entity_id, current_state_id, previous_state_id,
                     context, started_at, completed_at, status, created, modified)
                 VALUES
                    ({$defId}, 'Activities.Authorizations', {$authId}, {$currentStateId}, {$prevSql},
                     '{$contextJson}', '{$startedAt}', {$completedAt}, '{$instanceStatus}', '{$now}', '{$now}')"
            );

            // Get the newly created instance ID
            $instanceRows = $this->fetchAll("SELECT LAST_INSERT_ID() AS id");
            $instanceId = (int)$instanceRows[0]['id'];

            // Insert workflow_approvals for responded approvals
            if ($gateId !== null) {
                $order = 0;
                foreach ($approvals as $approval) {
                    if ($approval['responded_on'] === null) {
                        continue;
                    }
                    $order++;
                    $decision = (int)$approval['approved'] === 1 ? 'approved' : 'denied';
                    $notes = $approval['approver_notes'] !== null
                        ? "'" . addslashes($approval['approver_notes']) . "'"
                        : 'NULL';
                    $approverId = (int)$approval['approver_id'];
                    $token = addslashes($approval['authorization_token']);
                    $requestedAt = addslashes($approval['requested_on']);
                    $respondedAt = addslashes($approval['responded_on']);

                    $this->execute(
                        "INSERT INTO workflow_approvals
                            (workflow_instance_id, approval_gate_id, approver_id, decision, notes,
                             approval_order, token, requested_at, responded_at, created, modified)
                         VALUES
                            ({$instanceId}, {$gateId}, {$approverId}, '{$decision}', {$notes},
                             {$order}, '{$token}', '{$requestedAt}', '{$respondedAt}', '{$now}', '{$now}')"
                    );
                }
            }
        }
    }

    public function down(): void
    {
        // Delete workflow_approvals linked to migrated instances
        $this->execute(
            "DELETE wa FROM workflow_approvals wa
             INNER JOIN workflow_instances wi ON wa.workflow_instance_id = wi.id
             WHERE wi.entity_type = 'Activities.Authorizations'
               AND wi.context LIKE '%\"migrated\":true%'"
        );

        // Delete migrated workflow_instances
        $this->execute(
            "DELETE FROM workflow_instances
             WHERE entity_type = 'Activities.Authorizations'
               AND context LIKE '%\"migrated\":true%'"
        );
    }

    private function mapStatusToState(string $status, ?string $expiresOn): string
    {
        switch ($status) {
            case 'Pending':
                return 'pending-approval';
            case 'Approved':
                if ($expiresOn && strtotime($expiresOn) <= time()) {
                    return 'expired';
                }
                return 'approved';
            case 'Denied':
                return 'denied';
            case 'Revoked':
                return 'revoked';
            case 'Retracted':
                return 'retracted';
            case 'Replaced':
            case 'replaced':
                return 'revoked';
            case 'Expired':
                return 'expired';
            default:
                return 'requested';
        }
    }

    private function getPreviousStateId(string $currentSlug, string $status, array $states): ?int
    {
        switch ($currentSlug) {
            case 'pending-approval':
                return $states['requested'];
            case 'approved':
            case 'denied':
            case 'retracted':
                return $states['pending-approval'];
            case 'expired':
            case 'revoked':
                return $states['approved'];
            default:
                return null;
        }
    }

    private function getLastRespondedOn(array $approvals): ?string
    {
        $last = null;
        foreach ($approvals as $a) {
            if ($a['responded_on'] !== null && ($last === null || $a['responded_on'] > $last)) {
                $last = $a['responded_on'];
            }
        }
        return $last;
    }

    private function getLastRequestedOn(array $approvals): ?string
    {
        $last = null;
        foreach ($approvals as $a) {
            if ($a['requested_on'] !== null && ($last === null || $a['requested_on'] > $last)) {
                $last = $a['requested_on'];
            }
        }
        return $last;
    }

    private function buildTransitions(array $auth, array $approvals): array
    {
        $transitions = [];
        $status = $auth['status'];
        $requiredCount = (int)$auth['is_renewal']
            ? (int)$auth['num_required_renewers']
            : (int)$auth['num_required_authorizors'];

        $runningCount = 0;
        $lastApprover = null;
        $lastRespondedOn = null;

        foreach ($approvals as $a) {
            if ($a['responded_on'] === null) {
                continue;
            }

            if ((int)$a['approved'] === 1) {
                $runningCount++;
                $lastApprover = (int)$a['approver_id'];
                $lastRespondedOn = $a['responded_on'];

                $transitions[] = [
                    'type' => 'gate_approval',
                    'state' => 'pending-approval',
                    'by' => (int)$a['approver_id'],
                    'action' => 'approve',
                    'approval_count' => $runningCount,
                    'required_count' => $requiredCount,
                    'at' => $a['responded_on'],
                ];
            } elseif ($status === 'Denied') {
                $transitions[] = [
                    'from' => 'pending-approval',
                    'to' => 'denied',
                    'by' => (int)$a['approver_id'],
                    'action' => 'deny',
                    'at' => $a['responded_on'],
                ];
            }
        }

        // Final state transition
        switch ($status) {
            case 'Approved':
                if ($lastRespondedOn !== null) {
                    $transitions[] = [
                        'from' => 'pending-approval',
                        'to' => 'approved',
                        'by' => $lastApprover,
                        'action' => 'approve',
                        'at' => $lastRespondedOn,
                    ];
                }
                break;
            case 'Retracted':
                $firstRequested = null;
                foreach ($approvals as $a) {
                    if ($firstRequested === null || $a['requested_on'] < $firstRequested) {
                        $firstRequested = $a['requested_on'];
                    }
                }
                $transitions[] = [
                    'from' => 'pending-approval',
                    'to' => 'retracted',
                    'by' => null,
                    'action' => 'retract',
                    'at' => $firstRequested ?? $auth['created'],
                ];
                break;
            case 'Replaced':
            case 'replaced':
                $transitions[] = [
                    'from' => 'approved',
                    'to' => 'revoked',
                    'by' => null,
                    'action' => 'revoke',
                    'reason' => 'replaced_by_renewal',
                    'at' => $auth['expires_on'] ?? $auth['created'],
                ];
                break;
            case 'Revoked':
                $transitions[] = [
                    'from' => 'approved',
                    'to' => 'revoked',
                    'by' => null,
                    'action' => 'revoke',
                    'at' => $auth['expires_on'] ?? $auth['created'],
                ];
                break;
        }

        return $transitions;
    }
}
