# Project Context

- **Owner:** Josh Handel (josh@liveoak.ws)
- **Project:** KMP — Membership management system for SCA Kingdoms. Handles members, officers, warrants, awards, activities, and workflow-driven approvals. ~2 years of active development.
- **Stack:** CakePHP 5.x, Stimulus.JS, MariaDB, Docker, Laravel Mix, Bootstrap, plugin architecture
- **Created:** 2026-02-10

## Learnings

<!-- Append new learnings below. Each entry is something lasting about the project. -->


### 2026-02-10: Frontend Architecture (summarized)

**Asset pipeline:** `app/webpack.mix.js`. Entry: `assets/js/index.js`. Controllers auto-discovered → `controllers.js`. Core libs extracted → `core.js`. Only Waivers plugin CSS auto-compiled. Turbo Drive DISABLED (Frames only). 81 controllers total using `window.Controllers["name"]` registration.

**Template system:** 6 layouts (default, ajax, turbo_frame, mobile_app, public_event, error). Blocks via `$this->KMP->startBlock()/endBlock()`. ViewCellRegistry: types tab/detail/modal/json/mobile_menu, route-matched. Tab ordering via CSS flexbox `data-tab-order="N"` + `style="order: N;"`.

### 2026-02-10: Documentation Modernization (summarized)

9 tasks (8 modified, 1 confirmed accurate). Full rewrites: asset management, JS framework, qrcode controller. Targeted fixes: UI components (removed fictional controllers), view patterns (added helpers/layouts), grid system (fixed Gatherings mapping), Bootstrap Icons (1.11.3), JS development (removed duplicate sections).

### 2026-02-10: Workflow Engine Frontend + Designer Features (summarized)

**Designer review:** Drawflow v0.0.60, three-panel layout, 1,279-line monolith controller. Save bug (no workflowId/versionId sent). Inline scripts in templates break convention. No ARIA, no mobile fallback, no unsaved-changes warning.

**Policy approver type:** Added "By Policy" option to approval config with 5 conditional fields (policyClass, policyAction, entityTable, entityIdKey, permission).

**Autocomplete alignment:** Updated permission/role/member autocomplete widgets to match `autoCompleteControl.php` pattern. Created `autocomplete-helper.js` with `renderAutoComplete(options)` as JS equivalent of PHP element.

**Action schema phases 1-2:** Fixed variable picker bugs (trigger `payloadSchema`, action `.result.` path, approval output schema). Added inputSchema field rendering to `_actionHTML()` and `_conditionHTML()`. `params.*` namespace collected into `config.params = {}`.

📌 Team updates (2026-02-10): Architecture documented, backend patterns documented, test suite audited, workflow engine reviewed, roster sync implemented, docs modernized.
📌 Team update (2026-02-11): All 5 action schema phases complete. 459 tests pass.

### 2026-02-11: Workflow Designer Frontend Features (summarized)

**Flow control config panels:** Extended config panel and variable picker to all 10 node types. Trigger gets `inputMapping` UI, delay/subworkflow/fork/join/end/loop get dedicated panels. `inputMapping.*` namespace mirrors `params.*` pattern. Variable picker now includes output schemas for delay, loop, subworkflow.

**resumeData variables:** Added conditional `$.resumeData.*` variables to variable picker — only shown downstream of approval nodes. First use of upstream-type-dependent variable injection.

**RequiredCount smart selector:** Type dropdown (Fixed/App Setting/Context Path) with dynamic inputs. Form uses separate named inputs composed into final value in `updateNodeConfig()`. App settings fetched from `/app-settings/workflow-list.json`.

**Universal value picker (`renderValuePicker()`):** Generalized requiredCount pattern into reusable method on WorkflowConfigPanel. All 5 config panels refactored to use it. Data attributes: `data-vp-type`, `data-vp-field`, `data-vp-settings-select`. Generic `onValuePickerTypeChange()` handler. Deleted `_requiredCountHTML()` prototype. **Pattern:** Fixed values stay as plain scalars (backward compatible), context becomes `{type: 'context', path}`, app_setting becomes `{type: 'app_setting', key}`.

📌 Team updates (2026-02-11): Flow control panels, resumeData conditional picker, requiredCount smart selector, universal value picker. All 5 config panels use renderValuePicker().
📌 Team update (2026-02-11): Universal value picker backend (Kaylee) + frontend (Wash) complete. 463 tests pass.
📌 Team update (2026-02-11): Duplicate email fix — `activateApprovedRoster($sendNotifications=true)` added. — decided by Kaylee

### 2026-02-12: Resizable Config Panel in Workflow Designer

Made the workflow designer config panel wider and user-resizable:

- **Default width**: Increased from 320px to 400px to fit value picker dropdowns better.
- **Resize handle**: Added a 5px invisible drag handle on the left edge of the config panel (`.config-panel-resize-handle`). Highlights with `var(--bs-primary)` on hover/drag.
- **Drag logic**: `onResizeStart()` / `_onResizeMove()` / `_onResizeEnd()` in `workflow-designer-controller.js`. Uses mousedown/mousemove/mouseup pattern with bound listeners cleaned up on disconnect.
- **Constraints**: min-width 300px, max-width 60vw.
- **Persistence**: Saves width to `localStorage` key `wf-config-panel-width`, restored on `connect()`.
- **Canvas auto-resize**: Canvas uses `flex: 1` so it automatically takes remaining space when panel width changes — no extra work needed.
- **Files changed**: `workflow-designer.css`, `designer.php` (template), `workflow-designer-controller.js`.

### 2026-02-12: Collapsible Navigation Sidebar

Added desktop sidebar collapse/expand toggle to both `dashboard.php` and `view_record.php` layouts.

#### Architecture
- **Stimulus controller**: `sidebar-toggle-controller.js` — toggles `sidebar-collapsed` class on `document.body`, persists state in `localStorage` key `kmp-sidebar-collapsed`. Swaps icon between `bi-chevron-bar-left` / `bi-chevron-bar-right`.
- **CSS**: Added to `dashboard.css` inside `@media (min-width: 768px)` block. Uses `transform: translateX(-100%)` on `.sidebar` and overrides Bootstrap grid classes on `main[role="main"]` to full width. `sidebar-no-transition` class suppresses animation on initial page load.
- **Toggle button**: Placed inside `.navbar-brand` div, visible only on `d-md` and up (mobile already has Bootstrap collapse toggle). Uses `data-controller="sidebar-toggle"` directly on the button element.

#### Key Pattern
- Body class toggle (`sidebar-collapsed`) with CSS-only layout changes — no JS DOM manipulation of grid classes. The fixed-position sidebar slides out via `translateX(-100%)` and `visibility: hidden`; main content overrides Bootstrap's `col-lg-10` to `100%` width.
- Double `requestAnimationFrame` trick to add `sidebar-no-transition` class, ensuring the collapsed state is applied without a visible animation when the page first loads.

### 2026-02-12: Approvals Page — Converted to DataverseGrid

Rewrote `app/templates/Workflows/approvals.php` from a hand-coded card+table layout to a lazy-loading DataverseGrid pattern, matching `WarrantRosters/index.php`.

#### What changed
- Removed ~130 lines of hand-coded cards (pending approvals) and table (recent decisions) with inline pagination
- Replaced with a ~20-line template: extends dashboard layout, sets title block, renders `<h3>` header, and calls `$this->element('dv_grid', [...])` pointing at `approvalsGridData` endpoint
- Grid lazy-loads via Turbo Frame (`approvals-grid`), server provides system views (Pending Approvals / Decisions) as tabs automatically
- No asset changes needed — `dv_grid` element and `grid-view` controller already exist
📌 Team update (2026-02-11): Approvals DataverseGrid backend architecture decided — Kaylee defined ID pre-filtering for pending tab, post-pagination virtual fields, skipAuthorization on scoped endpoint

📌 Team update (2026-02-11): Warrant roster migration → Forward-Only (Option B). No historical data migration. Sync layer stays. Workflow engine unaffected — no synthetic instances will be created. — decided by Mal, Kaylee

### 2026-02-12: Serial Pick-Next Approver — Frontend (Phase 3)

Implemented frontend for the `serialPickNext` approval node enhancement per Mal's architecture proposal.

#### 1. Workflow Designer Config Panel — serialPickNext Toggle

- **File:** `assets/js/controllers/workflow-config-panel.js` (`_approvalHTML()`)
- Added a `form-switch` toggle "Serial Pick Next Approver" inside a `data-approver-section="dynamic"` wrapper — only visible when `approverType` is `dynamic`.
- Tooltip text: "Each approver picks the next approver from the eligible pool. Approvals happen one at a time in sequence."
- When checked, `allowParallel` is forced unchecked and disabled.
- `config.serialPickNext` stored as boolean alongside existing `allowParallel`.

- **File:** `assets/js/controllers/workflow-designer-controller.js`
- Added `onSerialPickNextChange()` handler: forces `allowParallel` unchecked/disabled when serial is on.
- `updateNodeConfig()` now reads `serialPickNext` checkbox alongside `allowParallel`.

#### 2. Approval Response UI — "Pick Next Approver" Modal

- **File:** `templates/Workflows/approvals.php`
- Added Bootstrap modal `#approvalResponseModal` with decision radios (Approve/Reject), comment textarea, and conditional "Select Next Approver" autocomplete picker.
- Next approver section shown only when: decision=approve AND serialPickNext=true AND approvedCount+1 < requiredCount.
- Autocomplete uses `data-controller="ac"` calling `/workflows/eligible-approvers/{approvalId}`.
- Form submits to `/workflows/record-approval` with `approvalId`, `decision`, `comment`, and `next_approver_id`.
- `show.bs.modal` listener extracts approval data from `outlet-btn` data attributes.

- **File:** `assets/js/controllers/approval-response-controller.js` (NEW)
- Stimulus controller for the modal form. Values: `serialPickNext`, `requiredCount`, `approvedCount`, `approvalId`, `eligibleUrl`.
- `configure()` method resets form and updates visibility/required state.
- `_updateVisibility()` shows/hides next approver section based on decision + serial config.
- Registered as `window.Controllers["approval-response"]`.

#### 3. Grid Row Actions

- **File:** `src/KMP/GridColumns/ApprovalsGridColumns.php`
- Added `getRowActions()` — "Respond" button (modal type) for pending approvals, passes `id`, `approver_config`, `required_count`, `approved_count` via `outlet-btn`.

- **File:** `src/Controller/WorkflowsController.php`
- `approvalsGridData()` now passes `rowActions` to view.
- Added `eligibleApprovers(int $approvalId)` endpoint — returns HTML `<li>` elements compatible with `ac` controller, with branch-qualified SCA names.
- `recordApproval()` now extracts `next_approver_id` from request data (stored for when Kaylee adds `$nextApproverId` param to `recordResponse()`).

- **File:** `config/routes.php`
- Added `/workflows/eligible-approvers/{approvalId}` route.

#### Dependencies on Kaylee (Backend Phase 2)
- `DefaultWorkflowApprovalManager::recordResponse()` needs `$nextApproverId` parameter
- `isMemberEligible()` needs `current_approver_id` check for serial-pick-next approvals
- `recordApproval()` controller call currently ignores `$nextApproverId` — will wire through when backend accepts it

📌 Team update (2026-02-11): Activities workflow scope limited to submit-to-approval only; Revoked/Expired out-of-band — decided by Josh Handel
📌 Team update (2026-02-11): Auth queue permission gating question — MoAS/Armored users get unauthorized on auth queue page. May affect approval UI routing — found by Jayne
