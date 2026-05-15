# Production Readiness Checklist (Multi-Tenancy Hardening)

Use this go/no-go checklist before production cutover for the current multi-tenancy hardening wave.

## Scope and evidence set

This checklist is anchored to the current implementation-wave documents:

- [Multi-tenant Datasource Audit](multi-tenant-datasource-audit.md)
- [Platform Operation Engine Decision ADR](../architecture/platform-operation-engine-decision.md)
- [Platform Operation State Machine](../architecture/platform-operation-state-machine.md)
- [Operations Gateway Runbook](operations-gateway-runbook.md)
- [Deployment Migration Runbook](deployment-migration-runbook.md)
- [Deployment Migration Strategy](deployment-migration-strategy.md)
- [Tenant Database Topology](tenant-database-topology.md)
- [Updating & Rollback](updating.md)
- [Backup & Restore](backup-restore-runbook.md)
- [Secret Rotation Runbook](secret-rotation-runbook.md)
- [Incident Response Runbook](incident-response-runbook.md)
- [Platform Registry Disaster Recovery Runbook](platform-registry-dr-runbook.md)
- [Azure Disaster Recovery Runbook](azure-dr-runbook.md)
- [Azure PostgreSQL Flexible Server Production Guide](azure-postgres-flex-guide.md)
- [PgBouncer Validation](pgbouncer-validation.md)
- [3.9 Multi-Tenancy Architecture and Operations](../3.9-multi-tenancy.md)
- [CI quality gates workflow](../../.github/workflows/tests.yml)
- [Nightly/UAT verification workflow](../../.github/workflows/verification.yml)

---

## 1) Datasource boundary safety (GO/NO-GO)

- [ ] **GO**: `platform` and `tenant` boundaries are enforced for runtime code paths.
- [ ] **GO**: no unapproved tenant-domain `ConnectionManager::get('default')` usage appears.
- [ ] **GO**: allow-list exceptions are documented and justified (`platform`/`health`/`legacy`) in audit docs.
- [ ] **NO-GO** if any tenant-domain path still depends on hardcoded `default` without approved justification.

Evidence:
- `cd app && php bin/check-default-connection-usage.php`
- `cd app && bash bin/verify.sh` (includes guardrail)
- [Multi-tenant Datasource Audit](multi-tenant-datasource-audit.md)

## 2) Operation state machine and jobs (GO/NO-GO)

- [ ] **GO**: platform operations are implemented/validated as platform control-plane concerns.
- [ ] **GO**: operation lifecycle states and transitions match the canonical contract.
- [ ] **GO**: idempotency keys, lease/lock ownership, correlation IDs, and child-job aggregation rules are enforced.
- [ ] **NO-GO** if platform operations persist to tenant `workflow_*` runtime tables.

Evidence:
- [Platform Operation Engine Decision ADR](../architecture/platform-operation-engine-decision.md)
- [Platform Operation State Machine](../architecture/platform-operation-state-machine.md)
- [Operations Gateway Runbook](operations-gateway-runbook.md)

## 3) Deployment migration strategy and schema compatibility (GO/NO-GO)

- [ ] **GO**: migration order is `platform:migrate` then `tenant:migrate`.
- [ ] **GO**: all-tenant migration runs include `tenant:doctor` verification.
- [ ] **GO**: platform migrations remain in `config/PlatformMigrations/`; tenant migrations remain in `config/Migrations/` and plugin paths.
- [ ] **NO-GO** if platform schema changes are attempted through tenant migration paths.

Evidence:
- [Deployment Migration Runbook](deployment-migration-runbook.md)
- [Deployment Migration Strategy](deployment-migration-strategy.md)
- [Updating & Rollback](updating.md)
- [3.9 Multi-Tenancy Architecture and Operations](../3.9-multi-tenancy.md)
- [Tenant Database Topology](tenant-database-topology.md)

## 4) Operations gateway controls (GO/NO-GO)

- [ ] **GO**: platform admin console is reachable only from approved `PLATFORM_ADMIN_HOSTS`.
- [ ] **GO**: tenant hosts cannot serve platform admin routes.
- [ ] **GO**: privileged operations are gated to platform admin roles with auditable actions.
- [ ] **NO-GO** if platform admin surfaces are exposed on tenant hosts or untrusted domains.

Evidence:
- [3.9 Multi-Tenancy Architecture and Operations](../3.9-multi-tenancy.md) (Platform Admin Console section)
- [Operations Gateway Runbook](operations-gateway-runbook.md)

## 5) Postgres CI gate status (GO/NO-GO)

- [ ] **GO (minimum)**: MySQL-required quality gates pass and Postgres compatibility job passes for the scoped suite.
- [ ] **GO (target)**: Postgres job is enforced as a hard gate (not `continue-on-error`) once seed/test parity is complete.
- [ ] **NO-GO** if Postgres compatibility regresses for core-unit coverage used as the current gate.

Current status in-repo:
- `tests.yml` runs Postgres in matrix, but currently marks the Postgres leg as `continue-on-error`.
- Postgres currently validates `core-unit` compatibility, while full suite remains MySQL-seeded.

Evidence:
- [CI quality gates workflow](../../.github/workflows/tests.yml)
- [Nightly/UAT verification workflow](../../.github/workflows/verification.yml)
- [PgBouncer Validation](pgbouncer-validation.md)

## 6) Restore/cutover safeguards (GO/NO-GO)

- [ ] **GO**: backups are tenant-scoped, restore target tenant DB is explicitly confirmed.
- [ ] **GO**: restore/cutover runbook validates `tenant:doctor` and tenant smoke checks before re-enable.
- [ ] **GO**: one-tenant-at-a-time restore/move policy is followed.
- [ ] **NO-GO** if restore plans permit tenant data import into platform datastore.

Evidence:
- [Backup & Restore](backup-restore-runbook.md)
- [Platform Registry Disaster Recovery Runbook](platform-registry-dr-runbook.md)
- [Tenant Database Topology](tenant-database-topology.md)
- [3.9 Multi-Tenancy Architecture and Operations](../3.9-multi-tenancy.md)

## 7) Observability and audit requirements (GO/NO-GO)

- [ ] **GO**: operation correlation IDs are captured end-to-end for platform operations and child jobs.
- [ ] **GO**: audit trail exists for approval/cancel/retry/resume actions with actor identity.
- [ ] **GO**: DB/pool/latency/error metrics and rollback triggers are defined for change windows.
- [ ] **NO-GO** if critical control-plane actions cannot be reconstructed from logs/audit records.

Evidence:
- [Platform Operation State Machine](../architecture/platform-operation-state-machine.md) (correlation + event requirements)
- [3.9 Multi-Tenancy Architecture and Operations](../3.9-multi-tenancy.md) (platform audit review capability)
- [Azure PostgreSQL Flexible Server Production Guide](azure-postgres-flex-guide.md)

## 8) Runbook completeness (GO/NO-GO)

- [ ] **GO**: operators have current, tested runbooks for onboarding, migration, maintenance, restore, rollback, and troubleshooting.
- [ ] **GO**: each runbook has explicit validation steps and rollback criteria.
- [ ] **GO**: runbooks reflect the platform/tenant split and current control-plane operation model.
- [ ] **NO-GO** if any production-critical procedure is undocumented or references obsolete single-DB assumptions.

Required runbook set:
- [Deployment README](README.md)
- [Deployment Migration Runbook](deployment-migration-runbook.md)
- [Updating & Rollback](updating.md)
- [Backup & Restore](backup-restore-runbook.md)
- [Secret Rotation Runbook](secret-rotation-runbook.md)
- [Incident Response Runbook](incident-response-runbook.md)
- [Troubleshooting](troubleshooting.md)
- [Tenant Database Topology](tenant-database-topology.md)
- [Tenant Maintenance Runbook](tenant-maintenance-runbook.md)
- [Operations Gateway Runbook](operations-gateway-runbook.md)
- [Capacity Thresholds and Tenant Sharding Runbook](capacity-sharding-runbook.md)
- [Platform Registry Disaster Recovery Runbook](platform-registry-dr-runbook.md)
- [Azure Disaster Recovery Runbook](azure-dr-runbook.md)
- [Azure PostgreSQL Flexible Server Production Guide](azure-postgres-flex-guide.md)

---

## Final release decision

- [ ] **GO**: all sections above are checked with evidence links and date/owner sign-off.
- [ ] **NO-GO**: any unchecked mandatory item blocks production release.
