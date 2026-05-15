# Backup & Restore Runbook

Operate tenant-safe backup and restore procedures for managed multi-tenant KMP environments.

[← Back to Deployment Guide](README.md)

## Scope and safety model

- Tenant application data and platform registry metadata are backed up independently.
- Restore operations are **tenant-scoped** and executed one tenant at a time.
- Never import tenant backups into the platform datastore.
- High-risk restore/cutover actions require explicit approval and full audit traceability.

## 1) Backup strategy and retention expectations

### Backup strategy

1. **Tenant databases (primary scope):**
   - Scheduled daily full logical backup per tenant database.
   - On-demand backup before any restore/cutover or major maintenance.
   - Backup artifact naming must include tenant slug + timestamp.
2. **Platform registry datastore (control-plane scope):**
   - Independent backup schedule from tenant backups.
   - Backup includes tenant routing/provisioning metadata.
3. **File/object content:**
   - Local uploads (`images/uploaded/`) must be backed up separately, or cloud storage replication must be verified.

### Retention expectations

- **Local staging copies:** keep last 7–30 days (operational convenience only).
- **Durable object storage:** keep at least 30 days, with immutable/locked retention where supported.
- **Monthly restore test evidence:** keep at least 90 days in the ops evidence location.

Example local retention pruning:

```bash
find /opt/kmp/backups/ -name "*.sql.gz" -mtime +30 -delete
```

## 2) Restore request workflow and approvals

Restore/cutover must run through the approved platform operation path (platform-admin/gateway + worker), not ad hoc SQL import on production targets.

### Required request inputs

- Tenant slug and environment.
- Source backup artifact ID/path + timestamp.
- Requested target database name (`new_database_name`).
- Change ticket / incident ID.
- Requester identity and approver identity.
- Rollback target (previous primary DB config) and communication channel.

### Approval gates

- Restore/cutover is a high-risk operation and requires explicit approval.
- Separation of duties is expected (`requester != approver`) unless incident override policy applies.
- Idempotency key and correlation ID must be present and persisted for tracking.
- Platform-admin restore step-up requires:
  - break-glass platform-admin role for action-code issuance,
  - fresh authenticated session (15-minute freshness window),
  - password + one-time emailed action code.

### Preflight checks

- Confirm target tenant is correct.
- Confirm `new_database_name` does not collide with known tenant DBs.
- Confirm `new_database_name` was not reused in recent restore-cutovers (30-day safety window).
- Confirm latest valid backup exists and integrity metadata is present.

## 3) Drain-mode cutover sequence

Before any restore/cutover, place tenant in drain mode:

```bash
kmp tenant:drain <tenant-slug>
```

Expected drain behavior:

- Tenant app endpoints return `503 Tenant is draining for cutover. Retry shortly.`
- `Retry-After: 30` is emitted.
- Health and platform-admin paths remain reachable.

### Enforced cutover sequence (worker)

1. Put tenant into/verify tenant remains in `draining` state.
2. Create target DB.
3. Run target schema migration.
4. Import selected backup.
5. Run integrity checks.
6. Capture config snapshot (old/new primary DB state).
7. Switch tenant primary DB config.
8. Publish cross-pod invalidation event (`tenant_restore_cutover`).
9. Re-enable tenant only after validation gates pass.

If cutover fails after mutation begins, worker restores prior primary config and publishes rollback invalidation (`tenant_restore_cutover_rollback`).

To end drain window after successful validation:

```bash
kmp tenant:enable <tenant-slug>
```

## 4) Validation checkpoints and rollback decision tree

### Validation checkpoints (must all pass)

1. Operation status is `completed` with expected phase progression.
2. `tenant:doctor --tenant=<tenant-slug>` passes.
3. Tenant host smoke checks pass (login + critical read/write path).
4. No schema mismatch / datasource boundary errors in logs.
5. Cross-pod routing/config refresh observed after cutover event.

### Rollback decision tree

- **Did failure occur before primary DB switch?**
  - **Yes:** keep tenant draining/maintenance, remediate, re-run restore with new idempotency key.
  - **No:** continue below.
- **Did integrity/doctor/smoke validation fail after switch?**
  - **Yes:** execute rollback to prior primary DB snapshot, verify rollback events propagated, keep tenant non-active until validation passes.
  - **No:** continue below.
- **Are tenant-facing SLO errors still elevated after successful checks?**
  - **Yes:** hold tenant in maintenance, investigate platform/network dependencies, rollback if risk remains.
  - **No:** approve re-enable and close change window.

No-go conditions:

- Unverified backup source.
- Ambiguous tenant target.
- Target DB naming collision.
- Missing approval, correlation, or change ticket evidence.

## 5) Post-restore verification and audit evidence capture

Capture and store the following evidence in the change/incident record:

- Operation ID, idempotency key, and correlation ID.
- Request + approval identities, timestamps, and rationale.
- If denied attempts occurred (stale session, non break-glass role, or missing step-up challenge), include the related `platform_admin.*_denied` audit events.
- Backup artifact ID/hash/timestamp and restore target DB name.
- Worker phase log (drain → migrate → import → integrity → cutover).
- `tenant:doctor` and tenant smoke-check results.
- Cross-pod invalidation events (`tenant_restore_cutover` or rollback event).
- Final tenant status transition and customer communication timestamps.

Minimum closeout checks:

- Tenant status is `active` (or explicitly `maintenance` with documented reason).
- Monitoring dashboards show healthy error/latency profile.
- Follow-up actions are logged for any manual exceptions.

## References

- [Deployment README](README.md)
- [Production Readiness Checklist](production-readiness-checklist.md)
- [Tenant Maintenance Runbook](tenant-maintenance-runbook.md)
- [Platform Registry Disaster Recovery Runbook](platform-registry-dr-runbook.md)
- [3.9 Multi-Tenancy Architecture and Operations](../3.9-multi-tenancy.md)
- [Platform Operation State Machine](../architecture/platform-operation-state-machine.md)
- [Production Command Strategy](production-command-strategy.md)
