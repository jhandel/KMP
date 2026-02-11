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
