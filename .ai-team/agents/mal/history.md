# Project Context

- **Owner:** Josh Handel (josh@liveoak.ws)
- **Project:** KMP — Membership management system for SCA Kingdoms. Handles members, officers, warrants, awards, activities, and workflow-driven approvals. ~2 years of active development.
- **Stack:** CakePHP 5.x, Stimulus.JS, MariaDB, Docker, Laravel Mix, Bootstrap, plugin architecture
- **Created:** 2026-02-10

## Learnings

<!-- Append new learnings below. Each entry is something lasting about the project. -->

### 2026-02-10: Architecture Overview (summarized from full map)

#### Structure
CakePHP 5.x app in `/app/` with Docker orchestration. Three services: PHP/Apache, MariaDB 11, Mailpit. Frontend: Stimulus.JS + Turbo Frames (Drive disabled) + Bootstrap 5.3.6, built via Laravel Mix (`app/webpack.mix.js`).

#### Plugin Ecosystem
**Active domain:** Activities (auth/activities, API), Officers (warrants/rosters, API), Awards (recommendations/state machine), Waivers (gathering waivers). **Infrastructure:** Queue (async jobs), GitHubIssueSubmitter. **Inactive:** Template (reference impl), Events (not implemented). **Third-party:** DebugKit, Bake, Tools, Migrations, Muffin/Footprint, Muffin/Trash, BootstrapUI, Authentication, Authorization, ADmad/Glide, CsvView.

Plugin registration: `config/plugins.php` → Plugin class implements `KMPPluginInterface` → `bootstrap()` registers navigation/cells/settings → optional DI in `services()` → API via `KMPApiPluginInterface`. Enable/disable: `Plugin.{Name}.Active` AppSetting.

#### Services & DI
Core DI: AWM (no txn), WM (owns txn, depends on AWM), CsvExport, ICal, Impersonation. Plugin DI: OfficerManager (AWM+WM), AuthorizationManager. Static: NavigationRegistry, ViewCellRegistry, ApiDataRegistry. All return `ServiceResult(success, reason, data)`.

#### Auth Architecture
Dual auth: session+form (web), Bearer token (API). Policy-based authorization with ORM+Controller resolvers. 37 policies, all extend BasePolicy (super-user bypass in `before()`). Permission chain: Members→MemberRoles→Roles→Permissions→PermissionPolicies→Policies. Three scopes: Global, Branch Only, Branch+Children. Cached via PermissionsLoader.

#### Dangerous to Change
1. BaseEntity/BaseTable hierarchy  2. PermissionsLoader + permission chain  3. ServiceResult pattern  4. NavigationRegistry/ViewCellRegistry static registration  5. Middleware order  6. ActiveWindowBehavior temporal logic  7. Transaction ownership (AWM=caller, WM=self)  8. window.Controllers registration pattern

#### Key Paths
Application: `app/src/Application.php`. KMP core: `app/src/KMP/`. Services: `app/src/Services/`. Controllers: `app/src/Controller/` (26 + Api/). Policies: `app/src/Policy/` (37 files). Config: `app/config/`. Plugins: `app/plugins/`. Frontend: `app/assets/js/`. Tests: `app/tests/TestCase/`. Build: `app/webpack.mix.js`.

### 2026-02-10: Test Infrastructure Attack Plan

Josh directed all features paused until testing is solid. 6-phase plan created:
1. Make suites runnable (delete duplicates, fix constants) ✅ DONE
2. Fix state leakage (migrate to BaseTestCase) ✅ DONE
3. Auth consolidation (standardize TestAuthenticationHelper) — gap found
4. Auth failure investigation (15 TEST_BUG, 2 CODE_BUG) ✅ DONE
5. Remove dead weight (delete stubs, fix warnings)
6. CI pipeline (GitHub Actions)

Key decisions: Standardize TestAuthenticationHelper (deprecate old traits). Queue plugin excluded. ViewCell stubs to be deleted. Constants: KINGDOM_BRANCH_ID=2, TEST_BRANCH_LOCAL_ID=14.

📌 Team update (2026-02-10): Backend patterns documented — 14 critical conventions including ServiceResult, transaction ownership, entity/table hierarchy, and authorization flow — decided by Kaylee
📌 Team update (2026-02-10): Frontend patterns documented — 81 Stimulus controllers cataloged, asset pipeline, tab ordering, inter-controller communication via outlet-btn — decided by Wash
📌 Team update (2026-02-10): Test suite audited — 88 files but ~15-20% real coverage, 36/37 policies untested, no CI pipeline, recommend adding CI test runner as Priority 1 — decided by Jayne
📌 Team update (2026-02-10): Josh directive — no new features until testing is solid. Test infrastructure is the priority. — decided by Josh Handel
📌 Team update (2026-02-10): Auth triage complete — 15 TEST_BUGs, 2 CODE_BUGs. Kaylee fixed both CODE_BUGs. All 370 project-owned tests now pass (was 121 failures + 76 errors). — decided by Jayne, Kaylee
📌 Team update (2026-02-10): Auth strategy gap identified — authenticateAsSuperUser() does not set permissions. Must be fixed before Phase 3.2 test migration. — decided by Mal

### 2026-02-10: Queue Plugin Architectural Review

Josh directed us to "own" the Queue plugin — it's a forked copy of `dereuromark/cakephp-queue` (MIT, CakePHP 5.x) that's been in-repo and already significantly modified to fit KMP patterns (BaseEntity/BaseTable, KMPPluginInterface, authorization, NavigationRegistry).

#### Key Findings
- **47 source files, 7,628 lines** — medium-sized plugin, core engine is ~1,500 lines
- **Only integration point:** `QueuedMailerAwareTrait` → `MailerTask` for async email (8 callsites across MembersController, WarrantManager, OfficerManager)
- **Already diverged from upstream** — entities extend BaseEntity, tables extend BaseTable, policy system integrated, navigation registered
- **Cron-driven:** `bin/cake queue run` every 2 minutes via Docker entrypoint
- **Security concern:** `ExecuteTask` allows arbitrary `exec()` from queued data — must be disabled/removed
- **Dead weight:** 8 example tasks, 2 unused mail transports, stale vendor directory

#### Decision
Own it. The divergence is too deep to re-sync, and we use a tiny fraction of its features. Slim it down, remove security risks, and treat as stable infrastructure.

#### P0 Actions
1. Disable/remove `ExecuteTask` (arbitrary command execution)
2. Remove/ignore example tasks from production

📌 Full review: `.ai-team/decisions/inbox/mal-queue-architecture-review.md`

📌 Team update (2026-02-10): Queue plugin ownership review — decided to own the plugin, security issues found, test triage complete

📌 Team update (2026-02-10): Documentation accuracy review completed — all 4 agents reviewed 96 docs against codebase

### 2026-02-10: Documentation Modernization Pass

Completed 8 documentation tasks fixing cross-references, data models, interface signatures, and migration orders across plugin docs.

#### Key Findings
- **Waivers plugin doc was severely outdated** — only covered ~half the plugin. Full rewrite from source code covering 4 entities, 4 tables, 8 policies, 9 JS controllers, 3 services, 2 view cells, 13 migrations.
- **Awards data model had phantom `active` fields** — Award, Domain, Level, and Event entities all had `active: bool` in the Mermaid diagram that doesn't exist in any entity. Award was also missing 6 real fields (abbreviation, insignia, badge, charter, open_date, close_date).
- **Migration orders were wrong in 5-plugins.md** — Officers/Awards were swapped in the Categories section. Queue, Bootstrap, GitHubIssueSubmitter had fabricated migrationOrder values (10, 12, 11) when they have no migrationOrder in plugins.php. Reports and OfficerEventReporting plugins were listed but don't exist. Example config had nonexistent keys (dependencies, conditional, description, category, required).
- **OfficerManagerInterface.release() had wrong param count** — doc showed 5 params (with `$releaseStatus`), interface actually has 4. The 5th param is implementation-only.
- **RecommendationsTablePolicy used `matching()` not `contain()`** — doc showed `contain(['Awards.Levels'])->where()`, actual code uses `matching('Awards.Levels', ...)`. Also undocumented: global access sentinel value `-10000000` that bypasses branch scoping.
- **Cross-reference rot** — `5.2.2-awards-event-entity.md` and `5.2.3-awards-domains-table.md` never existed.
- **Section number mismatch** — 5.4 filename but 5.5 title for GitHubIssueSubmitter.

### 2026-02-10: Workflow Engine Architecture Review

#### Key Architectural Patterns
- **Graph-based execution engine** replacing old state-machine design (legacy tables archived). Workflows are directed graphs of typed nodes (trigger, action, condition, approval, fork, join, loop, delay, end, subworkflow) connected by named output ports.
- **4 static registries** (`WorkflowTriggerRegistry`, `WorkflowActionRegistry`, `WorkflowConditionRegistry`, `WorkflowEntityRegistry`) follow the same pattern as `NavigationRegistry`/`ViewCellRegistry`.
- **Plugin extension** via `KMPWorkflowPluginInterface` (4-method contract) or standalone Provider classes with static `register()`. Both paths populate the same registries.
- **Context resolution** uses `$.path.to.value` dot-path convention throughout actions and conditions.
- **Versioning lifecycle:** draft → published → archived. Only one published version per definition. Instances pinned to versions. Migration with node remapping and audit trail.
- **Approval gates** are first-class nodes with 5 approver strategies: permission, role, member, dynamic (unimplemented), policy.

#### Important File Paths
- **Engine core:** `app/src/Services/WorkflowEngine/` — `WorkflowEngineInterface.php`, `DefaultWorkflowEngine.php` (~1,100 lines), `TriggerDispatcher.php`
- **Version manager:** `DefaultWorkflowVersionManager.php` — includes graph validation, BFS reachability check
- **Approval manager:** `DefaultWorkflowApprovalManager.php` — eligibility checks, response recording, policy-based approvals
- **Registries:** `app/src/Services/WorkflowRegistry/` — 5 files (Trigger, Condition, Action, Entity, PluginLoader)
- **Plugin contract:** `app/src/KMP/KMPWorkflowPluginInterface.php`
- **Core actions/conditions:** `app/src/Services/WorkflowEngine/Actions/CoreActions.php`, `Conditions/CoreConditions.php`
- **Warrant provider:** `app/src/Services/WorkflowEngine/Providers/WarrantWorkflowProvider.php`, `WarrantWorkflowActions.php`
- **Officers integration:** `app/plugins/Officers/src/Services/OfficersWorkflowProvider.php`, `OfficerWorkflowActions.php`, `OfficerWorkflowConditions.php`
- **Controller:** `app/src/Controller/WorkflowsController.php`
- **Policies:** `app/src/Policy/WorkflowDefinitionPolicy.php`, `WorkflowsControllerPolicy.php`, `WorkflowDefinitionsTablePolicy.php`
- **Schema:** `app/config/Migrations/20260209160000_CreateWorkflowEngine.php` (7 tables)
- **Seed data:** `app/config/Migrations/20260209170000_SeedWorkflowDefinitions.php` (2 seeded workflows: warrant-roster, officer-hire)

#### Concerns Worth Remembering
1. **DI is bypassed everywhere.** Controller and TriggerDispatcher use `new DefaultWorkflowEngine()` instead of DI. The interface registrations in `Application::services()` are never consumed.
2. **No transaction wrapping in engine.** Graph traversal creates logs and updates instance state without a transaction — mid-failure leaves inconsistent state.
3. **Dual-path business logic.** `WarrantWorkflowActions` duplicates `WarrantManager` logic; `OfficerWorkflowActions` duplicates `OfficerManager` logic. Both will diverge.
4. **Zero tests.** No test coverage exists for the workflow engine, which conflicts with the "no new features until testing is solid" directive.
5. **Synchronous execution.** The entire graph runs in one PHP request. `isAsync: true` flag on registry entries is ignored at execution time.
6. **`resolveValue()` copy-pasted** into 4 classes instead of being shared.
7. **`migrateInstances()` controller action** does raw `updateAll()` bypassing the version manager's node-mapping logic.

📌 Full review: `.ai-team/decisions/inbox/mal-workflow-architecture-review.md`

📌 Team update (2026-02-10): Workflow engine review complete — all 4 agents reviewed feature/workflow-engine. Key consolidated decisions: DI bypass fix (P1, Kaylee+Jayne), approval atomicity+concurrency (P0, Kaylee+Jayne). Mal's architecture review merged to decisions.md. — decided by Mal, Kaylee, Wash, Jayne

📌 Team update (2026-02-10): Warrant roster workflow sync implemented — decided by Mal, implemented by Kaylee

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
