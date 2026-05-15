# Cross-Pod Invalidation Design (Tenant Runtime Changes)

## Purpose

Define how KMP propagates tenant runtime changes across all running pods so each pod promptly refreshes tenant-scoped state after control-plane updates.

## Implementation Status (Current)

Implemented in app code as an incremental **version-based cross-pod invalidation** mechanism:

- New platform table: `tenant_runtime_invalidation_versions` (monotonic version per tenant + global scope).
- Runtime checker: `TenantInvalidationService` polls platform DB versions (short interval) and is safe without new infra dependencies.
- Runtime applier: `TenantInvalidationApplier` clears process-local stale state (managed secret cache, ORM/model metadata cache, runtime config) when versions advance.
- Tenant registry cache now supports stale-bust using tenant/global versions.
- Mutation triggers now bump versions for:
  - tenant config updates (including db/service config paths),
  - schema migration completion,
  - tenant status transitions,
  - managed secret updates,
  - restore cutover database switch.

## Deferred / Extension Points

- Azure Service Bus event fan-out is **deferred** but wired as an extension seam:
  - `TenantInvalidationPublisherInterface` + `NullTenantInvalidationPublisher`.
  - `TenantInvalidationService` can publish invalidation events once broker plumbing is enabled.
- Current implementation uses polling/version reconciliation only (Option D fallback) to keep rollout safe and dependency-light.

## Invalidation Triggers

Invalidate when any of the following platform-side changes are committed:

1. **Tenant database config changes**
   - host/port/database/user/URL updates in `tenant_database_configs`
   - active connection role changes
2. **Restore cutover**
   - active database target switches after restore/verification
3. **Secret rotation**
   - database secret reference updates
   - tenant email/storage secret reference updates in `tenant_service_configs`
4. **Schema migration completion**
   - tenant schema version advances (single tenant or all-tenant migration runs)
5. **Tenant status changes**
   - active/maintenance/disabled/failed transitions that affect request eligibility

## Invalidation Targets

For the affected tenant slug/id, invalidate:

1. **Tenant registry cache**
   - host → tenant resolution state and alias lookups
2. **Connection config**
   - `tenant` datasource runtime settings and any stale pooled handle assumptions
3. **Table locator + schema metadata**
   - `TableRegistry` locator state
   - `_cake_model_` tenant-prefixed schema metadata cache
4. **Runtime config**
   - tenant-applied email/storage config assembled by `TenantRuntimeConfigService`
5. **Tenant context**
   - long-lived worker/loop context, including context accessors and per-tenant loop state

## Propagation Mechanism Options

### Option A — PostgreSQL `LISTEN/NOTIFY`
- **Pros:** low latency, simple payloads.
- **Cons:** tied to DB connectivity/session behavior; weaker fit for autoscaling/stateless app pods; operationally harder across mixed infra.

### Option B — Redis Pub/Sub
- **Pros:** familiar pattern, low latency.
- **Cons:** transient delivery by default; adds always-on Redis dependency if not already required platform-wide.

### Option C — Azure Service Bus Topic (**Recommended**)
- **Pros:** durable brokered delivery, native Azure fit, retry/dead-letter support, clean fan-out to all pods.
- **Cons:** slightly higher complexity/latency than in-memory pub/sub.

### Option D — Polling-only version checks
- **Pros:** simplest correctness fallback.
- **Cons:** higher staleness, unnecessary platform DB load, slower reaction.

## Recommended Azure Pattern

Use **Azure Service Bus Topic** (`tenant-runtime-invalidation`) with one subscription per app deployment/worker group and message fan-out by tenant key.

- Publisher emits event after successful platform transaction commit.
- Consumers in each pod refresh local tenant state on receipt.
- Include a monotonic tenant config version in payload:
  - `tenant_id`, `tenant_slug`, `change_type`, `version`, `occurred_at`, `correlation_id`.
- Keep **polling/version reconciliation fallback** (periodic check) to heal missed events.

## Consistency Model and Staleness Window

- **Model:** eventual consistency with version monotonicity per tenant.
- **Guarantees:**
  - no rollback to older versions once a higher version is observed;
  - idempotent replay handling.
- **Targets:**
  - p95 propagation under normal conditions: **<= 15s**
  - hard operational SLO: **<= 60s**
- During the window, old config may still serve requests; high-risk transitions (restore cutover/status disable) should gate with temporary `503`/maintenance semantics until minimum version observed.

## Failure Modes and Recovery

1. **Message publish failure**
   - mark operation incomplete and retry publish with backoff;
   - retain outbox/event log row for replay.
2. **Consumer failure or pod restart**
   - resume from broker checkpoint; rely on durable subscription.
3. **Poison payload**
   - dead-letter with alert and correlation id.
4. **Out-of-order delivery**
   - compare `version`; ignore older payloads.
5. **Missed/expired message**
   - periodic reconciliation: compare local known version to platform version and force refresh.
6. **Platform DB unavailable during refresh**
   - keep last known good state; retry and surface degraded-health metric.

## Observability and Alerts

Track and alert on:

- publish success/failure count by `change_type`
- end-to-end invalidation latency (`occurred_at` to applied timestamp)
- consumer lag / backlog depth per subscription
- dead-letter queue count and age
- reconciliation heals (count of missed-event recoveries)
- per-tenant version skew across pods (max-min)

Recommended alerts:

- dead-letter count > 0 for 5m
- p95 latency > 60s for 10m
- backlog age > 2x expected processing window
- repeated publish failure for same tenant/change type

## Phased Implementation Notes

### Phase 0 — Instrument-only foundation
- Add tenant config version field and emit structured logs/metrics for current refresh behavior.
- No behavior change yet; baseline propagation and refresh timings.

### Phase 1 — Event contract + publisher
- Define event schema and producer in control-plane write paths.
- Use transactional outbox pattern for reliable publish.

### Phase 2 — Pod consumer + local invalidation
- Add background consumer in app pods/workers.
- On event: clear tenant registry cache, reset tenant ORM/schema/runtime config state, and invalidate context markers.

### Phase 3 — Reconciliation and hardening
- Add periodic version reconciliation loop.
- Add dead-letter handling runbook and replay tooling.

### Phase 4 — Enforcement for sensitive transitions
- For restore cutover/status disable, enforce minimum tenant version before resuming normal traffic.

## Required Code Touchpoints

Primary existing touchpoints:

- `app/src/Services/Tenant/TenantRegistry.php`
  - host/alias resolution cache invalidation hooks
- `app/src/Services/Tenant/TenantConnectionFactory.php`
  - datasource swap + table locator/schema metadata clearing
- `app/src/Services/Tenant/TenantRuntimeConfigService.php`
  - tenant runtime email/storage config refresh/reset
- `app/src/Middleware/TenantResolutionMiddleware.php`
  - request-path application of refreshed tenant state
- `app/src/Services/Tenant/TenantContext.php`
  - version marker carried with context
- `app/src/Services/Tenant/TenantProvisioningService.php`
  - publisher calls on tenant config/status mutations
- `app/src/Services/Tenant/TenantMigrationService.php`
  - emit post-migration schema invalidation events
- `app/src/Controller/PlatformAdminController.php`
  - publish on operator-driven status/cutover updates

Likely new components:

- `app/src/Services/Tenant/TenantInvalidationPublisher.php`
- `app/src/Services/Tenant/TenantInvalidationConsumer.php`
- `app/src/Services/Tenant/TenantInvalidationApplier.php`
- platform outbox migration/table + replay command

## Deployment Notes (Azure)

- Provision one Service Bus Topic per environment.
- Use managed identity for publish/subscribe credentials.
- Configure retry + dead-letter policies centrally.
- Keep reconciliation cron/worker enabled even when broker is healthy.
