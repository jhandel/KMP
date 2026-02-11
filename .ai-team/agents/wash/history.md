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

### 2026-02-12: Dynamic Approval Node — Resolver Service Display

Updated `_approvalHTML()` in `workflow-config-panel.js` to handle the `approverConfig` resolver pattern for dynamic approval nodes.

**What changed (lines 268-305):** The `data-approver-section="dynamic"` div now uses an IIFE to detect two sub-modes:
1. **Resolver service mode** (`config.approverConfig.service` exists): Shows read-only "Resolver Service" and "Resolver Method" fields, plus any custom config keys (e.g. `activity_id`) rendered via `renderValuePicker()` with context path support. Internal keys (`service`, `method`, `serial_pick_next`, `exclude_member_ids`, `current_approver_id`, `approval_chain`) are excluded from custom fields.
2. **Simple context path mode** (no `service` key): Shows the original "Context Path" text input — backward compatible.

**Pattern used:** IIFE inside template literal (`${(() => { ... })()}`) to keep conditional logic inline without restructuring the surrounding template. Custom config fields use `approverConfig.{key}` as the field name for `renderValuePicker()`.

**Second `data-approver-section="dynamic"` div (Serial Pick Next toggle, lines 353-361):** Left unchanged — it controls a separate feature.
📌 Team update (2026-02-12): Approval nodes MUST use nested `approverConfig` for dynamic resolvers. Engine flat config fallback added for backward compat. — decided by Kaylee
📌 Team update (2026-02-12): `action-create` node removed from Activities Authorization workflow to fix dual-write bug. — decided by Kaylee

### 2026-02-12: Dynamic Resolver — Fully Editable Config

Made the dynamic approval resolver section fully editable in the workflow designer config panel.

#### Changes to `workflow-config-panel.js` (`_approvalHTML()`, lines 268-323)
- **Service & method fields**: Replaced `readonly disabled` inputs with editable inputs using `name="approverConfig.service"` and `name="approverConfig.method"` with `change->workflow-designer#updateNodeConfig` actions.
- **Removed if/else branch**: Always shows resolver fields when `approverType` is `dynamic` — no more separate "Context Path" fallback. Empty fields shown when no resolver configured yet.
- **Custom params with remove buttons**: Each custom param row wrapped in `d-flex` with a `btn-outline-danger` × button (`click->workflow-designer#removeResolverParam`).
- **Add Param form**: `input-group` with text input (`data-resolver-new-key`) and "Add Param" button (`click->workflow-designer#addResolverParam`).

#### Changes to `workflow-designer-controller.js`
- **`updateNodeConfig()`**: Added `approverConfig.*` namespace handling parallel to `params.*` — collects into `newApproverConfig` object, composed via VP when applicable, stored as `config.approverConfig`. VP skip logic extended for `approverConfig.*` fields.
- **`addResolverParam()`**: Reads key from `[data-resolver-new-key]` input, adds empty string value to `approverConfig[key]`, re-renders config panel.
- **`removeResolverParam()`**: Reads key from `data-resolver-param-key` attribute, deletes from `approverConfig`, re-renders config panel.

📌 Team update (2026-02-11): Dynamic resolver config fields (service, method, custom params) must be fully editable, not read-only — supersedes prior read-only decision — decided by Josh Handel

### 2026-02-12: Resolver Dropdown — Registry-Driven Selection

Replaced free-text resolver service and method inputs in the dynamic approval section with **dropdowns populated from the `WorkflowApproverResolverRegistry`**.

#### Changes to `workflow-config-panel.js`
- **Constructor**: Now stores `this.resolvers = registryData.resolvers || []` from registry data.
- **Resolver dropdown**: `<select name="resolverKey">` replaces free-text service input. Options populated from `this.resolvers` array, showing `label (source)`. Fires `change->workflow-designer#onResolverChange`.
- **Method field**: Read-only `<input readonly disabled>` auto-populated from selected resolver's `method` property.
- **Schema-driven params**: When a resolver is selected, its `configSchema` fields are rendered via `renderValuePicker()` with context path support. Each schema field gets proper label, type, required flag, and description from the registry.
- **Custom params**: Only params NOT in `configSchema` AND NOT in `internalKeys` are shown with remove buttons. `skipKeys` = `[...internalKeys, ...schemaKeys]`.
- **Add Param form**: Kept for extra custom params beyond what the schema defines.

#### Changes to `workflow-designer-controller.js`
- **`onResolverChange(event)`**: New handler. Gets selected resolver key from dropdown, finds resolver data from `_configPanel.resolvers`, sets `approverConfig.service` and `approverConfig.method`, pre-populates schema fields with empty values, re-renders config panel.
- **Registry data flow**: `resolvers` array flows naturally through `registryData` already passed to `WorkflowConfigPanel` constructor — no additional wiring needed.

#### Data flow
1. `/workflows/registry` returns `resolvers[]` alongside triggers/actions/conditions/entities
2. `loadRegistry()` stores full response as `this.registryData` (already includes resolvers)
3. `WorkflowConfigPanel` constructor extracts `this.resolvers` from registry data
4. Dropdown selection → `onResolverChange` → updates node data → re-render shows schema fields

📌 Team update (2026-02-12): Resolver service input is now a dropdown populated from registry. Method is auto-set (read-only). Config schema drives param fields. Free-text service/method inputs removed.
### 2026-02-10: Frontend Architecture (summarized from full audit)

#### Asset Pipeline
- Config: `app/webpack.mix.js`. Build: `npm run dev`/`npm run prod` (in `app/`)
- Entry: `assets/js/index.js` → `webroot/js/index.js`
- Controllers auto-discovered from `assets/js/controllers/` and `plugins/*/assets/js/controllers/` → `webroot/js/controllers.js`
- Core libs (bootstrap, stimulus, turbo) extracted → `webroot/js/core.js`
- CSS: `app.css`, `signin.css`, `cover.css`, `dashboard.css`. Only Waivers plugin CSS auto-compiled — other plugins must be manually added to webpack.mix.js
- Runtime: bootstrap 5.3.6, stimulus 3.2.2, turbo 8.0.21, easymde, pdfjs-dist, qrcode, fontawesome 7.1
- **Turbo Drive DISABLED** — only Turbo Frames used

#### Controller Registration
All controllers use `window.Controllers["name"] = ControllerClass` pattern. Registered in `index.js` via `stimulusApp.register()` loop. NOT Stimulus webpack auto-loader.

#### Controller Inventory (81 total)
**Core (60):** Grid/data (`grid-view`, `filter-grid`, `csv-download`), forms (`app-setting-form`, `member-verify-form`, `gathering-form`, `role-add-*`, `permission-*`), UI (`detail-tabs`, `modal-opener`, `turbo-modal`, `popover`, `delete-confirmation`, `image-preview/zoom`), communication (`outlet-btn` — hub for inter-controller events), editor (`code-editor`, `markdown-editor`, `email-template-*`), autocomplete (`ac`), mobile/PWA (`member-mobile-card-*`, `mobile-calendar`, `mobile-offline-overlay`), misc (`session-extender`, `timezone-input`, `qrcode`, `kanban`, `sortable-list`, `nav-bar`, `delayed-forward`).

**Plugin:** Activities (5: auth request/approve/renew, mobile, GW sharing), Officers (5: roster search/table, edit/assign officer, office form), Waivers (10: upload wizard, camera, calendar, attestation, template, exemptions, retention, add-requirement), GitHubIssueSubmitter (1), Template (1: hello-world demo).

#### Template System
- 6 layouts: default (block-based), ajax, turbo_frame, mobile_app (PWA), public_event, error
- Blocks: `$this->KMP->startBlock()`/`endBlock()` — works across view cells
- Plugin content via `pluginTabButtons.php`, `pluginTabBodies.php`, `pluginDetailBodies.php` elements
- ViewCellRegistry: types `tab`, `detail`, `modal`, `json`, `mobile_menu`. Route-matched via `validRoutes`.

#### Tab Ordering
CSS flexbox with `data-tab-order="N"` + `style="order: N;"` on both button and content. Plugin tabs from ViewCellRegistry config. Guidelines: 1-10 plugins, 10-20 primary, 20-30 secondary, 30+ admin, 999 fallback. State in URL.

#### CSS
Bootstrap 5.3.6 primary. Icons: Bootstrap Icons (CDN) + FontAwesome (npm). Custom CSS minimal — use Bootstrap utilities first. Plugin CSS in `plugins/{Plugin}/assets/css/`.

#### View Helpers
KmpHelper (block mgmt, autocomplete, CSV, settings), MarkdownHelper, TimezoneHelper, SecurityDebugHelper. AppView loads: AssetMix, Identity, Bootstrap.Modal/Navbar, Url, Kmp, Markdown, Glide, Tools.Format/Time, Icon, Timezone, SecurityDebug.

#### Key Conventions
- Controller files: `{name}-controller.js` (kebab-case)
- Registration key matches kebab-case, except `auto-complete` → `ac`
- Inter-controller: `outlet-btn` dispatches events, connected controllers handle via `outletBtnOutletConnected/Disconnected`
- Empty controller files exist: `gathering-public`, `mobile-hub`
- Two duplicate `hello-world-controller.js` (Template and Waivers plugins)

#### Key Paths
JS: `app/assets/js/controllers/`, `app/assets/js/index.js`, `app/assets/js/KMP_utils.js`, `app/assets/js/timezone-utils.js`. CSS: `app/assets/css/`. Build: `app/webpack.mix.js`. Layouts: `app/templates/layout/`. Elements: `app/templates/element/`. Helpers: `app/src/View/Helper/`. Cells: `app/src/View/Cell/`. Compiled: `app/webroot/js/`, `app/webroot/css/`.

📌 Team update (2026-02-10): Architecture overview documented — plugin registration flow, ViewCellRegistry/NavigationRegistry patterns, 8 dangerous-to-change areas including window.Controllers pattern — decided by Mal
📌 Team update (2026-02-10): Backend patterns documented — ServiceResult pattern, DI registration, plugin architecture conventions, email sending must be async via queue — decided by Kaylee
📌 Team update (2026-02-10): Test suite audited — 88 files but ~15-20% real coverage, no frontend/JS tests exist, no CI pipeline — decided by Jayne
📌 Team update (2026-02-10): Josh directive — no new features until testing is solid. Test infrastructure is the priority. — decided by Josh Handel
📌 Team update (2026-02-10): Test infrastructure overhaul complete — all 370 project-owned tests pass (was 121 failures + 76 errors). Auth strategy: standardize on TestAuthenticationHelper, deprecate old traits. — decided by Jayne, Kaylee, Mal

📌 Team update (2026-02-10): Queue plugin ownership review — decided to own the plugin, security issues found, test triage complete

📌 Team update (2026-02-10): Documentation accuracy review completed — all 4 agents reviewed 96 docs against codebase

### 2026-02-10: Frontend Documentation Modernization

Completed 9 documentation tasks (8 modified, 1 no-change-needed):

#### Full Rewrites
- **10.4-asset-management.md**: Replaced with accurate webpack.mix.js config showing dynamic controller/service discovery, correct output files (index.js, controllers.js, core.js, manifest.js), full npm scripts from package.json, AssetMix helper instead of Html, accurate KMP_utils implementation (regex-based not DOM-based), plain CSS not SCSS
- **10.1-javascript-framework.md**: Fixed Turbo version (^8.0.21 not ^8.0.4), corrected index.js imports (includes timezone-utils.js and specific controller imports), documented Turbo.session.drive = false, added full dependency table with versions, documented turbo:render tooltip re-init
- **10.2-qrcode-controller.md**: Fixed errorCorrectionLevel default (H not M), documented canvas target is a div (not canvas element), documented Promise-based generate() with throw Error for missing values, documented actual download mechanism (toDataURL approach), fixed registration section

#### Targeted Fixes
- **9-ui-components.md**: Removed fictional form-handler and toasts controllers, fixed autocomplete controller name to "ac" with note about auto-complete-controller.js filename
- **4.5-view-patterns.md**: Added missing helpers (Markdown, Timezone, SecurityDebug), added missing layouts (mobile_app.php, public_event.php)
- **9.1-dataverse-grid-system.md**: Fixed Gatherings→GatheringsGridColumns mapping (was wrong as GatheringTypesGridColumns), added missing GatheringTypes row, removed non-existent applyFilter method
- **9.2-bootstrap-icons.md**: Corrected both version references to 1.11.3 (was 1.13.1 and 1.11)
- **10-javascript-development.md**: Removed duplicate Detail Tabs and Modal Opener controller sections, fixed controller example to use window.Controllers pattern instead of export default

#### No Change Needed
- **10.3-timezone-handling.md**: timezone_examples.php element confirmed to exist, no fix required

#### Key Learnings
- The qrcode controller's canvas target is a container div, not a canvas element — the controller creates the canvas dynamically
- Bootstrap Icons version is 1.11.3 (from CSS header), not managed via npm
- Only Waivers plugin CSS is auto-compiled; other plugins need manual webpack.mix.js entries
- Service files (assets/js/services/) are also bundled into controllers.js
