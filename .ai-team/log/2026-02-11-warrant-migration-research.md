# Session: Warrant Migration Research

**Date:** 2026-02-11
**Requested by:** Josh Handel

## Participants

- **Kaylee** — Data model mapping research
- **Mal** — Architecture assessment

## What Was Done

- Kaylee mapped the full data model between `warrant_rosters`/`warrant_roster_approvals` and `workflow_instances`/`workflow_approvals`/`workflow_approval_responses`. Identified field-level gaps, FK constraints, synthetic data requirements, and a draft migration query (~107 rows across 4 tables).
- Mal analyzed 4 strategic options: (A) Full Migration, (B) Forward-Only, (C) Unified View Layer, (D) Thin Adapter on view template. Assessed each for complexity, risk, UI impact, code cleanup, and future burden.

## Decision

Both agents recommend **Option B: Forward-Only**.

- Don't migrate historical data. New rosters already flow through the workflow engine.
- The sync layer (`syncWorkflowApprovalToRoster()`) bridges the gap cleanly — 40 lines, 13 tests, idempotent.
- Synthetic workflow instances are an antipattern — pollutes instance list, confuses "My Approvals" grid.
- Revisit in 6–12 months when all pending rosters have resolved via workflows.

## Key Findings

- 34 rosters (29 Approved, 5 Pending), 19 approval records — tiny volume.
- Only 3 rosters have workflow instances; the other 31 used the direct path.
- `warrants.warrant_roster_id` FK means roster tables cannot be dropped regardless.
- Entity/schema mismatch: `WarrantRoster` entity docblock references columns that don't exist in DB.
