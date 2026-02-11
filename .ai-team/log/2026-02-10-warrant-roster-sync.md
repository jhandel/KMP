# Warrant Roster ↔ Workflow Sync

**Date:** 2026-02-10
**Requested by:** Josh Handel

## Participants

- **Mal** — designed sync architecture
- **Kaylee** — implemented changes
- **Jayne** — wrote tests

## What Happened

Josh asked about warrant roster approval system integration with the workflow engine. Two parallel approval tracking systems (roster tables and workflow tables) were not syncing.

Mal designed the sync architecture: workflow engine is source of truth, sync back to roster tables at action execution time.

Kaylee implemented:
- `activateApprovedRoster()` — extracted warrant activation logic, idempotent
- `syncWorkflowApprovalToRoster()` — syncs workflow approval responses to roster with dedup
- Refactored `approve()` transaction boundaries
- Fixed `WarrantRosterApprovalsTable` validation for non-existent columns
- Fixed `WarrantApproval` entity `$_accessible` to match actual schema

Jayne wrote 16 tests covering sync, dedup, idempotency, and edge cases.

## Commits

1. `fix(warrants)` — implementation of sync architecture
2. `test(warrants)` — 16 new tests

## Outcome

273 workflow + warrant tests passing. Roster tables now stay in sync with workflow approvals.

## Decisions

- Workflow engine is source of truth for approval tracking
- Roster tables updated for display/audit at action execution time
- `activateApprovedRoster()` is idempotent and safe to retry
- Harmless double-decline on already-resolved workflows accepted (low-priority cleanup)
