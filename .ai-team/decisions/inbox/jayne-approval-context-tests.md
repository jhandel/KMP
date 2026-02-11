# Decision: Approval Node Context Test Pattern

**Date:** 2026-02-10
**Author:** Jayne (Tester)
**Status:** Implemented

## Context

Kaylee's fix to `DefaultWorkflowEngine::resumeWorkflow()` adds `$context['nodes'][$nodeId]` population for approval nodes after resume — matching the pattern already used by action and condition nodes. Without this, downstream condition nodes using `$.nodes.approve1.status` expressions would fail to resolve.

## Decision

Added 4 focused tests in `DefaultWorkflowEngineTest.php` to cover:
1. Approved path context population
2. Rejected path context population  
3. Backward compatibility (`resumeData` still populated)
4. Empty `additionalData` edge case (null defaults, no crash)

## Test Helper Pattern

`createAndStartApprovalWorkflow()` helper creates a trigger→approval→(end_ok|end_nope) graph, starts the workflow, and returns the paused instance. This pattern is reusable for any future `resumeWorkflow()` tests that need a WAITING instance with an approval gate.

## Impact

- All 36 tests in `DefaultWorkflowEngineTest.php` pass
- All 463 core-unit tests pass
- No changes to production code
