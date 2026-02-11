# Session: Timezone & Port Fixes

**Date:** 2026-02-11
**Requested by:** Josh Handel
**Agent:** Kaylee (Backend Dev)

## Work Done

1. **Workflow port label off-by-one fix** — `getPortLabel()` in the designer used Drawflow's 1-based port indices to index into 0-based arrays, causing trigger/action outputs named `output-2` instead of `default`, and approval port labels to be swapped. Added backward-compatible `output-N` → default alias in engine `portsMatch()`.

2. **Warrant grid timezone display fix** — Date columns in `dataverse_table.php` now use `$this->Timezone->date()` instead of raw `format()`. Date-range filter values are converted from kingdom timezone to UTC before SQL queries. System view boundary dates in `WarrantsGridColumns` use kingdom-timezone "today" instead of UTC.

## Commits

- `26aabd81` — fix(workflow): fix port label off-by-one and add backward compat
- `79313225` — fix(warrants): convert grid dates and filters to kingdom timezone
