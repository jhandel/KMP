---
name: "workflow-output-port-pattern"
description: "Adding new output ports to existing node types in a graph-based workflow engine"
domain: "workflow-engine-architecture"
confidence: "high"
source: "earned"
---

## Context
When a graph-based workflow engine needs to fire side-effect actions at new lifecycle points (e.g., intermediate approval steps), the cleanest approach is adding a new output port to the existing node type rather than callbacks, config hooks, or cross-workflow events.

## Pattern

### New Output Port for Lifecycle Events

When a node type needs to fire actions at a new lifecycle point:

1. **Add a named output port** to the node type alongside existing ports
2. **Keep existing port semantics unchanged** — the new port is additive
3. **Create a dedicated engine method** for the new lifecycle point — don't overload the existing resume/completion method
4. **Let the controller decide** which lifecycle method to call based on the operation's result
5. **Execute connected actions, then return to the original state** (e.g., fire intermediate actions, then go back to WAITING)

### Why Not Alternatives

- **Config callback** (e.g., `onIntermediateAction: 'SomeAction'`): Invisible in visual designer, can't compose chains, breaks the fundamental model of "nodes connected by edges"
- **Event/trigger to separate workflow**: Loses parent context, no transactional guarantee, multiplicative complexity
- **Inline in the node's execution logic**: Couples node behavior to specific actions, not reusable

### Key Implementation Details

- The new port's actions execute in a **non-finalizing** context — the node stays active, logs stay in waiting state
- Context should be injected with lifecycle-specific data before traversing the new port (e.g., approval progress, who acted, what's remaining)
- Backward compatible: existing nodes without edges to the new port work unchanged — empty target list means no-op
- **Use direct service invocation** (resolve from registry, call method, log) rather than `executeNode()` for non-finalizing actions. `executeNode()` has async-action and output-traversal side effects that can corrupt the workflow's state machine (e.g., async resume sets instance to RUNNING and never restores WAITING).

## Anti-Patterns

- Modifying the existing finalization method to sometimes-not-finalize — confusing dual semantics
- Storing the "intermediate action" in node config instead of as a visible graph edge
- Firing intermediate actions from the approval manager instead of the engine — leaks engine traversal logic into a different layer
- **Using `executeNode()` for non-finalizing side effects** — async actions queue `WorkflowResume` jobs that change instance status to RUNNING and leave it there, breaking the pending approval gate
