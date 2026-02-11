# Decision: Action Schema & Context Mapping (Phases 3–5)

**Date:** 2026-02-10
**Author:** Kaylee
**Status:** Implemented

## What Changed

### Phase 3: Approval Output Schema + builtinContext
- Added `APPROVAL_OUTPUT_SCHEMA` constant to `WorkflowActionRegistry` — declares the 5 output fields (status, approverId, comment, rejectionComment, decision) that approval nodes produce.
- Extended `WorkflowsController::registry()` to return `approvalOutputSchema` and `builtinContext` (instance.id, instance.created, triggeredBy) to the designer UI.

### Phase 4: Publish-Time Validation
- Extended `DefaultWorkflowVersionManager::validateDefinition()` to validate action/condition nodes against their registered `inputSchema` at publish time.
- Only validates nodes that have a `config.action` or `config.condition` set — bare/unconfigured nodes pass structural validation unchanged (preserves existing test fixtures).
- Checks: unknown action/condition references, missing required parameters.
- `WorkflowConditionRegistry::getCondition()` already existed — no new method needed.

### Phase 5: Schema Enrichment
- Added `description` fields to inputSchema entries in `WarrantWorkflowProvider` (5 actions) and `OfficersWorkflowProvider` (4 actions, 3 conditions).
- Added `default` values where sensible (e.g., `description` and `reason` fields default to `''`).
- Descriptions are short and functional — focused on what a workflow configurator needs to know.

## Key Decision
- Publish-time validation skips action/condition nodes without a `config` block. This prevents false positives on structurally minimal test definitions while still catching misconfigured nodes in real workflows.
