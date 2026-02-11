# Project Context

- **Owner:** Josh Handel (josh@liveoak.ws)
- **Project:** KMP — Membership management system for SCA Kingdoms. Handles members, officers, warrants, awards, activities, and workflow-driven approvals. ~2 years of active development.
- **Stack:** CakePHP 5.x, Stimulus.JS, MariaDB, Docker, Laravel Mix, Bootstrap, plugin architecture
- **Created:** 2026-02-10

## Learnings

<!-- Append new learnings below. Each entry is something lasting about the project. -->


### 2026-02-10: Architecture Overview & Codebase Map (summarized)

CakePHP 5.x app with Docker (PHP/Apache, MariaDB 11, Mailpit). Frontend: Stimulus.JS + Turbo Frames (Drive disabled) + Bootstrap 5.3.6 via Laravel Mix. Plugin ecosystem: 6 active (Activities, Officers, Awards, Waivers, Queue, GitHubIssueSubmitter) + 2 inactive. Registration: `config/plugins.php` → `KMPPluginInterface` → `bootstrap()` → registries. Core DI: AWM (no txn) → WM (owns txn) → OfficerManager. 37 policies, all extend BasePolicy. Dangerous to change: BaseEntity/BaseTable hierarchy, PermissionsLoader, ServiceResult pattern, static registries, middleware order, ActiveWindowBehavior, transaction ownership, window.Controllers pattern.

### 2026-02-10: Test Infrastructure & Queue Review (summarized)

**Test plan:** 6-phase plan. Phase 1-2 done (suites runnable, state leakage fixed). Auth strategy: standardize TestAuthenticationHelper, gap in `authenticateAsSuperUser()` not setting permissions. Queue plugin: decided to own it, security concerns (ExecuteTask removed), 47 source files trimmed.

### 2026-02-10: Documentation Modernization (summarized)

8 tasks completed: Waivers full rewrite, Awards data model phantom fields removed, migration orders corrected, OfficerManagerInterface param count fixed, RecommendationsTablePolicy code corrected, cross-reference rot cleaned, section numbering fixed.

### 2026-02-10: Workflow Engine Architecture Review (summarized)

Graph-based execution engine with 9 node types, 4 static registries (`WorkflowTriggerRegistry`, `WorkflowActionRegistry`, `WorkflowConditionRegistry`, `WorkflowEntityRegistry`), plugin extension via `KMPWorkflowPluginInterface`. Versioning: draft→published→archived. Approval gates: 5 approver strategies. Key concerns: DI bypassed (P1), no txn wrapping, dual-path business logic, zero tests, synchronous execution, `resolveValue()` copy-pasted 4x. Engine core: `DefaultWorkflowEngine.php` (~1,100 lines). Full reviews consolidated into decisions.md.

📌 Team updates (2026-02-10): All 4 agents reviewed codebase, workflow engine, and docs. Auth triage complete (370→463 tests). Queue plugin owned. Roster sync designed and implemented by Kaylee.

### 2026-02-11: Action Schema & Context Mapping Architecture

#### Key Findings
- **Action/trigger schemas are already structured** — `inputSchema` and `outputSchema` in providers already use `['type' => '...', 'label' => '...', 'required' => true]` format. The data is there; the frontend doesn't use it.
- **Variable picker has a trigger bug** — `WorkflowVariablePicker.getNodeOutputSchema()` looks for `trigger.outputSchema` but triggers register `payloadSchema`. Falls through to generic fallback.
- **Variable picker has an action path bug** — Generates `$.nodes.{nodeId}.{key}` but engine stores at `$.nodes.{nodeId}.result.{key}` (see `DefaultWorkflowEngine` line 641).
- **Config panel doesn't render action inputs** — `_actionHTML()` in `workflow-config-panel.js` only shows the action dropdown. No form fields for the action's `inputSchema` params.
- **Trigger `inputMapping` determines `$.trigger.*` keys** — The trigger node's `config.inputMapping` maps event fields into context. The variable picker ignores this and tries raw payload keys.
- **Approval resume data structure** — When approval resolves, `WorkflowsController` line 398-403 passes `{approval, approverId, decision, comment}` as `additionalData` to `resumeWorkflow()`, which stores it at `$context['resumeData']`.
- **Engine merges `config.params` into flat config** — `DefaultWorkflowEngine::executeActionNode()` line 634-636 merges `config.params` into `config` before calling the action. This means param nesting is intentional.

#### Architecture Decision
- Schema format stays the same — no migration needed. Add optional `description`, `default` fields.
- Context accumulation happens client-side (graph + registry data already available).
- Config panel enhanced to render `inputSchema` fields with variable picker per field.
- 5 phases: fix variable picker bugs → action input rendering → approval schema → publish validation → enrichment.
- No new PHP endpoints for Phase 1-2. Phase 3 adds approval/builtin schemas to existing `/workflows/registry`.

#### Key File Paths
- `app/assets/js/controllers/workflow-variable-picker.js` — Context variable builder, upstream traversal, searchable dropdown. Has trigger lookup bug (line 55: looks for `outputSchema` instead of `payloadSchema`).
- `app/assets/js/controllers/workflow-config-panel.js` — Per-node-type config rendering. `_actionHTML()` (line 82) only renders action dropdown, no input field forms.
- `app/src/Services/WorkflowRegistry/WorkflowActionRegistry.php` — Static registry, `getForDesigner()` (line 177) already exposes `inputSchema`/`outputSchema`.
- `app/src/Controller/WorkflowsController.php` — `registry()` action (line 150) serves all 4 registries as JSON.
- `app/src/Services/WorkflowEngine/DefaultWorkflowEngine.php` — `executeActionNode()` (line 579) does `config.params` merge and stores result at `context['nodes'][$nodeId]['result']`.

📌 Full proposal: `.ai-team/decisions/inbox/mal-action-schema-architecture.md`

📌 Team update (2026-02-11): Action Schema & Context Mapping — all 5 phases implemented and consolidated. Architecture (Mal), frontend fixes + field rendering (Wash commits 187032cf), backend schema + validation + enrichment (Kaylee commit 6c4528fb). 459 tests pass.

### 2026-02-11: Universal Value Picker Architecture

#### Architecture Decision
Designed a centralized "value picker" pattern for all workflow node parameters. Key decisions:

1. **Unified `resolveParamValue()` on backend** — single resolution method replacing the approval-specific `resolveRequiredCount()` logic. Handles plain scalars, `$.path` context shorthand, and `{type, value|path|key}` descriptor objects. `resolveRequiredCount()` becomes a thin int-casting wrapper around it.

2. **Three resolution types now, extensible later** — `fixed`, `context`, `app_setting` implemented. `entity_field` and `computed` reserved in the schema but not built (no real use case yet). Adding a new type is backward-compatible — just a new `case` in the switch.

3. **Frontend: `renderValuePicker()` on existing `WorkflowConfigPanel`** — not a separate file or Stimulus controller. A rendering helper that produces type-selector + dynamic-input HTML. Used by `_actionHTML`, `_conditionHTML`, `_approvalHTML`, `_delayHTML`, `_loopHTML`. Replaces Wash's `_requiredCountHTML()` (which was the prototype).

4. **Backward compatibility by design** — plain scalar values pass through unchanged. `$.path` strings auto-detected as context references. No workflow definition migration required. Old definitions work with new engine code.

5. **No new endpoints, no new files, no DB changes** — everything fits into existing structures. App settings API stays in `WorkflowsController`.

#### Key Patterns Discovered
- Wash's `_requiredCountHTML()` + `onRequiredCountTypeChange()` pattern is the exact right UX model — generalize it, don't replace it from scratch.
- `field` in condition nodes is a path REFERENCE (tells the condition where to look), NOT a value to resolve. Only `expectedValue` and `params.*` get value resolution.
- The variable picker (`WorkflowVariablePicker`) already handles context path computation correctly via reverse BFS. No changes needed there.
- `executeActionNode()` merges `config.params` into flat `config` (line 646-648) — param resolution must happen BEFORE this merge.

📌 Full design: `.ai-team/decisions/inbox/mal-universal-value-picker.md`

📌 Team update (2026-02-11): Universal value picker fully implemented — Kaylee built `resolveParamValue()` backend, Wash built `renderValuePicker()` frontend. All 5 config panels refactored. 463 tests pass. Architecture executed as designed. — decided by Mal, Kaylee, Wash
📌 Team update (2026-02-11): Duplicate email fix — `activateApprovedRoster($sendNotifications)` pattern established for services wrapped by workflow notification steps. — decided by Kaylee

### 2026-02-11: Warrant Roster Migration Architecture Assessment

#### Key Trade-offs
- **Full Migration (Option A):** Would consolidate to one approval system but requires creating synthetic workflow instances for 31 historical rosters. Synthetic instances pollute the workflow instance list and "My Approvals" grid with fake data. 19 old approval records lack decision/comment fields — migration would invent values. High complexity, moderate risk, eventual cleanliness.
- **Forward-Only (Option B, Recommended):** No migration. New rosters already flow through workflows. Sync layer (`syncWorkflowApprovalToRoster()`) bridges old UI — 40 lines, 13 tests, idempotent. Carries dual-table tech debt but at near-zero cost. Sync layer removable in 6-12 months when all historical rosters age out.
- **Unified View (Option C):** Adds abstraction layer to present both tables in one UI. Adds code complexity without removing either table. Lossy normalization between schemas.
- **Thin Adapter (Option D):** Simple if/else in view template — show workflow approval data for new rosters, old table for historical. Trivial change, optional.

#### Data Facts
- 34 rosters (29 Approved, 5 Pending), 19 old approval records, 3 workflow instances (rosters 437-439)
- "Warrant Roster Approval" workflow definition exists (ID 1, active, `Warrants.RosterCreated` trigger)
- No FK constraints reference `warrant_roster_approvals` from other tables
- No reports or exports depend on the old approval table — only the view template and WarrantManager service

#### Decision
**Forward-Only (Option B).** No migration. The sync layer bridges the transition cleanly. Revisit removal of sync layer and old table in 6-12 months. Not worth doing now — team time better spent on workflow engine improvements and authorization test fixes.

📌 Full analysis: `.ai-team/decisions/inbox/mal-warrant-migration-architecture.md`

📌 Team update (2026-02-11): Warrant roster migration → Forward-Only (Option B). No historical data migration. Sync layer stays. Revisit in 6–12 months. — decided by Mal, Kaylee
