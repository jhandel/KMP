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

### 2026-02-11: Action Schema & Context Mapping Architecture (summarized)

Action/trigger schemas already structured (`inputSchema`/`outputSchema` in providers). Variable picker had two bugs: trigger lookup (looks for `outputSchema` vs `payloadSchema`) and action path (`$.nodes.{id}.{key}` vs `$.nodes.{id}.result.{key}`). Config panel didn't render action input fields. Architecture: 5 phases implemented — fix variable picker → action input rendering → approval schema → publish validation → enrichment. All consolidated with Wash (frontend) and Kaylee (backend). 459 tests pass.

📌 Team update (2026-02-11): Action Schema & Context Mapping — all 5 phases implemented. Wash (187032cf), Kaylee (6c4528fb). 459 tests pass.

### 2026-02-11: Universal Value Picker Architecture (summarized)

Designed centralized value picker for all workflow node parameters. `resolveParamValue()` handles 3 types: `fixed`, `context`, `app_setting` (extensible). Frontend `renderValuePicker()` on existing `WorkflowConfigPanel` — replaces all ad-hoc patterns. Backward compatible (plain scalars pass through, `$.path` auto-detected). Key insight: `field` in conditions is a path reference, NOT a value to resolve. No new files, no DB changes.

📌 Team update (2026-02-11): Fully implemented by Kaylee (backend) + Wash (frontend). All 5 config panels refactored. 463 tests pass.
📌 Team update (2026-02-11): Duplicate email fix — `activateApprovedRoster($sendNotifications)` pattern for services wrapped by workflow notification steps — Kaylee.

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

### 2026-02-12: Activities Authorization Workflow Engine Integration Architecture

#### Architecture Decision
Designed workflow engine integration for Activities authorization approvals. Key decisions:

1. **Integration Strategy: Workflow wraps `AuthorizationManagerInterface` (Option B).** Same pattern as warrants — `ActivitiesWorkflowActions` delegates to the existing manager service. No rewrite. Manager keeps its business logic and transaction ownership.

2. **Serial Pick-Next Approver: New `serialPickNext` approval node behavior.** Enhancement to existing approval node config, not a new node type. When `serialPickNext: true`, the approval node accumulates approvals serially — each approver picks the next from the eligible pool. Chain state stored in `approver_config` JSON (no DB schema changes).

3. **Approver Resolution: DYNAMIC approver type with `AuthorizationApproverResolver`.** Custom service extracted from `AuthorizationApprovalsController::availableApproversList()`. Uses `Activity::getApproversQuery()` (permission_id-based). Shared by workflow engine and UI.

4. **5 Triggers registered:** `AuthorizationRequested`, `AuthorizationApproved`, `AuthorizationDenied`, `AuthorizationRevoked`, `AuthorizationRetracted`. Primary workflow trigger is `AuthorizationRequested`.

5. **8 Actions registered:** `CreateAuthorization`, `ApproveStep`, `DenyAuthorization`, `ActivateAuthorization`, `GetEligibleApprovers`, `NotifyApprover`, `NotifyRequester`, `RevokeAuthorization`.

6. **Forward-Only transition:** Dual-path (existing controller + workflow). No migration of historical data. Default workflow seeded but opt-in.

#### Engine Changes Required
- `DefaultWorkflowApprovalManager::recordResponse()` — accept `nextApproverId`, implement serial chain logic
- `WorkflowsController::respondToApproval()` — pass `nextApproverId`
- `executeApprovalNode()` — resolve `$.path` references in `approverConfig`
- `isMemberEligible()` — check `current_approver_id` for serial-pick-next

#### Key New Files
- `app/plugins/Activities/src/Services/ActivitiesWorkflowProvider.php` — trigger/action registration
- `app/plugins/Activities/src/Services/ActivitiesWorkflowActions.php` — workflow action implementations
- `app/plugins/Activities/src/Services/AuthorizationApproverResolver.php` — dynamic approver resolution

#### Implementation Plan
5 phases: Foundation (Kaylee) → Serial Pick-Next Engine (Kaylee) → Frontend (Wash) → Default Workflow Seed (Kaylee) → Tests (Jayne). No DB schema changes needed.

📌 Full proposal: `.ai-team/decisions/inbox/mal-activities-approval-workflow.md`

📌 Team update (2026-02-11): Activities authorization seed migration implemented (Phase 4) — 9-node graph, is_active=0, matches architecture spec — implemented by Kaylee
📌 Team update (2026-02-11): E2E tests 4/4 pass (Phase 5). Auth queue permission gating question (medium severity) — tested by Jayne
