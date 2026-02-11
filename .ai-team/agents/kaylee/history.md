# Project Context

- **Owner:** Josh Handel (josh@liveoak.ws)
- **Project:** KMP — Membership management system for SCA Kingdoms. Handles members, officers, warrants, awards, activities, and workflow-driven approvals. ~2 years of active development.
- **Stack:** CakePHP 5.x, Stimulus.JS, MariaDB, Docker, Laravel Mix, Bootstrap, plugin architecture
- **Created:** 2026-02-10

## Learnings

<!-- Append new learnings below. Each entry is something lasting about the project. -->

### 2026-02-10: Backend Architecture (summarized from deep dive)

#### Service Layer
**DI-registered (Application::services()):** ActiveWindowManagerInterface→DefaultActiveWindowManager (no txn mgmt), WarrantManagerInterface→DefaultWarrantManager (owns txns, depends on AWM), CsvExportService, ICalendarService, ImpersonationService.

**Plugin DI:** OfficerManagerInterface→DefaultOfficerManager (Officers, depends on AWM+WM), AuthorizationManagerInterface→DefaultAuthorizationManager (Activities).

**Static registries:** NavigationRegistry (session-cached), ViewCellRegistry (route-matched), ApiDataRegistry.

**DI graph:** AWM ← WM ← OfficerManager

**ServiceResult pattern:** All service methods return `ServiceResult(success, reason, data)`. Never throw from services.

#### Key Model Patterns
- Entities extend `BaseEntity` (provides `getBranchId()`) or `ActiveWindowBaseEntity` (time-bounded)
- Tables extend `BaseTable` (cache invalidation, impersonation audit). Never extend `Table` directly.
- 34 entity classes, 33 table classes, 45 core migrations
- Behaviors: ActiveWindowBehavior (temporal), JsonFieldBehavior (JSON queries), PublicIdBehavior (anti-enumeration), SortableBehavior
- JSON columns: declare via `getSchema()->setColumnType()`, query via JsonFieldBehavior
- AppSettings: `StaticHelpers::getAppSetting(key, default, type, createIfMissing)`

#### Authorization Flow
Permission chain: Members → MemberRoles (temporal) → Roles → Permissions → PermissionPolicies → Policy classes. Three scopes: Global, Branch Only, Branch+Children. Cached via PermissionsLoader (`member_permissions{id}`). All policies extend BasePolicy (super-user bypass in `before()`).

#### Transaction Management
- **AWM:** Callers MUST wrap in own transaction. Uses `$table->getConnection()->begin()/commit()/rollback()`.
- **WarrantManager:** Manages own transactions. Do NOT wrap calls.
- **termYears parameter is actually MONTHS** (misleading name).

#### Key Gotchas
1. Plugin enable/disable via `AppSetting` `Plugin.{Name}.Active`
2. Navigation/permission caches must be cleared on changes
3. `BaseTable` auto-logs impersonation on save/delete
4. Member entity uses `LazyLoadEntityTrait` — beware N+1 queries
5. Email: always use `QueuedMailerAwareTrait::queueMail()`, format dates with `TimezoneHelper::formatDate()` first

#### Key File Paths
- Application: `app/src/Application.php`
- Base table/entity: `app/src/Model/Table/BaseTable.php`, `app/src/Model/Entity/BaseEntity.php`
- Permissions: `app/src/KMP/PermissionsLoader.php`
- Services: `app/src/Services/` (ActiveWindowManager/, WarrantManager/, ViewCellRegistry, NavigationRegistry)
- Policies: `app/src/Policy/` (37 files, BasePolicy)
- Config: `app/config/` (.env, plugins.php, routes.php)
- Plugins: `app/plugins/` (Activities, Awards, Officers, Waivers, Queue, GitHubIssueSubmitter, Bootstrap, Template)

📌 Team update (2026-02-10): Architecture overview documented — 6 plugins, service layer map, auth chain, 8 dangerous-to-change areas identified — decided by Mal
📌 Team update (2026-02-10): Frontend patterns documented — 81 Stimulus controllers cataloged, window.Controllers registration pattern, Turbo Drive disabled, plugin CSS must be manually added to webpack.mix.js — decided by Wash
📌 Team update (2026-02-10): Test suite audited — 20/26 controllers untested, 26/32 tables untested, 0 mailer tests, seed-based DB approach with transaction isolation — decided by Jayne
📌 Team update (2026-02-10): Test infrastructure attack plan created — Kaylee owns Phase 4.2b (fix production auth code bugs found by Jayne). No new features until testing is solid. — decided by Mal, Josh Handel
📌 Team update (2026-02-10): Auth triage complete — Kaylee's 2 CODE_BUG fixes (PermissionsLoader revoker_id filter, ControllerResolver string resource handling) verified. All 370 project-owned tests pass. — decided by Jayne, Kaylee
📌 Team update (2026-02-10): Auth strategy decided — standardize on TestAuthenticationHelper, deprecate old traits. ⚠️ Gap: authenticateAsSuperUser() does not set permissions — needs fix (Option 3: load real member entity in test transaction recommended). — decided by Mal

### 2026-02-10: Queue Plugin Review & Fixes (summarized)

Queue plugin is a KMP-owned fork of `dereuromark/cakephp-queue` v8 (MIT). Job lifecycle: `createJob()` → `requestJob()` (FOR UPDATE locking) → `runJob()` → done/failed. Email via `MailerTask` + `QueuedMailerAwareTrait::queueMail()`. Tasks can use `ServicesTrait` for DI.

**Review found 22 issues, all 18 fixable ones resolved:** 2 P0 security (command injection in terminateProcess — fixed with numeric validation; deleted ExecuteTask + 8 example tasks), 10 P1 (getFailedStatus prefix, cleanOldJobs timestamp, deprecated APIs, silent save failures, etc.), 6 P2 (entropy, policy docblocks, strict_types). MariaDB JSON pattern (text + setColumnType) is correct. All core tests pass (183 unit, 99 feature).

📌 Team update (2026-02-10): Documentation accuracy review completed — all 4 agents reviewed 96 docs against codebase

### 2026-02-10: Documentation Modernization — Backend Docs (summarized)

Fixed 13 docs across 12 files. Key corrections: DI container (removed phantom registrations), session config (timeout 30 not 240, PHPSESSID not KMP_SESSION), PermissionsLoader (`scoping_rule` not `scope`, `Permission::SCOPE_*` constants), WarrantManager events removed (fictional), warrant expiry corrected to `SyncActiveWindowStatusesCommand`, file paths `src/Services/` (plural). Created `docs/7.7-console-commands.md` (5 CLI commands). Added PublicIdBehavior, 11 service descriptions, warrant roster tables to docs.

### 2026-02-10: Workflow Engine Deep-Dive

#### Implementation Patterns
- **Recursive graph traversal:** `executeNode()` is the central dispatcher — adds node to `active_nodes`, creates execution log, dispatches to type-specific handler, then calls `advanceToOutputs()` which recursively calls `executeNode()` on targets. No queue-based async — all in-process.
- **Context as shared state:** All data flows through a JSON `context` object on `WorkflowInstance`. Trigger data at `$.trigger.*`, node outputs at `$.nodes.{nodeId}.result.*`, internal tracking at `$._internal.*`. Config values starting with `$.` are resolved at runtime via `CoreConditions::resolveFieldPath()`.
- **Two edge formats:** Definitions support both per-node `outputs` arrays and top-level `edges` arrays. Port aliases `"default"` and `"next"` are treated as equivalent.
- **Action/condition instantiation bypasses DI:** Services are created with `new $serviceClass()`, not from the DI container. `OfficerWorkflowActions` works around this by manually resolving DI via `Cake\Core\Container`.
- **Approval halts execution:** Approval nodes set instance to `STATUS_WAITING`. Resumption happens externally via `recordApproval()` in the controller, which calls `resumeWorkflow()` with `approved`/`rejected` port.
- **Static registries pattern:** `WorkflowActionRegistry`, `WorkflowConditionRegistry`, `WorkflowTriggerRegistry`, `WorkflowEntityRegistry` are all static registries populated at bootstrap via `WorkflowPluginLoader::loadFromPlugins()`.

#### Database Schema Details
- **7 tables:** `workflow_definitions`, `workflow_versions`, `workflow_instances`, `workflow_execution_logs`, `workflow_approvals`, `workflow_approval_responses`, `workflow_instance_migrations`
- **No `getBranchId()`** on any workflow entity — no branch-scoped authorization yet
- **JSON columns:** `definition`, `canvas_layout`, `context`, `active_nodes`, `error_info`, `approver_config`, `escalation_config`, `node_mapping`, `trigger_config` — all use CakePHP's JSON type via `getSchema()->setColumnType()`
- **Unique constraints:** `[workflow_definition_id, version_number]` on versions, `[workflow_approval_id, member_id]` on responses, slug uniqueness on definitions
- **Footprint behavior** only on `workflow_definitions` and `workflow_versions` (not on instances/logs)

#### Queue Task Behavior and Edge Cases
- **`WorkflowResumeTask`:** Creates fresh `DefaultWorkflowEngine` (no DI). 120s timeout, 3 retries. Edge case: if instance is no longer in WAITING state when task runs (e.g., already cancelled), `resumeWorkflow()` returns a ServiceResult failure but the task still counts as successful (doesn't throw).
- **`WorkflowApprovalDeadlineTask`:** Unique=true (no parallel runs). Finds all PENDING approvals past deadline, marks EXPIRED, resumes on `expired` port. Edge case: no transaction — if marking approval expired succeeds but resume fails, approval is expired but workflow stays stuck in WAITING.
- **Subworkflow completion gap:** `executeSubworkflowNode()` starts child workflow and sets parent to WAITING, but no mechanism exists for child completion to notify/resume the parent. Currently creates orphaned waiting parents.

#### Integration Patterns (Warrants/Officers)
- **WarrantWorkflowProvider** registers 3 triggers (`RosterCreated`, `Approved`, `Declined`) and 5 actions (`CreateWarrantRoster`, `ActivateWarrants`, `CreateDirectWarrant`, `DeclineRoster`, `NotifyWarrantIssued`)
- **OfficersWorkflowProvider** registers 3 triggers (`HireRequested`, `Released`, `WarrantRequired`), 4 actions (`CreateOfficerRecord`, `ReleaseOfficer`, `SendHireNotification`, `RequestWarrantRoster`), 3 conditions (`OfficeRequiresWarrant`, `IsOnlyOnePerBranch`, `IsMemberWarrantable`), and 1 entity (Offices)
- **Transaction ownership in actions:** Warrant actions manage their own `begin()/commit()/rollback()` transactions. The engine does NOT wrap node execution in transactions.
- **Email in actions:** `notifyWarrantIssued` checks `Email.UseQueue` app setting and either queues via `Queue.Mailer` or sends synchronously as fallback. Uses `TimezoneHelper::formatDate()` correctly.
- **Seed workflows:** Two seeded — `warrant-roster` (active, policy-based approval with `WarrantRosterPolicy::canApprove`) and `officer-hire` (inactive, complex branching with 11 nodes)

📌 Team update (2026-02-10): Workflow engine backend deep-dive complete — 12 improvement items identified (3 P0/P1 critical: no transaction on recordResponse, no duplicate instance prevention, updateEntity has no allowlist). Full analysis in decisions/inbox/kaylee-workflow-backend-review.md. — decided by Kaylee

📌 Team update (2026-02-10): Workflow engine review complete — all 4 agents reviewed feature/workflow-engine. Kaylee's DI bypass recs consolidated with Jayne's controller DI rec. Kaylee's approval transaction rec consolidated with Jayne's concurrency guard rec (P0). — decided by Mal, Kaylee, Wash, Jayne

### 2026-02-10: Warrant Roster ↔ Workflow Approval Sync

Implemented Mal's design for syncing workflow approval data to roster tables. Key changes:

- **Extracted `activateApprovedRoster()`** from `approve()` — handles warrant activation (status→CURRENT, expire overlaps, email notifications) independently of approval bookkeeping. Idempotent: skips if no pending warrants.
- **Added `syncWorkflowApprovalToRoster()`** — creates `warrant_roster_approval` records with dedup guard on (roster_id, approver_id). Increments `approval_count` atomically via raw SQL.
- **Refactored `approve()`** — transaction now only covers approval record + roster status. Activation runs post-commit via `activateApprovedRoster()`. Direct path still fully functional.
- **Modified `activateWarrants()` workflow action** — syncs `approvals_required` from workflow gate's `required_count`, syncs each approve response to roster, sets APPROVED, then calls `activateApprovedRoster()` instead of `approve()`.
- **Modified `declineRoster()` workflow action** — syncs any approve responses that occurred before the decline for audit trail.
- **Fixed `WarrantRosterApprovalsTable` validation** — removed ghost columns (`authorization_token`, `requested_on`, etc.) that don't exist in DB schema. Actual columns: `id`, `warrant_roster_id`, `approver_id`, `approved_on`.
- **Fixed `WarrantApproval` entity `$_accessible`** — matched to actual DB schema.

#### Key Design Decisions
- Transaction boundary change in `approve()`: roster status committed before activation. If activation fails, roster shows APPROVED but warrants stay PENDING. Acceptable because `activateApprovedRoster()` is idempotent.
- `syncWorkflowApprovalToRoster()` uses raw SQL for atomic increment (`COALESCE(approval_count, 0) + 1`) rather than get-and-save pattern.
- `declineRoster()` still calls `WarrantManager::decline()` which internally calls `declineWorkflowForRoster()` — harmless no-op on already-resolved workflow (caught by try-catch).

📌 Team update (2026-02-10): Warrant roster ↔ workflow sync implemented — 5 files changed, 2 new WarrantManager methods, workflow actions now sync approval data to roster tables before activating/declining. Backwards compatible with direct approval path. — decided by Mal, Kaylee

📌 Team update (2026-02-10): Warrant roster workflow sync implemented — decided by Mal, implemented by Kaylee

### 2026-02-10: Workflow Autocomplete Endpoints

Added 4 backend endpoints for the workflow designer UI's approval node configuration:

1. **RolesController::autoComplete()** — HTML autocomplete search by role name, `data-ac-value` = role name string. Template at `templates/Roles/auto_complete.php`.
2. **PermissionsController::autoComplete()** — Same pattern for permissions. Template at `templates/Permissions/auto_complete.php`.
3. **WorkflowsController::policyClasses()** — JSON endpoint scanning `app/src/Policy/` and `plugins/*/src/Policy/` for entity policies (excludes BasePolicy, *TablePolicy, *ControllerPolicy). Returns `[{class, label}]`.
4. **WorkflowsController::policyActions()** — JSON endpoint using ReflectionClass to list public `can*` methods on a given policy class. Validates class exists, ends with "Policy", and is in a Policy namespace. Returns `[{action, label}]`.

All endpoints use `skipAuthorization()` matching the existing Members autocomplete pattern. Routes: Roles/Permissions via fallback routing, policy endpoints via explicit `/workflows/policy-classes` and `/workflows/policy-actions` routes.

📌 Team update (2026-02-10): Workflow autocomplete endpoints implemented — 4 endpoints for roles, permissions, policy classes, and policy actions. Ready for Wash's frontend integration. — decided by Josh Handel, implemented by Kaylee
