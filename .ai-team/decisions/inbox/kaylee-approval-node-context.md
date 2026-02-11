### 2026-02-11: Approval nodes must populate `$context['nodes'][$nodeId]` in resumeWorkflow()

**By:** Kaylee
**What:** Fixed `resumeWorkflow()` to store approval output data in `$context['nodes'][$nodeId]` with all 5 fields from `APPROVAL_OUTPUT_SCHEMA` (status, approverId, comment, rejectionComment, decision). Previously only `$context['resumeData']` was populated, making `$.nodes.<nodeId>.*` paths unresolvable at runtime.
**Why:** The variable picker (via `WorkflowActionRegistry::APPROVAL_OUTPUT_SCHEMA` and the `registry()` endpoint) advertises `$.nodes.<approvalNodeId>.approverId` etc. as available fields for downstream node configuration. Without writing to `$context['nodes']`, these paths silently resolve to null. Every other node type (actions, conditions, subworkflows) already writes to `$context['nodes'][$nodeId]` — approval was the only one missing. This is a data-flow consistency fix, not a behavioral change.
