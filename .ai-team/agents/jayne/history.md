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
