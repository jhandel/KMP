# Capacity Thresholds and Tenant Sharding Runbook

This runbook defines the operational process for deciding when to split tenant load and moving selected tenant databases to a new Azure PostgreSQL Flexible Server shard.

It aligns with:

- [Azure PostgreSQL Flexible Server Capacity Model](azure-postgres-flex-capacity-model.md)
- [Tenant Database Topology](tenant-database-topology.md)
- [Deployment Migration Strategy](deployment-migration-strategy.md)
- [Platform Operation State Machine](../architecture/platform-operation-state-machine.md)

## Objective

Maintain predictable tenant performance and bounded shard blast radius by applying explicit split triggers, moving tenants safely, and preserving fast rollback options.

## Preconditions

Before scheduling a tenant split/move:

- Platform and tenant datastores are already separated.
- The target shard server is provisioned in the same region/network trust boundary (or an approved DR boundary).
- Backups are verified and restore drills are current.
- Maintenance window and tenant communication are approved.
- Operator has platform-admin privileges for maintenance/cutover commands.
- Observability is active for connection pressure, CPU, latency, and per-tenant load skew.

## Threshold triggers (when to initiate split planning)

Use all gates; whichever triggers first should start planning.

### 1) Tenant-count threshold

- Calculate per-shard `T_operational_max` from current replica/pool settings.
- Start split planning at `>= 80%` of `T_operational_max`.
- Stop onboarding to that shard at `>= 90%`.

### 2) Connection saturation threshold

Trigger if either persists for 15+ minutes during normal peak windows:

- Active connections `> 70%` of `max_connections`, or
- Connection acquisition wait/timeout errors in app logs.

### 3) Storage growth threshold

Trigger split planning when any apply:

- Sustained storage growth projects `>= 80%` server storage usage within the next 30 days.
- WAL/checkpoint growth drives repeated IO throttling or backup window expansion.
- Autogrow events become frequent enough to reduce safety margin for restore/cutover operations.

### 4) Noisy-neighbor threshold

Trigger split or tenant isolation action when any apply repeatedly:

- DB CPU `> 75%` sustained for 30+ minutes.
- p95 query latency is `>= 2x` baseline for 30+ minutes.
- A single tenant repeatedly contributes a disproportionate share of DB time/IO (for example `>35%`).

## Target-server preparation checklist

Complete before placing a tenant in maintenance mode:

- [ ] Create/validate target Flexible Server (sizing, HA policy, backups, parameter baseline).
- [ ] Confirm network reachability from app/worker pods to target host.
- [ ] Apply PostgreSQL guardrails (timeouts, connection policy) consistent with production standards.
- [ ] Validate admin access and credential/secret rotation path.
- [ ] Pre-create a managed destination DB credential reference (`managed:tenant/<id>/database/primary/rotation/...`) and capture correlation/idempotency IDs for queued `tenant_rotate_db_secret`.
- [ ] Create (or reserve) tenant database name on target shard.
- [ ] Create tenant role/user with least privilege on target database only.
- [ ] Verify `pg_dump`/`pg_restore` tooling compatibility with source and target PostgreSQL versions.
- [ ] Record change ticket, correlation ID, and operator owner for audit traceability.

## Tenant move procedure

Move one tenant at a time unless explicitly approved for controlled parallel waves.

### 1) Select tenant(s) for move

Prioritize tenants with highest impact and lowest coupling risk:

- High DB CPU/IO contribution.
- Highest connection usage and timeout incidence.
- Fast growth trajectory or large maintenance windows.

Capture source metrics baseline (connections, p95 latency, error rate, DB size, key job timings).

### 2) Create target DB/user and secrets

1. Create target DB and tenant-scoped role/user on destination shard.
2. Store destination credentials as platform secret references.
3. Keep source credentials unchanged until cutover is validated.

### 3) Enter maintenance and drain

1. Put tenant in maintenance mode:
   - `bin/cake tenant:maintenance <slug>`
2. Drain in-flight writes/jobs to timeout boundary.
3. Confirm no active write workload remains.

### 4) Backup/export from source

Use tenant-scoped backup/export only:

1. Take fresh source backup/snapshot marker.
2. Export source tenant DB (`pg_dump` custom format recommended for restore flexibility).
3. Validate backup artifact integrity and size.

### 5) Restore/import to target

1. Restore into the prepared target tenant DB (`pg_restore`).
2. Re-apply required grants/ownership.
3. Run tenant migrations only if required by release/schema plan.

### 6) Cutover metadata update

1. Update platform `tenant_database_configs` to target host/database/secret reference.
2. Ensure cutover event is recorded with correlation ID and actor identity.
3. Clear/refresh tenant config cache where required by deployment model.

State-machine alignment for orchestration:

- `queued` -> `approval_required` (if policy requires) -> `approved` -> `running`.
- Use `hold` for operator pause and `blocked` for unmet preconditions/remediation.
- End in `completed` on success, `failed`/`cancelled` on terminal stop.

### 7) Re-enable tenant

After validation gates pass:

1. `bin/cake tenant:doctor --tenant=<slug>`
2. Tenant smoke checks (login, key read/write flows, queue job execution).
3. `bin/cake tenant:enable <slug>`

## Validation and rollback procedure

### Validation gates (must pass)

- `tenant:doctor` passes against destination.
- Application smoke tests pass on tenant host.
- Queue workers process tenant jobs on destination without cross-tenant leakage.
- Connection, latency, and error metrics are stable or improved vs baseline.

### Rollback criteria

Rollback immediately if any apply:

- Persistent tenant-facing errors after cutover.
- Data integrity mismatch between source and destination checks.
- Sustained timeout/latency regression beyond agreed SLO threshold.

### Rollback steps

1. Put tenant back in maintenance mode (if not already).
2. Repoint `tenant_database_configs` back to source host/database/credentials.
3. Clear/refresh tenant config cache and restart affected workers if required.
4. Run `tenant:doctor --tenant=<slug>` against source.
5. Execute tenant smoke checks.
6. Re-enable tenant on source and declare rollback complete.
7. Preserve failed destination artifacts/logs for postmortem.

## Post-move monitoring and cleanup

For at least 24 hours after cutover:

- Monitor tenant-specific connections, p95 latency, DB CPU/IO, and error rates.
- Verify scheduled jobs, queue throughput, and backups on destination shard.
- Confirm no stale sessions/workers remain pinned to old DB endpoint.

After stabilization:

- Decommission or archive old tenant DB backup artifacts per retention policy.
- Remove unused source credentials/secrets.
- Update shard occupancy (`T_operational_max` usage) and onboarding policy.
- Record final runbook outcome with metrics delta and lessons learned.

## References

- [Tenant Database Topology](tenant-database-topology.md)
- [Azure PostgreSQL Flexible Server Capacity Model](azure-postgres-flex-capacity-model.md)
- [Azure PostgreSQL Flexible Server Production Guide](azure-postgres-flex-guide.md)
- [Backup & Restore](backup-restore-runbook.md)
- [Deployment Migration Strategy](deployment-migration-strategy.md)
- [Platform Operation State Machine](../architecture/platform-operation-state-machine.md)
