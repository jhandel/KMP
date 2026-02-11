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

### 2026-02-10: Test Infrastructure Fixes (summarized)

KmpHelper static state bug fixed (4 lines in `KmpHelper.php`). Template HelloWorldControllerTest requires `PluginIntegrationTestCase`, CSRF/security tokens, and `$_SESSION['Flash']['flash']` for flash assertions.

📌 Team updates (2026-02-10, consolidated): Architecture (Mal), backend patterns (Kaylee), frontend patterns (Wash) all documented. Test plan: 6 phases, Jayne owns 1-3/4.1/4.2a/5/6. Auth triage: 15 TEST_BUGs, 2 CODE_BUGs fixed, 370 tests pass. Auth strategy: standardize TestAuthenticationHelper (gap: authenticateAsSuperUser lacks permissions).

### 2026-02-10: Queue Plugin Test Fixes (summarized)

**Triage:** 119 tests, 81 failed — all from 5 infrastructure root causes (not code bugs): redundant loadPlugins (16 errors), wrong Admin prefix routes (29 errors), missing TestApp autoload (15 errors), removed fixture declarations (16 errors), email transport config (3 errors).

**Fixes:** All 81 failures resolved → 0 errors, 0 failures, 6 skips. Removed loadPlugins from 9 files, removed Admin prefix from 3 files, added autoload-dev entries, restored fixtures to 14 files, configured Debug transport. Also deleted 9 test files for removed example tasks, added auth to controller tests, created missing test infrastructure. Key insight: Queue uses CakePHP fixture isolation, NOT BaseTestCase — don't migrate yet.

📌 Team updates (2026-02-10, consolidated): Docs accuracy review (96 docs), docs modernization (13 fixes across 12 files), workflow engine testability audit (0% coverage, ~190 tests planned across 5 phases, key risks: no depth guard, no row locking, fork/join deadlock). All 4 agents reviewed workflow engine.

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

### 2026-02-11: Activities Authorization Approval Workflow — E2E Test Results

**E2E tests run via Playwright against http://localhost:8080. 16 screenshots saved to `test-results/`.**

**TEST 1: Workflow Designer — Activities Triggers/Actions ✅ PASS**
- Workflow designer (`/workflows/designer/2`) loads fully with node palette
- **Triggers — Activities** section present with: "Authorization Requested", "Authorization Retracted"
- **Actions — Activities** section present with: "Create Authorization Request", "Activate Authorization", "Handle Authorization Denial", "Notify Approver", "Notify Requester"
- Flow control, Approvals (Approval Gate), Conditions — all present and rendering
- No JS console errors

**TEST 2: Approvals Pages ✅ PASS**
- `/workflows/approvals` loads for admin, agatha, devon, haylee — all render correctly with Pending/Decisions tabs and grid
- `/activities/authorization-approvals/my-queue` loads for admin with 6 pending auth requests (Approve/Deny buttons visible)
- Auth queue correctly unauthorized for agatha, devon, haylee (permission-gated via `authorizeModel('myQueue')` in `AuthorizationApprovalsController`)
- Pending Auths badge (6) visible in admin's sidebar

**TEST 3: Eligible Approvers Endpoint ✅ PASS**
- `/workflows/eligible-approvers/1` returns HTTP 200 (empty body = no eligible approvers for that ID, not a 500)
- Route correctly defined in `/workflows` scope, mapped to `WorkflowsController::eligibleApprovers()`

**TEST 4: Activities Plugin Smoke Tests ✅ PASS**
- `/activities/activities` — loads with grid showing activities list (Name, Activity Group, Grants Role, Duration, Min/Max Age, # for Auth columns)
- `/activities/activity-groups` — loads with 7 groups (Armored Combat, Cut & Thrust, Equestrian, Missile, Rapier, Youth Armored, Youth Rapier)
- `/members/view-card/1` — auth card renders correctly
- `/workflows` — definitions page shows Officer Hire and Warrant Roster Approval workflows
- `/workflows/instances` — loads cleanly (0 instances in dev env)
- No JS console errors on any tested page

**Findings:**
- Auth queue (`/activities/authorization-approvals/my-queue`) requires admin-level permissions — only super user can access it. Non-admin approvers (agatha, devon, haylee) all get unauthorized. This may be intentional but worth verifying the policy allows officer-level users who should be approving auths.
- `/activities/authorizations` (no action) returns MissingActionException — controller has no `index()` action mapped to default route. Not a bug, just no top-level listing for authorizations (they're accessed per-member).
- Workflow designer nodes palette shows "Loading..." initially, resolves after ~2-3 seconds (lazy load via JS fetch)

📌 Team update (2026-02-11): Activities authorization seed migration implemented (Phase 4) — 9-node graph, is_active=0, matches architecture spec — implemented by Kaylee
📌 Team update (2026-02-11): Activities workflow scope limited to submit-to-approval only; Revoked/Expired out-of-band — decided by Josh Handel
📌 Team update (2026-02-12): Dynamic resolver config fixed (nested approverConfig + engine fallback) and `action-create` node removed from Activities workflow. Config panel updated with resolver service display. — decided by Kaylee, Wash
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

### 2026-02-10: Queue Plugin Test Triage — Complete

**119 tests total: 38 pass, 81 fail (68 errors + 13 failures). 0 CODE_BUGs. 0 COMPAT issues.**

All 81 failures stem from 5 infrastructure/config root causes — the Queue plugin was ripped from its standalone test harness and dropped into KMP without adapting either side.

**Root causes (ordered by impact):**
1. **"Plugin already loaded"** (16 errors) — Queue tests call `$this->loadPlugins(['Queue'])` but KMP bootstrap already loads it. Fix: remove the calls.
2. **Missing Admin prefix routes** (29 errors) — Controller tests use `'prefix' => 'Admin'` but controllers were moved out of Admin namespace. Fix: remove `prefix` from URL arrays.
3. **TestApp/Foo autoload missing** (15 errors) — Queue's `composer.json` has `autoload-dev` for test stubs (`TestApp\`, `Foo\`) but KMP's doesn't include them. Fix: add to KMP's `autoload-dev`.
4. **No data isolation** (16 errors/failures) — Commit `6e25eea4` bulk-deleted `$fixtures` declarations. Queue tests need fixtures for table truncation. Fix: restore fixture declarations.
5. **Email transport config** (3 errors/failures) — Tests expect `Debug` transport, KMP configures `Smtp`. Fix: configure Debug transport in test setUp.

**Silver bullet:** Fixes #1 + #2 resolve 45 of 68 errors. All 5 fixes together resolve all 81 failures.

**Key insight:** Queue tests use CakePHP fixture-based isolation, NOT BaseTestCase transaction wrapping. The fixture removal was the biggest self-inflicted wound — it broke data isolation for every test that writes to `queued_jobs` or `queue_processes`. Do NOT migrate Queue to BaseTestCase yet — restore fixtures first, evaluate migration after all 119 pass.

**Controller auth warning:** Once routes are fixed, controller tests will likely hit KMP's auth middleware. That's a Phase 5 problem — tests don't authenticate as any user.

Full triage report: `.ai-team/decisions/inbox/jayne-queue-test-triage.md`

📌 Team update (2026-02-10): Queue plugin ownership review — decided to own the plugin, security issues found, test triage complete

### 2026-02-10: Queue Plugin Test Fixes — All 5+ Root Causes Fixed

**Result: 0 errors, 0 failures, 6 skips (from 81 failures)**

Fixed all root causes from the triage:
1. **loadPlugins**: Removed redundant `$this->loadPlugins(['Queue'])` from 9 files
2. **Admin prefix**: Removed `'prefix' => 'Admin'` from 3 controller test files (18 occurrences)
3. **Autoload**: Added TestApp/Foo PSR-4 entries to `composer.json` autoload-dev
4. **Fixtures**: Added `$fixtures` declarations to 14 test files
5. **Email transport**: Force Debug transport in setUp for EmailTaskTest, MailerTaskTest, SimpleQueueTransportTest

**Additional fixes discovered during execution:**
- `app_queue.php` had EmailTask in `ignoredTasks` — cleared the list (all other entries were deleted example tasks)
- Deleted 9 test files for Kaylee's deleted example/execute tasks
- Updated 22 test files to reference Queue.Email/Queue.Mailer instead of deleted tasks
- Added TestAuthenticationHelper + CSRF/security tokens to controller tests
- Created test fixture file and email template for missing test infrastructure
- Fixed TestMailer to produce correct debug output format
- 3 tests skipped: bake reference files not migrated, TestApp Foo task not scannable

**Auth update:** Controller auth works via `TestAuthenticationHelper::authenticateAsSuperUser()` — no issues with Queue controllers + authorization once authenticated.

Full report: `.ai-team/decisions/inbox/jayne-queue-test-fixes.md`

📌 Team update (2026-02-10): Documentation accuracy review completed — all 4 agents reviewed 96 docs against codebase

### 2026-02-10: Documentation Modernization — 13 Tasks Completed

Fixed 13 documentation issues across 12 files by verifying each claim against actual source code.

**Key corrections:**
- Deleted duplicate `8-development-workflow.md`
- Rewrote `7-development-workflow.md` from scratch with correct test suites (`core-unit`/`core-feature`/`plugins`), correct base classes (`BaseTestCase`/`HttpIntegrationTestCase`/`PluginIntegrationTestCase`), correct data strategy (seed SQL + transactions, NOT fixtures)
- Fixed `KINGDOM_BRANCH_ID` from 1 → 2, `TEST_BRANCH_LOCAL_ID` from 1073 → 14 in testing docs
- Fixed session timeout from "4 hours" → "30 minutes" in security docs
- Corrected session config location from `app_local.php` → `app.php`
- Removed non-existent `bin/cake security generate_salt` command references (replaced with `php -r`)
- Removed non-existent `npm run lint` / `npm run lint:fix` references
- Removed non-existent `StaticHelpers::logVar()` reference
- Removed non-existent `update_seed_data.sh` reference
- Removed non-existent `DOCUMENTS_STORAGE_ADAPTER` env var reference
- Fixed PHP version from 8.0/8.1 → 8.3 across 4 docs (1-introduction, 8-deployment, index)
- Fixed PHP-FPM socket path from `php8.0-fpm.sock` → `php8.3-fpm.sock`
- Fixed Bootstrap docs link from 5.0 → 5.3
- Fixed session type from "database-backed" → "PHP file-based" in ER diagrams
- Fixed config loading hierarchy in environment setup docs
- Replaced deprecated `SuperUserAuthenticatedTrait` guidance with `HttpIntegrationTestCase`
- Removed false "fact-checked" claims from index.md

**Files modified:** `docs/index.md`, `docs/1-introduction.md`, `docs/2-configuration.md`, `docs/2-getting-started.md`, `docs/3.5-er-diagrams.md`, `docs/7-development-workflow.md`, `docs/7.1-security-best-practices.md`, `docs/7.3-testing-infrastructure.md`, `docs/8-deployment.md`, `docs/8.1-environment-setup.md`, `docs/appendices.md`
**Files deleted:** `docs/8-development-workflow.md`
