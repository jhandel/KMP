# Platform Admin RBAC

Platform admin accounts now require a `role` and are constrained by server-side RBAC checks.

## Roles

| Role | Dashboard/Audit | Queue Controls (status/retry/cancel) | Tenant Provisioning | Secrets | Backup/Restore |
|---|---|---|---|---|---|
| `viewer` | ✅ | ❌ | ❌ | ❌ | ❌ |
| `operator` | ✅ | ✅ | ❌ | ❌ | ❌ |
| `provisioner` | ✅ | ✅ | ✅ | ❌ | ❌ |
| `security_admin` | ✅ | ❌ | ❌ | ✅ | ✅ |
| `break_glass` | ✅ | ✅ | ✅ | ✅ | ✅ |

## Enforcement Points

- `PlatformAdminController` checks capabilities for each action (including action-code requests).
- `TenantOperationGatewayService` verifies requester/approver roles before creating approved jobs.
- `tenant_rotate_db_secret` now requires `manage_secrets` capability (not generic tenant operations).
- `platform_admins.role` is persisted and validated by model rules.

## Hardened Admin Guardrails

In addition to role/capability RBAC, sensitive platform-admin actions now enforce:

- **Session freshness step-up:** action-code issuance/verification for sensitive actions requires a fresh authenticated session (15-minute window).
- **Break-glass-only actions:** managed secret updates and backup restore action-codes are restricted to `break_glass`.
- **Denied-attempt auditing:** role denials, stale-session denials, and break-glass denials are recorded in `platform_audit_events` with explicit `platform_admin.*_denied` action names.

## Operation Command Catalog UI/API

- HTML catalog: `/platform-admin/operations/catalog`
- JSON catalog: `/platform-admin/operations/catalog.json`
- Tenant doctor API: `/platform-admin/tenants/{slug}.json` now includes `doctor` and `doctor_findings` (guidance + remediation actions).
- Secret-rotation verification API (tenant scoped): `/platform-admin/tenants/{slug}/secret-rotation-status.json`
- Secret-rotation verification UI: Tenant detail page (`/platform-admin/tenants/{slug}`)
- Tenant doctor remediation UI: Tenant detail page (`/platform-admin/tenants/{slug}`), action-code step-up + queue submission via `/platform-admin/tenants/{slug}/doctor/{finding}/remediate/{action}`

Viewer-role admins can access the verification UI/API for read-only rotation evidence checks.

The catalog exposes each gateway-approved command with:

- command id + display name
- required/optional parameters and allowed-value hints
- target scope + allowed target modes
- approval policy + idempotency scope
- required capability and eligible platform-admin roles

Platform Admin enqueue endpoints use command-catalog preflight validation before gateway submission to surface
operator-friendly parameter/target/capability errors.

## CLI

- `bin/cake platform_admin:create --role <role>`
- `bin/cake platform_admin:seed --role <role>`

Defaults remain `break_glass` for operational continuity.
