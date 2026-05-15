# Tenant Maintenance Runbook (Drain + Operations Gateway)

Use this runbook for planned tenant maintenance windows (schema/data operations, cutover prep, recovery actions) using the platform operations model.

[← Back to Deployment Guide](README.md)

## Scope and alignment

This runbook aligns with:

- [Production Command Strategy](production-command-strategy.md)
- [Platform Operation State Machine](../architecture/platform-operation-state-machine.md)
- [3.9 Multi-Tenancy Architecture and Operations](../3.9-multi-tenancy.md)

Use Operations Gateway + `tenant_operation_jobs` as the normal path. Direct shell commands are break-glass only.

## Preconditions

Before starting:

- Approved maintenance window and communication plan exist.
- Requester/approver identities are known.
- Tenant slug and operation parameters are validated.
- Rollback path is confirmed (last good backup/config target).
- Worker runner is available (`tenant_operation:worker`).

## 1) Notify and coordinate

1. Open/confirm change ticket and incident channel.
2. Notify tenant stakeholders with:
   - maintenance start/end window,
   - expected user impact,
   - rollback trigger criteria,
   - status channel/contact.
3. Confirm an approver is available during execution.

## 2) Enter maintenance and drain

Submit a tenant status operation through the gateway (preferred path):

```bash
cd app
bin/cake tenant_operation:enqueue \
  --operation=tenant_status \
  --tenant=<tenant-slug> \
  --parameters-json='{"status":"maintenance"}' \
  --requester-email=<requester@example.com> \
  --approved-by-email=<approver@example.com> \
  --idempotency-key=tenant-status:<tenant-slug>:maintenance
```

Then run/confirm worker execution:

```bash
cd app
bin/cake tenant_operation:worker --once --limit=10 --lease-ttl=300 --worker-id="${HOSTNAME:-maintenance-runner}"
```

Drain checks (must pass before risky work):

- Tenant requests return maintenance/unavailable response as expected.
- No active write workflows for the tenant.
- Queue depth for tenant-specific mutable jobs is stable/decreasing and within planned drain boundary.

Decision point:

- If drain does not complete inside the agreed timeout, **stop** and choose rollback/cancel path (Section 6).

## 3) Run maintenance operation through gateway/jobs

Enqueue the specific operation (example shown; keep parameters allow-listed):

```bash
cd app
bin/cake tenant_operation:enqueue \
  --operation=<operation-name> \
  --tenant=<tenant-slug> \
  --parameters-json='<validated-json>' \
  --requester-email=<requester@example.com> \
  --approved-by-email=<approver@example.com> \
  --idempotency-key=<deterministic-operation-key>
```

Execution requirements:

- Record/track the operation correlation ID.
- Monitor progress/events (`queued|approved|running|blocked|completed|failed|cancelled`).
- Retry only via idempotent replay rules (same semantic payload).

Decision points:

- `blocked`: remediate unmet prerequisite, then resume.
- `failed`: evaluate rollback criteria immediately (Section 6).
- repeated lease takeovers/timeouts: pause further operations and escalate.

## 4) Verify health before re-enable

Run verification gates in order:

1. `bin/cake tenant:doctor --tenant=<tenant-slug>` passes.
2. Tenant smoke checks pass (login, core read/write flow, expected background jobs).
3. Error/latency metrics are within agreed thresholds.
4. No cross-tenant leakage indicators in logs/audit.

Connection pool checks (from `/health` `tenant_connection_pool` and platform dashboards):

- `kmp_tenant_connection_pool_connections{state=waiting}` should remain near zero during steady state.
- `kmp_tenant_connection_pool_saturation_ratio` sustained above `0.75` is high risk; `>=0.90` or any waiting connections is critical.
- Increasing `kmp_tenant_connection_pool_timeout_total` or `kmp_tenant_connection_pool_probe_error_total` indicates saturation/visibility degradation and requires investigation before re-enable.

Decision point:

- If any verification gate fails, keep tenant in maintenance and execute rollback decision flow (Section 6).

## 5) Re-enable tenant

When all gates pass, submit status re-enable through gateway:

```bash
cd app
bin/cake tenant_operation:enqueue \
  --operation=tenant_status \
  --tenant=<tenant-slug> \
  --parameters-json='{"status":"active"}' \
  --requester-email=<requester@example.com> \
  --approved-by-email=<approver@example.com> \
  --idempotency-key=tenant-status:<tenant-slug>:active
```

Then run/confirm worker processing and perform final checks:

- Tenant is reachable and serving normal responses.
- Queue workers are healthy for tenant workload.
- Stakeholder closeout notice sent with outcome + timestamps.

## 6) Failure handling and rollback decision flow

### Trigger rollback when any apply

- Persistent tenant-facing errors after operation.
- Data integrity mismatch from expected pre/post validation.
- Sustained timeout/latency regression beyond agreed SLO.
- Operation reaches terminal `failed` and remediation is not low-risk/immediate.

### Rollback steps

1. Keep tenant in maintenance mode (do not re-enable).
2. Revert operation-specific state (for example, restore prior config/cutover target/known-good backup) via approved gateway path.
3. Re-run `tenant:doctor --tenant=<tenant-slug>` and tenant smoke checks on restored state.
4. Only re-enable tenant after rollback verification gates pass.
5. Preserve correlation IDs, logs, and artifacts for postmortem.

### Cancel vs rollback decision

- **Cancel only**: no mutating side effects were applied (safe to stop).
- **Rollback required**: mutating side effects were applied and validation failed.

## 7) Post-window closeout

- Record final operation IDs/correlation IDs and outcome in change ticket.
- Document any blocked/failure states and corrective actions.
- Capture follow-up hardening tasks (automation, alerting, runbook updates).

## References

- [Production Command Strategy](production-command-strategy.md)
- [3.9 Multi-Tenancy Architecture and Operations](../3.9-multi-tenancy.md)
- [Deployment Migration Strategy](deployment-migration-strategy.md)
- [Backup & Restore](backup-restore-runbook.md)
- [Troubleshooting](troubleshooting.md)
