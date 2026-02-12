# Decision: Direct Service Invocation for Intermediate Approval Actions

**Date:** 2026-02-13
**By:** Kaylee
**Status:** Implemented

## Context

When firing side-effect actions from the `on_each_approval` port, the engine needs to execute action nodes without changing the approval node's state (WAITING status, active_nodes membership, execution log).

## Decision

Use **direct service invocation** (resolve from registry, call method, log result) instead of the engine's `executeNode()` traversal for intermediate actions.

## Rationale

`executeNode()` → `executeActionNode()` has two problematic paths for intermediate actions:

1. **Async actions:** Sets instance to WAITING, queues `WorkflowResume` job. When the job runs later, `resumeWorkflow()` sets instance to RUNNING, follows outputs (none for dead-end node), and leaves instance stuck in RUNNING. The next real approval then fails because `resumeWorkflow()` rejects non-WAITING instances.

2. **Output traversal:** `advanceToOutputs()` after action completion could inadvertently chain to end nodes or other state-changing nodes.

Direct invocation eliminates both risks. The instance never leaves WAITING, and no graph traversal occurs beyond the immediate target node.

## Impact

- `fireIntermediateApprovalActions()` logs execution but does not use retry logic or depth tracking. Intermediate actions are fire-and-forget.
- If retry support is needed for intermediate actions in the future, a separate retry mechanism should be built rather than reusing `executeNode()`.
