# Platform Registry Disaster Recovery Runbook

Use this runbook when the platform registry datastore (control plane) is unavailable, corrupted, or at risk.

[← Back to Deployment Guide](README.md)

## Scope and architecture alignment

This runbook is for the `platform` datasource only. It covers recovery of tenant routing/provisioning metadata and platform-admin control-plane access for the current host-based multi-tenant model.

Key references:

- [3.9 Multi-Tenancy Architecture and Operations](../3.9-multi-tenancy.md)
- [Backup & Restore](backup-restore-runbook.md)
- [Tenant Onboarding Runbook](tenant-onboarding-runbook.md)
- [Tenant Maintenance Runbook](tenant-maintenance-runbook.md)
- [Troubleshooting](troubleshooting.md)

## 1) Failure scenarios and severity tiers

| Tier | Scenario | User impact | Typical decision |
|------|----------|-------------|------------------|
| **SEV-1 Critical** | Platform DB unavailable/corrupted; tenant host resolution fails broadly | Multi-tenant outage or unsafe routing risk | Trigger incident, freeze tenant mutations, start restore/failover immediately |
| **SEV-2 High** | Partial registry loss (aliases, DB config, service config, secret refs) with some tenants impacted | Subset of tenants unavailable, admin actions blocked | Restore affected metadata set or fail over if blast radius is growing |
| **SEV-3 Medium** | Admin-only data issue (platform admin auth, audit lag, single table drift) with tenant traffic mostly intact | Control-plane degradation, delayed operations | Contain, restore targeted records, schedule controlled cutover |
| **SEV-4 Low** | Non-critical inconsistency detected during verification (`tenant:doctor` drift, stale alias) | No immediate outage | Correct in normal change window with documented validation |

Escalate to SEV-1 if tenant host resolution integrity cannot be proven.

## 2) Recovery data sources (what to restore from)

### Platform registry sources (required)

- Latest successful platform datastore snapshot/PITR backup.
- Recent logical export of platform tables (if used for quick diff/targeted restore).
- Platform migration history (`bin/cake platform:migrate` compatibility with target restore point).

Control-plane metadata to verify/restore:

- `tenants`
- `tenant_aliases`
- `tenant_database_configs`
- `tenant_service_configs`
- platform admin/control-plane tables (accounts, audit, operation/lock state) as required by incident scope.

### Tenant metadata corroboration sources (cross-check)

Use these to validate recovered registry records:

- DNS zone records for primary/alias tenant hosts.
- Infra source of truth for tenant DB hosts/db names/users.
- Secret manager references (env/managed references) and key-management backup records.
- Recent onboarding/maintenance/change tickets with approved tenant config changes.

## 3) RTO/RPO assumptions and decision points

Default planning assumptions (adjust per environment SLA):

- **RTO target**: 60 minutes for SEV-1 platform-registry outage.
- **RPO target**: 15 minutes with snapshot/PITR coverage for platform datastore.
- **Control-plane mutation freeze**: required during restore/cutover to avoid split-brain metadata.

Decision points:

1. **Failover vs restore-in-place**
   - Choose failover when replica/promoted target is healthy and within RPO.
   - Choose restore-in-place when failover target is unavailable or known-bad.
2. **PITR timestamp selection**
   - Prefer point just before first confirmed bad mutation/corruption event.
3. **Tenant activation gate**
   - Keep tenants in maintenance/drain if routing/DB/secret-reference checks fail for any critical tenant cohort.
4. **Escalate rollback**
   - If post-cutover validation fails and cannot be remediated quickly, roll back to pre-cutover platform snapshot/endpoint.

## 4) Failover/cutover procedure with validation gates

### A. Contain and prepare

1. Open incident channel and assign incident commander + operations scribe.
2. Freeze control-plane writes:
   - stop onboarding, tenant status changes, secret rotations, and restore/cutover jobs.
   - pause `tenant_operation:worker` runners if they may mutate control-plane state.
3. Capture current evidence:
   - platform DB health/errors,
   - last known good backup/PITR timestamp,
   - affected tenant set.

### B. Recover platform registry

1. Execute chosen recovery mode:
   - fail over platform datastore to healthy replica **or**
   - restore platform datastore from snapshot/PITR target.
2. Re-point platform app config (`PLATFORM_DATABASE_URL` / `PLATFORM_DB_*`) if endpoint changed.
3. Run schema compatibility gate:

```bash
cd app
bin/cake platform:migrate
```

4. Keep tenant traffic constrained (maintenance/drain policy) until validation gates pass.

### C. Validate registry integrity before full reopen

Run these gates in order:

1. **Connectivity + migration gate**
   - `platform:migrate` completes without pending/broken platform schema state.
2. **Tenant registry gate**
   - `bin/cake tenant:list` returns expected active tenants.
   - random-sample critical tenants for slug/display/status correctness.
3. **Routing/alias gate**
   - verify primary + alias hosts resolve to expected tenant IDs/slugs.
4. **Tenant DB config gate**
   - validate DB host/name/user + secret references for critical tenants match source-of-truth.
5. **Service config gate**
   - validate email/storage/service metadata and secret references for sampled tenants.
6. **Doctor/smoke gate**
   - `bin/cake tenant:doctor --tenant=<slug>` passes for critical cohort.
   - tenant login + core read/write smoke checks pass.
7. **Admin recovery gate**
   - platform admin login works on approved admin hosts only.
   - audit/event records capture recovery actions with correlation IDs.

Only reopen normal control-plane writes after all required gates pass.

### D. Re-enable operations

1. Resume worker runners and queued control-plane operations gradually.
2. Re-enable tenant traffic cohort-by-cohort (highest priority tenants first).
3. Monitor error/latency/routing anomalies for at least one agreed observation window.

## 5) Rollback plan

Trigger rollback if any apply:

- recovered registry routes tenants to incorrect DB/config,
- repeated `tenant:doctor` or smoke failures for critical tenants,
- platform admin/auth controls fail on approved hosts,
- RPO breach or data-integrity uncertainty remains unresolved.

Rollback steps:

1. Re-freeze control-plane writes and keep impacted tenants in maintenance/drain.
2. Revert to previous known-good platform datastore endpoint/snapshot.
3. Re-run validation gates (Section 4C) before reopening.
4. Preserve incident timeline, commands, correlation IDs, and evidence for postmortem.

## 6) Communication protocol

### Internal

- At incident start: declare severity, scope, current RTO/RPO estimate, next update time.
- During recovery: publish updates at fixed cadence (for example every 15 minutes for SEV-1/2).
- At cutover/failback: announce checkpoint + validation status + go/no-go decision.

### Tenant-facing

Provide:

- impacted services (routing, login, admin actions, background ops),
- expected degradation window and next update time,
- confirmation when service is restored and what validation was completed.

### Closeout

Within one business day:

1. Publish post-incident summary (timeline, root cause, detection gap, corrective actions).
2. Create follow-up tasks for backup policy, validation automation, and runbook updates.
