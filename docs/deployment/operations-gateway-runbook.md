# Operations Gateway Runbook

Operate privileged multi-tenant write operations through the platform Operations Gateway.

[← Back to Deployment Guide](README.md)

## Purpose and scope

This runbook covers day-2 execution for gateway-backed tenant operations (`tenant_status`, `tenant_migrate`, `tenant_doctor`, `tenant_rotate_db_secret`) and aligns with:

- [Production Command Strategy](production-command-strategy.md)
- [Platform Operation Engine Decision ADR](../architecture/platform-operation-engine-decision.md)
- [Platform Operation State Machine](../architecture/platform-operation-state-machine.md)

## 1) When to use gateway vs web UI

Use the **gateway path** (CLI + worker) for all privileged, auditable write operations in production.

Use **Platform Admin web UI** for:

- Single-tenant status changes and secret update flows that already enqueue gateway jobs.
- Queue monitoring, correlation lookup, and retry/cancel controls.

Use **CLI gateway enqueue** for:

- Selected/all-tenant waves.
- Controlled batching (`--batch-size`, `--batch-pause-ms`, `--max-targets`).
- Explicit requester/approver identity and idempotency control.

Do **not** run ad hoc shell write commands on web replicas except break-glass incidents documented under [Production Command Strategy](production-command-strategy.md#1-break-glass-shell-policy).

## 2) Command catalog governance and approvals

Catalog source of truth:

- `app/src/Services/Platform/TenantOperationCommandCatalog.php`
- UI: `/platform-admin/operations/catalog`
- JSON: `/platform-admin/operations/catalog.json`

Governance rules:

1. Only `gateway_enabled` operations may be submitted.
2. `allowed_target_modes`, required/optional parameters, and allowed values are enforced at submission and re-validated by workers.
3. `idempotency_scope` must match catalog policy.
4. Requester and approver must both hold the operation capability.
5. Approval policy is catalog-driven:
   - `tenant_status`: n-of-m, 1 approval.
   - `tenant_migrate`: two-person, 2 approvals, requester/approver separation.
   - `tenant_doctor`: no approval requirement.
   - `tenant_rotate_db_secret`: two-person, 2 approvals, requester/approver separation.

Current operational note: gateway submission records one approval (`gateway_approved`) when requester/approver separation is satisfied. Two-person policies still remain in `approval_required` until a second authorized admin records approval.

Approval completion options:

- UI: `/platform-admin` or `/platform-admin/tenants/{slug}` → **Approve** / **Reject** buttons on `approval_required` rows.
- API/UI endpoints: `POST /platform-admin/operations/{id}/approve` and `POST /platform-admin/operations/{id}/reject`.
- Every decision writes both:
  - durable decision rows in `tenant_operation_approvals`, and
  - platform audit events (`tenant.operation_approval`) with correlation context.

## 3) Submitting operations

### 3.1 Preflight checklist

- Confirm operation exists in catalog and supports intended target mode.
- Confirm requester/approver identities and capabilities.
- Choose an idempotency key stable for retry/replay.
- Set explicit correlation id for incident/change ticket linkage.

### 3.2 CLI submission patterns

Single-tenant status change:

```bash
cd app
bin/cake tenant_operation:enqueue \
  --operation=tenant_status \
  --tenant=my-tenant \
  --parameters-json='{"status":"maintenance"}' \
  --requester-email=requester@example.com \
  --approved-by-email=approver@example.com \
  --idempotency-key=tenant-status:my-tenant:maintenance:2026-06-01 \
  --correlation-id=chg-2026-06-01-maint
```

Selected/all-tenant migration wave:

```bash
cd app
bin/cake tenant_operation:enqueue \
  --operation=tenant_migrate \
  --tenants=tenant-a,tenant-b,tenant-c \
  --parameters-json='{}' \
  --requester-email=requester@example.com \
  --approved-by-email=approver@example.com \
  --idempotency-key=tenant-migrate:wave-2026-06-01 \
  --batch-size=25 \
  --batch-pause-ms=500 \
  --max-targets=200 \
  --continue-on-error
```

DB secret rotation (single only):

```bash
cd app
bin/cake tenant_operation:enqueue \
  --operation=tenant_rotate_db_secret \
  --tenant=my-tenant \
  --parameters-json='{"new_secret_reference":"managed:tenant/123/database/primary/rotation/20260618120000","max_attempts":1}' \
  --requester-email=requester@example.com \
  --approved-by-email=approver@example.com \
  --idempotency-key=tenant-rotate-db-secret:my-tenant:20260618120000
```

### 3.3 Web UI submission paths

- Tenant status action in `/platform-admin/tenants/{slug}` enqueues `tenant_status`.
- Tenant secret update with database password enqueues `tenant_rotate_db_secret`.
- Tenant Doctor remediation controls in `/platform-admin/tenants/{slug}` expose finding-specific guidance and queue approved operations:
  - `tenant_status` finding → remediation `tenant_status` (`active`) queue request.
  - `schema_version` / missing required settings findings → remediation `tenant_migrate` queue request (typically lands in `approval_required` until a distinct approver signs off).
  - connectivity/settings verification findings → remediation `tenant_doctor` rerun queue request.

Both paths run catalog preflight and write queue/audit metadata.

## 4) Running operations (worker execution)

Run workers outside web request lifecycle (Azure job/runner pattern):

```bash
cd app
bin/cake tenant_operation:worker --once --limit=10 --lease-ttl=300 --worker-id="${HOSTNAME:-worker}"
```

Continuous polling mode is supported, but bounded `--once` invocations are preferred in scheduled job systems.

Worker behavior:

- Claims runnable jobs with a lease token (`queued`, `approved`, or stale `running`).
- Enforces approval-readiness for `approved` jobs.
- Emits progress (`phase`, `message`, optional checkpoint/percent).
- On retryable failure, returns job to `approved` until `max_attempts` is reached.

## 5) Cancelling and retrying operations

### Cancel

- UI: **Cancel** in queue table (`/platform-admin/operations/{id}/cancel`).
- Allowed states: `queued`, `approval_required`, `approved`, `running`, `hold`, `blocked`.
- Running jobs receive `cancelled_at`; worker converts them to terminal `cancelled`.

### Retry

- UI: **Retry** (`/platform-admin/operations/{id}/retry`).
- Allowed states: `failed`, `cancelled`, `blocked`.
- Retry creates a new job with a new idempotency key and links to prior operation in progress payload.
- If the operation policy requires approvals, retry state is `approval_required` and the approval workflow must be completed again before worker pickup.

## 6) Interpreting statuses, locks, and failure modes

Lifecycle states shown in queue UI:

- `queued`: accepted, pending approval or worker pickup.
- `approval_required`: waiting for required approvals.
- `approved`: ready for worker pickup.
- `running`: worker lease active.
- `hold` / `blocked`: execution paused by policy or issue.
- `completed` / `failed` / `cancelled`: terminal.

Lease indicators:

- **Lock active**: running + lease expiry in future.
- **Stale lock**: running + lease expired; next worker may reclaim.
- **Lease recorded**: lease metadata exists but not currently active.

Common failure modes and responses:

1. **Catalog validation failure** (unsupported operation/param/target mode)
   - Fix request to match catalog; resubmit.
2. **Idempotency conflict** (same key, different payload)
   - Use a new key or replay exact original payload.
3. **Approval deadlock** (`approval_required` not progressing)
   - Resubmit with policy-compliant requester/approver separation.
4. **Lease conflict/stale running rows**
   - Ensure workers are healthy; rerun worker command and monitor heartbeat/lease expiry.
5. **Permanent validation exceptions in worker**
   - Correct input/catalog mismatch; retry only after root cause fix.
6. **Transient runtime failures**
   - Worker auto-retries to `approved` until attempt cap; then state becomes `failed`.

## 7) Evidence and audit requirements

Minimum evidence for each change window:

- Change/incident id mapped to `operation_correlation_id`.
- Request payload summary (operation, target mode, tenant snapshot, parameters).
- Requester + approver identity.
- Terminal outcome per operation/job (including child state summaries for bulk).
- If cancelled/retried: reason and replacement operation id.

Audit capture:

- Queue view links correlation id directly to platform audit search.
- Export audit records for the window:

```bash
cd app
bin/cake platform_audit:export \
  --output=logs/platform-audit-ops-gateway-2026-06-01.jsonl \
  --from=2026-06-01T00:00:00Z \
  --to=2026-06-02T00:00:00Z \
  --correlation-id=chg-2026-06-01-maint
```

## 8) Incident escalation

Escalate immediately when any of the following occurs:

- Repeated stale leases or queue starvation across worker runs.
- Bulk waves show systemic failures (same error across many tenants).
- Approval-required jobs cannot be advanced with standard role policy.
- Unexpected terminal failures on high-risk operations (`tenant_migrate`, `tenant_rotate_db_secret`).

Escalation package:

1. Correlation id(s), operation id(s), tenant scope.
2. Current state snapshots (including lease owner/expiry and status message).
3. Last error payload and retry counters.
4. Platform audit export and worker execution logs.
5. Immediate containment action taken (cancelled, paused wave, rollback trigger).

Use [Troubleshooting](troubleshooting.md), [Tenant Maintenance Runbook](tenant-maintenance-runbook.md), and [Backup & Restore Runbook](backup-restore-runbook.md) as companion procedures.
