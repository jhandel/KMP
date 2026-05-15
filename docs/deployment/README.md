# Archived Self-Hosted Deployment Reference

KMP is moving to a managed multi-tenant hosting model. The standalone installer is retired for new deployments, but the self-hosted deployment knowledge is preserved here for legacy operators and maintainers.

## Status

| Area | Current status |
|------|----------------|
| Managed multi-tenant hosting | Primary deployment path |
| `kmp install` | Retired for new deployments |
| `bin/cake kmp_install` | Retired for new deployments |
| Self-hosted guides below | Archived reference |

## Managed Multi-Tenant Operations

New managed environments use host-based tenants and two separate database roles:

- `platform` stores global tenant registry/provisioning metadata only.
- `tenant` points at the selected tenant database for normal KMP data.

Before onboarding tenants, run `bin/cake platform:migrate` against the independent platform datastore. Do not point `PLATFORM_DATABASE_URL` or `PLATFORM_DB_DATABASE` at a tenant application database. See [Multi-Tenancy Architecture](../3.9-multi-tenancy.md) for tenant onboarding, migration, queue worker, and backup procedures.
For the platform operations orchestration architecture decision, see [Platform Operation Engine Decision ADR](../architecture/platform-operation-engine-decision.md).
For the concrete operation lifecycle contract used by platform operations, see [Platform Operation State Machine](../architecture/platform-operation-state-machine.md).
For safe execution of production admin/console commands in Azure multi-replica environments, see [Production Command Strategy](production-command-strategy.md).
For day-2 operational procedures for gateway submission, worker execution, status interpretation, retry/cancel handling, and audit evidence, see [Operations Gateway Runbook](operations-gateway-runbook.md).
For step-by-step tenant provisioning (DNS, DB/user, metadata/aliases, secrets, gateway migration, activation, rollback checkpoints), see [Tenant Onboarding Runbook](tenant-onboarding-runbook.md).
For planned tenant maintenance windows using drain + gateway/job orchestration and rollback gates, see [Tenant Maintenance Runbook](tenant-maintenance-runbook.md).
For credential/reference lifecycle operations (inventory, cadence, staged gateway rotation, verification, rollback, evidence), see [Secret Rotation Runbook](secret-rotation-runbook.md).
Platform Admin tenant pages now include a **Secret Rotation Verification** section and JSON endpoint (`/platform-admin/tenants/{slug}/secret-rotation-status.json`) for recent rotation outcome/confidence checks.
For cross-pod invalidation of tenant config/schema/secret/cutover changes in Azure multi-pod environments, see [Cross-Pod Invalidation Design](../architecture/cross-pod-invalidation-design.md).
For migration execution steps (preflight, orchestration, dashboard interpretation, hold/resume, stop/rollback comms), use the [Deployment Migration Runbook](deployment-migration-runbook.md). For migration policy/guardrails, use [Deployment Migration Strategy](deployment-migration-strategy.md).
For incident response procedures across tenant isolation, migration wave failures, restore/cutover incidents, secret compromise/rotation failures, and gateway/worker contention, use the [Incident Response Runbook](incident-response-runbook.md).
For production topology patterns and split decisions on Azure PostgreSQL Flexible Server, see [Tenant Database Topology](tenant-database-topology.md), [Azure PostgreSQL Flexible Server Capacity Model](azure-postgres-flex-capacity-model.md), [Capacity Thresholds and Tenant Sharding Runbook](capacity-sharding-runbook.md), and the [Azure PostgreSQL Flexible Server Production Guide](azure-postgres-flex-guide.md).
For platform control-plane outage recovery (registry metadata restore/failover, routing validation, rollback, and communications), see [Platform Registry Disaster Recovery Runbook](platform-registry-dr-runbook.md).
For Azure regional disaster recovery across app, networking, PostgreSQL Flexible Server, storage, and secrets dependencies, see [Azure Disaster Recovery Runbook](azure-dr-runbook.md).
For release go/no-go sign-off across boundaries, operations, migrations, Postgres CI, restore/cutover, observability, and runbooks, use the [Production Readiness Checklist](production-readiness-checklist.md).

## Legacy Self-Hosted Platforms

| Platform | Type | Database | SSL | Difficulty |
|----------|------|----------|-----|------------|
| Docker (Local/VPC) | Self-hosted | Bundled MariaDB or BYO | Caddy (auto Let's Encrypt) | ⭐ Easy |
| Fly.io | PaaS | Fly Postgres | Automatic | ⭐ Easy |
| Railway | PaaS | Managed MySQL / Redis (optional) | Automatic (Railway edge TLS, no extra proxy required) | ⭐ Easy |
| Azure | Cloud | Azure DB for MySQL | Automatic | ⭐⭐ Moderate |
| AWS | Cloud | RDS MySQL | ALB/ACM | ⭐⭐ Moderate |
| VPS (SSH) | Self-hosted | Bundled MariaDB | Caddy | ⭐⭐ Moderate |
| Shared hosting (no root) | Traditional web host | Provider-managed or external | Provider-managed | ⭐⭐ Moderate |

## Legacy Lifecycle Commands

| Command | Description |
|---------|-------------|
| `kmp install` | Retired; retained as historical reference only |
| `kmp update` | Legacy self-hosted maintenance |
| `kmp status` | Legacy self-hosted health and version checks |
| `kmp logs [-f]` | Legacy self-hosted log access |
| `kmp backup` | Legacy self-hosted database backup |
| `kmp restore <id>` | Legacy self-hosted restore |
| `kmp rollback` | Legacy self-hosted rollback |
| `kmp config` | Legacy self-hosted deployment configuration |
| `kmp self-update` | Legacy tool maintenance |

## Self-Hosted Image Channels

| Channel | Stability | Use Case |
|---------|-----------|----------|
| `release` | Stable | Production deployments (default) |
| `beta` | Pre-release | Testing upcoming features |
| `dev` | Development | Latest main branch |
| `nightly` | Nightly build | Bleeding edge |

## Legacy Self-Hosted Architecture

The KMP deployment system uses pre-built Docker images:

```
GitHub Releases → ghcr.io/jhandel/kmp:{tag} → Your infrastructure
```

Every app image release is:
- Multi-architecture (amd64 + arm64)
- Smoke-tested in CI before publishing
- Tagged with version, channel, and SHA digest
- Immutable once published

## Archived Guides

- [Docker/VPC Quick Start](quickstart-vpc.md)
- [Fly.io Quick Start](quickstart-fly.md)
- [Railway Quick Start](quickstart-railway.md)
- [Azure Quick Start](quickstart-azure.md)
- [Tenant Database Topology](tenant-database-topology.md)
- [Deployment Migration Runbook](deployment-migration-runbook.md)
- [Deployment Migration Strategy](deployment-migration-strategy.md)
- [Production Readiness Checklist](production-readiness-checklist.md)
- [Azure PostgreSQL Flexible Server Capacity Model](azure-postgres-flex-capacity-model.md)
- [Capacity Thresholds and Tenant Sharding Runbook](capacity-sharding-runbook.md)
- [Platform Registry Disaster Recovery Runbook](platform-registry-dr-runbook.md)
- [Azure Disaster Recovery Runbook](azure-dr-runbook.md)
- [Azure PostgreSQL Flexible Server Production Guide](azure-postgres-flex-guide.md)
- [Production Command Strategy (Azure Multi-Replica)](production-command-strategy.md)
- [Tenant Onboarding Runbook](tenant-onboarding-runbook.md)
- [Tenant Maintenance Runbook](tenant-maintenance-runbook.md)
- [Incident Response Runbook](incident-response-runbook.md)
- [PgBouncer Validation (CakePHP + Multi-Tenant PostgreSQL)](pgbouncer-validation.md)
- [AWS Quick Start](quickstart-aws.md)
- [Updating & Rollback](updating.md)
- [Backup & Restore](backup-restore-runbook.md)
- [Configuration Reference](configuration.md)
- [Troubleshooting](troubleshooting.md)
