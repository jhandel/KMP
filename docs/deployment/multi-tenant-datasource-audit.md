# Multi-tenant Datasource Audit

## Scope
- Audited direct `ConnectionManager::get(...)` usages in `app/src` plus critical runtime areas (`app/config/Migrations`) and test-only areas (`app/tests`, `app/plugins/*/tests`).
- Focused on unsafe tenant-domain use of `ConnectionManager::get('default')`.
- Snapshot generated from repository state in this worktree.

## Runtime inventory (non-test)

| File | Line | Call | Category | Keep/Fix | Rationale / Action |
|---|---:|---|---|---|---|
| `app/src/Services/BackupService.php` | n/a | `TenantConnectionAccessor::tenantDomain()` | tenant-domain | **Fix applied** | Uses tenancy-aware accessor instead of direct connection-manager calls. |
| `app/src/Services/Tenant/TenantMigrationService.php` | 140 | `ConnectionManager::get($connection)` | platform-domain | Keep | Migration service intentionally targets caller-selected connection (`tenant` / platform contexts). |
| `app/src/Services/Tenant/TenantProvisioningService.php` | 169 | `ConnectionManager::get('platform')` | platform-domain | Keep | Physical DB create checks must run against platform/control-plane DB. |
| `app/src/Services/Tenant/TenantProvisioningService.php` | 216 | `ConnectionManager::get('tenant')` | tenant-domain | Keep | Tenant reachability check should target tenant DB. |
| `app/src/Command/GeneratePublicIdsCommand.php` | 113 | `ConnectionManager::get('tenant')` | tenant-domain | Keep | Tenant-aware command already pinned to tenant connection. |
| `app/src/Command/ResetDatabaseCommand.php` | 70 | `ConnectionManager::get('tenant')` | tenant-domain | Keep | Tenant-aware reset command correctly scopes to tenant DB. |
| `app/src/Controller/HealthController.php` | 42 | `ConnectionManager::get('default')` | health/infra | Keep | Health probe is infra-level; checks app DB availability. |
| `app/src/Controller/TableAdminController.php` | n/a | `TenantConnectionAccessor::tenantDomain()` | tenant-domain | **Fix applied** | Tenant-domain access now goes through tenancy-aware accessor. |
| `app/config/Migrations/20241207172311_AddWarrantableToMembers.php` | 35 | `ConnectionManager::get($connName)` | legacy/compat | Keep | Migration-time adapter-based connection resolution is intentional and bounded to migration execution. |

## Test-only inventory

| File | Line(s) | Call | Category | Keep/Fix | Notes |
|---|---|---|---|---|---|
| `app/tests/bootstrap.php` | 109, 154, 328, 337 | `ConnectionManager::get('test')` | test-only | Keep | Test harness setup and DB bootstrap. |
| `app/tests/TestCase/BaseTestCase.php` | 171, 233 | `ConnectionManager::get('test')` | test-only | Keep | Shared test infrastructure. |
| `app/tests/TestCase/Services/AuthorizationEdgeCasesTest.php` | 237, 278 | `ConnectionManager::get('test')` | test-only | Keep | Fixture assertions. |
| `app/tests/TestCase/TestAuthenticationHelperTrait.php` | 93, 259 | `ConnectionManager::get('test')` | test-only | Keep | Test auth helpers. |
| `app/tests/TestCase/TestDatabaseTrait.php` | 31, 45, 72, 86 | `ConnectionManager::get('test')` | test-only | Keep | Test DB cleanup helpers. |
| `app/tests/TestCase/Command/TenantProvisioningCommandTest.php` | 29 | `ConnectionManager::get('test')` | test-only | Keep | Command test fixture setup. |
| `app/tests/TestCase/Support/SeedManager.php` | 78 | `ConnectionManager::get($connection)` | test-only | Keep | Test seed utility with explicit connection parameter. |
| `app/plugins/Waivers/tests/TestCase/Controller/GatheringWaiversControllerTest.php` | 528 | `ConnectionManager::get('test')` | test-only | Keep | Plugin controller test fixture assertions. |

## `default` usage decisions

| Location | Domain classification | Decision | Why |
|---|---|---|---|
| `BackupService::backupConnection()` fallback | legacy/compat | Keep | Supports environments without active tenant context; tenant path already preferred when context exists. |
| `HealthController::index()` | health/infra | Keep | Endpoint is an infra probe; not user tenant data-path logic. |
| `TableAdminController::index()` | tenant-domain + compat fallback | **Fixed** | Unsafe to force `default` in tenant requests; now uses `tenant` when context exists. |
| `DefaultWorkflowApprovalManager` transaction connection | tenant-domain | **Fixed** | Previously hardcoded `default`; now uses `WorkflowApprovals` table connection for tenant-safe transaction scope. |
| `DefaultWorkflowVersionManager` transaction connection | tenant-domain | **Fixed** | Previously hardcoded `default`; now uses table-bound connection (`WorkflowVersions`/`WorkflowInstances`). |
| `DefaultWorkflowEngine` transaction connection | tenant-domain | **Fixed** | Previously hardcoded `default`; now uses `WorkflowInstances` table connection. |

## Static-analysis guardrail (`add-default-connection-static-analysis`)

- Guard script: `app/bin/check-default-connection-usage.php`
- Config allow-list: `app/config/static-analysis/default-connection-allowlist.php`
- CI integration: runs from `app/bin/verify.sh` as **Default Connection Guardrail**.

The guardrail scans runtime PHP code under `app/src` and `app/plugins` for:

- `ConnectionManager::get('default')`
- `ConnectionManager::get("default")`

Any match outside the allow-list fails verification.

### Allow-list policy

Allowed categories are intentionally narrow and must be justified per file:

- `platform`: control-plane/bootstrap use where platform DB access is required.
- `health`: infrastructure readiness/liveness probes.
- `legacy`: compatibility fallback while tenant-aware paths remain primary.

### How to add/remove allow-list entries

1. Edit `app/config/static-analysis/default-connection-allowlist.php`.
2. Add or remove an entry keyed by app-relative path (for example `src/Controller/HealthController.php`).
3. Every entry must include:
   - `category` (`platform`, `health`, or `legacy`)
   - `justification` (short, concrete reason)
4. Run:
   - `cd app && php bin/check-default-connection-usage.php`
   - or full suite `cd app && bash bin/verify.sh`
5. If code was refactored and a previously allow-listed usage is gone, remove the stale entry reported by the guardrail.

## Required code changes for tenant safety

### Completed in this task
1. Replace tenant-domain hardcoded `ConnectionManager::get('default')` in workflow engine services with table-bound connections.
   - `DefaultWorkflowApprovalManager`
   - `DefaultWorkflowVersionManager`
   - `DefaultWorkflowEngine`
2. Make `TableAdminController` tenant-aware by preferring `tenant` connection when a tenant context is active.
3. Keep infra/compat `default` usages (`HealthController`, `BackupService` fallback) with explicit rationale documented.
4. Introduce `TenantConnectionAccessor` as the standard runtime accessor:
   - `tenantDomain()` for tenant-domain code paths.
   - `tenant()` / `platform()` / `default()` only when explicit domain selection is required.

### Remaining follow-up (optional hardening)
1. Decide whether `BackupService` should hard-fail without tenant context in strictly multi-tenant deployments (remove fallback to `default` if policy requires).
2. Decide whether health endpoint should probe both platform and tenant paths in environments where `default` is not tenant data-plane.
