# Secret Rotation Runbook (Operations Gateway + Platform/Tenant Scope)

Use this runbook for planned and emergency credential/secret rotations in managed multi-tenant KMP.

[← Back to Deployment Guide](README.md)

## Scope and architecture alignment

This runbook aligns with:

- [3.9 Multi-Tenancy Architecture and Operations](../3.9-multi-tenancy.md)
- [Production Command Strategy](production-command-strategy.md)
- [Tenant Maintenance Runbook](tenant-maintenance-runbook.md)
- [Cross-Pod Invalidation Design](../architecture/cross-pod-invalidation-design.md)

Normal execution path is **platform admin + Operations Gateway + `tenant_operation:worker`**.  
Break-glass shell mutation is incident-only and must be audited.

## 1) Secret inventory and ownership

| Secret scope | Where reference lives | Rotation mechanism | Primary owner | Secondary owner |
|---|---|---|---|---|
| Platform DB credentials (`PLATFORM_DB_*` / `PLATFORM_DATABASE_URL`) | deployment/runtime configuration | Infrastructure secret-store rotation + controlled config rollout | Platform SRE | DBA/infra owner |
| Platform managed-secret root (`PLATFORM_SECRET_KEY`, `PLATFORM_SECRET_KEY_VERSION`) | deployment/runtime configuration | Controlled key lifecycle (requires managed-secret continuity planning) | Platform Security | Platform SRE |
| Platform admin email transport secret | `platform_service_configs` (`service_name=email`) | Update secret reference (`env:` or `managed:`), verify mail flow | Platform Ops | Security |
| Tenant primary DB credential reference | `tenant_database_configs.secret_reference` | `tenant_rotate_db_secret` gateway operation (single tenant only) | Platform Ops | Tenant support owner |
| Tenant email/storage secret references | `tenant_service_configs.secret_reference` | Update managed/env reference through approved admin workflow, then invalidate/verify | Platform Ops | Tenant support owner |

Rules:

- Keep **platform** and **tenant** credentials separate; never reuse tenant credentials for platform operations.
- Platform metadata stores **references**, not plaintext secret values.
- Use `managed:` or `env:` references only; never persist raw secrets in job payloads, logs, or tickets.

## 2) Rotation cadence and triggers

### Routine cadence (minimum baseline)

- Tenant DB credentials: every 90 days (or stricter policy).
- Tenant email/storage credentials: every 90–180 days based on provider policy.
- Platform DB/admin-email credentials: every 90 days or infra policy.
- Platform secret key lifecycle: scheduled and rehearsed with explicit recovery plan.

### Immediate triggers

- Suspected credential exposure or unauthorized access.
- Personnel access change requiring privilege revocation.
- Upstream secret-store/key-vault key rotation mandate.
- Break-glass incident where elevated credentials/tokens were used.
- Audit finding requiring shortened credential lifetime.

## 3) Staged rotation procedure (prepare → rotate → verify → rollback)

Use **one tenant at a time** for `tenant_rotate_db_secret` (catalog-enforced `single` target mode).

### A) Prepare

1. Open change ticket and incident/ops channel; assign requester + approver.
2. Confirm privileged access posture before issuing action codes:
   - requester is logged in as `break_glass` (required for managed-secret update workflows),
   - session is freshly authenticated (15-minute freshness guard),
   - operator can complete password + emailed action-code step-up.
3. Record execution metadata:
   - tenant slug (or platform scope),
   - planned window,
   - correlation ID,
   - deterministic idempotency key.
4. Pre-create the new secret in approved store and obtain a reference (`managed:...` or `env:...`).
5. Confirm worker availability:

```bash
cd app
bin/cake tenant_operation:worker --once --limit=10 --lease-ttl=300 --worker-id="${HOSTNAME:-secret-rotation}"
```

6. Capture pre-rotation baseline:
   - `bin/cake tenant:doctor --tenant=<tenant-slug>`
   - tenant login + core read/write smoke checks
   - current active `secret_reference` (reference only, never plaintext value)

### B) Rotate

#### Tenant DB secret (gateway path, recommended)

```bash
cd app
bin/cake tenant_operation:enqueue \
  --operation=tenant_rotate_db_secret \
  --tenant=<tenant-slug> \
  --parameters-json='{"new_secret_reference":"managed:tenant/<id>/database/primary/rotation/<timestamp>","max_attempts":1}' \
  --requester-email=<requester@example.com> \
  --approved-by-email=<approver@example.com> \
  --idempotency-key=tenant-rotate-db-secret:<tenant-slug>:<timestamp>
```

This operation executes staged logic in worker code:

1. prepare rotation context,
2. update `tenant_database_configs.secret_reference`,
3. clear secret/runtime/ORM state,
4. verify DB connectivity (`SELECT 1` on tenant datasource),
5. auto-rollback to previous reference on verification failure.

#### Tenant email/storage secret updates

- Update tenant secret references via approved platform-admin workflow.
- Ensure invalidation is published so all pods refresh runtime config.
- Treat high-impact adapter/credential changes like a maintenance-window operation.

### C) Verify

Rotation is successful only when all checks pass:

1. Operation reaches `completed` (not `failed`/`blocked`/`cancelled`).
2. Result indicates rotation applied (`rotated=true`, `rolled_back=false`).
3. `bin/cake tenant:doctor --tenant=<tenant-slug>` passes.
4. Tenant smoke checks pass (login + key read/write + expected background jobs).
5. Audit trail includes requester/approver, correlation ID, operation ID, and outcome.
6. Invalidation event observed (`tenant_secret_rotated` for success).

Platform Admin now exposes a tenant-scoped verification surface to speed operator checks:

- **UI:** `Platform Admin → Tenant → Secret Rotation Verification`
- **API:** `GET /platform-admin/tenants/{slug}/secret-rotation-status.json`

The view/API reports verification status (`success`, `failure`, `rollback`, `in_progress`), confidence level,
timestamps, actor, correlation ID, and actionable next-step guidance. Secret values are never returned.
Use this surface as the primary operator checklist before marking a rotation complete.

### D) Rollback

#### Automatic rollback (implemented behavior)

- If DB connectivity verification fails after reference update, worker rolls back to previous secret reference and emits rollback invalidation (`tenant_secret_rotation_rollback`).

#### Operator actions after rollback/failure

1. Keep tenant in maintenance if user impact exists.
2. Confirm previous reference is restored and connectivity is healthy.
3. Re-run `tenant:doctor` and smoke checks on restored state.
4. Capture failure evidence and remediation plan before retry.
5. Retry only with a **new idempotency key** after root cause is fixed.

## 4) Tenant/platform scope coordination

- **Platform scope first:** validate platform datastore health and admin host access before tenant rotations.
- **Tenant scope isolation:** execute DB secret rotation per tenant (single target) with tenant lock/lease semantics.
- **Wave control:** batch only low-risk reference-only updates; keep DB credential rotations serialized unless explicitly approved.
- **Cross-pod consistency:** require invalidation propagation for secret/config changes before declaring success.
- **Dependency coordination:** pause conflicting destructive operations (restore/cutover/shard move) for the same tenant during rotation.

## 5) Audit and evidence capture requirements

Attach all of the following to the change ticket/postmortem package:

1. Request metadata:
   - requester, approver, timestamp, tenant scope
   - correlation ID and idempotency key
2. Operation evidence:
   - queued job ID / operation ID
   - lifecycle transitions and terminal state
   - sanitized result/error payload
3. Validation evidence:
   - pre/post `tenant:doctor`
   - smoke-check outcomes and timestamps
   - invalidation event type/version confirmation
4. Security evidence:
    - secret reference changed (old/new reference identifiers only)
    - confirmation that no plaintext secret appeared in logs/tickets/payloads
    - any denied-attempt audit events (`platform_admin.authorization_denied`, `platform_admin.step_up_denied`, `platform_admin.break_glass_denied`) when access checks blocked unsafe requests
5. Exportable audit trail (as needed):

```bash
cd app
bin/cake platform_audit:export \
  --output=logs/platform-audit-secret-rotation-<date>.jsonl \
  --from=<iso-start> \
  --to=<iso-end> \
  --tenant-slug=<tenant-slug> \
  --action=tenant.secret_update \
  --correlation-id=<correlation-id>
```

## References

- [Production Command Strategy](production-command-strategy.md)
- [Tenant Maintenance Runbook](tenant-maintenance-runbook.md)
- [Capacity Thresholds and Tenant Sharding Runbook](capacity-sharding-runbook.md)
- [Platform Registry Disaster Recovery Runbook](platform-registry-dr-runbook.md)
