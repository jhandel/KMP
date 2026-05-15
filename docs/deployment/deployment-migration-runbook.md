# Deployment Migration Runbook (Platform-First + Tenant Waves)

Execute managed multi-tenant deployment migrations using the platform operations model, parent/child job linkage, and dashboard-driven control loop.

[← Back to Deployment Guide](README.md)

## Scope and alignment

This runbook operationalizes:

- [Deployment Migration Strategy](deployment-migration-strategy.md)
- [Operations Gateway Runbook](operations-gateway-runbook.md)
- [Platform Operation State Machine](../architecture/platform-operation-state-machine.md)
- [Updating & Rollback](updating.md)

Use `deployment:migrate` and platform operation jobs as the default path. Ad hoc direct migration execution is break-glass only.

## 1) Pre-deploy prerequisites and safety checks

Before starting the change window:

- Change ticket, incident bridge, and release owner/deputy are assigned.
- Rollback owner is assigned and rollback artifact target (known-good image + backup/restore checkpoint) is documented.
- `platform` datastore and tenant datastores are confirmed separate.
- Worker runner capacity is available for sustained queue processing.
- Catalog/approval policy is valid for migration actions and requester/approver identities are confirmed.
- Alert routing is active for migration failures, hold/blocked states, and worker heartbeat anomalies.

Preflight validation checklist:

1. Confirm app image is deployed but incompatible code paths are still feature-gated.
2. Confirm platform admin access and dashboard visibility (`/platform-admin`).
3. Confirm migration lock health and no active conflicting schema operations.
4. Confirm operation correlation ID convention for the release window.
5. Confirm `tenant:doctor` baseline is green (or known exceptions documented).

Go/No-Go:

- **GO** only if all preflight checks pass and rollback path is executable.
- **NO-GO** if platform/tenant boundary is unclear, worker health is degraded, or rollback evidence is missing.

## 2) Platform-first + tenant migration orchestration sequence

### Canonical sequence

1. Roll out app image to fleet with compatibility mode/flags.
2. Run platform migrations.
3. Start all-tenant migration orchestration (parent + child jobs).
4. Monitor parent/child progression and enforce hold policy on critical failures.
5. Run tenant post-checks (`tenant:doctor`) and close release only when migration gates pass.

### Primary command path

```bash
cd app
bin/cake deployment:migrate --wait --drive-worker --on-failure=hold
```

Expected behavior:

- Executes `platform:migrate` first.
- Creates one parent `tenant_migrate_all` operation.
- Creates per-tenant child migration jobs linked by `parent_tenant_operation_job_id`.
- Records schema-before/after, duration, attempts, and structured error/result metadata.
- Holds parent on configured critical failure conditions.

### Resume path after remediation

```bash
cd app
bin/cake deployment:migrate --wait --resume-parent-id=<parent_job_id> --on-failure=hold
```

Use resume only after root cause is remediated and stop/rollback criteria are re-evaluated.

## 3) Interpreting dashboard states, counts, and errors

Use the Deployment Migration Dashboard in `/platform-admin` (or JSON filters) to evaluate both parent and child health.

### Parent state interpretation

- `queued` / `approved`: accepted but not yet running.
- `running`: active orchestration (children should be progressing).
- `hold`: intentionally paused by operator/policy; safe to investigate before resuming.
- `blocked`: unmet dependency/precondition (requires explicit remediation).
- `completed`: rollout migration phase succeeded under selected completion policy.
- `failed` / `cancelled`: terminal outcome; evaluate rollback and communication path.

### Child counts to monitor continuously

- `queued + approved` should trend down.
- `running` should remain near expected concurrency envelope.
- `completed` should steadily increase.
- `failed`, `blocked`, or repeated retry spikes indicate instability.

Escalation thresholds (default operational policy):

- Any platform migration failure.
- Parent stuck in `hold`/`blocked` beyond SLA.
- Cross-tenant failure rate above release threshold.
- Repeated stale lease takeovers/heartbeat anomalies.

### Error payload triage fields

For each failed/blocked child, classify by:

- `error.code` (stable machine category)
- `retryable` (true/false)
- `category` (`validation`, `dependency`, `transient`, `external`, `internal`)
- `attempt_count` vs `max_attempts`
- correlation ID and tenant slug

Prioritize systemic failures first (same code across many tenants), then isolated tenant-specific issues.

## 4) Hold/failure triage and resume workflow

When parent enters `hold` or children enter `failed/blocked`:

1. **Stabilize**
   - Stop new migration waves.
   - Preserve current parent/child IDs and correlation IDs in incident ticket.
2. **Classify impact**
   - Determine whether issue is platform-wide, shard-wide, or tenant-isolated.
   - Identify if affected tenants are in unsafe schema/serving state.
3. **Contain**
   - Keep unsafe tenants in maintenance/drained mode.
   - Cancel only jobs with no applied mutating effects.
4. **Remediate**
   - Fix precondition/dependency/input cause.
   - Validate fix on canary tenant (or equivalent low-risk target).
5. **Resume**
   - Resume held/blocked parent via dashboard action or `--resume-parent-id` path.
   - Monitor first resumed batch closely before widening concurrency.
6. **Verify**
   - Run `bin/cake tenant:doctor --all-tenants`.
   - Confirm no tenants remain schema-incompatible for normal serving.

Resume safety rules:

- Resume only from `hold`/`blocked` after documented remediation.
- Do not resume if stop/rollback criteria are currently met.
- Preserve immutable audit trail for approve/hold/resume/cancel actions.

## 5) Rollback/stop conditions and communication checklist

### Immediate stop conditions

Trigger stop and rollback decision review when any apply:

- `platform:migrate` fails or leaves platform schema in unknown state.
- Parent/child failures indicate systemic incompatibility.
- Tenant safety gate is violated (serving with incompatible schema).
- Error budget/SLO breach persists beyond approved tolerance.
- Worker/lease instability prevents reliable orchestration progress.

### Rollback decision criteria

- **Cancel only** when no mutating side effects were applied.
- **Rollback required** when mutating side effects were applied and post-checks fail.
- Keep affected tenants in maintenance until rollback validation gates pass.
- Use [Updating & Rollback](updating.md) and [Backup & Restore](backup-restore-runbook.md) for execution details.

### Communication checklist (required)

At start:

- Announce change window start, scope, and correlation/change ID.
- Confirm decision roles: release lead, DB lead, incident commander, comms owner.

During migration:

- Publish periodic dashboard summary (parent state + child counts + key errors).
- Announce entry into `hold`/`blocked` immediately with impact scope.
- Communicate resume decision and expected next checkpoint.

On rollback/stop:

- Announce stop trigger and rollback path selected.
- Provide tenant impact list and estimated recovery timeline.
- Provide explicit “do not proceed” signal for dependent release actions.

At close:

- Publish final outcome (completed/partial/rolled back).
- Include parent job ID, correlation ID, failed tenant list (if any), and follow-up actions.
- Attach audit export and runbook deviations to the change record.

## References

- [Deployment Migration Strategy](deployment-migration-strategy.md)
- [Operations Gateway Runbook](operations-gateway-runbook.md)
- [Tenant Maintenance Runbook](tenant-maintenance-runbook.md)
- [Updating & Rollback](updating.md)
- [Troubleshooting](troubleshooting.md)
