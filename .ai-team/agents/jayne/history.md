# Project Context

- **Owner:** Josh Handel (josh@liveoak.ws)
- **Project:** KMP — Membership management system for SCA Kingdoms. Handles members, officers, warrants, awards, activities, and workflow-driven approvals. ~2 years of active development.
- **Stack:** CakePHP 5.x, Stimulus.JS, MariaDB, Docker, Laravel Mix, Bootstrap, plugin architecture
- **Created:** 2026-02-10

## Learnings

<!-- Append new learnings below. Each entry is something lasting about the project. -->

### 2026-02-10: Test Suite — Summary (summarized from detailed audit)

**88 test files** (35 core + 53 plugin). ~536 test methods. Estimated real coverage: ~15-20%.

**Well-tested:** Authorization service (unit + edge cases + branch scoping), Members CRUD, App settings, Branches, Permissions/Roles, Gatherings, KMP helper, Officers plugin, Waivers plugin.

**Major gaps:** 20/26 core controllers untested, 26/32 tables, 29/34 entities, 36/37 policies, 6/7 commands, 0 mailer tests, 0 API tests. Activities plugin: all stubs. Awards: 0 real tests.

**Test infrastructure:**
- `BaseTestCase` → transaction wrapping + seed data constants (ADMIN_MEMBER_ID=1, TEST_MEMBER_AGATHA_ID=2871, BRYCE=2872, DEVON=2874, EIRIK=2875, KINGDOM_BRANCH_ID=2, TEST_BRANCH_LOCAL_ID=14)
- `HttpIntegrationTestCase` → HTTP tests with `TestAuthenticationHelper`
- `PluginIntegrationTestCase` → plugin HTTP tests (requires `PLUGIN_NAME` constant)
- Database: seeded via `SeedManager` + `dev_seed_clean.sql`, NOT CakePHP fixtures
- Run: `cd app && composer test` or `vendor/bin/phpunit --testsuite {core-unit|core-feature|plugins|all}`
- Queue plugin (31 tests) has own fixture system — leave alone

### 2026-02-10: KmpHelper Static State Bug (production code fix)

`KmpHelper::$mainView` is static — persists across PHPUnit test runs. `beforeRender()` stored first View and never updated, causing all subsequent tests to write blocks to a stale View. Fix: compare `$view->getRequest()` instead of just `isset()`. File: `app/src/View/Helper/KmpHelper.php` (4 lines). No production impact (static resets per PHP process).

### 2026-02-10: Template HelloWorldControllerTest Fix

- Must use `PluginIntegrationTestCase` (not `HttpIntegrationTestCase`) — Template plugin commented out in `config/plugins.php`
- Must call `enableCsrfToken()`, `enableSecurityToken()`, `authenticateAsSuperUser()` in setUp
- Flash assertions: use `$_SESSION['Flash']['flash']` not `assertFlashMessage()` (CakePHP trait reads wrong session key in KMP)

📌 Team update (2026-02-10): Architecture overview documented — 6 plugins, 37 policy classes, PermissionsLoader is security backbone, 8 dangerous-to-change areas — decided by Mal
📌 Team update (2026-02-10): Backend patterns documented — 14 critical conventions to follow, transaction ownership split (AWM=caller, WM=self), termYears is actually months — decided by Kaylee
📌 Team update (2026-02-10): Frontend patterns documented — 81 Stimulus controllers cataloged, asset pipeline via webpack.mix.js, no frontend test infrastructure exists — decided by Wash
📌 Team update (2026-02-10): Test infrastructure attack plan created — 6 phases, Jayne owns Phases 1-3, 4.1, 4.2a, 5, 6. No new features until testing is solid. — decided by Mal, Josh Handel
📌 Team update (2026-02-10): Auth triage complete — 15 TEST_BUGs, 2 CODE_BUGs classified. Kaylee fixed both CODE_BUGs (PermissionsLoader revoker_id, ControllerResolver string handling). All 370 project-owned tests now pass. — decided by Jayne, Kaylee
📌 Team update (2026-02-10): Auth strategy decided — standardize on TestAuthenticationHelper, deprecate old traits. ⚠️ Gap: authenticateAsSuperUser() does not set permissions — must be fixed before migrating tests. — decided by Mal

### 2026-02-10: Queue Plugin Test Fixes (summarized)

**Triage:** 119 tests, 81 failed — all from 5 infrastructure root causes (not code bugs): redundant loadPlugins (16 errors), wrong Admin prefix routes (29 errors), missing TestApp autoload (15 errors), removed fixture declarations (16 errors), email transport config (3 errors).

**Fixes:** All 81 failures resolved → 0 errors, 0 failures, 6 skips. Removed loadPlugins from 9 files, removed Admin prefix from 3 files, added autoload-dev entries, restored fixtures to 14 files, configured Debug transport. Also deleted 9 test files for removed example tasks, added auth to controller tests, created missing test infrastructure. Key insight: Queue uses CakePHP fixture isolation, NOT BaseTestCase — don't migrate yet.

📌 Team update (2026-02-10): Documentation accuracy review completed — all 4 agents reviewed 96 docs against codebase

### 2026-02-10: Documentation Modernization — 13 Tasks (summarized)

Fixed 13 docs across 12 files. Key: deleted duplicate `8-development-workflow.md`, rewrote `7-development-workflow.md` (correct test suites, base classes, data strategy), fixed test constants (`KINGDOM_BRANCH_ID`=2, `TEST_BRANCH_LOCAL_ID`=14), session timeout 30min not 4hr, removed non-existent commands/scripts, PHP version 8.3 across 4 docs, replaced deprecated trait guidance.

### 2026-02-10: Workflow Engine Testability Audit — Complete

**Current test coverage: 0%.** Zero PHPUnit tests, zero fixtures, zero model/entity/policy/service tests. Only BDD smoke tests (5 view-only Playwright scenarios) and an interactive Playwright script that checks pages load. No backend logic is tested.

**Module scope:** 7 tables, 7 entities, 3 service classes (~2200 LOC), 1 static dispatcher, 2 helper classes (CoreActions/CoreConditions), 4 static registries, 1 domain provider (Warrants), 1 controller (16 authorized actions), 3 policy classes, 2 queue tasks.

**Critical paths needing tests first:**
1. `DefaultWorkflowEngine::startWorkflow()` — instance creation + recursive node execution (9 node types)
2. `DefaultWorkflowEngine::resumeWorkflow()` — WAITING→RUNNING transition + output port routing
3. `DefaultWorkflowVersionManager::publish()` — transactional publish with archive + validation
4. `DefaultWorkflowVersionManager::validateDefinition()` — graph integrity (trigger, end, targets, reachability, loops)
5. `DefaultWorkflowApprovalManager::recordResponse()` — 5 eligibility types, duplicate prevention, resolution logic
6. `CoreConditions::evaluateExpression()` — expression parser with 6 operators + dot-path resolution
7. `WorkflowsController` — 16 actions with authorization checks (super user gating + open approvals)

**Edge cases and failure modes discovered:**
- Infinite recursion risk: `executeNode()` is recursive with no depth guard; cyclic action graphs = stack overflow
- Race condition: `recordResponse()` reads/increments/saves approval counts without row locking
- Fork/join deadlock: if a fork path fails, join waits forever (no timeout/dead-path detection)
- Context mutation: parallel fork paths share in-memory context — execution order dependent
- Subworkflow orphan: parent set to WAITING but no mechanism to detect child completion
- `migrateInstances()` controller action uses `updateAll()` — bypasses node remapping, validation, audit
- Deadline parsing: edge cases (0d, negative, empty string, non-matching format) weakly handled
- Static registries: no `reset()` method — test pollution across PHPUnit runs

**Testing patterns and fixture requirements:**
- Use `BaseTestCase` with transaction wrapping (project standard)
- Workflow tables exist from migration but seed SQL has NO workflow data — tests must create data inline via `TableRegistry::getTableLocator()->get()`
- Static registries (Action, Condition, Trigger, Entity) need `reset()` methods or careful test isolation
- Services are DI-registered in `Application::services()` but controller bypasses DI with `new` — controller tests are forced integration tests
- `TriggerDispatcher::dispatch()` is static with hardcoded `new DefaultWorkflowEngine()` — untestable without refactor
- Queue tasks also hardcode `new DefaultWorkflowEngine()` — integration tests only
- Time-sensitive tests (deadlines) should use `FrozenTime::setTestNow()`
- Recommended test strategy: Phase 1 unit (pure logic ~40 tests) → Phase 2 integration (services ~60 tests) → Phase 3 controller (~40 tests) → Phase 4 policies (~20 tests) → Phase 5 edge cases (~30 tests)

Full report: `.ai-team/decisions/inbox/jayne-workflow-testing-review.md`

📌 Team update (2026-02-10): Workflow engine review complete — all 4 agents reviewed feature/workflow-engine. Jayne's DI controller rec consolidated with Kaylee's DI recs. Jayne's concurrency guard rec consolidated with Kaylee's transaction rec (P0). 0% coverage finding confirmed by all agents. — decided by Mal, Kaylee, Wash, Jayne

### 2026-02-10: Warrant Roster ↔ Workflow Sync Tests — Complete

Created `app/tests/TestCase/Services/WarrantManager/WarrantRosterSyncTest.php` — 16 tests, 25 assertions, all passing.

**Coverage:** `syncWorkflowApprovalToRoster()` (5 tests: record creation, approval_count increment, dedup guard, idempotent success on dup, null notes/approvedOn handling), `activateApprovedRoster()` (4 tests: status→CURRENT, approved_date set, idempotency when already active, ServiceResult return), `activateWarrants` workflow action integration (4 tests: syncs approvals_required from workflow config, syncs all approval responses, sets roster APPROVED, activates warrants after sync), `declineRoster` integration (1 test: syncs approve responses before decline for audit trail), edge cases (2 tests: 0 workflow responses, mixed approve/reject only syncs approves).

**Key testing patterns discovered:**
- `DefaultWarrantManager` uses `QueuedMailerAwareTrait` which calls `queueMail()` — this blows up in test env (no Queue.Mailer job type, no email transport). Fix: partial mock with `onlyMethods(['queueMail'])` and `willReturnCallback(function () {})`. Cannot use `willReturn(null)` because return type is `void`.
- `WorkflowApprovals.approver_type` is validated against enum `['permission','role','member','dynamic','policy']` — must use valid value in test data.
- Integration tests for `WarrantWorkflowActions` work well by using the real `WarrantWorkflowActions` class with a partially-mocked `DefaultWarrantManager` (stubbed email only). This tests the full sync flow without mocking away the sync logic itself.
- `createWorkflowApprovalContext()` helper pattern (definition→version→instance→log→approval) reusable for any workflow action tests.

📌 Team update (2026-02-10): Warrant roster workflow sync implemented — decided by Mal, implemented by Kaylee

### 2026-02-10: Approval Node Context Tests — Complete

Added 4 tests to `app/tests/TestCase/Services/WorkflowEngine/DefaultWorkflowEngineTest.php` verifying `resumeWorkflow()` populates `$context['nodes'][$nodeId]` for approval nodes.

**Tests added:**
1. `testResumeApprovedPopulatesNodesContext` — verifies `status`, `approverId`, `comment`, `decision` set correctly on 'approved' port
2. `testResumeRejectedPopulatesNodesContext` — same for 'rejected' port, also checks `rejectionComment`
3. `testResumeApprovalStillPopulatesResumeData` — backward compatibility: `resumeData` key still present alongside `nodes`
4. `testResumeWithEmptyAdditionalDataPopulatesNullDefaults` — empty `additionalData` doesn't crash, nulls/defaults used

**Testing patterns used:**
- `createAndStartApprovalWorkflow()` helper — creates trigger→approval→(end_ok|end_nope) workflow, starts it, returns `[$instanceId, $nodeId]` with instance in WAITING state
- Resume via `$this->engine->resumeWorkflow()` then re-fetch instance from DB and inspect `$instance->context`
- Tests the fix at lines 217-228 of `DefaultWorkflowEngine.php` — nodes context population matching action/condition patterns
