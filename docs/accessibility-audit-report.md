# KMP Accessibility Audit Report

## Executive summary

This report reflects a fresh code-level audit of KMP against **WCAG 2.2 Level AA** after the accessibility remediation work completed on 2026-04-25. It supersedes the original pre-remediation findings in this file.

KMP is now in a much stronger accessibility position than the original audit baseline. Core layout landmarks, skip links, nav toggles, Dataverse sorting, column picker keyboard reordering, combobox semantics, native dialog replacement, and many mobile/plugin flows have been remediated. The remaining gaps are concentrated in older or specialized UI surfaces: Awards recommendation tables, branch link-type selectors, mobile image zoom, mobile PIN gate behavior, waiver upload wizard semantics, and some icon/status affordances.

This is a code-level audit only. It is not a legal opinion, VPAT/ACR, or a substitute for assistive-technology testing with real users.

## Standards baseline

| Region | Practical baseline for KMP |
| --- | --- |
| United States | WCAG 2.2 AA is a strong practical target for ADA risk reduction and nonprofit inclusivity. Section 508 procurement still references older WCAG levels in places, but modern expectations are broader. |
| European Union | EN 301 549 and European Accessibility Act expectations align closely with WCAG AA. WCAG 2.2 AA is a reasonable forward-looking target. |
| Australia | Disability Discrimination Act accessibility risk is commonly assessed against WCAG AA. WCAG 2.2 AA is an appropriate practical target. |

## Scope of this re-audit

Reviewed:

- Core CakePHP layouts, templates, elements, and CSS under `app/templates` and `app/assets/css`
- Shared components: navigation, Dataverse grids, tabs, modals, autocomplete/combobox controls, calendar views
- Stimulus controllers and utilities under `app/assets/js`
- Active first-party plugin UI in Activities, Officers, Awards, and Waivers
- Native dialog and inline-handler patterns after the global `KMP_accessibility` remediation

Not fully covered:

- Manual screen reader testing with NVDA, JAWS, VoiceOver, TalkBack
- Complete rendered color-contrast measurement for every theme/state
- Full legal conformance documentation
- Third-party plugin admin screens that may be outside ordinary KMP user workflows

## Current status summary

| Category | Count | Notes |
| --- | ---: | --- |
| Critical active blockers | 0 | No remaining audited issue appears to block all keyboard access to primary app navigation or forms. |
| High priority gaps | 4 | Active issues likely to affect keyboard, screen reader, or low-vision users in real workflows. |
| Medium priority gaps | 8 | Important semantic, status, target-size, or dismissibility issues. |
| Low priority / advisory gaps | 4 | Should be cleaned up, but lower direct user impact or requires rendered/manual confirmation. |
| Retired-code guardrails | 1 | Awards Kanban remains retired but should not be reactivated without accessibility work. |

## Resolved since the original audit

The following original audit themes are no longer active findings:

- Shared layouts now include skip links and consistent main-content landmarks.
- Navigation parent controls are real buttons with keyboard support.
- Dataverse sortable headers use keyboard-operable buttons and `aria-sort`.
- Dataverse row/select-all checkboxes and column picker controls have accessible names.
- Column picker reordering has Move up / Move down controls and live announcements.
- Autocomplete/combobox controls expose combobox/listbox semantics, active descendant state, and result-count status messages.
- Grid search inputs have explicit accessible labels and helper text.
- Native `alert()`, `confirm()`, and `prompt()` usage has been migrated to accessible modal/status patterns or covered by the global CakePHP confirm adapter.
- Active inline `onclick`, `onsubmit`, `onchange`, and `javascript:` navigation patterns have been replaced, except for intentional parsing inside the Cake confirm adapter.
- Active audited mobile/plugin card/image/remove controls now generally have text or accessible names.

## Remaining high priority gaps

### H1. Mobile image zoom is pointer/gesture-only for pan and reset

**Evidence:**

- `app/assets/js/controllers/image-zoom-controller.js:63-71`
- `app/assets/js/controllers/image-zoom-controller.js:93-132`
- Active use examples:
  - `app/templates/Members/view_mobile_card.php:151-153`
  - `app/templates/element/members/verifyMembershipModal.php:55-58`

**Issue:** The image zoom controller supports wheel zoom, pointer drag pan, double-click reset, touch pinch, and touch pan. It does not provide keyboard equivalents for zooming, panning, or resetting the view. The zoom stage also is not made focusable by the controller.

**User impact:** Keyboard-only users and some switch/assistive-technology users cannot inspect zoomed membership/card images with the same functionality as mouse or touch users.

**WCAG 2.2 AA:** 2.1.1 Keyboard, 2.5.7 Dragging Movements

**Recommended fix:**

- Make the zoom container focusable with `tabindex="0"` and an accessible label or instructions.
- Add keyboard controls:
  - `+` / `=` zoom in
  - `-` zoom out
  - Arrow keys pan when zoomed
  - `Home` or `0` reset view
  - `Escape` optionally reset and return focus to the close button
- Announce zoom percentage or "image reset" through `KMP_accessibility.announce()`.
- Add JS tests for keyboard zoom/pan/reset.

### H2. Awards recommendations table has unlabeled checkboxes and filter controls

**Evidence:** `app/plugins/Awards/templates/Recommendations/table.php:61-62`, `73-79`, `99-106`, `121-127`, `138-158`, `175-183`, `207-208`

**Issue:** The Awards recommendations table includes a select-all checkbox, row checkboxes, and several filter controls with `label => false`. Several rely on placeholder text only, and the row checkboxes do not expose row-specific purpose.

**User impact:** Screen reader users may hear generic "checkbox" or unlabeled fields and cannot reliably determine what is being selected or filtered.

**WCAG 2.2 AA:** 1.3.1 Info and Relationships, 3.3.2 Labels or Instructions, 4.1.2 Name, Role, Value

**Recommended fix:**

- Add `aria-label="Select all recommendations"` to the header checkbox.
- Add row-specific labels such as `aria-label="Select recommendation for {recipient} submitted on {date}"`.
- Add visible or visually hidden labels for every filter input/select.
- Prefer `aria-label` only when visible labels would make the dense table unusable.

### H3. Branch link type dropdown uses icon-only hash links

**Evidence:** `app/templates/element/branches/editModal.php:73-91`

**Issue:** The branch links editor uses an icon-only dropdown toggle and icon-only `<a href="#">` menu items for link types. These items perform UI actions, not navigation, and they lack accessible names.

**User impact:** Screen reader users cannot identify link-type choices, and keyboard users can encounter hash-link behavior instead of button semantics.

**WCAG 2.2 AA:** 1.1.1 Non-text Content, 2.1.1 Keyboard, 4.1.2 Name, Role, Value

**Recommended fix:**

- Add `aria-label` to the dropdown toggle, for example `aria-label="Select branch link type"`.
- Replace each dropdown `<a href="#">` with `<button type="button" class="dropdown-item">`.
- Include visually hidden text or visible text for each type, for example "Website", "Discord", "Facebook", "Instagram", "TikTok", "Threads", "X/Twitter", "YouTube".
- Mark decorative icons with `aria-hidden="true"`.

### H4. Mobile PIN gate needs full dialog semantics and keyboard/status handling

**Evidence:** `app/assets/js/controllers/mobile-pin-gate-controller.js:121-181`, `184-208`

**Issue:** The mobile quick-login PIN gate creates a custom overlay and hides page scrolling, but it does not expose `role="dialog"`, `aria-modal="true"`, a focus trap, a documented escape/exit path, or status announcements during verification. Error text is shown visually but is not explicitly a live alert/status.

**User impact:** Keyboard and screen reader users may not understand that a modal gate is active, may tab into content behind it, or may miss verification/error state changes.

**WCAG 2.2 AA:** 2.1.2 No Keyboard Trap, 2.4.3 Focus Order, 3.3.1 Error Identification, 4.1.2 Name, Role, Value

**Recommended fix:**

- Add `role="dialog"`, `aria-modal="true"`, and `aria-labelledby` to the overlay/card.
- Store previously focused element and restore it when the gate closes.
- Trap focus inside the PIN gate while it is active.
- Add a keyboard-accessible escape route appropriate for the security model, such as "Sign out" or "Cancel and return to login"; do not silently bypass security.
- Add `role="alert"` or `aria-live="assertive"` to error text.
- Add `aria-busy="true"` and disable the submit button while PIN verification is running.

## Remaining medium priority gaps

### M1. Dataverse boolean values are icon-only

**Evidence:** `app/templates/element/dataverse_table.php:194-196`

**Issue:** Boolean cells render a green check or red X icon without text or an accessible name.

**User impact:** Users who cannot perceive color or who rely on screen readers may miss the value.

**WCAG 2.2 AA:** 1.4.1 Use of Color, 4.1.2 Name, Role, Value

**Recommended fix:** Add visible or visually hidden "Yes" / "No" text and mark decorative icons `aria-hidden="true"`.

### M2. Calendar badges and previous/next controls rely on `title`

**Evidence:**

- `app/templates/element/gatherings/calendar_month.php:137-146`
- `app/templates/element/gatherings/calendar_toolbar.php:142-164`

**Issue:** Calendar status badges use `title` attributes for meanings such as "I'm attending", "Has location", and "Multi-day event". Calendar previous/next icon links also rely on `title`.

**User impact:** `title` is not consistently announced by assistive technologies and is not available to many touch/keyboard users.

**WCAG 2.2 AA:** 1.1.1 Non-text Content, 4.1.2 Name, Role, Value

**Recommended fix:** Add `aria-label` or visually hidden text. Mark inner icons `aria-hidden="true"`.

### M3. Mobile authorization approval tabs are missing explicit tab relationships

**Evidence:** `app/plugins/Activities/templates/AuthorizationApprovals/mobile_approve_authorizations.php:60-88`

**Issue:** The tab buttons have `role="tab"` but do not include initial `aria-controls` and `aria-selected` values. Tab panels also do not link back with `aria-labelledby`.

**User impact:** Screen reader users may not hear the selected tab state or tab/panel relationship reliably.

**WCAG 2.2 AA:** 1.3.1 Info and Relationships, 4.1.2 Name, Role, Value

**Recommended fix:** Add complete Bootstrap tab ARIA:

- `aria-controls="pending-pane"` and `aria-selected="true"` on the active tab
- `aria-selected="false"` on inactive tabs
- `aria-labelledby="pending-tab"` on each tab panel

### M4. Waiver upload wizard steps and attestation radios need stronger semantics

**Evidence:**

- `app/plugins/Waivers/templates/element/GatheringWaivers/mobile_wizard_steps.php:22`, `123`, `223`
- `app/plugins/Waivers/templates/element/GatheringWaivers/upload_wizard_steps.php:18`, `111`, `204`
- `app/plugins/Waivers/templates/element/waiver_attestation_modal.php:34-37`

**Issue:** Wizard steps use `data-step-number` and visual headings, but the active step is not exposed as a region/current step. Dynamically inserted attestation radio options are placed in a generic `<div>` rather than a fieldset/legend structure.

**User impact:** Screen reader users may not understand wizard progress, current step, or the shared question for dynamic radio options.

**WCAG 2.2 AA:** 1.3.1 Info and Relationships, 2.4.6 Headings and Labels, 3.3.2 Labels or Instructions

**Recommended fix:**

- Add `role="region"` and `aria-labelledby` to each step.
- Add a visible or visually hidden "Step X of Y" status and update it on step changes.
- Wrap dynamically inserted attestation radio buttons in `<fieldset><legend>Why was this waiver not needed?</legend>...</fieldset>`.

### M5. Grid filter remove buttons are below WCAG 2.2 AA target size minimum

**Evidence:** `app/assets/js/controllers/grid-view-controller.js:312-319` and similar dynamically generated remove buttons

**Issue:** Filter pill remove buttons are styled at `18px` by `18px`.

**User impact:** Users with motor impairments and touch users may have difficulty removing filters.

**WCAG 2.2 AA:** 2.5.8 Target Size (Minimum)

**Recommended fix:** Increase the effective target to at least `24px` by `24px`, or provide adequate spacing/equivalent target behavior that satisfies WCAG 2.2 AA exceptions. Prefer `32px` square where layout allows.

### M6. Popovers do not support Escape dismissal

**Evidence:** `app/assets/js/controllers/popover-controller.js:68-96`

**Issue:** Popovers can be closed with a close button or click handling, but there is no Escape key handler.

**User impact:** Keyboard users expect Escape to dismiss transient overlay content.

**WCAG 2.2 AA:** 1.4.13 Content on Hover or Focus, 2.1.1 Keyboard

**Recommended fix:** Add a document-level Escape listener while the popover is shown, hide the popover, and remove the listener on disconnect.

### M7. Several action table headers are empty

**Evidence examples:**

- `app/plugins/Activities/templates/element/authorization_approvals_table.php:36`
- `app/plugins/Activities/templates/element/member_authorizations_table.php:38`
- `app/plugins/Awards/templates/Recommendations/table.php:198`

**Issue:** Action columns often use empty `<th scope="col" class="actions">` cells.

**User impact:** Screen reader users navigating by table header may not understand the purpose of action cells.

**WCAG 2.2 AA:** 1.3.1 Info and Relationships

**Recommended fix:** Use visible "Actions" text or visually hidden text:

```php
<th scope="col" class="actions">
    <span class="visually-hidden"><?= __('Actions') ?></span>
</th>
```

### M8. Waiver upload file selection lacks programmatic description of constraints

**Evidence:**

- `app/plugins/Waivers/templates/element/GatheringWaivers/mobile_wizard_steps.php:160-177`
- `app/plugins/Waivers/templates/element/GatheringWaivers/upload_wizard_steps.php:147-158`

**Issue:** The visible file-selection buttons have understandable text, but the hidden file inputs and triggers are not programmatically tied to the supported-format and max-size instructions.

**User impact:** Assistive technology users may not receive the same file constraints before opening the picker.

**WCAG 2.2 AA:** 3.3.2 Labels or Instructions

**Recommended fix:** Add IDs to the tips/instructions and set `aria-describedby` on the visible trigger button and file input. If the hidden input is ever made focusable, add an explicit accessible name.

## Low priority / advisory gaps

### L1. Small controls should be reviewed for WCAG 2.2 target-size exceptions

**Evidence examples:** Dense tables, `btn-sm` actions, grid badges, and compact plugin controls.

**Issue:** WCAG 2.2 AA introduced 2.5.8 Target Size (Minimum). Some small controls may be compliant through exceptions such as inline layout, equivalent controls, or sufficient spacing, but this needs rendered review.

**Recommended action:** During visual QA, check touch target size and spacing for all primary mobile workflows. Do not rely on `.btn-sm` for important mobile actions unless spacing/equivalent-target exceptions clearly apply.

### L2. Pagination `aria-current` should be verified in rendered output

**Evidence:** Paginator helper usage in grid/table elements.

**Issue:** CakePHP/Bootstrap paginator helpers generally provide good markup, but the rendered active page should be verified for `aria-current="page"` or equivalent active-state semantics.

**Recommended action:** Include pagination in the next axe/screen-reader smoke pass.

### L3. Profile photo remove focus styling should be visually confirmed

**Evidence:** `app/assets/css/app.css` includes custom focus styles for member profile photo remove controls.

**Issue:** A box-shadow focus alternative is present, but the visual contrast and thickness should be checked on the actual image/background combinations.

**Recommended action:** Confirm visible focus at normal and high-contrast settings.

### L4. Calendar and dashboard color contrast needs rendered measurement

**Evidence:** Calendar and waiver dashboard use inline/background colors from event types and CSS variables.

**Issue:** Some colors are data-driven. Code review cannot guarantee every configured foreground/background pair meets 1.4.3 Contrast (Minimum).

**Recommended action:** Run automated contrast checks against representative production/staging data, especially custom event type colors and mobile theme variables.

## Retired / inactive code guardrail

### R1. Awards Kanban remains a reactivation risk

**Evidence:**

- `app/plugins/Awards/templates/Recommendations/board.php:26-54`
- `app/assets/js/controllers/kanban-controller.js`
- `app/plugins/Awards/src/Controller/RecommendationsController.php:1466-1487`

**Status:** The team has stated that Awards Kanban is retired and not present in active UI navigation. Do not count it as an active user-facing WCAG blocker while it remains unreachable.

**Risk:** The route/template/controller still exist. If the board is re-linked or used directly, it remains drag-only and would fail keyboard/dragging requirements.

**Required guardrail:** Before any reactivation, either remove the board entirely or add a keyboard-accessible move workflow with visible controls, status announcements, and focus management.

## Native dialog / inline handler status

Current active UI status:

- No direct active `window.alert()`, `window.confirm()`, or `window.prompt()` calls were found outside the shared accessibility helpers/controllers.
- CakePHP `Form->postLink(... ['confirm' => ...])` usage still exists in templates and plugins, but the global `KMP_accessibility.installCakeConfirmAdapter()` intercepts Cake's `data-confirm-message` click path before the native confirm executes and routes it through the accessible modal.
- The only remaining `onclick` source match is intentional parsing inside `KMP_accessibility` so the adapter can submit Cake's generated hidden POST forms after accessible confirmation.
- The reusable back button no longer uses inline `window.history.back()`; it is wired to the `history-back` Stimulus controller.

Future code should still prefer explicit POST forms plus `data-controller="confirmation"` for new work. Treat the Cake adapter as backward compatibility for older `postLink` usage, not as the preferred pattern.

## Detailed AI remediation instructions

Use these instructions when picking up future accessibility remediation. Do not repeat the original audit; start from this current remaining-gap list.

### Priority 1 - Keyboard and name/role/value blockers

1. Fix `image-zoom-controller.js` keyboard access.
   - Add focusability, keyboard zoom/pan/reset, and announcements.
   - Update any templates using `data-controller="image-zoom"` with instructions if needed.
   - Add Jest coverage for keyboard controls.

2. Fix Awards recommendations table labels.
   - Add accessible names to select-all and row checkboxes.
   - Add labels or `aria-label` values to filter controls where `label => false` is used.
   - Confirm bulk edit behavior still works.

3. Fix branch link type selector.
   - Replace icon-only hash links with real buttons.
   - Add accessible names and hidden text.
   - Confirm `branch-links#setLinkType` still receives the expected `data-value`.

4. Fix mobile PIN gate semantics.
   - Add dialog semantics and focus containment.
   - Provide a security-safe exit path.
   - Add alert/status semantics for errors and busy state.
   - Add tests around invalid PIN, valid PIN, and focus behavior.

### Priority 2 - Dynamic status, tabs, and wizard semantics

1. Add text/hidden labels to Dataverse boolean icon output.
2. Add `aria-label`/hidden text to calendar badge and nav icon controls.
3. Complete tab relationships in mobile authorization approval tabs.
4. Add wizard region/current-step semantics and fieldset/legend around dynamic attestation radio groups.
5. Add Escape dismissal to `popover-controller.js`.

### Priority 3 - Target size and rendered QA

1. Increase grid filter remove buttons to meet WCAG 2.2 AA target-size minimum or document applicable exceptions.
2. Review dense `btn-sm` surfaces on mobile.
3. Verify paginator active state in rendered output.
4. Run contrast checks against configured event colors and mobile theme variables.

### Implementation rules for future agents

- Prefer native HTML controls before ARIA.
- Keep CakePHP helper conventions where possible.
- Do not add custom keyboard handlers to non-interactive elements when a `<button>`, `<a>`, `<input>`, or `<select>` can be used instead.
- For new destructive/state-changing actions, prefer POST forms plus `confirmation` Stimulus controller instead of Cake `postLink` confirms.
- Do not use `title` as the only accessible name.
- Do not introduce icon-only controls without either visible text or an explicit accessible name.
- Dynamic status changes must use `role="status"` / `aria-live="polite"` for routine changes or `role="alert"` / assertive live regions for errors.
- Any drag, swipe, pan, or gesture workflow must have a keyboard and non-drag alternative under WCAG 2.2.
- Preserve focus after dynamic UI changes; restore focus after dialogs close.
- When using color, include text, icon shape with accessible text, or another non-color cue.
- For target size, use WCAG 2.2 AA 2.5.8 minimum target size as the baseline: at least 24 by 24 CSS pixels unless an exception clearly applies.

## Verification plan for future remediation

After fixes, run:

1. Targeted Jest tests for changed controllers.
2. `npm run production -- --no-progress` from `app/`.
3. `bash bin/verify.sh` from `app/`.
4. Playwright E2E with the local no-Docker override when needed:

```bash
PLAYWRIGHT_BASE_URL=http://127.0.0.1:8080 \
PLAYWRIGHT_WEB_SERVER_COMMAND='true' \
npx playwright test --reporter=line --timeout=90000
```

Manual QA should include:

- Keyboard-only navigation through primary admin workflows
- 200% zoom and 320 CSS pixel viewport reflow
- Reduced-motion mode
- Windows High Contrast / forced colors mode
- NVDA with Firefox or Chrome
- VoiceOver with Safari
- At least one nonprofit stakeholder or real-user workflow review

## Overall conclusion

KMP has addressed the largest original code-level accessibility risks and is now reasonably close to a strong WCAG 2.2 AA baseline for common form, table, navigation, and modal workflows. It should not yet be represented as fully WCAG 2.2 AA conformant until the remaining high and medium findings are fixed and validated in a browser with assistive technologies.

The next best investment is to finish keyboard alternatives for gesture-heavy controls, add missing accessible names in older plugin tables and icon controls, expose dynamic wizard/dialog states more explicitly, and verify rendered target sizes and contrast.
