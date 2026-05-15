# Azure Disaster Recovery Runbook (Multi-Tenant, Single Deployment)

Use this runbook when an Azure regional event, regional service outage, or unrecoverable regional dependency failure threatens availability of the managed multi-tenant KMP deployment.

[← Back to Deployment Guide](README.md)

## Scope and assumptions

- Architecture: host-based multi-tenant KMP with a single app deployment serving all tenants.
- Control plane (`platform` datasource) and tenant application data (`tenant` datasource) are distinct concerns and must remain isolated during recovery.
- This runbook focuses on Azure regional DR and cross-region recovery.
- Tenant-level recoveries without regional impact should use [Backup & Restore](backup-restore-runbook.md) and [Tenant Maintenance Runbook](tenant-maintenance-runbook.md).
- Platform metadata integrity recovery remains governed by [Platform Registry Disaster Recovery Runbook](platform-registry-dr-runbook.md).

## 1) Regional failure scenarios

| Tier | Scenario | Impact | Primary DR decision |
|------|----------|--------|---------------------|
| **SEV-1** | Full Azure region outage (compute, DB, storage, or network unavailable) | Broad tenant outage and control-plane interruption | Initiate cross-region failover |
| **SEV-1** | Regional networking failure (Front Door/App Gateway/VNet path loss) | App unreachable even if DB is healthy | Shift traffic + fail over dependent services |
| **SEV-2** | PostgreSQL Flexible Server regional failure/corruption | Tenant and/or platform data unavailable | Promote DR target or PITR restore in DR region |
| **SEV-2** | Key Vault or secret access outage in primary region | App cannot authenticate to DB/storage/service dependencies | Switch to DR Key Vault + validated secret set |
| **SEV-3** | Storage account regional outage (documents, exports, attachments) | Partial feature outage; core app may still run | Fail over storage endpoint and validate data access |

Escalate to SEV-1 when tenant routing integrity or write-safety cannot be guaranteed.

## 2) Azure resource dependency map

Recover in dependency order to avoid split-brain and false-positive health checks.

1. **Traffic + DNS**: Azure Front Door / Traffic Manager / DNS zone.
2. **Networking**: VNet/subnets, NSGs, Private DNS zones, private endpoints.
3. **Secrets**: Key Vault (or secret provider) and managed identity access policies.
4. **Data**:
   - PostgreSQL Flexible Server (`platform` + tenant shard servers).
   - Azure Storage (blob/files for tenant assets/backups).
5. **Application**: App Service / Container Apps / AKS workload and worker processes.
6. **Async control paths**: queue/cron/worker runners and platform operation workers.

### Critical coupling notes

- App pods must not be reopened before `platform` and required tenant DB connectivity is proven.
- Secret references must be validated before any migration, restore, or queue-resume action.
- Storage endpoint failover must be aligned with application configuration and signed URL generation.

## 3) Backup, restore, and failover paths

### PostgreSQL Flexible Server (platform + tenant shards)

- **Primary path**: promote replica/failover target in DR region (if pre-provisioned and current enough for RPO).
- **Secondary path**: Point-in-time restore (PITR) into DR region and cut app over to restored servers.
- **Validation gates**:
  - `cd app && bin/cake platform:migrate`
  - `cd app && bin/cake tenant:doctor --tenant=<slug>` for critical tenant cohort
  - sample tenant read/write smoke tests

### Azure Storage

- **Primary path**: RA-GRS/GRS secondary read access + promoted write endpoint (per storage design).
- **Secondary path**: restore required objects/containers from backup replication/export.
- **Validation gates**:
  - attachment upload/download for sampled tenants
  - export/import artifacts accessible from app and workers

### Application compute

- **Primary path**: activate warm standby deployment in DR region.
- **Secondary path**: redeploy release artifact to DR region from immutable image/version.
- **Validation gates**:
  - health endpoints, login, platform admin host enforcement, background job processing

### Secrets and identity

- **Primary path**: fail over to DR Key Vault with synchronized secret versions.
- **Secondary path**: restore secret set from secure backup procedure and rotate compromised credentials.
- **Validation gates**:
  - DB/storage credentials resolve via managed identity
  - no startup/auth failures in app or worker logs

## 4) Regional failover procedure

### A. Contain and declare

1. Open incident channel; assign incident commander, ops lead, and scribe.
2. Declare severity, target RTO/RPO, and update cadence.
3. Freeze risky mutations:
   - tenant onboarding/state changes,
   - restore/import operations,
   - secret rotations except DR-required rotations.
4. Place high-risk tenants into maintenance/drain where write safety is uncertain.

### B. Recover foundations in DR region

1. Confirm DR networking, private endpoints, and DNS resolution paths.
2. Restore/fail over Key Vault access and managed identity authorizations.
3. Fail over or restore PostgreSQL (`platform` first, then tenant shard servers by priority).
4. Activate storage DR endpoint/path and verify required containers/artifacts.

### C. Rehydrate and start application

1. Deploy/scale app + worker workloads in DR region using known-good release artifact.
2. Repoint configuration:
   - `PLATFORM_DATABASE_URL` / platform DB env vars,
   - tenant DB host mappings and secret references,
   - storage endpoint settings.
3. Run schema compatibility gates:

```bash
cd app
bin/cake platform:migrate
```

4. Resume tenant operation workers only after data-plane checks succeed.

### D. Traffic cutover

1. Shift traffic via Front Door/Traffic Manager/DNS to DR region.
2. Re-enable tenant cohorts in phases (critical tenants first).
3. Observe stability window before full reopen.

## 5) Cutover validation checklist

Use this checklist before declaring service restored:

- [ ] Platform DB reachable; `platform:migrate` clean.
- [ ] Critical tenant cohort passes `tenant:doctor`.
- [ ] Tenant host/alias routing resolves correctly in DR.
- [ ] Platform admin routes restricted to approved admin hosts.
- [ ] Secret references valid for DB, storage, and mail/service integrations.
- [ ] Attachment/document operations pass smoke tests.
- [ ] Queue/worker throughput stable with no error spikes.
- [ ] App latency/error-rate within agreed incident thresholds.
- [ ] Audit trail captures DR actions, operators, timestamps, and correlation IDs.

If any required check fails, keep affected tenants in maintenance/drain and execute rollback/fallback (Section 6).

## 6) Rollback and fallback plan

Trigger rollback/fallback when:

- routing or tenant isolation cannot be proven,
- data integrity concerns persist after restore/failover,
- repeated critical smoke failures continue beyond agreed timeout,
- RPO breach exceeds approved business tolerance.

Rollback/fallback sequence:

1. Freeze writes and hold tenant cohorts in maintenance/drain.
2. Revert traffic to last known-good region/endpoint (if still healthy) **or** move to alternate DR target.
3. Revert app/secret/storage configuration to last known-good bundle.
4. Re-run validation checklist before reopening any tenant cohort.
5. Preserve evidence for post-incident review (timeline, commands, config versions, correlation IDs).

## 7) Communication plan

### Internal cadence

- Incident declaration: severity, scope, expected next update.
- Update every 15 minutes (SEV-1/2) or agreed cadence (SEV-3).
- Announce each gate: data recovered, app ready, cutover started, validation pass/fail, rollback decision.

### Tenant-facing updates

Provide:

- affected capabilities (login, writes, uploads, background jobs, admin actions),
- expected restoration window and next communication time,
- final restoration confirmation with validation summary and residual risks/workarounds.

### Closeout

Within one business day:

1. Publish incident summary with root cause, RTO/RPO achieved, and impact by tenant cohort.
2. Create follow-up actions for automation gaps, backup policy tuning, and runbook updates.
3. Schedule DR drill improvements based on observed bottlenecks.
