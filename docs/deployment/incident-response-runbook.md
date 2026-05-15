# Incident Response Runbook (Multi-Tenant Platform + Admin Workflows)

Use this runbook for production incidents affecting tenant isolation, migrations, restore/cutover, secret rotation, and operations gateway/worker execution.

## Incident handling baseline (all scenarios)

1. Open an incident channel and assign **Incident Commander (IC)**, **Ops Lead**, **Comms Lead**, and **Scribe**.
2. Freeze non-emergency deploys and platform-admin writes until the IC authorizes changes.
3. Preserve volatile evidence before remediation that can overwrite signals.
4. Record every action with timestamp, actor, and correlation ID.

---

## 1) Tenant isolation breach suspicion

### Detection signals

- Tenant user reports visibility of another tenant's data.
- Unexpected host/tenant mapping changes in platform registry/audit logs.
- Cross-tenant identifiers observed in request traces, query logs, or exports.
- Sudden spike in authorization denials paired with unusual tenant context switches.

### Immediate containment steps

1. Activate incident severity (SEV-1 unless disproven quickly).
2. Disable affected tenant endpoints or place suspect tenants in maintenance/drain mode.
3. Suspend platform-admin tenant metadata edits except emergency rollback.
4. Revoke active admin sessions/tokens for potentially compromised operators.
5. Snapshot relevant registry/config state and DB audit trails.

### Investigation checklist

- Confirm scope: which tenants, hosts, routes, and time window are affected.
- Validate host-to-tenant routing records and recent alias/cutover changes.
- Review correlation IDs through gateway, worker, and DB logs.
- Check recent deploy/migration/config changes touching tenant resolution.
- Verify datasource boundaries (`platform` vs `tenant`) in implicated code paths.
- Determine whether this is exposure-only or includes write/corruption risk.

### Escalation and communication protocol

- Page platform on-call, security lead, and engineering manager immediately.
- Escalate to executive/legal/privacy contacts if data exposure is plausible.
- Issue internal status update every 30 minutes (or faster for SEV-1).
- Prepare tenant/customer communication with facts only (scope, mitigation, next update ETA).

### Recovery and rollback steps

1. Roll back incorrect routing/alias/registry changes to last known good state.
2. Redeploy or hotfix tenant-resolution logic only after containment validation.
3. Re-enable tenants gradually with smoke checks per tenant boundary.
4. Monitor for recurrence with targeted cross-tenant access detectors.

### Required audit evidence capture

- Incident timeline with role assignments and decision log.
- Host/tenant mapping diffs before and after rollback.
- Correlation ID sample showing suspect and corrected paths.
- Access-log extracts proving containment and post-fix isolation behavior.
- Customer/internal comms artifacts and approvals.

---

## 2) Failed deployment migration wave

### Detection signals

- Migration orchestration shows elevated failed tenant counts or stalled waves.
- `tenant:doctor` or schema compatibility checks failing post-wave.
- Error rates increase after release in tenant write/read paths.
- Gateway reports retries/timeouts tied to migration operation IDs.

### Immediate containment steps

1. Halt further migration waves (`hold`/`pause` in migration orchestration).
2. Stop non-essential deploy progression and block new schema-dependent features.
3. Isolate failed tenant cohort from additional automated retries.
4. Preserve migration logs, operation records, and release artifact identifiers.

### Investigation checklist

- Identify failing migration version(s), plugin path(s), and tenant cohorts.
- Confirm ordering: `platform:migrate` completed before `tenant:migrate`.
- Compare failed tenants by DB topology/version/feature flags.
- Inspect lock, timeout, and long-running query indicators.
- Validate rollback compatibility of app version against pre-wave schema.
- Determine if issue is migration logic, data shape, capacity, or orchestration.

### Escalation and communication protocol

- Escalate to release manager, DBA/on-call SRE, and owning application team.
- Publish incident state in release channel with tenant impact counts.
- Notify support of customer-facing risk and expected communication cadence.

### Recovery and rollback steps

1. Choose path: targeted fix-forward migration or release rollback per runbook criteria.
2. Run canary migration on representative tenant(s) before resuming waves.
3. Resume in reduced batch size with explicit health gates between waves.
4. Validate all previously failed tenants with `tenant:doctor` + smoke checks.

### Required audit evidence capture

- Migration operation IDs, batch/wave boundaries, and failure cohorts.
- Failing SQL/migration stack traces and remediation commits/releases.
- Hold/resume/rollback decisions with approval actors.
- Post-recovery validation report for all affected tenants.

---

## 3) Restore/cutover failure

### Detection signals

- Restore job completes with errors or tenant smoke checks fail after cutover.
- Tenant points to wrong database/backup generation.
- Elevated auth/data integrity errors immediately after cutover.
- Replication lag or backup artifact mismatch discovered during validation.

### Immediate containment steps

1. Keep tenant in maintenance mode; do not reopen traffic.
2. Stop further restore/cutover attempts for other tenants in the wave.
3. Lock target metadata to prevent concurrent mapping edits.
4. Preserve source/target backup identifiers and restore command outputs.

### Investigation checklist

- Verify tenant identifier, source backup, and target DB selection.
- Confirm checksum/backup integrity and restore completion markers.
- Validate schema and data health (`tenant:doctor`, core smoke tests).
- Inspect cutover sequencing (DNS/alias/registry cache invalidation timing).
- Check for partial writes during cutover window and reconcile if needed.

### Escalation and communication protocol

- Escalate to incident commander, DBA, and platform admin owner.
- Notify support and customer success for affected tenant(s) with ETA updates.
- Trigger leadership notification if RTO/RPO thresholds are exceeded.

### Recovery and rollback steps

1. Roll back to pre-cutover tenant mapping and known-good database.
2. Re-run validation on restored candidate before any second cutover attempt.
3. Execute cutover again only with explicit IC approval and additional observer.
4. Post-restore monitor application and DB health for stability window.

### Required audit evidence capture

- Backup IDs, restore targets, and checksum/integrity verification output.
- Cutover timeline (disable traffic, restore, validation, re-enable/rollback).
- Tenant data validation evidence (doctor + smoke test results).
- RTO/RPO measurements and exception approvals if exceeded.

---

## 4) Secrets rotation failure or compromise indication

### Detection signals

- Secret rotation verification endpoint reports failed or unknown status.
- Authentication failures spike immediately after key/credential rotation.
- SIEM or cloud provider alerts on leaked/abused credentials.
- Unexpected secret version changes without approved change request.

### Immediate containment steps

1. Declare security incident path if compromise is suspected.
2. Disable affected credentials/keys and block automated retries.
3. Restrict platform-admin secret operations to designated responders.
4. Rotate high-risk credentials on emergency cadence with staged rollout.

### Investigation checklist

- Identify impacted secret classes (DB, queue, API, storage, OAuth, etc.).
- Compare intended rotation plan vs actual version/state per tenant/environment.
- Review secret access logs for anomalous principals/locations/timestamps.
- Validate propagation and cache invalidation across pods/workers.
- Confirm dependent services can authenticate with new material.

### Escalation and communication protocol

- Page security on-call, platform ops, and service owners immediately.
- Engage legal/compliance if external exposure or regulated data risk exists.
- Communicate mitigation status and rotation progress at fixed intervals.

### Recovery and rollback steps

1. Complete emergency re-rotation for compromised/failed credentials.
2. Roll back only when required for service restoration and approved by security.
3. Re-validate all dependencies and tenant health after rotation completion.
4. Re-enable normal rotation pipelines after root-cause correction.

### Required audit evidence capture

- Secret inventory affected, version timeline, and revocation timestamps.
- Access/audit logs for suspect credential use and responder actions.
- Rotation verification artifacts (endpoint snapshots, health checks).
- Security decision records, approvals, and communication history.

---

## 5) Operations gateway/worker backlog or lock contention

### Detection signals

- Queue depth/backlog exceeds SLO and age of oldest job grows.
- Worker throughput drops while pending/running jobs accumulate.
- Lock lease expiry/churn or repeated lock-acquisition failures.
- Increase in operation timeouts, retries, or duplicate execution safeguards.

### Immediate containment steps

1. Freeze non-critical platform operations submissions.
2. Scale workers cautiously or rebalance workloads by queue/tenant class.
3. Extend/adjust lease settings only under IC-approved emergency change.
4. Capture queue/lock metrics and current operation snapshots before cleanup.

### Investigation checklist

- Identify whether bottleneck is CPU, DB locks, network dependency, or code regression.
- Inspect hot operations/tenants causing contention or starvation.
- Correlate backlog growth with deploy/migration/maintenance events.
- Verify idempotency and lock ownership behavior in failing jobs.
- Review dead-letter/retry patterns for poison job signatures.

### Escalation and communication protocol

- Escalate to platform ops on-call and owning service team.
- Notify release manager if linked to active deployment wave.
- Publish impact scope (affected operation types, tenant classes, ETA).

### Recovery and rollback steps

1. Cancel/requeue poison or stuck operations using documented gateway controls.
2. Apply targeted mitigation (query/index fix, worker scale, throttling, feature gate).
3. Drain backlog to safe threshold, then re-open submissions gradually.
4. Run post-recovery verification for lock stability and duplicate-prevention guarantees.

### Required audit evidence capture

- Queue depth/latency/throughput graphs across incident window.
- Lock acquisition/lease metrics and representative failing operation IDs.
- Cancel/retry/requeue actions with actor + reason.
- Validation report showing backlog recovery and stable steady-state.

---

## Post-incident closeout (all scenarios)

1. Publish incident report with root cause, contributing factors, and corrective actions.
2. Link evidence artifacts and comms transcript in the incident record.
3. Open follow-up tasks for runbook, monitoring, automation, and training gaps.
4. Review and update [Production Readiness Checklist](production-readiness-checklist.md) if new controls are required.
