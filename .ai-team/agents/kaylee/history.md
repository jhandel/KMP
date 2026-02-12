# Project Context

- **Owner:** Josh Handel (josh@liveoak.ws)
- **Project:** KMP — Membership management system for SCA Kingdoms. Handles members, officers, warrants, awards, activities, and workflow-driven approvals. ~2 years of active development.
- **Stack:** CakePHP 5.x, Stimulus.JS, MariaDB, Docker, Laravel Mix, Bootstrap, plugin architecture
- **Created:** 2026-02-10

## Learnings

<!-- Append new learnings below. Each entry is something lasting about the project. -->


### 2026-02-10: Backend Architecture & Codebase Knowledge (summarized)

**Service layer:** DI services return `ServiceResult(success, reason, data)`. AWM: no txn mgmt (caller wraps). WM: manages own txns (don't wrap). `termYears` is actually months. Static registries: NavigationRegistry, ViewCellRegistry, ApiDataRegistry.

**Model patterns:** Entities extend `BaseEntity`/`ActiveWindowBaseEntity`, tables extend `BaseTable`. JSON via `setColumnType()` + `JsonFieldBehavior`. Email: always `queueMail()` + `TimezoneHelper::formatDate()`.

**Auth chain:** Members→MemberRoles→Roles→Permissions→Policies. Three scopes. Cached via PermissionsLoader. All policies extend BasePolicy.

**Queue plugin:** Owned fork. Fixed 18 issues (2 P0 security). 13 backend docs fixed/created.

### 2026-02-10: Workflow Engine Deep-Dive (summarized)

Recursive graph traversal engine, 7 tables, 4 static registries, 9 node types. Context: `$.trigger.*`, `$.nodes.{id}.result.*`. DI bypassed everywhere (uses `new`). No txn wrapping. Two seed workflows (warrant-roster, officer-hire). Key concerns: subworkflow orphans, deadline race conditions, fork/join deadlocks.

### 2026-02-10: Warrant Roster Sync + Autocomplete + Action Schema (summarized)

**Roster sync:** Extracted `activateApprovedRoster()`, added `syncWorkflowApprovalToRoster()` with dedup + atomic SQL increment. Updated workflow actions for activate/decline paths.

**Autocomplete endpoints:** 4 new — roles, permissions, policyClasses, policyActions. All `skipAuthorization()`.

**Action schema phases 3-5:** `APPROVAL_OUTPUT_SCHEMA` constant, `approvalOutputSchema`+`builtinContext` in registry endpoint, publish-time param validation, provider `description`/`default` enrichment.

📌 Team updates (2026-02-10): Architecture documented, frontend patterns cataloged, test suite audited, auth triage complete (370→463 tests pass), queue owned, docs reviewed, workflow engine reviewed, roster sync implemented.
📌 Team update (2026-02-11): Action Schema complete across all 5 phases. 459 tests pass.

### 2026-02-11: Bug Fixes & Feature Work (summarized)

**Approval node context fix:** `resumeWorkflow()` now populates `$context['nodes'][$nodeId]` with APPROVAL_OUTPUT_SCHEMA fields. All node types must write to `$context['nodes'][$nodeId]` for the variable picker to resolve `$.nodes.<nodeId>.*`.

**TriggerDispatcher DI fix:** `DefaultOfficerManager` was calling `TriggerDispatcher::dispatch()` statically — fatal error when officer-hire workflow active. Fixed by constructor DI injection matching `DefaultWarrantManager` pattern. **Rule:** TriggerDispatcher must always be injected via DI.

**Grid date timezone fix:** `dataverse_table.php` `case 'date':` now uses `$this->Timezone->date()`. Date-range filters convert to UTC via `TzHelper::toUtc()`. Warrants system views use kingdom-timezone today. Expression tree date handling in `GridViewConfig.php` does NOT do timezone conversion (potential follow-up).

**Duplicate email fix:** Added `bool $sendNotifications = true` to `activateApprovedRoster()`. Workflow action passes `false` since workflow has own notify step. **Pattern:** When a service sends notifications AND a workflow wraps it with its own notify step, service must allow suppressing notifications.

**App settings endpoint:** `GET /workflows/app-settings` on `WorkflowsController`. JSON pattern: `setClassName('Json')` + `setOption('serialize', [...])`.

**Universal resolveParamValue():** 4 resolution paths: null→default, scalar→as-is, `$.path`→resolveContextValue, `{type}` object→fixed/context/app_setting. `resolveRequiredCount()` now delegates to it. Action and condition node params resolved through it. Uses `TableRegistry` for app_setting (no cache layer during execution).

**Approvals grid backend:** `ApprovalsGridColumns.php` + `approvalsGridData()` on WorkflowsController with DataverseGridTrait. Pending tab: get eligible IDs via `getPendingApprovalsForMember()` then `WHERE id IN (...)`. Decisions tab: `innerJoinWith('WorkflowApprovalResponses')`. **Pattern:** For eligibility-backed grids, pre-filter IDs then let DataverseGridTrait paginate.

📌 Team updates (2026-02-11): Approval context fix, TriggerDispatcher DI, grid timezone, duplicate emails, app-settings endpoint, universal resolveParamValue, approvals grid backend. 463 tests pass.
📌 Team update (2026-02-11): Collapsible sidebar uses body class toggle pattern — decided by Wash
📌 Team update (2026-02-11): Resizable config panel in workflow designer (300px–60vw, localStorage persistence) — decided by Wash

### 2026-02-12: Warrant Roster Migration Research (summarized)

Mapped `warrant_rosters`/`warrant_roster_approvals` → `workflow_instances`/`workflow_approvals`/`workflow_approval_responses`. 34 rosters (29 Approved, 5 Pending), 19 approvals. ~107 rows to migrate. Key blockers: no decline actor records, execution_log FK requirement, entity docblock mismatch (`description`/`planned_start_on`/`planned_expires_on` don't exist in DB). Cannot drop roster tables due to `warrants.warrant_roster_id` FK.

📌 Team update (2026-02-12): Completed warrant roster → workflow migration research. 34 rosters, 19 approvals to port. Key blockers: no decline actor records, execution_log FK requirement, dual-path approval code. Full analysis in decisions/inbox/kaylee-warrant-migration-research.md. — researched by Kaylee

📌 Team update (2026-02-11): Warrant roster migration → Forward-Only (Option B). No historical data migration. Sync layer stays. Revisit in 6–12 months. — decided by Mal, Kaylee

### 2026-02-12: Activities Workflow Backend (Phase 1+2)

**Phase 1 — Foundation:**
- Created `ActivitiesWorkflowProvider` (2 triggers: AuthorizationRequested, AuthorizationRetracted; 5 actions: CreateAuthorizationRequest, ActivateAuthorization, HandleDenial, NotifyApprover, NotifyRequester). Follows WarrantWorkflowProvider pattern exactly.
- Created `ActivitiesWorkflowActions` with `WorkflowContextAwareTrait`, constructor-injects `AuthorizationManagerInterface` via DI. Each method resolves config via `resolveValue()`, delegates to service, catches/logs errors. Follows WarrantWorkflowActions pattern.
- Created `AuthorizationApproverResolver` for DYNAMIC approver type. Uses `Activity::getApproversQuery()` via `permission_id`. Supports `current_approver_id` (serial pick-next) and `exclude_member_ids`.
- Added `activate()` method to `AuthorizationManagerInterface` and `DefaultAuthorizationManager`. Extracts core logic from `processApprovedAuthorization()` — sets APPROVED status, starts ActiveWindow, assigns role. Does NOT send notifications (workflow has own notify step).
- Registered `ActivitiesWorkflowProvider::register()` in `WorkflowPluginLoader::loadCoreComponents()`.
- Injected `TriggerDispatcher` into `DefaultAuthorizationManager` via DI. Dispatches `Activities.AuthorizationRequested` after successful `request()` and `Activities.AuthorizationRetracted` after successful `retract()`. Non-critical — wrapped in try/catch.
- Registered `ActivitiesWorkflowActions` in `ActivitiesPlugin::services()` with `AuthorizationManagerInterface` argument.

**Phase 2 — Serial Pick-Next Engine Enhancement:**
- Extended `WorkflowApprovalManagerInterface::recordResponse()` with optional `?int $nextApproverId` parameter.
- In `DefaultWorkflowApprovalManager::recordResponse()`: when `serial_pick_next` is true in `approver_config` AND decision is approve AND `approved_count < required_count`, updates `approver_config.current_approver_id`, appends to `approval_chain` array, adds to `exclude_member_ids`, returns `{approvalStatus: 'pending', needsMore: true}` without resolving the approval.
- Updated `isMemberEligible()` and `isMemberEligibleCached()`: when `serial_pick_next` + `current_approver_id` is set, only the designated member is eligible.
- Updated `WorkflowsController::recordApproval()`: passes `$nextApproverId` to `recordResponse()`. When `needsMore` is true (serial chain still pending), does NOT call `resumeWorkflow()`.
- In `DefaultWorkflowEngine::executeApprovalNode()`: resolves `$.` context references in `approverConfig` values via `resolveParamValue()` before saving. Also propagates `serialPickNext` config flag into `approver_config` as `serial_pick_next`.

**Key decisions:**
- Trigger dispatch from service layer (not controller) — follows established TriggerDispatcher DI pattern from warrants/officers.
- `activate()` method does NOT send notifications — follows "suppress notifications when workflow wraps service" pattern from warrant activate.
- `serial_pick_next` stored in JSON `approver_config` column, no DB schema changes needed.
- Scope: only AuthorizationRequested and AuthorizationRetracted triggers (no Revoked — out of band).

📌 Team update (2026-02-12): Activities workflow backend complete (Phase 1+2). 3 new files, 8 modified. 554 workflow tests pass. — built by Kaylee

### 2026-02-12: Activities Authorization Seed Migration (Phase 4)

Added `activities-authorization` workflow definition to `20260209170000_SeedWorkflowDefinitions.php`. 9-node graph: trigger-auth → action-create → approval-gate (dynamic approver, serialPickNext, context-resolved requiredCount) → approved path (activate → notify → end) / rejected path (deny → notify → end). Set `is_active: 0` per Mal's architecture decision (admin opt-in). Followed exact SQL insert pattern from warrant-roster and officer-hire blocks. Rollback updated to include new slug.

**Key patterns followed:**
- Same 3-step SQL insert pattern: INSERT definition → INSERT version → UPDATE current_version_id
- Private `getActivitiesAuthorizationDefinition()` method matching `getWarrantRosterDefinition()` structure
- Node IDs match the workflow graph spec from Mal's architecture doc §8
- Canvas positions laid out left-to-right: trigger(50)→create(350)→gate(650)→actions(950)→notify(1250)→end(1550), approved branch y=100, denied branch y=300

📌 Team update (2026-02-12): Activities authorization seed migration added to SeedWorkflowDefinitions (Phase 4). 9 nodes, is_active=0 (opt-in). — built by Kaylee

📌 Team update (2026-02-11): Activities workflow scope limited to submit-to-approval only; Revoked/Expired out-of-band — decided by Josh Handel
📌 Team update (2026-02-11): Auth queue permission gating question raised — MoAS/Armored marshals get unauthorized on /activities/authorization-approvals/my-queue. May need policy update or confirmation that /workflows/approvals is the intended path — found by Jayne

### 2026-02-12: Dynamic Resolver Bug Fix

**Bug:** Activities Authorization approval-gate seed used flat config keys (`resolverService`, `activity_id`) that the engine's `executeApprovalNode()` never picked up into `approverConfig`. Also used wrong key name (`resolverService` vs engine-expected `service`) and omitted `method`. Result: `approver_config` saved as `{"serial_pick_next": true}` — no service, no method, no activity_id. `resolveDynamicApproverIds()` threw RuntimeException.

**Fix 1 — Seed:** Changed `approval-gate` config to use nested `approverConfig` with correct keys: `service` (not `resolverService`), `method` (`getEligibleApproverIds`), and `activity_id`. This is the preferred format — engine uses it directly.

**Fix 2 — Engine:** Added flat config fallback for dynamic resolver in `executeApprovalNode()`. Inside the `empty($approverConfig)` block: maps `resolverService`→`service`, `resolverMethod`→`method`, and for `dynamic` approverType preserves non-standard custom keys into `approverConfig`. Makes engine robust for both nested (preferred) and flat (backward compat) configs.

**Key learning:** Approval node config has two paths: nested `approverConfig` (used directly) and flat keys (assembled by engine). Dynamic resolver keys must be in either the nested config or explicitly handled in the flat fallback. Always use nested `approverConfig` for new approval nodes.

📌 Team update (2026-02-12): Fixed activities-authorization dynamic resolver bug — seed used wrong config structure and key names. Engine now supports flat config fallback for dynamic resolvers. 603 tests pass. — fixed by Kaylee
📌 Team update (2026-02-12): Config panel now shows resolver service/method (read-only) + custom config fields with value picker for dynamic approvers. — decided by Wash

### 2026-02-12: WorkflowApproverResolverRegistry

Created `WorkflowApproverResolverRegistry` following the same static registry pattern as `WorkflowActionRegistry` and `WorkflowTriggerRegistry`. Plugins register dynamic approver resolvers so the designer can show them in a dropdown.

**Changes:**
1. Created `app/src/Services/WorkflowRegistry/WorkflowApproverResolverRegistry.php` — register/getResolver/getAllResolvers/getForDesigner/clear methods. Keys resolvers by unique key (e.g., `Activities.AuthorizationApproverResolver`). `getForDesigner()` strips `serviceClass` for frontend safety.
2. Updated `ActivitiesWorkflowProvider::register()` — added `registerResolvers()` call. Registers one resolver: `Activities.AuthorizationApproverResolver` with configSchema for `activity_id`.
3. Updated `WorkflowsController::registry()` — added `'resolvers' => WorkflowApproverResolverRegistry::getForDesigner()` to response.
4. Updated `DefaultWorkflowApprovalManager::resolveDynamicApproverIds()` — registry lookup first, falls back to direct class name for backward compat.
5. Updated `SeedWorkflowDefinitions` — `approverConfig.service` now uses registry key `Activities.AuthorizationApproverResolver` instead of raw class name.

**Key pattern:** `approver_config.service` can be either a registry key (looked up via `getResolver()`) or a fully qualified class name (backward compat). Registry entry provides `serviceClass` and default `serviceMethod`.

📌 Team update (2026-02-12): WorkflowApproverResolverRegistry created. Plugins register dynamic approver resolvers for designer dropdown. Engine does registry-first lookup with FQCN fallback. 261 workflow tests pass. — built by Kaylee

### 2026-02-13: Intermediate Approval Actions (on_each_approval port)

**Feature:** Added third output port `on_each_approval` to approval nodes. When a non-final approval is recorded (serial pick-next intermediate step, or parallel approval that hasn't reached requiredCount), the engine fires actions connected to this port, then returns the node to WAITING state. Existing `approved`/`rejected` ports fire only on final resolution.

**Changes (5 files):**

1. **WorkflowEngineInterface** — Added `fireIntermediateApprovalActions(int $instanceId, string $nodeId, array $approvalData): ServiceResult` method.

2. **DefaultWorkflowEngine** — Implemented `fireIntermediateApprovalActions()`. Key design decisions:
   - Executes intermediate action nodes **synchronously and directly** (resolves service from registry, calls method, logs execution) rather than using `executeNode()`. This avoids the async-action status corruption problem: if `executeNode()` handled an `isAsync` action, it would set instance to WAITING and queue a WorkflowResume job — when that job runs later, `resumeWorkflow()` changes instance to RUNNING, and no subsequent node resets it to WAITING, leaving the instance stuck in RUNNING while the approval gate is still pending.
   - Context injection: populates `context['nodes'][$nodeId]` with approvedCount, requiredCount, approverId, nextApproverId, approvalChain, decision, comment. Uses `approvalData` parameter values when present, falls back to DB approval record.
   - Instance stays WAITING throughout — never transitions to RUNNING.
   - Approval node's execution log stays WAITING — never marked completed.
   - Approval node stays in `active_nodes` — never removed.

3. **DefaultWorkflowApprovalManager** — Added `needsMore: true` to return data for parallel non-final approvals (when status remains PENDING after recording a response). Also added `nextApproverId` to the serial pick-next return data for controller passthrough.

4. **WorkflowsController** — After existing `if (approved/rejected) → resumeWorkflow` block, added `if (!empty($data['needsMore'])) → fireIntermediateApprovalActions()` call.

5. **SeedWorkflowDefinitions** — Added `on_each_approval` port to activities-authorization `approval-gate` node targeting new `action-notify-step` node. Notification uses `Activities.NotifyRequester` with `status: 'pending'` and context-resolved `approverId`/`nextApproverId` from `$.nodes.approval-gate.*`.

**Key pattern: Direct service invocation for intermediate actions (not executeNode)**
When firing side-effect actions at a lifecycle point that shouldn't change the workflow's state machine position, invoke the action service directly rather than using the full `executeNode()` traversal machinery. This prevents: (1) async actions corrupting instance status, (2) output port traversal inadvertently advancing the graph, (3) the node being incorrectly removed from active_nodes.

**Edge case: async action resume orphans.** If the intermediate notification action were async (executeNode path), the queued WorkflowResume job would later set instance to RUNNING and leave it there — breaking the pending approval. The direct invocation approach eliminates this entirely.

📌 Team update (2026-02-13): Intermediate approval actions implemented (on_each_approval port). 5 files changed, 267 workflow tests pass. Direct service invocation pattern avoids async status corruption. — built by Kaylee

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

### 2026-02-10: Queue Plugin Deep Code Review

#### Plugin Origin & Status
Queue plugin is a fork of `dereuromark/cakephp-queue` v8 (MIT license). KMP team has partially adapted it: entities extend BaseEntity, tables extend BaseTable, controllers use AppController with authorization, Plugin class implements KMPPluginInterface. Now KMP-owned.

#### Key Architecture
- **Job lifecycle:** `createJob()` → `requestJob()` (FOR UPDATE row locking in transaction) → `runJob()` → `markJobDone()`/`markJobFailed()`
- **Task discovery:** `TaskFinder` scans `Queue/Task/` dirs in app and all plugins, builds `[name => className]` map
- **Worker process:** `Processor::run()` loops, fetching jobs via `requestJob()`, with PCNTL signal handling for graceful shutdown
- **Email integration:** KMP uses `MailerTask` via `QueuedMailerAwareTrait::queueMail()` — all email is async through Queue
- **DI support:** Tasks can use `ServicesTrait` to access the DI container for service injection

#### Critical Findings (22 issues total)
- **P0 (2):** Command injection in `terminateProcess()` (unsanitized PID to exec()), open redirect in `refererRedirect()`
- **P1 (10):** Broken `getFailedStatus()` (wrong task name prefix), `cleanOldJobs()` passes unix timestamp instead of DateTime, missing index on `queued_jobs.workerkey`, deprecated `TableRegistry` in migration, deprecated `loadComponent()`, silent save failures in markJobDone/markJobFailed, wrong auth context in QueueProcessesController, missing Shim dependency for JsonableBehavior, configVersion never written back
- **P2 (10):** Various cleanup — broken `clearDoublettes()`, missing strict_types in 2 files, weak worker key entropy, declare(ticks=1) should be pcntl_async_signals, missing docblocks, copy-paste policy docblock

#### MariaDB/JSON Pattern
The `text` column + `setColumnType('json')` in `initialize()` is correct for MariaDB. No changes needed — CakePHP ORM handles serialization transparently.

#### Dead Code Candidates
- `clearDoublettes()` — broken, never called in KMP
- 8 example task files — upstream examples, not used in production
- `EmailTask` — KMP uses `MailerTask` instead
- `SimpleQueueTransport` — appears unused

📌 Team update (2026-02-10): Queue plugin code review complete — 22 issues found (2 P0 security, 10 P1, 10 P2). Full findings in decisions/inbox/kaylee-queue-code-review.md. Key: command injection in terminateProcess(), broken getFailedStatus(), cleanOldJobs timestamp bug. — decided by Kaylee

📌 Team update (2026-02-10): Queue plugin ownership review — decided to own the plugin, security issues found, test triage complete

### 2026-02-10: Queue Plugin Security & Code Quality Fixes

Fixed 18 issues across Queue plugin production code:
- **P0 Security:** Deleted ExecuteTask.php (arbitrary exec), deleted 8 example tasks (demo code in prod), fixed command injection in terminateProcess() (numeric validation + int cast), hardened open redirect in refererRedirect() (backslash check)
- **P1 Code:** Fixed cleanOldJobs() timestamp (DateTime instead of time()), fixed getFailedStatus() wrong prefix, fixed configVersion not persisted, fixed QueueProcessesController auth context ("migrate"→"index"), added logging for markJobDone/Failed silent failures, added class_exists guard for Shim in JsonableBehavior, replaced deprecated loadComponent(), replaced deprecated TableRegistry in migration with raw SQL
- **P2 Quick Wins:** Fixed policy docblock, added canReset() docblock, added explicit getBranchId() to Queue entities, improved worker key entropy (random_bytes), replaced declare(ticks=1) with pcntl_async_signals, removed broken clearDoublettes()
- All core tests pass (183 unit, 99 feature)

📌 Team update (2026-02-10): Documentation accuracy review completed — all 4 agents reviewed 96 docs against codebase

### 2026-02-10: Documentation Modernization — Backend Docs Fixed

Completed 13 documentation tasks fixing inaccuracies found during codebase review:

#### Key Corrections Made
- **DI Container:** Removed phantom `NavigationRegistry` and `KmpAuthorizationService` from services() doc; added actual registrations (ICalendarService, ImpersonationService)
- **Session Config:** Fixed timeout (30 not 240), cookie name (PHPSESSID not KMP_SESSION), and structure (uses `ini` block, not nested `cookie` object)
- **PermissionsLoader:** Fixed property name (`scoping_rule` not `scope`) and values (`Permission::SCOPE_*` constants not lowercase strings)
- **findUpcoming SQL:** Fixed top-level OR → AND to match actual CakePHP query builder behavior
- **Entity hierarchy:** Fixed `ActivityAuthorization` → `Authorization` (Activities plugin entity name), added `Warrant` entity
- **WarrantManager events:** Removed fictional `ActiveWindow.before/afterStart/Stop` events — no events are dispatched
- **Warrant expiry:** Replaced reference to non-existent `expireOldWarrants()` with actual `SyncActiveWindowStatusesCommand`
- **File paths:** Fixed `src/Service/` → `src/Services/` (plural) in email template docs
- **Plugin listing:** Added Waivers plugin to architecture docs
- **Branch schema:** Removed non-existent `deleted_date` column
- **Migration scoping:** Fixed colon-delimited scope values to `Permission::SCOPE_*` constants

#### New Documentation Created
- `docs/7.7-console-commands.md` — Documented 5 CLI commands (generate_public_ids, migrate_award_events, reset_database, sync_member_warrantable_statuses, update_database)
- Added PublicIdBehavior section to `docs/3.2-model-behaviors.md`
- Added 11 service descriptions to `docs/6-services.md`
- Added `warrant_rosters` and `warrant_roster_approvals` tables to `docs/3.3-database-schema.md`

#### Pattern Observed
Docs were consistently wrong about: DI registrations (showing services that aren't registered), session config structure (CakePHP uses flat `ini` block not nested objects), and event names (fictional ActiveWindow events). These likely came from AI-generated docs that assumed patterns rather than reading actual source.

### 2026-02-13: Authorization Approval ID Investigation

**Question:** Josh asked what the "Authorization Approval ID" field is on the "Handle Authorization Denial" action and whether the workflow engine needs changes.

**Finding: The inputSchema is misleading — the action doesn't actually use `authorizationApprovalId`.**

The `Activities.HandleDenial` action registration (in `ActivitiesWorkflowProvider`) declares `authorizationApprovalId` in its inputSchema, but:
1. The actual `handleDenial()` method in `ActivitiesWorkflowActions` accepts `authorizationId` (not `authorizationApprovalId`) and looks up the pending approval record by `authorization_id` internally.
2. The seed workflow definition passes `authorizationId: '$.trigger.authorizationId'` — never passes `authorizationApprovalId`.
3. The old controller flow (`AuthorizationApprovalsController::deny()`) takes the `authorization_approvals.id` directly from the route/form, but the workflow action was designed to avoid this — it resolves the pending approval from the authorization_id instead.

**What `authorization_approvals` is:**
- Table: `activities_authorization_approvals`
- Columns: id, authorization_id, approver_id, authorization_token, requested_on, responded_on, approved, approver_notes
- It's the Activities plugin's own approval tracking table (separate from `workflow_approvals`)
- The old deny flow: controller gets `authorization_approvals.id` from route → calls `AuthorizationManager::deny($approvalId, $approverId, $reason)` → loads approval + contained authorization, marks denied

**Current APPROVAL_OUTPUT_SCHEMA (in WorkflowActionRegistry):**
- `status`, `approverId`, `comment`, `rejectionComment`, `decision`
- Available at `$.nodes.<approvalNodeId>.*`
- Does NOT include any Activities-specific IDs — and doesn't need to.

**Resolution:** The inputSchema on `Activities.HandleDenial` has a bug — it lists `authorizationApprovalId` as a required field, but the implementation uses `authorizationId`. The schema should be corrected to match the implementation:
- Remove `authorizationApprovalId` from inputSchema
- Add `authorizationId` (which is already what the seed passes and the code resolves)
- No changes needed to the workflow engine's APPROVAL_OUTPUT_SCHEMA
- No changes needed to the seed definition (it already correctly passes `authorizationId`)

**Key file paths:**
- Provider registration: `app/plugins/Activities/src/Services/ActivitiesWorkflowProvider.php` (lines 130-144)
- Action implementation: `app/plugins/Activities/src/Services/ActivitiesWorkflowActions.php` (lines 146-182)
- Old controller deny: `app/plugins/Activities/src/Controller/AuthorizationApprovalsController.php` (lines 676-707)
- Service deny method: `app/plugins/Activities/src/Services/DefaultAuthorizationManager.php` (lines 422-467)
- Seed definition: `app/config/Migrations/20260209170000_SeedWorkflowDefinitions.php` (lines 297-312)
- APPROVAL_OUTPUT_SCHEMA: `app/src/Services/WorkflowRegistry/WorkflowActionRegistry.php` (lines 26-32)

📌 Team update (2026-02-13): Authorization Approval ID investigation — it's a misleading inputSchema bug, not a missing engine feature. The HandleDenial action uses authorizationId (not authorizationApprovalId). Schema needs correction. No workflow engine changes needed. — investigated by Kaylee
