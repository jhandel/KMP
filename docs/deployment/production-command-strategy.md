# Production Command Strategy (Azure Multi-Replica)

Define a safe, auditable way to execute privileged admin/console commands in production where multiple app replicas are live behind a load balancer.

[← Back to Deployment Guide](README.md)

## Scope and architecture alignment

This strategy applies to control-plane and tenant-operations commands (for example: `platform:migrate`, `tenant:migrate`, `tenant:doctor`, tenant status transitions, restore/cutover operations).

It aligns with:

- [Platform Operation Engine Decision ADR](../architecture/platform-operation-engine-decision.md) (platform-native control plane)
- [Platform Operation State Machine](../architecture/platform-operation-state-machine.md) (`queued`, `approval_required`, `approved`, `running`, `blocked`, `completed`, `failed`, `cancelled`)
- [Multi-Tenancy Architecture](../3.9-multi-tenancy.md)

## 1) Break-glass shell policy

Break-glass shell access is emergency-only and not a normal operations path.

Policy:

1. Prefer platform operation APIs/commands routed through the Operations Gateway (below).
2. Shell execution on a running replica is allowed only for incident response, recovery, or gateway unavailability.
3. Break-glass access requires incident ticket + named approver + bounded time window.
4. All break-glass sessions must produce audit records (who, when, why, command, correlation id, outcome).
5. After incident completion, rotate any elevated credentials/tokens used for break-glass.

Guardrails:

- No ad hoc SQL for tenant data-plane modifications from shell.
- No direct edits of tenant secrets in process env; use platform-managed secret references.
- Any break-glass action that changes tenant/platform state must be backfilled into platform operation events.

## 2) Operations Gateway model

Use a dedicated Operations Gateway service/API as the normal command entrypoint.

Responsibilities:

- Accept signed operator requests and enforce RBAC + approval policy.
- Validate parameters and normalize targeting (`single`, `selected`, `all-tenant`).
- Create platform operation records with idempotency key + correlation id.
- Dispatch workers to execute handlers with lease/lock semantics.
- Stream structured progress/events and persist final result/error payloads.

Execution model:

- Gateway receives request -> creates `platform_operations` entry (`queued` or `approval_required`).
- Approved operations transition to `running` under lease ownership.
- Handler emits checkpoints/events; terminal transition is `completed`/`failed`/`cancelled`.
- No direct dependency on which web replica received the initial HTTP request.

## 3) Command execution context and single-run semantics

Production commands must run in an explicit execution context; never rely on ambient process state.

Required context fields:

- `operation_type`
- `tenant_scope` (`single`, `selected`, `all-tenant`)
- `tenant_ids` snapshot (for `selected`/`all-tenant` fan-out)
- `requested_by`, `approved_by` (when required)
- `idempotency_key`, `correlation_id`
- `requested_at`, execution lease metadata

Single-run semantics in multi-replica environments:

1. Operation creation is deduplicated by idempotency tuple/hash.
2. Exactly one active worker lease owns a `running` operation at a time.
3. State/progress writes require matching lease token.
4. On stale lease expiry, a new worker may resume from persisted checkpoint only.
5. Replays return existing operation/result when request hash matches.

This prevents duplicate execution when retries, client reconnects, or replica failover occur.

## 4) RBAC and approval requirements

Minimum authorization model:

- **Platform Admin**: create and monitor production operations.
- **Approver**: may approve/reject operations that require `approval_required`.
- **Executor Worker Identity**: non-human principal allowed to run approved handlers only.

Approval requirements:

- High-risk operations (cross-tenant migration, tenant disable/enable, restore/cutover, global maintenance toggles) require explicit approval.
- Approval must be recorded with actor id, timestamp, rationale, and correlation id.
- Separation of duties recommended: requester != approver for high-risk operations.

Emergency override:

- Break-glass can bypass normal approval only under incident policy; must emit explicit audit reason and post-incident review record.

## 5) Parameter validation and idempotency

Validation rules:

- Reject unknown flags/options; allowlist command-specific parameters.
- Validate tenant identifiers against platform registry before enqueue.
- For `selected` mode, resolve tenant list at request time and persist immutable snapshot.
- Require dry-run capability for destructive operations when supported.

Idempotency rules:

- Every mutating operation must include idempotency key + request hash.
- Duplicate with same semantic payload returns existing operation id/state.
- Duplicate key with different payload is rejected as conflict.
- Dedupe retention window must outlive retry horizon and operator replay window.

Handler design:

- Make each phase checkpointed and restart-safe.
- Side effects must be safe to replay or protected with resource-level guards.

## 6) Output capture, redaction, and retention

Capture:

- Persist structured progress/error/result payloads in platform operation event stream.
- Store raw stdout/stderr only in bounded, centralized logs; link by correlation id.

Redaction:

- Never store secrets, access tokens, connection strings, or sensitive PII in operation payloads.
- Apply redaction filters before persistence and before operator display/export.
- Mark fields as `sensitive` in command schemas to guarantee masking.

Retention:

- Keep operation metadata/events according to platform audit retention policy.
- Keep verbose logs for a shorter operational window; retain summarized terminal results longer.
- Ensure legal/security hold process can preserve records for incident investigations.

### Platform audit export and retention commands

Use the platform audit commands to produce filtered exports and perform safe archival/purge workflows:

```bash
cd app
bin/cake platform_audit:export \
  --output=logs/platform-audit-2026-06-01.jsonl \
  --from=2026-05-01T00:00:00Z \
  --to=2026-06-01T00:00:00Z \
  --tenant-slug=my-tenant \
  --action=tenant.status \
  --correlation-id=corr-123
```

Retention planning (no deletion):

```bash
cd app
bin/cake platform_audit:retention \
  --before=2026-01-01T00:00:00Z \
  --archive-path=logs/platform-audit-archive-2026-01-01.jsonl
```

Retention purge (requires explicit `--purge`):

```bash
cd app
bin/cake platform_audit:retention \
  --before=2026-01-01T00:00:00Z \
  --archive-path=logs/platform-audit-archive-2026-01-01.jsonl \
  --purge
```

Safety behavior:

- Purge is blocked if the remaining event chain would lose the expected hash linkage.
- Purge requires an archive path unless `--allow-purge-without-archive` is explicitly set.
- Successful purge writes a `platform_audit_retention_anchors` record that preserves boundary hash metadata for later chain verification.

## 7) Command targeting modes

### Mode A: `single`

- Exactly one tenant target.
- Requires explicit tenant identifier.
- Preferred for routine maintenance and remediation.

### Mode B: `selected`

- Operator supplies a bounded set of tenants.
- Gateway resolves and stores immutable tenant snapshot at enqueue time.
- Execution may be parallelized per tenant with child operations.

### Mode C: `all-tenant`

- Fan-out operation to all currently eligible tenants.
- Parent operation creates child jobs from a start-time snapshot.
- Parent aggregates child states and enforces operation policy (`all_success_required` vs `partial_success_allowed`).
- New tenants created after snapshot are excluded from that run.

## 8) Recommended production runbook requirements

1. All privileged production command entrypoints route through Operations Gateway.
2. Direct shell commands are break-glass only and audited.
3. Every mutating run has idempotency key + correlation id.
4. Multi-tenant fan-out uses parent/child operation model with immutable target snapshot.
5. Approval policy enforces RBAC and separation-of-duties on high-risk operations.
6. Output is structured, redacted, retained, and queryable for incident forensics.

## 9) Azure worker runner for tenant operation jobs

Use the platform operation worker command for durable execution of `tenant_operation_jobs` from Azure jobs or runners:

```bash
cd app
bin/cake tenant_operation:worker --once --limit=10 --lease-ttl=300 --worker-id="${HOSTNAME:-azure-job}"
```

Recommended Azure pattern:

1. Run this command in an Azure Container Apps Job (or equivalent scheduled/queued runner), not in web request handlers.
2. Keep each invocation bounded (`--once`) so the platform scheduler controls concurrency and retry.
3. Run multiple job replicas for throughput; lease ownership prevents duplicate execution.
4. Include a stable `--worker-id` (pod/job identity) for auditability of lease takeover and failure forensics.

## 10) Operations Gateway request flow (current MVP)

Privileged platform-admin actions should enqueue approved requests into `tenant_operation_jobs` instead of running ad hoc shell commands.

### Platform admin console path

- `POST /platform-admin/tenants/{slug}/status/{status}` now submits an approved `tenant_status` gateway request.
- `POST /platform-admin/tenants/{slug}/secrets` now queues `tenant_rotate_db_secret` when a new database password is provided. The operation performs staged prepare/update/invalidate/verify with automatic rollback on verification failure.
- Request metadata is persisted in each job input under `gateway`:
  - `requested_by_admin_id` / `approved_by_admin_id`
  - `tenant_target_mode` (`single` for console status changes)
  - normalized `parameters`
  - deterministic `request_hash`
- Correlation id is persisted in `operation_correlation_id`.

### CLI gateway submission

Use the gateway enqueue command for approved production operations:

```bash
cd app
bin/cake tenant_operation:enqueue \
  --operation=tenant_status \
  --tenants=tenant-a,tenant-b,tenant-c \
  --parameters-json='{"status":"maintenance"}' \
  --requester-email=platform-admin@example.com \
  --approved-by-email=approver@example.com \
  --idempotency-key=tenant-status:maintenance-wave-2026-06-01 \
  --batch-size=25 \
  --batch-pause-ms=500 \
  --max-targets=200 \
  --continue-on-error
```

Database credential rotation (single tenant only):

```bash
cd app
bin/cake tenant_operation:enqueue \
  --operation=tenant_rotate_db_secret \
  --tenant=my-tenant \
  --parameters-json='{"new_secret_reference":"managed:tenant/123/database/primary/rotation/20260618120000","max_attempts":1}' \
  --requester-email=platform-admin@example.com \
  --approved-by-email=approver@example.com \
  --idempotency-key=tenant-rotate-db-secret:my-tenant:20260618120000
```

Supported target modes:

- `--tenant` => `single`
- `--tenants=slug-a,slug-b` => `selected`
- `--all-tenants` => `all-tenant`
- `tenant_rotate_db_secret` is restricted to `single`.

Bulk safety controls:

- `--batch-size` limits tenant submissions per batch (default `25`, max `250`).
- `--batch-pause-ms` adds controlled pauses between batches for platform/API rate limiting.
- `--max-targets` fails fast if the resolved tenant target set exceeds an explicit safety bound.
- `--continue-on-error` enables per-tenant failure isolation for selected/all-tenant fan-out.

### Worker execution

- Gateway-created jobs are stored as `state=approved`.
- Selected/all-tenant submissions also create a `_bulk_submit` parent operation row for dashboard progress and partial-failure summaries.
- `bin/cake tenant_operation:worker` acquires approved jobs, executes the mapped handler, and writes progress/result/error payloads with lease-guarded updates.
- Idempotency protection is enforced via `(operation, tenant_id, idempotency_scope, idempotency_key)` plus request-hash conflict checks in the gateway service.

## 11) Gateway command catalog (production policy source of truth)

The production gateway allowlist is defined in:

- `app/src/Services/Platform/TenantOperationCommandCatalog.php`

Each catalog operation declares:

- supported target modes (`single`, `selected`, `all-tenant`)
- required/optional parameters and allowed values
- approval requirement policy
- required idempotency scope
- required capability/role guidance for requester + approver paths
- gateway/worker enablement

Current gateway-approved operations:

- `tenant_status`
- `tenant_migrate`
- `tenant_doctor`
- `tenant_rotate_db_secret`

Maintenance rules:

1. Add or modify operation policy in `TenantOperationCommandCatalog`.
2. Keep `tenant_operation:enqueue` help text aligned (it reads from the catalog).
3. Ensure gateway validation and worker validation both pass for the updated policy.
4. Add/update tests in:
   - `app/tests/TestCase/Services/Platform/TenantOperationGatewayServiceTest.php`
   - `app/tests/TestCase/Services/Platform/TenantOperationWorkerServiceTest.php`
5. For any new mutating operation, explicitly define approval and idempotency scope in the catalog before enabling gateway execution.

Platform Admin operator surfaces:

- Catalog UI: `/platform-admin/operations/catalog`
- Catalog JSON: `/platform-admin/operations/catalog.json`

These views are intended for discoverability and safe invocation guidance, and are used by tenant operation enqueue
flows for preflight validation feedback.
