### Flow Control Node Config Panels
**By:** Wash
**Date:** 2026-02-11
**What:** Extended config panel + variable picker coverage to ALL flow control node types (trigger inputMapping, delay, subworkflow, fork, join, end). Previously only action and condition had schema-driven fields.
**Why:** Consistency — every node type now has a proper config panel. Trigger nodes can map payload fields via `inputMapping.*` namespace. Delay inputs support variable references. Subworkflow, fork, join, and end get appropriate UI. Variable picker outputs added for delay, loop, and subworkflow so downstream nodes can reference their results.
**Pattern:** `inputMapping.*` form fields follow the same nesting pattern as `params.*` — collected in `updateNodeConfig()` and stored as `config.inputMapping = {}`. The `event` field now triggers config panel re-render like `action` and `condition`.
