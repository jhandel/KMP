# Project Context

- **Owner:** Josh Handel (josh@liveoak.ws)
- **Project:** KMP — Membership management system for SCA Kingdoms. Handles members, officers, warrants, awards, activities, and workflow-driven approvals. ~2 years of active development.
- **Stack:** CakePHP 5.x, Stimulus.JS, MariaDB, Docker, Laravel Mix, Bootstrap, plugin architecture
- **Created:** 2026-02-10

## Learnings

<!-- Append new learnings below. Each entry is something lasting about the project. -->

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

### 2026-02-10: Workflow Engine Frontend Review

#### Key UI Patterns
- The workflow designer uses Drawflow (^0.0.60) for the visual canvas — a third-party graph editor library, new dependency for this project
- Designer is a three-panel layout (palette 230px / canvas flex / config 320px) — all CSS, no responsive breakpoints
- Node types have consistent color-coding across palette icons, card accent stripes, and port colors in `workflow-designer.css`
- Context variable picker traverses upstream nodes via Drawflow's connection graph to build available variables

#### Stimulus Controller Structure
- `workflow-designer-controller.js` is 1,279 lines — the largest Stimulus controller in the project by far (most others are 50-200 lines)
- Follows the window.Controllers registration pattern correctly
- 7 targets, 8 values, ~12 action methods
- Contains mixed concerns: canvas init, palette rendering, config panel forms, validation engine, undo/redo history, variable picker, auto-layout algorithm, API calls — all in one file
- Has a bug: save() doesn't send workflowId/versionId to the server, so updates would create new definitions

#### Template Patterns
- index.php and versions.php contain significant inline `<script>` blocks (~160 lines total) instead of Stimulus controllers — breaks project convention
- statusBadge closure is duplicated across 5 templates with inconsistent status-to-color mappings
- Templates use raw `h($created)` instead of TimezoneHelper for date formatting
- view_instance.php shows raw member_id instead of member name for approval responders
- No ARIA attributes anywhere across all 7 workflow templates

#### Navigation Integration
- Registered in CoreNavigationProvider under "Workflows" parent group (order 28) with three children: Definitions, My Approvals, Instances
- Uses bi-diagram-3 icon for the parent nav item
- activePaths configured for designer and versions sub-pages

#### UX Concerns
- Designer is desktop-only — no mobile/tablet fallback, no panel collapse behavior
- No unsaved-changes warning when leaving designer
- No loading/disabled states on save/publish buttons during async operations
- Instances page hard-limits to 100 results with no pagination
- Approval cards are well-structured with responsive grid (col-md-6 col-lg-4)

📌 Team update (2026-02-10): Workflow engine review complete — all 4 agents reviewed feature/workflow-engine. Wash's frontend review merged to decisions.md. P0 save bug confirmed. Backend agents found P0 issues in DI bypass and approval transactions that affect frontend integration. — decided by Mal, Kaylee, Wash, Jayne

📌 Team update (2026-02-10): Warrant roster workflow sync implemented — decided by Mal, implemented by Kaylee

### 2026-02-10: Workflow Designer — Policy Approver Type

Added "By Policy" approver type to `workflow-config-panel.js` `_approvalHTML()`. The seeded warrant workflow uses `approverType: "policy"` with fields `policyClass`, `policyAction`, `entityTable`, `entityIdKey`, and `permission` — but the designer dropdown was missing this option, making policy configs invisible and vulnerable to being wiped on save.

Changes:
- Added `<option value="policy">By Policy</option>` to the approverType dropdown
- Added 5 conditional policy fields (policyClass, policyAction, entityTable, entityIdKey, permission) with inline `display` toggle based on `isPolicy`
- Hid the Permission/Role (`approverValue`) field when policy is selected since policy type uses its own field set
- All new fields use the same `data-action="change->workflow-designer#updateNodeConfig"` pattern
- entityIdKey field has `data-variable-picker="true"` since it references context variables (e.g. `trigger.rosterId`)
- Note: The config panel is statically rendered — visibility toggles are baked into the HTML at render time. When the user changes the dropdown, `updateNodeConfig` fires which re-renders the entire config panel with the new approverType, so the toggle works correctly without JS event listeners.

### 2026-02-10: Workflow Config Panel — Autocomplete Pattern Alignment

Updated all three autocomplete widgets (permission, role, member) in `workflow-config-panel.js` `_approvalHTML()` to match the existing `autoCompleteControl.php` pattern used app-wide.

#### Changes per autocomplete widget:
- Added `role="combobox"` and `class="position-relative kmp_autoComplete"` to the `data-controller="ac"` wrapper div
- Wrapped text input + clear button in `<div class="input-group input-group-sm">` (Bootstrap input group)
- Changed clear button from floating icon (`btn btn-sm btn-link` with absolute positioning + `<i class="bi bi-x-lg">`) to proper Bootstrap `btn btn-outline-secondary` with text "Clear"
- Moved hidden inputs before the input-group to match template element ordering
- Changed results `<ul>` from `class="list-group shadow-sm"` with inline z-index styles to `class="list-group z-3 col-12 position-absolute auto-complete-list"` matching app convention
- Kept `form-control-sm` and `input-group-sm` since this is a sidebar config panel

#### Key pattern: Autocomplete HTML structure
The canonical autocomplete structure from `autoCompleteControl.php` (line 157-179) is:
1. Wrapper: `data-controller="ac"` + `role="combobox"` + `position-relative kmp_autoComplete`
2. Hidden inputs: `data-ac-target="hidden"` then `data-ac-target="hiddenText"`
3. Input group: `div.input-group` containing text input (`data-ac-target="input"`) + clear button (`btn btn-outline-secondary`, `data-ac-target="clearBtn"`, `data-action="ac#clear"`, starts `disabled`, text "Clear")
4. Results list: `ul` with `data-ac-target="results"`, `class="list-group z-3 col-12 position-absolute auto-complete-list"`, `hidden="hidden"`

### 2026-02-10: Autocomplete Helper — JS Reuse Module

Created `app/assets/js/autocomplete-helper.js` — a standalone JS module that generates autocomplete widget HTML matching `autoCompleteControl.php`. This is the JavaScript equivalent of the PHP element, for use when markup is rendered client-side (e.g. workflow config panels, dynamic forms).

#### What was done
- **New file:** `assets/js/autocomplete-helper.js` exports `renderAutoComplete(options)` which returns an HTML string with the canonical autocomplete structure (wrapper div with `data-controller="ac"`, hidden inputs, input-group with Clear button, results list).
- **Refactored:** `workflow-config-panel.js` `_approvalHTML()` now imports and calls `renderAutoComplete()` three times (permission, role, member) instead of having three copy-pasted blocks of identical markup.
- Shared options (`size: 'sm'`, `name: 'approverValue'`, `minLength: 2`, `hiddenAttrs`) are factored into `acSharedOpts` and spread into each call.

#### Reuse pattern established
When JS needs to render autocomplete widgets dynamically:
```js
import { renderAutoComplete } from '../autocomplete-helper.js'
const html = renderAutoComplete({ url: '/endpoint/auto-complete', name: 'field_id', ... })
```
This mirrors how PHP templates use `$this->element('autoCompleteControl', [...])`. Any future JS-rendered autocomplete should use this helper rather than duplicating markup.

### 2026-02-10: Action Schema & Context Mapping — Phase 1+2

#### Variable Picker Fixes (workflow-variable-picker.js)
- **Bug 1 fixed:** Trigger schema lookup changed from `outputSchema` to `payloadSchema` to match registry data. Added `config.inputMapping` awareness — if inputMapping exists, only mapped keys appear as trigger variables.
- **Bug 2 fixed:** Action output paths now include `.result.` segment (`$.nodes.{key}.result.{field}`) matching engine's actual context structure.
- **Bug 3 fixed:** Approval outputs now check `registryData.approvalOutputSchema` first, with hardcoded fallback. Same pattern for `builtinContext`.

#### Config Panel Input Fields (workflow-config-panel.js)
- `_actionHTML()` now renders inputSchema fields below the action dropdown when an action is selected. Fields use `name="params.{key}"` namespace and have `data-variable-picker="true"`.
- `_conditionHTML()` renders inputSchema fields for plugin conditions (non-`Core.` prefix). Built-in condition fields (field, expectedValue) remain for all conditions.
- Added `_escapeAttr()` helper for safe HTML attribute escaping in template literals.

#### Designer Controller (workflow-designer-controller.js)
- `updateNodeConfig()` now handles `params.*` form fields — collects them into `config.params = {}` instead of storing `params.key` flat in config.
- After saving, if the changed field is `action` or `condition`, re-renders the entire config panel to show new inputSchema fields and re-attaches variable pickers.

#### Key Pattern: Registry-first, hardcoded-fallback
- Approval output schema, builtin context variables, and inputSchema fields all check registry data first. If the backend hasn't provided it yet, hardcoded defaults keep things working. As the backend rolls out schema data, the frontend automatically picks it up.

📌 Team update (2026-02-11): Action Schema & Context Mapping — all 5 phases implemented and consolidated. Architecture (Mal), frontend fixes + field rendering (Wash commits 187032cf), backend schema + validation + enrichment (Kaylee commit 6c4528fb). 459 tests pass.

### 2026-02-11: Flow Control Node Config Panels

Extended config panel and variable picker to all remaining node types (commit 5981a134):

#### Config Panel (`workflow-config-panel.js`)
- **Trigger**: Added `inputMapping` UI — when a trigger is selected and has `payloadSchema`, renders mapping fields below dropdown with `name="inputMapping.{key}"` and `data-variable-picker="true"`. Default values are `$.event.{key}`.
- **Delay**: Added `data-variable-picker="true"` to both `duration` and `waitEvent` inputs. Updated placeholder to hint at variable refs.
- **Subworkflow**: New `_subworkflowHTML()` with `workflowSlug` text input.
- **Fork**: New `_forkHTML()` — info-only panel explaining parallel fan-out.
- **Join**: New `_joinHTML()` — info-only panel explaining wait-for-all behavior.
- **End**: New `_endHTML()` with status dropdown (completed/cancelled/failed).
- **Loop**: Verified `exitCondition` already had `data-variable-picker="true"` ✅.
- Updated `getTypeSpecificHTML()` switch to route all 10 node types.

#### Variable Picker (`workflow-variable-picker.js`)
- Added output schemas for `delay` (delayConfig object), `loop` (iteration + maxIterations), and `subworkflow` (childInstanceId + result object).
- Fork, join, end don't produce meaningful outputs — no schemas added.

#### Designer Controller (`workflow-designer-controller.js`)
- `updateNodeConfig()` now handles `inputMapping.*` form field prefix — same nesting pattern as `params.*`.
- Added `event` to the re-render trigger check alongside `action` and `condition`.

#### Key Pattern
- `inputMapping.*` namespace mirrors `params.*` — both are collected from FormData, stripped of prefix, and stored as nested objects in `config`.
- Selecting a trigger event now re-renders the config panel (like action/condition) to show the payload schema fields dynamically.

### 2026-02-11: resumeData Variables in Variable Picker

Added `$.resumeData.*` context variables to `workflow-variable-picker.js` `buildVariableList()`. These appear conditionally — only when a node being configured is downstream of an approval node, since `resumeData` is populated by `DefaultWorkflowEngine::resumeWorkflow()` when an approval gate resumes.

#### What was done
- After collecting upstream node variables, added an `upstream.some(n => n.data?.type === 'approval')` check
- When true, pushes three variables: `$.resumeData.approverId` (integer), `$.resumeData.decision` (string), `$.resumeData.comment` (string)
- Placed BEFORE builtins section so dropdown order is: trigger → upstream nodes → resumeData → builtins

#### Key Pattern
- Conditional variable injection based on upstream node types — first use of this pattern in the variable picker. Could be extended for other context-dependent variables (e.g., loop iteration data only downstream of loops).

📌 Team update (2026-02-11): Three new designer decisions merged — resumeData conditional picker, condition field visibility, flow control config panels — decided by Wash
📌 Team update (2026-02-11): Kaylee fixed approval node context in resumeWorkflow(); Jayne added 4 tests — decided by Kaylee, Jayne
