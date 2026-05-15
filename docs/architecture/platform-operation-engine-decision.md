# ADR: Platform Operation Engine Decision

## Status

Accepted

## Context and Problem Statement

KMP now operates with strict host-based multi-tenancy and two explicit data planes:

- `platform` datasource for tenant registry and platform control-plane metadata.
- `tenant` datasource for tenant application data (including workflow tables).

We need a concrete engine for **platform operations** (tenant provisioning, activation/deactivation, maintenance transitions, migrations orchestration, backup/restore cutover checkpoints, and operator remediation runs).

The open choice was:

1. Build a platform-native operation state machine in the platform domain.
2. Reuse the existing generic workflow engine.

This decision must align with the datasource audit and current multi-tenant architecture boundaries.

## Evaluated Options

### Option A — Platform-native operation state machine (platform control-plane)

Build a dedicated operation state machine persisted in platform tables and executed by platform-scoped services/commands.

**Pros**
- Preserves hard boundary: platform operations stay in `platform` datasource.
- Matches audit guidance that tenant-domain workflow code is tenant-scoped.
- Clear operational semantics (idempotency keys, retry policy, compensation, operator checkpoints).
- Easier blast-radius control and incident forensics for control-plane actions.

**Cons**
- New implementation and maintenance surface.
- Some overlap with workflow-like concepts (states, transitions, logs).

### Option B — Reuse generic workflow engine

Adapt/extend existing workflow engine for platform operations.

**Pros**
- Reuses existing DSL/runtime concepts.
- Lower short-term feature-build effort if platform constraints are ignored.

**Cons**
- Workflow engine is currently tenant-domain oriented (`workflow_*` tables, tenant-scoped usage).
- Datasource audit explicitly fixed workflow engine transaction scope to table-bound tenant-safe behavior; this strengthens tenant-domain coupling, not platform control-plane suitability.
- Reuse would require significant rework to avoid leaking platform operations into tenant data plane or introducing complex dual-datasource behavior.
- Higher risk of violating platform/tenant isolation guarantees documented in multi-tenancy architecture.

## Decision and Rationale

**Decision: Choose Option A — a platform-native operation state machine.**

Rationale:

1. **Architectural boundary correctness (primary driver):** Platform operations are control-plane concerns and must persist/execute in `platform`, not tenant workflow tables.
2. **Datasource audit consistency:** Audit findings confirmed workflow engine fixes were about tenant safety via table-bound connections in tenant-domain services; that is evidence to keep generic workflow engine scoped to tenant workflows.
3. **Operational safety:** Platform operations need first-class idempotency, resumability, and compensating transitions tuned for infrastructure/stateful operations, not business approvals.
4. **Reduced coupling and clearer ownership:** Prevents entangling tenant workflow evolution with platform lifecycle orchestration.

## Consequences and Tradeoffs

### Positive
- Strong enforcement of platform-vs-tenant separation.
- Better reliability model for provisioning/maintenance/restore operations.
- Clearer observability and audit trail for platform admins.

### Negative
- Additional code paths, schema, and tests to maintain.
- Need to define/operate a second engine concept (business workflow vs platform operation state machine).

### Neutral/Managed
- Keep generic workflow engine for tenant business process automation only.
- Reuse supporting primitives where safe (logging patterns, retry helpers), but not shared runtime state tables.

## Migration Path from Current State

Current state: platform operations are command/service-driven with ad hoc status transitions; generic workflow engine remains tenant business-flow oriented.

Migration plan:

1. **Introduce platform operation schema (platform datasource)**
   - `platform_operations`
   - `platform_operation_events`
   - `platform_operation_locks` (or lease fields)
   - Include operation type, tenant id, state, idempotency key, attempt counters, correlation id, timestamps.

2. **Define minimal deterministic state model**
   - Canonical states, transition guards, idempotency, lock ownership, payload contracts, and child-job orchestration are defined in [Platform Operation State Machine](platform-operation-state-machine.md).

3. **Wrap existing platform commands/services as operation handlers**
   - `tenant:create`, `tenant:migrate`, `tenant:doctor`, maintenance/enable/disable, backup/restore cutover.
   - Handlers must be idempotent and emit structured events.

4. **Add operator surfaces**
    - Platform admin UI/API read model for operation status and event stream.
    - Retry/cancel/resume actions gated by platform-admin policy.
   - Current UI entry points: `/platform-admin` (global queue) and `/platform-admin/tenants/{slug}` (tenant queue) with state/tenant/sort filters and lock-staleness indicators.

5. **Progressive cutover**
   - New operations default to state machine path.
   - Legacy command path remains as fallback behind explicit flag during transition.
   - Remove fallback once parity and reliability targets are met.

6. **Guardrails**
   - Explicitly prevent platform operations from writing `workflow_*` tenant tables.
   - Add tests asserting platform operation persistence uses `platform` datasource only.

## Related References

- [Multi-Tenant Datasource Audit](../deployment/multi-tenant-datasource-audit.md)
- [Multi-Tenancy Architecture and Operations](../3.9-multi-tenancy.md)
- [Platform Operation State Machine](platform-operation-state-machine.md)
- [Deployment Overview](../deployment/README.md)
- [Deployment Migration Strategy](../deployment/deployment-migration-strategy.md)
- [Production Readiness Checklist](../deployment/production-readiness-checklist.md)
