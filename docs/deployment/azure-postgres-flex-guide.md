# Azure PostgreSQL Flexible Server Production Guide (KMP Multi-Tenant)

This guide documents the production operating model for running KMP multi-tenancy on Azure Database for PostgreSQL Flexible Server.

It complements:

- [Tenant Database Topology](tenant-database-topology.md)
- [Azure PostgreSQL Flexible Server Capacity Model](azure-postgres-flex-capacity-model.md)
- [PgBouncer Validation (CakePHP + Multi-Tenant PostgreSQL)](pgbouncer-validation.md)

> **Validation requirement:** Values and patterns here are production defaults. Validate them in a staging environment with representative tenant traffic before enforcing in production.

## 1) TLS/SSL requirements and certificate validation

Use encrypted PostgreSQL connections for every KMP path (`platform`, `tenant`, web, workers, migrations, backup/restore jobs).

### Baseline requirements

- Require TLS in Flexible Server (`require_secure_transport` enabled).
- Use `sslmode=verify-full` wherever possible so both encryption and host identity are validated.
- Include the Azure CA chain in client trust stores and rotate when Azure publishes new CA timelines.
- Ensure hostname validation matches the server FQDN used in DSNs.

### What to verify

- App startup fails fast on invalid cert chain or hostname mismatch.
- `platform` and `tenant` datasource paths both enforce certificate validation.
- `tenant:doctor`, `platform:migrate`, `tenant:migrate`, and queue workers all pass using validated TLS endpoints.
- Monitoring alerts exist for TLS handshake/auth failures.

## 2) Pooling strategy and PgBouncer relationship

### Recommended rollout

- Start with **session pooling** for PgBouncer in front of Flexible Server.
- Treat **transaction pooling** as an optimization phase only after full compatibility evidence.

This aligns with [PgBouncer Validation](pgbouncer-validation.md), especially for prepared statement behavior and transactional workflow paths.

### What to verify before mode approval

- No prepared-statement protocol errors under sustained traffic.
- Transactional CakePHP paths (including lock-sensitive workflows) behave correctly under concurrency.
- Migration/provisioning/backup/restore commands remain reliable through PgBouncer.
- Emergency fallback path to direct PostgreSQL or session mode is tested and documented.

## 3) Connection sizing and limits

Use the capacity model formulas in [Azure PostgreSQL Flexible Server Capacity Model](azure-postgres-flex-capacity-model.md) as the source of truth.

### Operating rules

- Maintain reserved server connections for admin/maintenance operations.
- Keep app-side pool demand below usable capacity with explicit headroom.
- Include web replicas, queue workers, and overlapping scheduled jobs in total demand.
- Bound per-tenant role connection limits to reduce noisy-neighbor blast radius.

### What to verify

- Calculated `DB_app_demand` remains below policy threshold after each scale change.
- Connection wait/timeout errors are absent at expected peak load.
- Tenant onboarding and split triggers are enforced at documented thresholds.

## 4) HA/failover model and verification

Azure PostgreSQL Flexible Server offers zonal/regional high availability depending on region/features. Design KMP runbooks assuming transient reconnects and short write interruptions during failover events.

### Practices

- Use at least two app replicas and resilient worker supervision.
- Keep connection timeout/retry settings bounded so failures surface quickly.
- Ensure platform and tenant topology isolates platform-plane availability from tenant shard hotspots.

### Verification drills

- Perform planned failover drills in non-production and record RTO/RPO outcomes.
- Verify app recovery for active web traffic, queue workers, and migration-safe operations.
- Confirm reconnection does not cause tenant context leakage across requests/jobs.
- Re-baseline p95 latency/error budgets post-failover.

## 5) Backup/PITR and tenant-scoped logical restore strategy

Flexible Server backups and PITR are **server-level** protections. KMP tenant operations still require **tenant-scoped logical backups/restores** to avoid restoring unrelated tenants on the same shard.

### Recommended model

- Use Azure automated backups/PITR for shard/server disaster recovery.
- Maintain tenant-scoped logical backup artifacts for tenant-level incidents and re-sharding.
- Keep platform datastore backups separate from tenant shard backups.

### What to verify

- Regular PITR restore drill to alternate server for shard recovery.
- Tenant-only logical restore drill (one tenant in maintenance mode, no cross-tenant impact).
- `tenant:doctor` and smoke tests pass after restore/cutover.
- Backup encryption, retention, and access controls meet policy requirements.

## 6) Private networking and firewall guidance

Prefer private access for PostgreSQL endpoints; avoid broad public ingress.

### Baseline controls

- Deploy Flexible Server with private networking (VNet integration/private DNS) where feasible.
- Restrict firewall rules to known app subnets/egress addresses when public access is unavoidable.
- Deny `0.0.0.0/0`-style broad allow rules in production.
- Keep PgBouncer and app workloads in controlled network segments with least-privilege routing.

### What to verify

- Only approved sources can reach PostgreSQL and PgBouncer endpoints.
- DNS resolution for private endpoints is stable from app/worker runtimes.
- Connection failures from disallowed origins are logged and alertable.

## 7) Managed identity and Key Vault integration touchpoints

KMP runtime secrets should be resolved from secure secret stores, with platform metadata holding references rather than raw credentials.

### Integration touchpoints

- Use managed identity for app components that fetch secrets from Key Vault.
- Store tenant DB secret references in platform metadata (`tenant_database_configs`) and resolve at runtime.
- Rotate DB credentials and update secret references without requiring code changes.
- Separate platform and tenant credential sets; do not reuse tenant credentials for platform operations.

### What to verify

- Managed identity has minimum required Key Vault permissions.
- Secret version rotation is tested end-to-end (read new secret, reconnect successfully).
- Audit logs capture secret access, failed retrievals, and operational overrides.

## 8) Maintenance windows and parameter tuning guidance

Flexible Server maintenance and parameter tuning should be controlled through change windows with explicit validation.

### Parameters and behaviors to tune/validate

- Connection and timeout guards (`max_connections`, statement/lock/idle-in-transaction timeouts).
- Autovacuum/analyze behavior for tenant-heavy write patterns.
- Logging/observability parameters needed for slow-query and lock diagnostics.
- PgBouncer pool sizing/timeouts consistent with server-side limits.

### Validation checklist per change window

1. Capture baseline metrics (connections, CPU, latency, lock waits, error rates).
2. Apply parameter change in staging first; run representative tenant load.
3. Confirm migrations, queue workloads, and backup/restore paths still succeed.
4. Roll to production with canary tenant cohort and defined rollback trigger.
5. Update runbook thresholds and capacity assumptions after stabilization.

## 9) Production readiness checklist

Before production sign-off, confirm all are true:

- TLS with certificate and hostname validation is enforced everywhere.
- PgBouncer mode is explicitly approved with checklist evidence.
- Connection budget, headroom, and tenant split thresholds are documented.
- Failover and restore drills were executed and met SLO targets.
- Private networking/firewall posture is least-privilege.
- Secret retrieval/rotation through managed identity + Key Vault is validated.
- Maintenance-window parameter tuning has reproducible verification evidence.
