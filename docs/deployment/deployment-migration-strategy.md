# Deployment Migration Strategy (Platform-First + All-Tenant)

This runbook defines the production deployment migration strategy for managed multi-tenant KMP. It aligns with the [Platform Operation Engine Decision ADR](../architecture/platform-operation-engine-decision.md) and the [Platform Operation State Machine](../architecture/platform-operation-state-machine.md).

## Scope and assumptions

- `platform` and `tenant` datastores are strictly separated.
- Platform operations are orchestrated as control-plane operations in `platform`, not tenant `workflow_*` tables.
- All-tenant migration runs execute under a parent operation (`tenant_migrate_all`) with per-tenant child operations.

## 1) Platform-first sequencing and hard gates

### Required sequence

1. Build + image rollout to app fleet (without enabling incompatible code paths).
2. Run `bin/cake platform:migrate` once against `platform`.
3. Start all-tenant migration orchestration (parent op + child tenant ops).
4. Run `bin/cake tenant:doctor --all-tenants` (or equivalent parent post-check phase).
5. Mark deployment migration phase complete only when hard gates pass.

### Hard gates (must pass before advancing)

- **Gate A: Platform schema gate**
  - `platform:migrate` exits successfully.
  - Platform migration lock is released cleanly.
  - Required control-plane tables/columns are present.
- **Gate B: Engine readiness gate**
  - Operation worker can acquire lease and transition `queued|approved -> running`.
  - Operation event writes succeed for correlation/audit.
- **Gate C: Tenant migration completion gate**
  - Every active tenant child op reaches `completed`, or explicit policy declares partial success acceptable for this rollout.
- **Gate D: Runtime safety gate**
  - No active tenant is left in schema-mismatched serving state.
  - Compatibility serving rules (section 6) are enforced.

If any hard gate fails, transition parent operation to `hold` or `blocked` and stop rollout progression.

### CLI orchestration command

Use the deployment orchestrator command for production-safe sequencing:

```bash
cd app
bin/cake deployment:migrate --wait --drive-worker --on-failure=hold
```

Behavior:
- runs `platform:migrate` first (hard Gate A),
- snapshots active tenants and enqueues `tenant_migrate` child jobs under one `tenant_migrate_all` parent operation,
- persists explicit parent-child linkage via `tenant_operation_jobs.parent_tenant_operation_job_id`,
- records per-tenant migration metadata (`schema_before`, `schema_after`, duration, result/error),
- records retry/hold metadata per child (`attempt_count`, `max_attempts`, retryability, hold/blocked state),
- in wait mode, either monitors externally processed child jobs or executes worker batches in-process (`--drive-worker`),
- supports resume after remediation via `--resume-parent-id=<parent_job_id>`.

## 2) Active-tenant discovery rules

At parent operation start, build an immutable target snapshot:

Include tenant when all are true:
- Tenant exists in platform registry.
- Tenant status is serviceable at start (`active`, or explicitly allowed maintenance target).
- Tenant has valid database config and passes preflight connectivity.

Exclude tenant when any are true:
- Status is `disabled`, `failed`, `deleted`, or onboarding-incomplete.
- Tenant is explicitly drained for unrelated incident response.
- Tenant has unresolved schema lock or unrecoverable preflight validation error.

Rules:
- Snapshot is immutable for that run (late-created tenants are not auto-added).
- Exclusions are emitted in parent result payload with machine-readable reason codes.
- Re-run (new idempotency key) is required to include newly active tenants.

## 3) Batch sizing and ordering strategy

Use controlled fan-out rather than unbounded parallelism.

- **Ordering**
  1. Canary tenants (small, low-risk internal tenants).
  2. Low-volume production tenants.
  3. Medium-volume tenants.
  4. Largest/high-risk tenants last.
- **Default batch/concurrency**
  - Start with max 5 concurrent tenant migrations.
  - Increase gradually (for example 5 -> 10 -> 20) only if SLO/error budget remains healthy.
- **Batch boundaries**
  - Evaluate health between batches (DB load, lock waits, error rate).
  - Pause progression automatically if observability thresholds are breached.

Ordering metadata (`priority_tier`, optional explicit order index) should be captured in child job payload to make execution deterministic and auditable.

## 4) Retry and resume semantics

All operations must be idempotent and lease-safe per state machine contract.

- Transient tenant migration failures: bounded retries (for example 3 attempts, exponential backoff with jitter).
- Non-retryable failures: set child to `failed` with `error.code`, `category`, and remediation hint.
- Stale lease recovery: another worker may resume from persisted checkpoint only.
- Resume behavior:
  - `hold -> approved -> running` when operator lifts hold.
  - `blocked -> approved -> running` only after dependency/precondition is remediated.

Parent completion policy must be explicit per deployment:
- `all_success_required` (recommended default for schema-changing releases).
- `partial_success_allowed` only with explicit release-manager approval and documented blast radius.

## 5) Failure hold behavior and tenant maintenance/drain policy

### Failure hold behavior

Place parent operation in `hold` when:
- Platform migration fails.
- Cross-tenant error rate exceeds threshold.
- Critical observability signal is missing/invalid.

Place specific children in `blocked` when:
- Tenant precondition is unmet (connectivity, lock contention, incompatible extension state).
- Tenant requires manual remediation before retry.

### Tenant maintenance/drain policy

- Before migrating a tenant, optionally set tenant to maintenance mode for disruptive migrations.
- Drain policy for disruptive migrations:
  - Stop new write traffic.
  - Allow in-flight requests/jobs to finish up to timeout.
  - Then execute tenant migration.
- If tenant migration fails after drain, keep tenant in maintenance until either:
  - successful resume/retry, or
  - rollback/cutover playbook is completed.

Never continue broad batch rollout while unresolved critical tenants remain in unsafe partial state.

## 6) Schema-version compatibility and serving rules

Define compatibility contract per release:

- `min_supported_schema_version`
- `target_schema_version`
- optional `max_supported_schema_version` for strict mode

Serving rules:
- Tenant schema `< min_supported_schema_version` -> do not serve normal traffic; return safe unavailable/maintenance response.
- Tenant schema in compatible window -> serve traffic.
- Tenant schema `> app-supported max` (if strict mode enabled) -> block serving and require app rollout alignment.

During rolling app deploys:
- App code must remain compatible with both pre- and post-migration schema until Gate C completes, or
- incompatible features must be feature-gated off until all active tenants complete migration.

## 7) Observability signals required during deployment

Deployment must surface these signals per parent run and per tenant child:

- **State machine health**
  - operation counts by state (`queued`, `running`, `hold`, `blocked`, `completed`, `failed`, `cancelled`)
  - transition latency (`queued->running`, `running->completed`)
- **Migration outcome**
  - child success/failure counts
  - retry counts and exhausted retries
  - error codes/categories distribution
- **Database safety**
  - migration lock wait time
  - deadlock/lock-timeout rates
  - DB CPU/IO saturation for platform and tenant shards
- **Serving compatibility**
  - tenants rejected due to schema mismatch
  - maintenance-mode tenant count and duration
- **Auditability**
  - correlation-id propagation across operation/event logs
  - actor trail for approve/hold/resume/cancel actions

Minimum alert triggers:
- any platform migration failure,
- parent in `hold`/`blocked` beyond SLA,
- tenant failure rate above configured threshold,
- missing heartbeat/lease-expiry recovery anomalies.

## Operational checklist

1. Confirm release compatibility window and feature flags.
2. Execute `platform:migrate` (Gate A).
3. Start parent all-tenant migration operation with deterministic tenant snapshot.
4. Monitor required signals; enforce automatic hold on critical threshold breach.
5. Resolve blocked tenants, resume, and complete rollout gates.
6. Run doctor checks and publish final parent result summary.

Example recovery flow after hold/failure:

```bash
bin/cake deployment:migrate --wait --resume-parent-id=123 --on-failure=hold
```

Platform Admin operators can also review/resume runs from `/platform-admin`:

- **Deployment Migration Dashboard** groups child tenant jobs under each `tenant_migrate_all` parent.
- Shows stage, child progress/counts, per-tenant schema/error summaries, and hold/failure badges.
- Resume is available for held/blocked parents with recoverable children (`POST /platform-admin/operations/{id}/resume`).
- JSON consumers can query the same view via `/platform-admin.json` and filters:
  `migration_state`, `migration_tenant_state`, `migration_correlation`, `migration_limit`.

## References

- [Platform Operation Engine Decision ADR](../architecture/platform-operation-engine-decision.md)
- [Platform Operation State Machine](../architecture/platform-operation-state-machine.md)
- [Multi-Tenancy Architecture and Operations](../3.9-multi-tenancy.md)
- [Deployment Overview](README.md)
