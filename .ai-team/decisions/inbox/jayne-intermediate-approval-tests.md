### 2026-02-12: Intermediate Approval Actions — Tests Written (Spec-First)

**By:** Jayne  
**Status:** Tests ready, awaiting Kaylee's implementation

**What:** Created `app/tests/TestCase/Services/WorkflowEngine/IntermediateApprovalActionsTest.php` with 6 test methods covering all spec cases for `fireIntermediateApprovalActions()`. Tests use `markTestSkipped()` when the method doesn't exist — they'll auto-activate once Kaylee's implementation lands.

**Test coverage:**
1. Serial pick-next: 3-step approval chain, intermediate fires on_each_approval, final fires approved
2. Parallel: same pattern with parallel approvals
3. Backward compat: no on_each_approval edges → no errors
4. Rejection: rejected port only, on_each_approval never fires
5. Context injection: approvedCount, requiredCount, approverId, decision verified in context and action service
6. Single-approval gate: on_each_approval never fires (only approval is final)

**For Kaylee:** Tests use a `IntermediateActionTracker` dummy action registered as `TestIntermediate.Track` in `WorkflowActionRegistry`. Your implementation needs to:
- Reset `visitedNodes`/`executionDepth` (test 1 calls the method twice on the same instance)
- Inject approvalData into `context['nodes'][$nodeId]` before executing targets
- Use `getNodeOutputTargets(definition, nodeId, 'on_each_approval')` to find targets
- Execute targets (action nodes work), then restore instance to WAITING
- Return `ServiceResult(true)` even when there are no on_each_approval targets

**For Wash:** Tests don't cover frontend. The designer's 3-port rendering is a separate concern.

**Run:** `cd app && vendor/bin/phpunit --filter IntermediateApprovalActionsTest --no-coverage`
