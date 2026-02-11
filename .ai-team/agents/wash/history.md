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

### 2026-02-11: Approval RequiredCount — Smart Config Selector

Replaced the plain `<input type="number">` for `requiredCount` in the approval config panel with a type selector + dynamic value input.

#### Problem
The backend supports `requiredCount` as a plain integer OR an object (`{type: 'app_setting', key: '...'}`, `{type: 'context', path: '...'}`, `{type: 'fixed', value: N}`). The old number input showed blank when the value was an object, and saved incorrectly.

#### Changes
- **`workflow-config-panel.js`**: Extracted `_requiredCountHTML(config)` method. Parses existing `requiredCount` (detecting object vs integer), renders a type dropdown (Fixed Value / App Setting / Context Path) with three conditional `data-rc-section` divs — same visibility toggle pattern as `data-approver-section`. App Setting section uses a `<select>` populated via fetch. Context Path input has `data-variable-picker="true"`.
- **`workflow-designer-controller.js`**:
  - Added `onRequiredCountTypeChange(event)` — toggles `data-rc-section` visibility, triggers `_loadAppSettings()` when app_setting selected.
  - Added `_loadAppSettings(formOrContainer, selectedKey)` — fetches `/app-settings/workflow-list.json`, populates settings dropdown. Graceful fallback shows "Settings unavailable" if endpoint not ready yet, while preserving existing key.
  - Modified `updateNodeConfig()` — after FormData loop, composes `requiredCount` from `requiredCountType` + per-type value fields (`requiredCountFixedValue`, `requiredCountSettingKey`, `requiredCountContextPath`), then deletes the temporary fields. Fixed values save as plain integer (backward compatible).
  - Modified `onNodeSelected()` — pre-populates app settings dropdown when loading an approval node with `requiredCount.type === 'app_setting'`.

#### Key Pattern: Separate form fields → composed config value
Form uses multiple named inputs (`requiredCountType`, `requiredCountFixedValue`, etc.) that are composed into the final `requiredCount` value in `updateNodeConfig()`, then temporary keys are deleted. This avoids multi-input name collision issues while keeping the FormData collection loop generic.

### 2026-02-11: Universal Value Picker — renderValuePicker()

Generalized the approval-specific `_requiredCountHTML()` prototype into a universal `renderValuePicker()` method on `WorkflowConfigPanel`, and refactored all config panels to use it.

#### What was done

**New method: `renderValuePicker(fieldName, fieldMeta, currentValue, options)`**
- Added to `workflow-config-panel.js` as a public method on WorkflowConfigPanel
- Parses `currentValue` to detect active type: plain scalar → fixed, `$.` prefix → context, object with `.type` → use that type, null/undefined → fixed empty
- Renders a type dropdown (Fixed Value / Context Path / App Setting) + dynamic input in an `input-group input-group-sm` — consistent with sidebar's compact layout
- Helper `_renderValuePickerInput()` produces the correct input element per type: number input for integer, text for string, checkbox for boolean, text with `data-variable-picker="true"` for context, select with `data-vp-settings-select` for app_setting
- Uses `data-vp-type="{fieldName}"` and `data-vp-field="{fieldName}"` data attributes for the generic handler

**Refactored config panels:**
- `_approvalHTML()` — `requiredCount` now uses `renderValuePicker()` instead of `_requiredCountHTML()`
- `_actionHTML()` — each `inputSchema` param uses `renderValuePicker('params.{key}', ...)` instead of plain text inputs
- `_conditionHTML()` — `expectedValue` uses `renderValuePicker()`, plugin condition `params.*` also use it. `field` stays as a plain context-only input (it's always a path, not a resolvable value)
- `_delayHTML()` — `duration` uses `renderValuePicker()`. `waitEvent` stays as plain text (not a value to resolve)
- `_loopHTML()` — `maxIterations` uses `renderValuePicker()`. `exitCondition` stays as plain text with variable picker

**Designer controller changes (`workflow-designer-controller.js`):**
- Added `onValuePickerTypeChange(event)` — generic handler that swaps the input element when the type dropdown changes, fetches app settings for app_setting type, re-attaches variable pickers for context type
- Replaced `_loadAppSettings()` with `_loadAppSettingsForPicker(selectEl, selectedKey)` — takes a direct select element reference instead of searching by `data-rc-settings-select`
- Updated `updateNodeConfig()` — generic value picker composition loop (`form.querySelectorAll('[data-vp-type]')`) replaces the `requiredCountType`/`requiredCountFixedValue`/etc. special-case block. The FormData loop skips fields managed by value pickers (tracked in a `vpFields` Set)
- Updated `onNodeSelected()` — pre-populates all `[data-vp-settings-select]` dropdowns generically instead of checking for `requiredCount.type === 'app_setting'` specifically

**Deleted:**
- `_requiredCountHTML()` method
- `onRequiredCountTypeChange()` handler
- `requiredCountType`/`requiredCountFixedValue`/`requiredCountSettingKey`/`requiredCountContextPath` special-case composition in `updateNodeConfig()`

#### Key Patterns
- **Value picker data attributes**: `data-vp-type="{field}"` on type select, `data-vp-field="{field}"` on container, `data-vp-settings-select="{field}"` on app settings select. All keyed by field name for generic lookup.
- **Composition in updateNodeConfig**: Fixed values stay as plain scalars (backward compatible). Context values become `{type: 'context', path: '...'}`. App settings become `{type: 'app_setting', key: '...'}`. Empty values become empty string.
- **Type switching in onValuePickerTypeChange**: Removes old input from input-group, inserts new HTML via `insertAdjacentHTML('beforeend')`, then re-attaches pickers and triggers config save.
- **No separate form fields needed anymore**: The old pattern used hidden fields (`requiredCountType`, `requiredCountFixedValue`) that were composed and deleted. The new pattern reads the type from `data-vp-type` select and the value from the single `name="{fieldName}"` input — cleaner, no cleanup needed.

📌 Team update (2026-02-11): Universal value picker backend complete — Kaylee added `resolveParamValue()` to `DefaultWorkflowEngine`, handles fixed/context/app_setting. `resolveRequiredCount()` now delegates to it. Action and condition nodes resolve params through it. 463 tests pass. — decided by Kaylee
📌 Team update (2026-02-11): Duplicate email fix — `activateApprovedRoster($sendNotifications=true)` added. Workflow passes `false`. No frontend changes needed. — decided by Kaylee

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
