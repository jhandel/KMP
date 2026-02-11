# Decision: Condition Config Fields Are Context-Dependent

**Date:** 2026-02-11
**Author:** Wash
**Scope:** workflow-config-panel.js `_conditionHTML()`

## Decision

The "Field Path" and "Expected Value" inputs in the condition config panel are only rendered when:
1. No condition is selected (empty/default state)
2. A `Core.*` condition is selected (`Core.FieldEquals`, `Core.FieldNotEmpty`, `Core.Expression`)

They are hidden when a plugin condition is selected (anything not starting with `Core.`), since plugin conditions use their own `inputSchema` fields rendered separately.

## Rationale

Plugin conditions like `Officers.OfficeRequiresWarrant` define their own parameters via `inputSchema`. The generic "Field Path" / "Expected Value" fields are meaningless for those conditions and create confusing clutter in the config panel. This follows the same pattern already established for action `inputSchema` fields — show only what's relevant to the selected item.
