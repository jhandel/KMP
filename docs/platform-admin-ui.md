---
layout: default
---

# Platform Admin UI and Operations Guide

The platform admin UI is the production-safe control plane for KMP multi-tenancy. It is separate from tenant member accounts and tenant application routes, and it manages platform records, tenant registry metadata, queued tenant operations, approvals, audit records, backups, restore cutovers, and managed secrets.

This guide reflects the implemented routes in `app/config/routes.php`, the behavior in `PlatformAdminController`, the platform admin templates, and the controller tests.

## Access model

Platform admin routes are available only under `/platform-admin` on hosts listed in `PLATFORM_ADMIN_HOSTS`. The default development host is `admin.localhost`; `PLATFORM_ADMIN_REDIRECT_FROM_HOSTS` can redirect local tenant hosts such as `localhost` and `127.0.0.1` to the platform admin host instead of serving the console there.

| Route | Purpose |
| --- | --- |
| `/platform-admin` | Dashboard, tenant list, tenant health, deployment migration status, and operation queue |
| `/platform-admin/login` | Password login plus emailed verification code |
| `/platform-admin/logout` | End the platform admin session |
| `/platform-admin/change-password` | First-login or user-initiated password rotation |
| `/platform-admin/action-code` | Issue a sensitive-action verification code |
| `/platform-admin/tenants/create` | Create or update tenant registry and runtime metadata |
| `/platform-admin/tenants/{slug}` | Tenant detail, doctor findings, secrets, backup/restore, and tenant-scoped operations |
| `/platform-admin/operations/catalog` | Gateway command catalog |
| `/platform-admin/audit` | Platform audit search and review |

JSON projections are available for `/platform-admin.json`, `/platform-admin/tenants/{slug}.json`, `/platform-admin/tenants/{slug}/secret-rotation-status.json`, and `/platform-admin/operations/catalog.json`.

## Development login and email verification

The local reset scripts seed a break-glass platform admin account:

| Field | Value |
| --- | --- |
| Email | `platform-admin@localhost.test` |
| Password | `TestPassword` |
| Role | `break_glass` |

Platform admin accounts are stored in the platform datasource and are not tenant `members`. Login uses a password plus a 6-digit emailed verification code. In development, the code is delivered to Mailpit at `http://localhost:8025`. Privileged actions also require current-password confirmation and an emailed action code.

The seed and reset commands print the initial or reset password. Verification codes are not printed by the commands; they are sent by email:

```bash
bin/cake platform_admin:seed --email=platform-admin@example.org --display-name="Root Platform Admin"
bin/cake platform_admin:reset_password platform-admin@example.org
```

## Sessions and step-up checks

Platform admin sessions use the `__Host-KMPPlatformAdmin` cookie and expire after 8 hours. Login and action email codes expire after 10 minutes and allow 5 attempts.

Sensitive actions enforce an additional freshness check: the admin must have authenticated within the last 15 minutes before an action code can be issued or verified. Managed-secret updates and restore requests also require the `break_glass` role.

## Roles and capabilities

Roles are persisted on platform admin accounts and checked server-side before rendering or executing operations.

| Role | Capabilities |
| --- | --- |
| `viewer` | View dashboard, tenant detail, audit, command catalog, and read-only verification evidence |
| `operator` | Viewer capabilities plus tenant operations such as status changes, doctor checks, retry, cancel, and resume where allowed |
| `provisioner` | Operator capabilities plus tenant create/update and migration-style provisioning operations |
| `security_admin` | Viewer capabilities plus managed secrets, backup, and restore capabilities |
| `break_glass` | All capabilities, including destructive restore and managed-secret action-code requests |

Capability failures, stale-session denials, and break-glass denials are audited in `platform_audit_events`.

## Dashboard

`/platform-admin` combines several operational surfaces:

| Surface | What it shows |
| --- | --- |
| Tenant list | Tenant slug, display name, status, primary host, database target summary, and links to tenant detail |
| Tenant health | Recent operation failures, stale locks, and tenant-level operational health indicators |
| Deployment Migration Dashboard | Recent `tenant_migrate_all` parent jobs, platform migration stage, child counts by state, per-tenant result/error summaries, held or failed status, and resume affordances for held parents |
| Operation Queue | Filterable tenant operation jobs with state, tenant, operation name, correlation ID, parent/child relationship, timestamps, and role-aware controls |

In the Tenant Health panel, **tenant backlog** means non-terminal tenant operation jobs that still require work by the platform operation system. It counts jobs in `queued`, `approval_required`, `approved`, `running`, `hold`, or `blocked` states. A small backlog can be normal during deployments or maintenance windows; sustained growth, held jobs, blocked jobs, or backlog outside an active operation window usually means workers, approvals, tenant locks, or failing operations need operator attention.

The operation queue supports query filters for `state`, `tenant`, `correlation`, `sort`, and `limit`. Valid states are `queued`, `approval_required`, `approved`, `running`, `hold`, `blocked`, `completed`, `failed`, and `cancelled`. The HTML dashboard caps queue results between 10 and 100 rows.

The dashboard JSON endpoint supports deployment migration filters: `migration_state`, `migration_tenant_state`, `migration_correlation`, and `migration_limit` with a maximum of 25 migration runs.

## Tenant detail

`/platform-admin/tenants/{slug}` is the primary per-tenant operations page. It includes:

- tenant registry details and database/runtime metadata,
- tenant-scoped operation queue and stale-lock indicators,
- doctor checks with remediation guidance,
- status controls,
- managed secret update forms,
- backup creation,
- restore-to-new-database and cutover forms,
- secret rotation verification status and rollback guidance.

Tenant detail JSON includes the tenant projection, doctor checks, doctor findings with remediation actions, operation queue data, and action availability. Secret rotation polling uses `/platform-admin/tenants/{slug}/secret-rotation-status.json` and requires an authenticated platform admin session.

## Doctor findings and remediation

Doctor findings provide operator-facing guidance and, where implemented, queue safe remediation through the command gateway:

| Finding | Guidance/action behavior |
| --- | --- |
| `tenant_status` | If not active, can queue `tenant_status` to set the tenant active |
| `schema_version` | If drift is detected, can queue `tenant_migrate` |
| `database_config` | No automatic remediation; update database metadata through approved provisioning workflows |
| `database_reachable` | Investigate credentials or network path; can queue `tenant_doctor` to rerun checks |
| `required_app_settings` | Seed missing settings through migration/provisioning workflows; can queue `tenant_migrate` or `tenant_doctor` depending on the check |

Doctor remediation posts to `/platform-admin/tenants/{slug}/doctor/{finding}/remediate/{remediation}` and uses the same action-code, capability, approval, and command-catalog preflight checks as other gateway operations.

## Operation queue controls

The UI exposes controls according to job state, required capability, approval policy, and requester/approver separation:

| Control | Available when |
| --- | --- |
| Retry | Job is `failed`, `cancelled`, or `blocked`, and the admin has the required capability |
| Cancel | Job is `queued`, `approval_required`, `approved`, `running`, `hold`, or `blocked`, and the admin has the required capability |
| Resume | Used for resumable held/blocked parent operations, including deployment migration parents |
| Approve/reject | Job is `approval_required`, the admin has the required capability, has not already decided, and is not the requester when requester separation is required |

Terminal completed, failed, and cancelled jobs cannot be cancelled. Completed and already-queued jobs cannot be retried.

## Command catalog

`/platform-admin/operations/catalog` lists the curated gateway commands that can be invoked from the platform UI. It intentionally replaces arbitrary production shell access with parameterized, audited operations.

| Command | Purpose | Required capability | Approval policy |
| --- | --- | --- | --- |
| `tenant_status` | Set tenant lifecycle status | Operate tenants | 1 approval |
| `tenant_migrate` | Run tenant migrations | Provision tenants | 2 approvals with requester separation |
| `tenant_doctor` | Run tenant doctor checks | Operate tenants | No approval |
| `tenant_rotate_db_secret` | Rotate and verify tenant DB secret reference | Manage secrets | 2 approvals with requester separation |

The catalog shows command ID, display name, parameters, allowed target modes, idempotency scope, required capability, eligible roles, and approval requirements. JSON consumers can use `/platform-admin/operations/catalog.json` for the same command metadata and per-admin invocation eligibility.

## Tenant creation and updates

`/platform-admin/tenants/create` creates or updates tenant registry records, primary host and aliases, primary database configuration, email metadata, and storage metadata. The form is capability-gated to provisioning roles and sensitive submissions require action-code verification. Secret values are stored through platform-managed references instead of being displayed after save.

The development reset scripts seed a local `localdev` tenant with aliases for `localhost`, `127.0.0.1`, `kmp.localhost`, and `amp.localhost`.

## Managed secrets and rotation verification

Database credential updates submitted from the tenant detail page are queued as `tenant_rotate_db_secret` operations. The worker records staged rotation progress, updates the tenant database config secret reference, invalidates tenant connection/runtime caches, verifies connectivity, and rolls back to the previous reference if verification fails.

The tenant detail page displays secret rotation verification evidence, including success or rollback state and recommended follow-up such as running tenant doctor and smoke checks. It does not reveal secret values.

## Backup, restore, and cutover

Backup creation is available from tenant detail to admins with the backup capability. Restore posts to `/platform-admin/tenants/{slug}/restore` and requires break-glass action-code verification. Restore imports into a new database name, rejects database-name collisions with current tenant configs or recent restore targets, validates the restored database, and cuts over only through the recorded operation path.

Restore and cutover operations are queued, audited, and tied to tenant operation jobs rather than browser request lifetimes.

## Audit review

`/platform-admin/audit` lists platform audit events with hash-chain metadata. The page supports filtering by correlation ID and operation ID, and links audit events back to related tenant operation jobs and tenants when those relationships are available.

Use audit correlation IDs when investigating a command-gateway run, approval decision, denied access attempt, secret change, backup, restore, or deployment migration.

## Production posture

The platform admin UI is intentionally a constrained operations gateway. It does not provide phpMyAdmin-style SQL, arbitrary shell execution, or free-form command input. Production console-level work should be added as command-catalog operations with RBAC, parameter validation, approvals, idempotency, output capture, and audit correlation.
