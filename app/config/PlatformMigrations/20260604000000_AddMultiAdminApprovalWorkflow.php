<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class AddMultiAdminApprovalWorkflow extends BaseMigration
{
    public function change(): void
    {
        $jobs = $this->table('tenant_operation_jobs');
        if (!$jobs->hasColumn('approval_policy_json')) {
            $jobs->addColumn('approval_policy_json', 'json', [
                'default' => null,
                'null' => true,
            ]);
        }
        if (!$jobs->hasColumn('approvals_required')) {
            $jobs->addColumn('approvals_required', 'integer', [
                'default' => 0,
                'limit' => 11,
                'null' => false,
            ]);
        }
        if (!$jobs->hasColumn('approvals_received')) {
            $jobs->addColumn('approvals_received', 'integer', [
                'default' => 0,
                'limit' => 11,
                'null' => false,
            ]);
        }
        if (!$jobs->hasColumn('approval_rejected_at')) {
            $jobs->addColumn('approval_rejected_at', 'datetime', [
                'default' => null,
                'null' => true,
            ]);
        }
        if (!$jobs->hasColumn('approval_rejection_reason')) {
            $jobs->addColumn('approval_rejection_reason', 'string', [
                'default' => null,
                'limit' => 1024,
                'null' => true,
            ]);
        }
        $jobs->update();

        $approvals = $this->table('tenant_operation_approvals');
        if (!$approvals->hasColumn('decision')) {
            $approvals->addColumn('decision', 'string', [
                'default' => 'approved',
                'limit' => 32,
                'null' => false,
            ]);
        }
        if (!$approvals->hasColumn('decided_at')) {
            $approvals->addColumn('decided_at', 'datetime', [
                'default' => null,
                'null' => true,
            ]);
        }
        if (!$approvals->hasColumn('decision_note')) {
            $approvals->addColumn('decision_note', 'string', [
                'default' => null,
                'limit' => 1024,
                'null' => true,
            ]);
        }
        if (!$approvals->hasIndex(['tenant_operation_job_id', 'decision'])) {
            $approvals->addIndex(['tenant_operation_job_id', 'decision'], [
                'name' => 'idx_tenant_operation_approvals_job_decision',
            ]);
        }
        $approvals->update();

        $connection = $this->getAdapter()->getConnection();
        $connection->execute(
            "UPDATE tenant_operation_approvals\n"
            . "SET decision = 'approved', decided_at = COALESCE(approved_at, created)\n"
            . "WHERE decision IS NULL OR decision = ''"
        );
        $connection->execute(
            "UPDATE tenant_operation_jobs\n"
            . "SET approvals_required = CASE\n"
            . "    WHEN JSON_EXTRACT(input, '$.gateway.catalog.approval_required') IN (1, true, '1', 'true') THEN 1\n"
            . "    ELSE 0\n"
            . "END\n"
            . "WHERE approvals_required IS NULL OR approvals_required = 0"
        );
        $connection->execute(
            "UPDATE tenant_operation_jobs j\n"
            . "SET approvals_received = (\n"
            . "    SELECT COUNT(*)\n"
            . "    FROM tenant_operation_approvals a\n"
            . "    WHERE a.tenant_operation_job_id = j.id\n"
            . "      AND COALESCE(a.decision, 'approved') = 'approved'\n"
            . ")"
        );
        $connection->execute(
            "UPDATE tenant_operation_jobs\n"
            . "SET state = 'approval_required', status = 'approval_required'\n"
            . "WHERE state = 'approved'\n"
            . "  AND COALESCE(approvals_required, 0) > COALESCE(approvals_received, 0)\n"
            . "  AND completed_at IS NULL\n"
            . "  AND cancelled_at IS NULL"
        );
    }
}
