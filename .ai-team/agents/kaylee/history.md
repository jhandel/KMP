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

### 2026-02-11: Fix Approval Node Context Population in resumeWorkflow()

**Bug:** `resumeWorkflow()` stored approval `additionalData` in `$context['resumeData']` but never in `$context['nodes'][$nodeId]`. This meant `$.nodes.<nodeId>.approverId` (and other APPROVAL_OUTPUT_SCHEMA fields) never resolved at runtime, even though the variable picker advertised them.

**Fix:** Added a block in `resumeWorkflow()` (after `resumeData` assignment, before `updateInstance`) that writes to `$context['nodes'][$nodeId]` with all 5 APPROVAL_OUTPUT_SCHEMA fields: `status`, `approverId`, `comment`, `rejectionComment`, `decision`. Follows the same pattern as action nodes (line ~641) and condition nodes (line ~693). Also moved `$instance->context = $context` to always execute (not only when `additionalData` is non-empty), ensuring the nodes write is persisted.

**Note on Bug 2 (not fixed, by design):** `$.resumeData` is ephemeral — a second approval gate overwrites the first's data. This is harmless since downstream nodes from the first gate already executed. Left as-is per task instructions.

#### Key Patterns
- All node types must write to `$context['nodes'][$nodeId]` for the variable picker (`$.nodes.<nodeId>.*`) to resolve
- Approval output schema fields are defined in `WorkflowActionRegistry::APPROVAL_OUTPUT_SCHEMA`
- `$instance->context = $context` must be set after all context mutations, before `updateInstance()`

📌 Team update (2026-02-11): Fixed approval node context bug — `resumeWorkflow()` now populates `$context['nodes'][$nodeId]` with APPROVAL_OUTPUT_SCHEMA fields so `$.nodes.<nodeId>.approverId` etc. resolve at runtime. 459 tests pass. — fixed by Kaylee

📌 Team update (2026-02-11): Approval node context fix consolidated with Jayne's test coverage into single decision — decided by Scribe

### 2026-02-11: Fix TriggerDispatcher Static Call Bug in DefaultOfficerManager

**Bug:** `DefaultOfficerManager` called `TriggerDispatcher::dispatch()` statically in two places (assign line 91, release line 606), but `dispatch()` is an instance method requiring `WorkflowEngineInterface` via constructor injection. This caused a fatal error when the officer-hire workflow was active.

**Fix:** Injected `TriggerDispatcher` into `DefaultOfficerManager` via constructor DI, matching the existing pattern in `DefaultWarrantManager`. Updated `OfficersPlugin::services()` to pass `TriggerDispatcher::class` as a DI argument. Changed both static calls to instance calls (`$this->triggerDispatcher->dispatch(...)`).

**Files changed:** `DefaultOfficerManager.php` (constructor + 2 call sites), `OfficersPlugin.php` (DI registration). No other static callers found in the codebase. All 463 tests pass.

**Rule:** `TriggerDispatcher` must always be injected via DI and called on an instance. It cannot work statically because it depends on `WorkflowEngineInterface`.

📌 Team update (2026-02-11): Fixed TriggerDispatcher static call bug — `DefaultOfficerManager` now injects TriggerDispatcher via DI instead of calling statically. 2 files changed, 463 tests pass. — fixed by Kaylee

### 2026-02-11: Fix Grid Date Column Timezone Display & Filters

**Bug:** The `case 'date':` block in `dataverse_table.php` displayed dates using raw `$value->format('F j, Y')` (UTC), while the `case 'datetime':` block correctly used `$this->Timezone->format($value)`. Warrant grid columns `start_on` and `expires_on` are type `date`, so they showed UTC dates to users.

**Fixes (3 files):**

1. **`dataverse_table.php`** — Changed `case 'date':` to use `$this->Timezone->date($value)` (the view helper's date-only method that converts to user/kingdom timezone before formatting). Matches the pattern already used by `case 'datetime':`.

2. **`DataverseGridTrait.php`** — Added `TzHelper::toUtc()` conversion for date-only filter values (Y-m-d strings) before applying them to SQL queries. Start dates convert start-of-day (`00:00:00`) in kingdom timezone to UTC. End dates convert end-of-day (`23:59:59`) in kingdom timezone to UTC. Original values preserved for filter pill display. This affects all grids with `date-range` filter columns (Warrants, WarrantRosters, Gatherings, GatheringAttendances, WarrantPeriods, MemberRoles).

3. **`WarrantsGridColumns.php`** — Changed system view boundary dates from `FrozenDate::today()` (UTC) to kingdom-timezone-aware today using `TzHelper::getAppTimezone()`. Ensures "Current", "Upcoming", "Previous" views use the kingdom's local date.

#### Key Patterns Discovered
- The `Timezone` view helper already had a `date()` method perfect for date-only display with timezone conversion — wraps `TzHelper::toUserTimezone()` + `TzHelper::formatDate()`.
- `TzHelper::toUtc()` with `$member = null` falls back to the app's configured timezone (`KMP.DefaultTimezone`), which is the kingdom's timezone. This is the correct default for grid filters.
- Expression tree date handling in `GridViewConfig.php` does NOT do timezone conversion — potential follow-up item for system views that use `expression` blocks with date operators (e.g., the "Previous" warrants view's OR expression).

📌 Team update (2026-02-11): Fixed grid date timezone bug — `case 'date':` now uses `$this->Timezone->date()`, date-range filters convert to UTC via `TzHelper::toUtc()`, warrants system views use kingdom-timezone today. 3 files changed, 463 tests pass. — fixed by Kaylee

### 2026-02-11: Fix Duplicate Warrant Notification Emails

**Bug:** The warrant roster workflow sent duplicate notification emails — one set from `activateApprovedRoster()` inside `DefaultWarrantManager`, and a second set from the workflow's `notifyWarrantIssued` action. Both fire on the approved path: `action-activate → action-notify-approved`.

**Root Cause:** `activateApprovedRoster()` always sent notification emails via `queueMail()` after activating each warrant. When called from the workflow's `activateWarrants()` action, the next workflow step `notifyWarrantIssued()` also sent emails for the same warrants. This doubled the emails for every warrant on the roster.

**Fix:** Added `bool $sendNotifications = true` parameter to `activateApprovedRoster()` (interface + implementation). The workflow action `activateWarrants()` now passes `false` to suppress the internal emails, since the workflow has its own dedicated `notifyWarrantIssued` step. The direct approval path (`approve()`) continues to use the default `true`, preserving email behavior for non-workflow approvals.

**Files changed:** `WarrantManagerInterface.php` (signature + docblock), `DefaultWarrantManager.php` (parameter + conditional), `WarrantWorkflowActions.php` (pass `false`). 463 tests pass.

#### Key Patterns
- When a service method both performs an action AND sends notifications, and a workflow wraps that service with its own notification step, the service must allow suppressing notifications to avoid duplicates.
- `activateApprovedRoster()` is called from two paths: direct (`approve()` with notifications) and workflow (`activateWarrants()` without notifications). The `$sendNotifications` parameter gates which path sends email.

📌 Team update (2026-02-11): Fixed duplicate warrant notification email bug — `activateApprovedRoster()` now accepts `$sendNotifications` parameter (default true). Workflow action passes false since workflow has its own notify step. 3 files changed, 463 tests pass. — fixed by Kaylee

### 2026-02-11: API Endpoint for App Settings (Workflow Designer)

Added `GET /workflows/app-settings` endpoint to `WorkflowsController` for the workflow designer's approval node configuration. Returns all app settings as JSON (`name`, `value`, `type`). Follows the same pattern as `policyClasses()` and `policyActions()` — `skipAuthorization()`, `Json` view class, explicit route in `/workflows` scope.

Key patterns:
- Workflow designer endpoints live on `WorkflowsController`, not `AppSettingsController`, to keep all designer APIs together.
- JSON endpoints use `$this->viewBuilder()->setClassName('Json')` + `setOption('serialize', [...])` pattern.
- `resolveRequiredCount()` in `DefaultWorkflowEngine` supports 4 input types: plain integer, `{type: 'fixed', value: N}`, `{type: 'app_setting', key: '...'}` (with optional `default`), and `{type: 'context', path: '$.field'}`.

📌 Team update (2026-02-11): Added `GET /workflows/app-settings` endpoint — returns all app settings as JSON for workflow designer approval node requiredCount dropdown. 2 files changed, 463 tests pass. — implemented by Kaylee

### 2026-02-11: Universal resolveParamValue() in DefaultWorkflowEngine

Added `resolveParamValue()` as the universal value resolution method for workflow parameter values. This is the backend half of the universal value picker architecture (Mal's design).

**Changes to `DefaultWorkflowEngine.php`:**

1. **Added `resolveParamValue(mixed $value, array $context, mixed $default = null): mixed`** — Handles 4 resolution paths: null/empty → default, plain scalar → as-is, `$.path` string → `resolveContextValue()`, array with `type` key → dispatches to `fixed`/`context`/`app_setting` handlers. Unknown types log a warning and return default.

2. **Refactored `resolveRequiredCount()`** — Now a thin wrapper: calls `resolveParamValue($value, $context, 1)` then returns `max(1, (int)$resolved)`. Preserves the int cast and min-1 guarantee for approval nodes.

3. **Updated `executeActionNode()`** — Params are now resolved through `resolveParamValue()` before being merged into `$nodeConfig`. Each param in `config.params` is individually resolved, so action handlers receive already-resolved values.

4. **Updated `executeConditionNode()`** — `expectedValue` is resolved via `resolveParamValue()` before passing to the evaluator. Params in `config.params` are individually resolved. `field` is NOT resolved — it's a context PATH reference that gets passed to `resolveFieldPath()` as-is.

#### Key Patterns
- `resolveParamValue()` is placed right before `resolveRequiredCount()` in the file, after `resolveContextValue()`. This groups all resolution methods together.
- The `app_setting` resolution uses the same `TableRegistry::getTableLocator()->get('AppSettings')` pattern that the old `resolveRequiredCount()` used — no dependency on `StaticHelpers::getAppSetting()` which would add a cache layer we don't want during workflow execution.
- Inline expression evaluation in condition nodes (the `else` branch) is untouched — `evaluateExpression()` handles its own field resolution.
- Backward compatible: plain scalars pass through unchanged, `$.path` strings still work, existing `{type: "app_setting"}` objects still work.

📌 Team update (2026-02-11): Universal `resolveParamValue()` added to DefaultWorkflowEngine — handles fixed/context/app_setting value descriptors and $.path shorthand. `resolveRequiredCount()` refactored to delegate. `executeActionNode()` and `executeConditionNode()` now resolve params through it. 1 file changed, 463 tests pass. — implemented by Kaylee

📌 Team update (2026-02-11): Universal value picker frontend complete — Wash built `renderValuePicker()`, refactored all 5 config panels, deleted `_requiredCountHTML()` prototype. Data attributes: `data-vp-type`, `data-vp-field`, `data-vp-settings-select`. — decided by Wash
