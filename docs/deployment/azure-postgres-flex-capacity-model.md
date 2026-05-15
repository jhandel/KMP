# Azure PostgreSQL Flexible Server Capacity Model (KMP Multi-Tenant)

This guide defines an **operational capacity model** for running multiple KMP tenants on Azure Database for PostgreSQL Flexible Server with Azure Container Apps.

It is aligned with the nightly Azure deployment shape in [`deploy/azure/README.md`](../../deploy/azure/README.md): web replicas plus scheduled/queue jobs sharing one PostgreSQL server.

> **Validation requirement:** Numeric values below are production starting points, not universal constants. Validate with tenant traffic profiles and load tests before locking SLO/SLA commitments.

## 1) Inputs and planning assumptions

Collect these inputs before sizing:

- `T`: active tenants on this PostgreSQL server.
- `R_web`: steady-state web replica count in Container Apps.
- `R_worker`: concurrent queue worker/job replicas that hold DB connections while running.
- `P_web`: DB pool size per web replica (app setting).
- `P_worker`: DB pool size per worker/job replica.
- `C_tenant_peak`: expected peak concurrent in-flight DB requests per tenant.
- `H`: safety headroom factor (recommend 20-30%; use `0.25` initially).

Recommended first-pass assumptions when real telemetry is not yet available:

- `R_web = 2` (HA baseline).
- `R_worker = 1` minimum (plus any burst workers).
- Queue/sync jobs are short-lived but must still be included when they overlap with traffic peaks.

## 2) Connection budget model

### 2.1 Server-level budget

Use PostgreSQL `max_connections` as a hard cap, and reserve part of it for admin/maintenance.

- `DB_reserved = max(15, 0.1 * max_connections)`
- `DB_usable = max_connections - DB_reserved`

### 2.2 App-side demand

Worst-case app demand if every pool fills:

- `DB_app_demand = (R_web * P_web) + (R_worker * P_worker)`

Enforce:

- `DB_app_demand <= DB_usable * (1 - H)`

This preserves emergency capacity during failover spikes, deploys, or long-running maintenance.

### 2.3 Tenant-concurrency check

Use a tenant-level sanity check so one server is not overloaded by aggregate demand:

- `Tenant_demand = T * C_tenant_peak`
- Require: `Tenant_demand <= DB_app_demand`

If `Tenant_demand` is consistently above this line, reduce tenant density per server or raise server/app capacity.

## 3) Worked sizing example (adjust to your environment)

Example (illustrative):

- `max_connections = 400`
- `DB_reserved = 40` (10%)
- `DB_usable = 360`
- `H = 0.25` → target usable for app pools: `270`
- `R_web = 3`, `P_web = 60`
- `R_worker = 2`, `P_worker = 20`

Then:

- `DB_app_demand = (3*60) + (2*20) = 220` ✅ under target `270`

If `C_tenant_peak = 3`, server tenant estimate:

- `T_max_by_concurrency = floor(220 / 3) = 73 tenants`

Apply an operational derate (20%) for uneven tenant behavior:

- `T_operational_max ≈ 58 tenants`

Use this as an onboarding ceiling for that shard until telemetry supports higher density.

## 4) Max tenants per server and shard split triggers

Use **all** gates below; whichever trips first determines shard split timing.

### 4.1 Tenant-count gate

- Set a per-server `T_operational_max` from the formulas above.
- Trigger split planning at `>= 80%` of `T_operational_max`.
- Stop onboarding new tenants at `>= 90%`.

### 4.2 Connection-pressure gate

Trigger split when either condition persists for 15+ minutes during normal peaks:

- Active connections `> 70%` of `max_connections`, or
- Connection acquisition wait/timeout errors appear in app logs.

### 4.3 Performance/noisy-neighbor gate

Trigger split (or strict throttling) when:

- DB CPU is `> 75%` sustained for 30+ minutes, or
- p95 query latency is 2x baseline for 30+ minutes, or
- One tenant contributes disproportionate load (for example >35% of DB time/IO) repeatedly.

## 5) Noisy-neighbor controls

Apply these controls per tenant role/user where possible.

### 5.1 Timeouts and guards

Use explicit statement/lock safeguards (values below are **example defaults**, must be validated):

- `statement_timeout`: start at 15s for interactive paths.
- `lock_timeout`: start at 5s.
- `idle_in_transaction_session_timeout`: start at 60s.

These should be tuned after observing slow-query patterns and queue workloads.

### 5.2 Role-level connection limits

Set per-tenant DB role limits so one tenant cannot consume all sessions:

- Example policy: `role_connection_limit = min( max(10, 2 * C_tenant_peak), floor(DB_usable / 4) )`

Validate role limits against migration jobs, backups, and restore workflows before enforcing.

### 5.3 Monitoring thresholds

Alert when any tenant crosses agreed guardrails (examples):

- tenant active connections > 60% of its role limit for 10+ min,
- repeated statement timeout errors,
- sudden step-change in query volume (for example >2x 7-day baseline).

## 6) Container Apps tie-in (replicas and jobs)

PostgreSQL capacity and Container Apps scaling must be changed together:

1. Before increasing `R_web` or `R_worker`, recompute `DB_app_demand`.
2. Keep queue/job concurrency bounded so cron windows do not exhaust connection headroom.
3. Treat scheduled jobs (migrate/reset/sync/queue bursts) as overlapping load unless run in isolated windows.

Operational rule:

- **Replica increase is allowed only when** projected `DB_app_demand` remains within the headroom policy.

## 7) Scale-up vs split playbook

Use this decision path during incidents or planned growth.

### Scale up the PostgreSQL server when

- Tenant count is still below shard threshold, and
- Pressure is broad-based (many tenants), and
- You need immediate relief with minimal tenant movement.

### Split tenants to another server when

- Tenant-count or noisy-neighbor gates are repeatedly exceeded, or
- A few high-activity tenants dominate load, or
- You need predictable isolation boundaries for growth.

### Execution summary

1. Recompute connection budget from current replica/job settings.
2. Check trigger gates (tenant count, connections, latency/CPU, tenant skew).
3. If broad pressure: scale up first, then re-baseline.
4. If skewed pressure or near density ceiling: move least-coupled/highest-load tenants to a new shard.
5. Update onboarding policy (`T_operational_max`) and runbook thresholds after each change.

## 8) Required validation checklist

Before declaring a shard policy production-ready:

- Run load tests that include web + queue overlap.
- Verify timeout settings do not break known long-running maintenance tasks.
- Verify role connection limits across migration, backup, restore, and tenant-admin operations.
- Confirm alerting captures early warning before user-visible errors.
- Document current `T_operational_max` and split trigger thresholds in your environment runbook.

## 9) Operational runbook handoff

Use this model to decide **when** to split. Use the runbook below for **how** to execute tenant moves safely:

- [Capacity Thresholds and Tenant Sharding Runbook](capacity-sharding-runbook.md)
- [Tenant Database Topology](tenant-database-topology.md)
