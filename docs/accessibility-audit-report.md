# KMP Accessibility Audit Report

## Executive summary

KMP has a solid accessibility foundation in several areas, especially where it uses CakePHP form helpers, scoped table headers, Bootstrap modal markup, server-rendered alert roles, and visually hidden loading text. The largest active accessibility risks are in custom interactive controls: drag-and-drop column reordering, grid/table controls, navigation toggles, autocomplete widgets, dynamic status messages, and some mobile/plugin flows.

This audit recommends treating WCAG 2.2 Level AA as the practical target for KMP. That target is appropriate for supporting a wide variety of nonprofit users in the United States and is also a strong practical baseline for users in the EU and Australia.

This is a code-level audit only. It is not a legal opinion, VPAT/ACR, or substitute for testing with real users and assistive technologies.

## Scope

The audit reviewed code-level accessibility concerns in:

- CakePHP layouts and templates under `app/templates`
- Shared UI elements such as navigation, grids, tables, tabs, and autocomplete controls
- Stimulus controllers under `app/assets/js/controllers`
- CSS under `app/assets/css` and plugin CSS
- First-party plugin workflows in Activities, Awards, Officers, and Waivers

This audit did not include a full live browser pass with screen readers, automated axe results, color contrast measurements for every rendered state, or legal conformance documentation.

## Standards baseline

| Region | Practical baseline for KMP |
| --- | --- |
| United States | WCAG 2.2 AA is the best practical target. ADA website claims commonly use WCAG AA as the benchmark. Section 508 procurement still references WCAG 2.0 AA, but modern federal/state expectations are closer to WCAG 2.1/2.2 AA. |
| European Union | EN 301 549 and European Accessibility Act expectations align closely with WCAG AA, currently centered around WCAG 2.1 AA with WCAG 2.2 increasingly relevant. |
| Australia | Disability Discrimination Act accessibility risk is commonly assessed against WCAG AA. WCAG 2.1/2.2 AA is the practical target. |

## Severity summary

| Severity | Count | Primary theme |
| --- | ---: | --- |
| Critical | 3 | Core keyboard access failures in active navigation and grid workflows |
| High | 8 | Missing accessible names, labels, live regions, sort controls, and page language |
| Medium | 8 | Motion, reflow, color/status cues, native dialogs, image alt text, inline JavaScript |
| Retired / inactive | 2 | Awards Kanban findings retained only to prevent reactivation without accessibility fixes |
| Positive patterns | 6 | Existing accessible patterns that reduce overall risk |

The highest-impact remediation work is concentrated in reusable components:

- `app/templates/element/nav/nav_parent.php`
- `app/templates/element/dataverse_table.php`
- `app/templates/element/grid_view_toolbar.php`
- `app/templates/element/autoCompleteControl.php`
- `app/templates/element/comboBoxControl.php`
- `app/assets/js/controllers/sortable-list-controller.js`

Retired code retained for awareness only:

- `app/plugins/Awards/templates/Recommendations/board.php`
- `app/assets/js/controllers/kanban-controller.js`

## Critical findings

Note: C2 retains its original finding ID for traceability, but it is no longer counted as an active critical finding because the Awards Kanban board is retired and not present in the active UI.

### C1. Main navigation parent items are not keyboard-operable controls

**Evidence:** `app/templates/element/nav/nav_parent.php:42-52`

The parent navigation toggle is rendered as a `<div>` with `data-bs-toggle="collapse"`, `aria-expanded`, and `aria-controls`. A `<div>` is not keyboard-operable by default, and ARIA state on a non-interactive element does not make it an accessible control.

**User impact:** Keyboard-only users, switch users, and some screen reader users may not be able to expand or collapse navigation groups. This can block access to major parts of the application.

**WCAG:** 2.1.1 Keyboard, 4.1.2 Name, Role, Value

**Recommended remediation:**

Replace the `<div>` with a real button while preserving the existing classes and data attributes:

```php
<button type="button"
    data-bs-target="#<?= $randomId ?>"
    data-bs-toggle="collapse"
    aria-expanded="<?= $expanded ?>"
    id="<?= $parent['id'] ?>"
    data-collapse-url="<?= $collapseUrl ?>"
    data-expand-url="<?= $expandUrl ?>"
    aria-controls="<?= $randomId ?>"
    class="navheader <?= $collapsed ?> text-start badge fs-5 mb-2 mx-1 text-bg-secondary bi <?= $parent['icon'] ?>"
    data-nav-bar-target="navHeader">
    <?= $parent['label'] ?>
</button>
```

If a button cannot be used, add `role="button"`, `tabindex="0"`, and Enter/Space keyboard handlers, but a real button is strongly preferred.

### C2. Retired/inactive: Awards recommendation board is drag-and-drop only

**Evidence:** `app/plugins/Awards/templates/Recommendations/board.php:41-50`; `app/assets/js/controllers/kanban-controller.js:70-238`

**Current status:** Retired functionality. Per team input on 2026-04-25, this Kanban board is not present anywhere in the active UI. Do not treat this as an active critical remediation item unless the board is re-enabled.

The Awards recommendation board uses draggable cards and drop targets. The controller implements pointer drag/drop behavior but does not provide a keyboard or non-pointer alternative for moving recommendations between columns.

**User impact:** Keyboard-only users, screen reader users, voice-control users, and many motor-impaired users cannot move recommendations through the workflow.

**WCAG:** 2.1.1 Keyboard, 2.5.7 Dragging Movements

**Recommended remediation:**

No active remediation is required while the feature remains retired and unreachable. Future work should either remove the dead route/template/controller or keep it clearly marked as retired. If this board is re-enabled, add non-drag alternatives to every movable card before exposing it to users, such as:

- A "Move to..." menu listing valid target states
- Buttons for allowed state transitions
- Keyboard shortcuts with documented instructions
- Live announcements after moves, for example: "Recommendation moved to Approved"
- Focus retention on the moved card or its equivalent in the destination column

### C3. Column picker reordering is drag-and-drop only

**Evidence:** `app/templates/element/grid_view_toolbar.php:223-228`; `app/assets/js/controllers/sortable-list-controller.js:40-46`

The column picker tells users to drag columns to reorder them. The sortable controller marks items as draggable and only handles drag/drop events.

**User impact:** Keyboard and screen reader users cannot reorder grid columns.

**WCAG:** 2.1.1 Keyboard, 2.5.7 Dragging Movements

**Recommended remediation:**

Add keyboard-operable Move Up and Move Down buttons for each column, or a selection-based "move before/after" workflow. Announce changes in a polite live region:

```text
Column "SCA Name" moved to position 3.
```

After this is implemented, update the UI instructions so they do not only say "Drag to reorder."

### C4. Sortable grid headers are clickable table headers instead of keyboard controls

**Evidence:** `app/templates/element/dataverse_table.php:61-69`

Sortable columns attach a click action directly to `<th>` elements and use a visual cursor. Table headers are not buttons by default.

**User impact:** Keyboard users may not be able to sort grids. Screen reader users may not hear current sort direction or know which headers are actionable.

**WCAG:** 2.1.1 Keyboard, 4.1.2 Name, Role, Value

**Recommended remediation:**

Render a real `<button type="button">` inside sortable `<th>` elements and expose sort state with `aria-sort` on the active header:

```php
<th scope="col" aria-sort="<?= $isSorted ? h($sortDirection === 'asc' ? 'ascending' : 'descending') : 'none' ?>">
    <button type="button"
        class="btn btn-link p-0 text-decoration-none"
        data-action="click-><?= h($controllerName) ?>#applySort"
        data-column-key="<?= h($columnKey) ?>">
        <?= h($column['label']) ?>
        <span class="visually-hidden">
            <?= $isSorted ? h(__('sorted {0}', $sortDirection)) : h(__('not sorted')) ?>
        </span>
    </button>
</th>
```

## High findings

### H1. No consistent skip link or main-content landmark in the default layout

**Evidence:** `app/templates/layout/default.php:121`, `app/templates/layout/default.php:193-230`

The default layout renders the body, flash messages, and page content without a guaranteed skip link or `main` landmark target.

**User impact:** Keyboard users must tab through repeated navigation on every page.

**WCAG:** 2.4.1 Bypass Blocks

**Recommended remediation:**

Add a skip link immediately after `<body>` and wrap page content in a main region:

```php
<a href="#main-content" class="visually-hidden-focusable skip-link">
    <?= __('Skip to main content') ?>
</a>

<main id="main-content" tabindex="-1">
    <?= $this->fetch("content") ?>
</main>
```

### H2. Several standalone layouts/pages lack page language

**Evidence:**

- `app/templates/layout/mobile_app.php:58`
- `app/templates/Members/view_card.php:32`
- `app/templates/layout/error.php:19`

Some standalone pages render `<html>` without a `lang` attribute.

**User impact:** Screen readers may use the wrong pronunciation rules.

**WCAG:** 3.1.1 Language of Page

**Recommended remediation:**

Add a configured language or default to English:

```php
<html lang="<?= h(Configure::read('App.language') ?: 'en') ?>">
```

### H3. Icon-only buttons lack accessible names

**Evidence:**

- `app/templates/element/dataverse_table.php:89-94`
- `app/plugins/Waivers/assets/js/controllers/waiver-upload-controller.js:170-172`

The Dataverse column picker button is only a Bootstrap icon. The Waiver upload remove-file button is dynamically created with only `<i class="bi bi-x"></i>`.

**User impact:** Screen reader users hear "button" without knowing what the button does.

**WCAG:** 1.1.1 Non-text Content, 4.1.2 Name, Role, Value

**Recommended remediation:**

Add accessible names and hide decorative icons:

```php
<button type="button"
    class="btn btn-sm btn-outline-secondary"
    aria-label="<?= h(__('Show or hide columns')) ?>"
    data-bs-toggle="modal"
    data-bs-target="#columnPickerModal-<?= h($gridKey) ?>">
    <i class="bi bi-list-columns" aria-hidden="true"></i>
</button>
```

For dynamically generated file buttons:

```javascript
`<button type="button"
    class="btn btn-sm btn-outline-danger"
    data-index="${index}"
    aria-label="Remove ${this.escapeHtml(file.name)}">
    <i class="bi bi-x" aria-hidden="true"></i>
</button>`
```

### H4. Bulk selection checkboxes are unlabeled

**Evidence:** `app/templates/element/dataverse_table.php:46-50`, `app/templates/element/dataverse_table.php:112-116`

The select-all checkbox uses a `title` attribute but no accessible label. Row checkboxes also lack row-specific labels.

**User impact:** Screen reader users cannot determine whether a checkbox selects all rows or a specific row.

**WCAG:** 1.3.1 Info and Relationships, 3.3.2 Labels or Instructions

**Recommended remediation:**

Use explicit ARIA labels:

```php
<input type="checkbox"
    class="form-check-input"
    data-<?= h($controllerName) ?>-target="selectAllCheckbox"
    data-action="change-><?= h($controllerName) ?>#toggleAllSelection"
    aria-label="<?= h(__('Select all rows on this page')) ?>">
```

For row checkboxes, include a row-specific accessible name where a display field is available.

### H5. Grid search input has placeholder but no label

**Evidence:** `app/templates/element/grid_view_toolbar.php:146-150`

The search input has `placeholder="Search..."` but no visible or visually hidden label.

**User impact:** Placeholder text disappears while typing and is not a reliable accessible label.

**WCAG:** 3.3.2 Labels or Instructions

**Recommended remediation:**

Add a label and connect helper text with `aria-describedby`:

```php
<label for="grid-search-<?= h($gridKey) ?>" class="visually-hidden">
    <?= __('Search records') ?>
</label>
<input type="text"
    id="grid-search-<?= h($gridKey) ?>"
    class="form-control"
    placeholder="<?= h(__('Search...')) ?>"
    aria-describedby="grid-search-help-<?= h($gridKey) ?>"
    data-<?= h($controllerName) ?>-target="searchInput"
    data-action="keyup-><?= h($controllerName) ?>#handleSearchKeyup keydown.enter-><?= h($controllerName) ?>#performSearch">
```

### H6. Autocomplete and combobox ARIA is incomplete

**Evidence:**

- `app/templates/element/autoCompleteControl.php:157-179`
- `app/templates/element/comboBoxControl.php:181-208`
- `app/assets/js/controllers/auto-complete-controller.js:717-739`

The wrapper has `role="combobox"` and the controller sets `aria-expanded` on the wrapper. The input does not consistently expose the combobox relationship and the results list lacks an explicit `role="listbox"` in the template.

**User impact:** Screen reader users may not reliably know that a popup opened, which list controls it, how many results exist, or what option is active.

**WCAG:** 4.1.2 Name, Role, Value; 4.1.3 Status Messages

**Recommended remediation:**

Follow the ARIA combobox pattern:

- Put `role="combobox"` on the input or correctly wire the wrapper pattern
- Add `aria-autocomplete="list"`
- Add `aria-expanded` and `aria-controls` to the input
- Give the result list a stable ID and `role="listbox"`
- Keep `aria-activedescendant` on the input
- Announce result counts and closed/open state with a polite live region

### H7. Dynamic status and help messages are not consistently announced

**Evidence:**

- `app/plugins/Activities/templates/Authorizations/mobile_request_authorization.php:54-56`
- `app/plugins/Waivers/assets/js/controllers/waiver-upload-controller.js:222-233`
- Several dynamically shown staff/gathering notices reported in shared templates

The mobile authorization approver help text changes dynamically but lacks `aria-live`. Waiver upload validation uses `alert()`, and other dynamic notices can be hidden/shown without a live announcement.

**User impact:** Screen reader users may miss validation messages, loading status, or availability updates.

**WCAG:** 4.1.3 Status Messages

**Recommended remediation:**

Add live regions for dynamic help/status text:

```php
<div class="form-text"
    data-mobile-request-auth-target="approverHelp"
    role="status"
    aria-live="polite"
    aria-atomic="true">
    <?= __('Loading approvers...') ?>
</div>
```

Use `role="alert"` for blocking validation errors and inline messages instead of native `alert()`.

### H8. Custom switch focus indicator is too weak

**Evidence:** `app/assets/css/app.css:197-239`

The custom switch hides the native checkbox with zero dimensions and only applies `box-shadow: 0 0 1px #2196F3` to the visual slider on focus.

**User impact:** Keyboard users may not see where focus is, especially on high-resolution displays or varied backgrounds.

**WCAG:** 2.4.7 Focus Visible; WCAG 2.2 focus appearance expectations

**Recommended remediation:**

Use a stronger focus-visible style:

```css
.switch input:focus-visible + .slider {
    outline: 2px solid #0d6efd;
    outline-offset: 3px;
    box-shadow: 0 0 0 4px rgba(13, 110, 253, 0.25);
}
```

Also consider increasing mobile target height to at least 44px where switches appear in touch-heavy workflows.

## Medium findings

### M1. Native alert, confirm, and prompt dialogs are heavily used

**Evidence examples:**

- `app/assets/js/controllers/grid-view-controller.js:1262-1442`
- `app/assets/js/controllers/backup-restore-status-controller.js:67-77`
- `app/assets/js/controllers/code-editor-controller.js:373`
- `app/assets/js/controllers/my-rsvps-controller.js:315`
- `app/plugins/Waivers/assets/js/controllers/waiver-upload-controller.js:222-233`

Native dialogs are inconsistent across assistive technology and browser combinations. They also cannot associate errors with fields or include rich context.

**User impact:** Screen reader, keyboard, and cognitive-accessibility users can receive abrupt, context-poor interruptions.

**Recommended remediation:** Replace confirmations with accessible Bootstrap modal or alertdialog patterns. Replace validation alerts with inline errors using `role="alert"` and `aria-describedby` on affected fields.

### M2. `javascript:history.back()` links in mobile flows

**Evidence:**

- `app/plugins/Activities/templates/Authorizations/mobile_request_authorization.php:65`
- `app/plugins/Waivers/templates/GatheringWaivers/mobile_select_gathering.php:26`

These are links that execute browser history behavior through a `javascript:` URL.

**User impact:** Screen readers announce these as links, but behavior is closer to an action button. `javascript:` URLs also have poor fallback behavior and are fragile for assistive tooling.

**Recommended remediation:** Prefer a real URL. If browser history is necessary, use a button with a Stimulus action and accessible text.

### M3. Missing alt text on standalone card watermark images

**Evidence:** `app/templates/Members/view_card.php:211,218`

The watermark images have no `alt` attribute.

**User impact:** Screen readers encounter unlabeled images.

**WCAG:** 1.1.1 Non-text Content

**Recommended remediation:** If decorative, use:

```php
<img src="<?= h($watermarkimg) ?>" alt="" aria-hidden="true">
```

If meaningful, provide descriptive alt text.

### M4. Color, opacity, and title-only status cues appear in calendar/event UI

**Evidence examples:** Cancelled event opacity and icon title attributes were found in calendar and public event templates.

**User impact:** Users with low vision, color blindness, high contrast settings, or screen readers may miss event status.

**WCAG:** 1.4.1 Use of Color, 1.4.3 Contrast

**Recommended remediation:** Pair color with visible text, badges, icons with `aria-label`, or patterns. Avoid relying on `title` for accessibility.

### M5. Motion reduction coverage is incomplete

**Evidence:**

- `app/assets/css/app.css:50-54`, `app/assets/css/app.css:106-110`
- `app/templates/layout/public_event.php:89-91`, `app/templates/layout/public_event.php:220-225`
- Waiver upload CSS includes pulse/spinner animations

Only some navigation transitions are protected by `prefers-reduced-motion`. Public event pages use smooth scrolling and hover transforms; upload flows include ongoing animation.

**User impact:** Users with vestibular disorders, migraines, or motion sensitivity may be adversely affected.

**Recommended remediation:**

Add a broad reduced-motion rule:

```css
@media (prefers-reduced-motion: reduce) {
    *,
    *::before,
    *::after {
        animation-duration: 0.01ms !important;
        animation-iteration-count: 1 !important;
        scroll-behavior: auto !important;
        transition-duration: 0.01ms !important;
    }
}
```

Then review any UI where animation communicates state and provide a static equivalent.

### M6. Column picker instructions describe only drag behavior

**Evidence:** `app/templates/element/grid_view_toolbar.php:218-226`

The instructions say "Check columns to show/hide. Drag to reorder." No keyboard alternative is documented.

**User impact:** Users are instructed to use an inaccessible interaction.

**Recommended remediation:** After adding keyboard reordering, update text to mention Move Up/Move Down controls or keyboard shortcuts.

### M7. Inline JavaScript for clipboard, copy, and confirmation behavior is common

**Evidence examples:**

- `app/templates/Gatherings/view.php`
- `app/templates/Gatherings/quick_view.php`
- `app/templates/element/gatherings/mapTab.php`
- `app/templates/element/gatherings/calendar_toolbar.php:223-225`

Inline JavaScript often uses `alert()` or changes visible content without live announcements.

**User impact:** Screen reader users may not know that copy actions succeeded or failed. Voice-control users and security tooling also handle inline JavaScript less predictably.

**Recommended remediation:** Move these actions to Stimulus controllers, keep button accessible names stable, and announce success/failure in a shared live region.

### M8. Complex dropdown filter panel may need explicit focus behavior

**Evidence:** `app/templates/element/grid_view_toolbar.php:125-197`

The filter dropdown uses `data-bs-auto-close="outside"` and contains dynamic tabs/panels.

**User impact:** Keyboard users may have unclear focus order or difficulty exiting if focus behavior is not explicitly managed.

**Recommended remediation:** Verify that:

- Focus moves to the first useful control on open
- Escape closes the dropdown
- Focus returns to the trigger on close
- Tab order exits naturally

### M9. Retired/inactive: Award board uses a fixed-width workflow table

**Evidence:** `app/plugins/Awards/templates/Recommendations/board.php:22-30`

**Current status:** Retired functionality. Per team input on 2026-04-25, this Kanban board is not present anywhere in the active UI. Do not prioritize this as active accessibility remediation unless the board is re-enabled.

The Kanban board is a table with `min-width:1020px`.

**User impact:** Fixed-width workflow UI can be difficult at 200% zoom, on narrow screens, or with magnification.

**WCAG:** 1.4.10 Reflow risk

**Recommended remediation:** No active remediation is required while retired. If the board is re-enabled, provide a stacked/card alternative or a list view with the same state transition controls before shipping it.

## Positive patterns observed

### P1. CakePHP form helpers are used widely

Many forms use `$this->Form->control()`, which usually creates associated labels and IDs. This reduces risk compared with manually assembled forms.

### P2. Table header scopes are present in shared tables

Shared table markup includes `scope="col"` in several places, including `app/templates/element/dataverse_table.php`.

### P3. Bootstrap modal markup is often semantically strong

Many modals include `tabindex="-1"`, `aria-labelledby`, and close buttons with `aria-label="Close"`.

### P4. Loading states often include screen-reader text

Multiple spinners include `.visually-hidden` text such as "Loading..." or "Searching."

### P5. Server-rendered alerts often use alert roles

Several server-rendered alerts use `role="alert"`, including flash/turbo stream patterns.

### P6. Recent image previews include meaningful alt text

The waiver document preview includes useful alt text:

`app/plugins/Waivers/templates/GatheringWaivers/view.php:291-292`

## Recommended remediation roadmap

### Phase 1: Foundation

Address broad issues that affect many pages:

1. Add skip link and a consistent `<main id="main-content">` landmark.
2. Add `lang` to every standalone layout/page.
3. Add strong global `:focus-visible` styling.
4. Add broad `prefers-reduced-motion` coverage.

### Phase 2: Reusable components

Fix shared controls so every feature benefits:

1. Replace navigation parent `<div>` toggles with buttons.
2. Fix Dataverse sort headers, checkbox labels, and column picker button names.
3. Add a real grid search label and descriptions.
4. Add keyboard reordering to column picker.
5. Bring autocomplete and combobox controls into the ARIA combobox pattern.

### Phase 3: Workflow blockers

Focus on interactions that block task completion:

1. Add non-drag controls for Awards recommendation board state changes.
2. Add keyboard alternatives for any sortable list workflow.
3. Ensure focus is retained or restored after moving items.
4. Announce state changes with live regions.

### Phase 4: Dynamic feedback and dialogs

Improve feedback for screen reader and cognitive-accessibility users:

1. Replace native `alert()`, `confirm()`, and `prompt()` where practical.
2. Use inline validation errors with `role="alert"`.
3. Add shared status/live-region utilities.
4. Convert clipboard/copy success messages to accessible status announcements.

### Phase 5: Plugin and mobile cleanup

Address user-facing plugin workflows:

1. Add labels to Waiver upload remove buttons.
2. Replace `javascript:history.back()` links with real URLs or buttons.
3. Add live announcements for mobile authorization approver help.
4. Improve upload validation and progress messaging.
5. Review mobile touch target sizes for important actions.

## AI implementation instructions for future work

This section is intended for future AI coding agents or developers picking up accessibility remediation. Do not repeat the full audit before starting. Use the findings and work packages in this document as the source of truth, then verify current source lines with `rg` and `view` because line numbers may drift.

### General rules for AI agents

1. Preserve existing CakePHP and Stimulus patterns. Use CakePHP helpers where possible and follow existing controller registration conventions in `app/assets/js/controllers`.
2. Make small, behavior-focused changes. Do not redesign pages or refactor unrelated code while fixing accessibility issues.
3. Prefer semantic HTML over ARIA. Use native `<button>`, `<label>`, `<main>`, and form controls before adding ARIA roles.
4. When ARIA is needed, keep name, role, value, and state synchronized. Do not add ARIA attributes that are not updated by JavaScript.
5. Keep visible text and accessible names stable. If an icon changes to a checkmark after copy/save, announce status in a live region instead of changing the button's accessible name unexpectedly.
6. Add tests where behavior changes. JavaScript controller changes should usually have Jest coverage under `app/tests/js/controllers`.
7. After code changes, run relevant existing checks from `app/`. For broad changes, use `bash bin/verify.sh`. For JavaScript-only changes, run `npm run test:js` and the relevant targeted tests when possible.
8. Update this document when a finding is fixed. Mark the work package complete, note the commit or PR, and record any follow-up testing that remains.

### Priority definitions

| Priority | Meaning | Fix before |
| --- | --- | --- |
| P0 | Blocks keyboard or screen reader users from core navigation or workflow completion | Any visual polish or lower-risk cleanup |
| P1 | Shared component issue that affects many pages or causes confusing assistive technology output | Plugin-specific cleanup |
| P2 | Workflow-specific accessibility defects, motion/reflow issues, or status messaging gaps | Nice-to-have contrast/style refinements |
| P3 | Best-practice improvements and verification/documentation follow-up | Optional UI polish |

### Work package backlog

| Package | Priority | Related findings | Primary files | What to change | Done criteria |
| --- | --- | --- | --- | --- | --- |
| A11Y-01: Add page bypass and landmarks | P0 | H1 | `app/templates/layout/default.php`; review `app/templates/layout/TwitterBootstrap/*.php`; `app/templates/layout/mobile_app.php`; `app/templates/layout/public_event.php` | Add a visible-on-focus skip link immediately after `<body>`. Wrap primary content in `<main id="main-content" tabindex="-1">` or equivalent. Ensure only one primary `main` landmark per rendered page. | Keyboard users can tab to "Skip to main content" first and activate it. Focus lands at main content. Existing layout content still renders. |
| A11Y-02: Fix navigation parent toggles | P0 | C1 | `app/templates/element/nav/nav_parent.php`; `app/assets/js/controllers/nav-bar-controller.js`; relevant nav tests if present | Replace the parent toggle `<div>` with `<button type="button">`, or add full keyboard support if a button cannot be used. Preserve collapse behavior and nav-bar state persistence. | Parent navigation groups expand/collapse with mouse, Enter, and Space. `aria-expanded` changes correctly. No visual regression in sidebar styling. |
| A11Y-03: Make Dataverse table controls accessible | P0 | C4, H3, H4 | `app/templates/element/dataverse_table.php`; `app/assets/js/controllers/grid-view-controller.js`; grid tests | Replace clickable sortable `<th>` behavior with buttons inside headers. Add `aria-sort`. Add accessible names to column picker and row-selection checkboxes. Hide decorative sort icons from AT or provide text. | Sorting works with Enter/Space. Screen reader can identify sorted column/direction. Select-all and row checkboxes have meaningful names. |
| A11Y-04: Add keyboard alternatives for column reordering | P0 | C3, M6 | `app/templates/element/grid_view_toolbar.php`; `app/assets/js/controllers/sortable-list-controller.js`; `app/assets/js/controllers/grid-view-controller.js`; `app/tests/js/controllers/sortable-list-controller.test.js` | Add Move Up/Move Down buttons or keyboard shortcut support to sortable list items. Update instructions from "Drag to reorder" to include keyboard method. Add a polite live region announcing new position. | Columns can be reordered without pointer drag. Reordering updates grid state as drag currently does. Announcements are made after each move. |
| A11Y-05: Retired Awards Kanban cleanup / reactivation guard | P3 | C2, M9 | `app/plugins/Awards/templates/Recommendations/board.php`; `app/assets/js/controllers/kanban-controller.js`; Awards recommendation routes if still present | Do not build non-drag state controls unless the board is re-enabled. Confirm the Kanban board is unreachable in active UI, then either remove dead code or document/guard it as retired. If re-enabled, add per-card accessible controls for allowed state transitions before exposing it. | Team confirms the board is not active. Dead code is removed or clearly guarded as retired. If reactivated, recommendations can be moved using only keyboard and focus remains understandable after move. |
| A11Y-06: Complete page language coverage | P1 | H2 | `app/templates/layout/mobile_app.php`; `app/templates/Members/view_card.php`; `app/templates/layout/error.php`; email layout only if rendered as browser content | Add `lang` to standalone `<html>` elements using configured app language or `en`. | All browser-rendered pages have a page language. No duplicate or malformed `<html>` tags. |
| A11Y-07: Strengthen global focus and reduced-motion support | P1 | H8, M5 | `app/assets/css/app.css`; `app/templates/layout/public_event.php`; Waivers CSS files with animations | Add strong `:focus-visible` styles for common interactive elements and custom switches. Add global `prefers-reduced-motion` coverage. Remove or neutralize smooth scrolling and continuous animations under reduced motion. | Keyboard focus is visible on links, buttons, inputs, nav toggles, switches, and custom controls. Reduced-motion OS setting suppresses nonessential animation. |
| A11Y-08: Bring autocomplete and combobox into ARIA pattern | P1 | H6 | `app/templates/element/autoCompleteControl.php`; `app/templates/element/comboBoxControl.php`; `app/assets/js/controllers/auto-complete-controller.js`; autocomplete tests | Put combobox semantics and `aria-expanded`, `aria-controls`, `aria-activedescendant`, and `aria-autocomplete` on the correct element. Add `role="listbox"` to results. Add a polite status region for result count/open/closed messages. | Screen reader can identify the field as autocomplete/combobox. Results opening/closing and active options are announced. Keyboard behavior remains Arrow/Enter/Escape/Tab compatible. |
| A11Y-09: Label grid search and complex filter controls | P1 | H5, M8 | `app/templates/element/grid_view_toolbar.php`; `app/assets/js/controllers/grid-view-controller.js` | Add labels and descriptions to search input. Confirm filter dropdown focus moves logically on open/close and Escape returns focus to trigger. | Search input has a durable accessible name. Filter dropdown is operable with keyboard and does not trap focus. |
| A11Y-10: Replace native dialogs with accessible app patterns | P2 | M1, H7, M7 | `app/assets/js/controllers/grid-view-controller.js`; `backup-restore-status-controller.js`; `code-editor-controller.js`; `my-rsvps-controller.js`; Waivers upload controllers; related templates | Create or reuse accessible confirmation, prompt, toast/status, and inline-error patterns. Avoid native `alert()`, `confirm()`, and `prompt()` for app workflows. | Confirmations use modal/alertdialog semantics with focus trap and restoration. Validation errors are associated with fields and announced. Status messages use live regions. |
| A11Y-11: Fix mobile/plugin flow semantics | P2 | M2, H7 | `app/plugins/Activities/templates/Authorizations/mobile_request_authorization.php`; `app/plugins/Waivers/templates/GatheringWaivers/mobile_select_gathering.php`; related mobile controllers | Replace `javascript:history.back()` links with real URLs or button actions. Add live regions for dynamic mobile help text. Preserve large touch targets. | Mobile flows remain keyboard/touch accessible. Back/cancel controls have correct role and label. Dynamic help updates are announced. |
| A11Y-12: Fix image alt and icon-only plugin controls | P2 | M3, H3 | `app/templates/Members/view_card.php`; `app/plugins/Waivers/assets/js/controllers/waiver-upload-controller.js`; search for `<img` and icon-only `.bi` buttons | Add `alt="" aria-hidden="true"` for decorative images. Add descriptive alt for meaningful images. Add accessible labels for icon-only buttons. | No browser-rendered meaningful image lacks alt text. Icon-only buttons have accessible names. |
| A11Y-13: Improve color/status cues and contrast-sensitive states | P2 | M4 | Calendar templates; public event templates; `app/assets/css/app.css`; plugin CSS | Pair color/opacity with visible text or pattern. Replace `title`-only explanations with visible text or `aria-label` as appropriate. Verify contrast for red/gray/status text. | Event status is understandable without color. Critical text meets WCAG AA contrast. High contrast mode still communicates status. |
| A11Y-14: Verification and regression pass | P3 | All findings | Playwright UI tests; manual testing notes; this document | Add or update tests for fixed behaviors. Run keyboard, zoom, reduced-motion, forced-colors, axe/Lighthouse, NVDA, and VoiceOver checks where possible. Update this report with completion notes. | Team can demonstrate fixed workflows without relying on the original audit. Remaining risks are documented. |

### Suggested implementation sequence

1. Complete A11Y-01, A11Y-02, and A11Y-03 first. These address application-wide keyboard access and shared controls.
2. Complete A11Y-04 before or alongside A11Y-03 because column picker instructions currently depend on inaccessible drag behavior.
3. Do not implement A11Y-05 as feature work unless the Awards Kanban board is re-enabled. Treat it as cleanup or a reactivation guard because the board is retired and not in active UI.
4. Complete A11Y-06 through A11Y-09 to stabilize the shared accessibility foundation.
5. Complete A11Y-10 through A11Y-13 as workflow cleanup and polish.
6. Complete A11Y-14 after each group of fixes, not only at the end.

### Acceptance criteria for all accessibility fixes

Every completed work package should satisfy these criteria unless the package says otherwise:

1. All interactive behavior works with keyboard only.
2. Focus is visible and moves predictably.
3. Screen reader users can identify each control's name, role, value, and state.
4. Dynamic changes that do not move focus are announced through status or alert regions.
5. Color, icon, position, or motion is not the only way information is conveyed.
6. The UI works at 200% zoom and does not require two-dimensional scrolling except for legitimate data tables.
7. Existing permissions, validation, CSRF protection, Turbo behavior, and form submissions remain intact.

### Useful search commands for future agents

Run these from the repository root to find known issue patterns:

```bash
rg -n "<html\b" app/templates
rg -n "javascript:|onclick=|onchange=" app/templates app/plugins
rg -n "\b(alert|confirm|prompt)\s*\(" app/assets app/plugins
rg -n "draggable=\"true\"|dragstart->|drop->|sortable-list" app
rg -n "<img\b" app/templates app/plugins
rg -n "role=['\"]combobox|aria-activedescendant|data-ac-target" app/templates app/assets/js/controllers
rg -n "aria-live|role=['\"]status|role=['\"]alert" app/templates app/plugins app/assets/js
```

### Testing guidance for future agents

Use existing project tooling only. Recommended checks after code changes:

```bash
cd app
npm run test:js
bash bin/verify.sh
```

For UI-affecting changes, also run the relevant Playwright lane if the local environment supports it:

```bash
cd app
npm run test:ui:smoke
```

Manual checks are still required for accessibility quality:

1. Navigate the changed workflow with Tab, Shift+Tab, Enter, Space, Escape, and arrow keys.
2. Verify focus is visible at every step.
3. Check 200% browser zoom and a 320 CSS pixel wide viewport.
4. Enable reduced motion in the OS/browser and confirm nonessential animation stops.
5. Test with NVDA plus Firefox or Chrome for Windows workflows when possible.
6. Test with VoiceOver plus Safari for iOS/macOS-oriented workflows when possible.

### How to record progress in this document

When a work package is completed, add a short completion note under the package or in a new "Remediation progress" section:

```markdown
- A11Y-03 completed in PR #123. Added keyboard sortable header buttons, aria-sort, and row checkbox labels. Verified with Jest grid tests and keyboard-only smoke test.
```

If a finding is intentionally deferred, document why, who accepted the risk, and what compensating workflow remains available.

## Verification checklist

After remediation, verify the application with:

- Keyboard-only navigation across primary workflows
- 200% zoom and 320 CSS pixel viewport reflow testing
- Reduced-motion mode
- Windows High Contrast / forced colors mode
- Automated axe or Lighthouse accessibility scans
- NVDA with Firefox or Chrome
- VoiceOver with Safari
- At least one real-user or stakeholder review for nonprofit user workflows

## Overall conclusion

KMP is reasonably close to a strong accessibility baseline in standard form and table areas, but it should not be considered WCAG AA conformant until the critical and high findings are addressed and validated in a running browser with assistive technologies.

The most important improvements are to make every active custom interaction keyboard-operable, provide accessible names and labels for controls, announce dynamic changes, and remove pointer-only drag/drop dependencies from active workflows.
