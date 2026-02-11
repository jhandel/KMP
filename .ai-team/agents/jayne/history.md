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

### 2026-02-10: Workflow Engine Testability Audit (summarized)

**Coverage: 0%.** 7 tables, 7 entities, 3 service classes (~2200 LOC), 16 controller actions, 3 policies, 2 queue tasks — all untested.

**Critical paths:** `startWorkflow()` (recursive node execution), `resumeWorkflow()` (WAITING→RUNNING + port routing), `publish()` (transactional), `validateDefinition()` (graph integrity), `recordResponse()` (5 eligibility types + dedup), `evaluateExpression()` (6 operators), controller authorization.

**Key risks:** `executeNode()` recursive with no depth guard (stack overflow on cycles), `recordResponse()` no row locking (race condition), fork/join deadlock (no dead-path detection), `migrateInstances()` bypasses validation.

**Test infrastructure:** Use `BaseTestCase`, create data inline (no seed workflow data), static registries need `reset()`, controller bypasses DI (`new`), `TriggerDispatcher` hardcoded — untestable without refactor. Strategy: 5 phases, ~190 tests total.

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

📌 Team update (2026-02-11): Approval node context tests consolidated with Kaylee's fix into single decision — decided by Scribe

📌 Team update (2026-02-11): Universal value picker implemented — `resolveParamValue()` backend (Kaylee) + `renderValuePicker()` frontend (Wash) replace all ad-hoc parameter input patterns. All 5 config panels refactored. Jayne should add unit tests for `resolveParamValue()` covering fixed/context/app_setting/unknown type paths. — decided by Mal, Kaylee, Wash
📌 Team update (2026-02-11): Duplicate email fix — `activateApprovedRoster()` now accepts `$sendNotifications` param. Workflow action passes `false`. Consider workflow integration test tracing email sends through activate→notify flow. — decided by Kaylee
