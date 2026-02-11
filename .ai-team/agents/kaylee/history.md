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
