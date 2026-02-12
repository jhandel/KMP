### 2026-02-12: Intermediate Approval Actions — New `on_each_approval` Output Port on Approval Node

**By:** Mal
**What:** Add a third output port `on_each_approval` to the approval node type. When a non-final approval is recorded (serial pick-next intermediate step, or parallel approval that doesn't yet meet `requiredCount`), the engine fires actions connected to this port, then returns the node to WAITING. The existing `approved`/`rejected` ports fire only on final resolution — no change to their semantics.

**Why:** The old hardcoded Activities authorization flow sent two emails on every intermediate approval step: (1) notify the next approver they have a pending approval, and (2) notify the requester of approval progress. The workflow engine currently has no way to fire actions on intermediate approvals — `recordResponse()` either returns `needsMore: true` (and the controller does nothing) or resolves the gate (and the controller calls `resumeWorkflow` with `approved`/`rejected`). This leaves a UX gap where multi-approval workflows are silent between the first and final approval. Adding `on_each_approval` to the generic engine means ANY workflow with multi-step approvals can have intermediate notification actions — not just Activities.

---

## Architecture Analysis

### Current Flow (Before)

1. `WorkflowsController::recordApproval()` calls `approvalManager->recordResponse()`
2. `recordResponse()` atomically increments counts, checks resolution:
   - If `approved_count >= required_count` → status = APPROVED, returns `approvalStatus: 'approved'`
   - If `rejected_count > 0` → status = REJECTED, returns `approvalStatus: 'rejected'`
   - If serial-pick-next and more needed → stays PENDING, returns `needsMore: true`
   - Parallel and more needed → stays PENDING (implicit — status stays 'pending')
3. Controller checks: if `approvalStatus` is `approved` or `rejected`, calls `engine->resumeWorkflow()` with that port
4. **If `needsMore: true` or status is still `pending`: controller does nothing. No intermediate actions fire.**

### The Gap

Line 504-519 of `WorkflowsController.php`:
```php
if (in_array($data['approvalStatus'] ?? '', ['approved', 'rejected'])) {
    $engine->resumeWorkflow(...);
}
```

When an intermediate approval comes in, this `if` block doesn't execute. The workflow instance stays in WAITING. No actions fire. No emails. Silence.

### Old Hardcoded Activities Flow (What We're Preserving)

In `DefaultAuthorizationManager::processForwardToNextApprover()` (line 855-913):

On each intermediate approval:
1. **`sendApprovalRequestNotification()`** — emails the NEXT approver: "You have a pending authorization to review"
2. **`sendAuthorizationStatusToRequester()`** — emails the REQUESTER: "Step N approved by [approver], forwarded to [next approver]"

Two emails per intermediate step. This is the UX we need to replicate generically.

---

## Options Evaluated

### Option A: New `on_each_approval` Output Port ✅ RECOMMENDED

**Mechanism:** Approval node gets 3 output ports: `approved`, `rejected`, `on_each_approval`. When `recordResponse()` returns a non-final approval (either `needsMore: true` for serial, or `approvalStatus === 'pending'` with incremented counts), the controller calls a new engine method — `fireIntermediateActions(instanceId, nodeId, approvalData)` — which traverses the `on_each_approval` port, executes connected action nodes, then leaves the instance in WAITING state (does NOT mark the approval node as complete).

**Fits architecture:** This is exactly how condition nodes work — they have two ports (`true`/`false`), and the engine follows one based on evaluation. The approval node already has two ports (`approved`/`rejected`); adding a third is the same pattern. The designer already handles multi-port nodes (condition, fork). The engine's `getNodeOutputTargets()` is port-name-based — no structural change needed.

**Designer support:** Change `getNodePorts()` from `outputs: 2` to `outputs: 3`. Add `'on_each_approval'` to port labels. The third output connector renders naturally in Drawflow. Users wire notification action nodes to it.

**Context available to intermediate actions:** The engine injects approval progress into context before following the port:
```
$.nodes.<approvalNodeId>.approvedCount
$.nodes.<approvalNodeId>.requiredCount  
$.nodes.<approvalNodeId>.approverId (who just approved)
$.nodes.<approvalNodeId>.nextApproverId (for serial pick-next)
$.nodes.<approvalNodeId>.approvalChain (array of {approver_id, responded_at})
$.nodes.<approvalNodeId>.decision ('approve')
```

**Works for both patterns:**
- **Serial pick-next:** Each step fires `on_each_approval`. Context includes `nextApproverId`.
- **Parallel:** Each non-final approval fires `on_each_approval`. Context includes `approvedCount` vs `requiredCount`.

**Engine changes:**
1. `WorkflowsController::recordApproval()` — when `needsMore: true` OR status stays `pending` with approved count incremented, call `engine->fireIntermediateApprovalActions()`
2. New `DefaultWorkflowEngine::fireIntermediateApprovalActions()` — ~30 lines. Loads instance, injects approval progress into context, traverses `on_each_approval` port, executes connected nodes, then re-sets instance to WAITING (does NOT advance the approval node to complete)
3. `workflow-designer-controller.js` — `getNodePorts()` approval: `outputs: 3`, `getPortLabel()` approval: `['approved', 'rejected', 'on_each_approval']`, `buildNodeHTML()` three labels

**Estimated scope:** ~80 lines backend, ~15 lines frontend. No DB schema changes. No new files. Backward compatible — existing 2-port approval nodes still work (no edges to `on_each_approval` → nothing fires).

### Option B: Config-Based Callback ❌ REJECTED

**Mechanism:** Add `onIntermediateAction: 'Activities.NotifyApprover'` to approval node config. Engine calls the action inline when intermediate approval happens.

**Problems:**
- Not visible in the designer — users can't see what happens on intermediate approvals
- Only supports one action (or requires array syntax, which is ad-hoc)
- Can't compose chains (e.g., notify approver → then notify requester) without inventing sub-graph semantics
- Context passing is implicit and hard to configure
- Breaks the engine's fundamental model: "nodes are wired by edges, actions execute via traversal"

### Option C: Event-Based ❌ REJECTED

**Mechanism:** Fire `ApprovalStepCompleted` trigger event. A separate workflow listens for it.

**Problems:**
- Cross-workflow coupling — the intermediate action workflow needs context from the parent workflow instance
- No transactional guarantee — parent workflow's approval state and child workflow's actions execute in separate transactions
- Over-engineered for the use case — we don't need a whole separate workflow, we need "run these 2 action nodes"
- Context sharing is lossy — the child workflow only gets what's in the event payload, not the full parent context
- Multiplicative complexity — every workflow with approvals needs a companion workflow

---

## Implementation Plan

### Phase 1: Engine Backend (Kaylee)

**File:** `app/src/Services/WorkflowEngine/DefaultWorkflowEngine.php`

Add method `fireIntermediateApprovalActions()`:
```php
public function fireIntermediateApprovalActions(
    int $instanceId,
    string $nodeId,
    array $approvalData,
): ServiceResult
```

This method:
1. Loads instance + definition
2. Injects approval progress into `context['nodes'][$nodeId]` (approvedCount, requiredCount, approverId, nextApproverId, approvalChain, decision = 'approve')
3. Gets targets from `on_each_approval` port via `getNodeOutputTargets()`
4. If targets exist: temporarily sets instance to RUNNING, executes targets, then sets back to WAITING
5. Updates instance context (preserves the new data for downstream `$.nodes` resolution)
6. Returns success

**Key detail:** Does NOT remove approval node from `active_nodes`. Does NOT mark execution log as completed. The approval node remains in WAITING state.

**File:** `app/src/Controller/WorkflowsController.php`

In `recordApproval()`, after the existing `if (approved/rejected)` block, add:
```php
// Fire intermediate actions for non-final approvals  
if ($data['approvalStatus'] === 'pending' || !empty($data['needsMore'])) {
    $engine->fireIntermediateApprovalActions(
        $data['instanceId'],
        $data['nodeId'],
        [
            'approverId' => $currentUser->id,
            'decision' => $decision,
            'comment' => $comment,
            'nextApproverId' => $nextApproverId,
        ]
    );
}
```

**File:** `app/src/Services/WorkflowEngine/DefaultWorkflowApprovalManager.php`

In `recordResponse()`, for the **parallel** (non-serial-pick-next) case where `approved_count < required_count` and `rejected_count == 0`, explicitly return `needsMore: true` in the result data so the controller knows it's an intermediate approval (currently this case returns with `approvalStatus: 'pending'` but no `needsMore` flag — controller ignores it):

```php
// After the serial-pick-next block, before the final status check
if ($approval->status === WorkflowApproval::STATUS_PENDING && $decision === WorkflowApprovalResponse::DECISION_APPROVE) {
    // Non-final parallel approval — return needsMore for intermediate action firing
    return new ServiceResult(true, null, [
        'approvalStatus' => 'pending',
        'needsMore' => true,
        'instanceId' => $approval->workflow_instance_id,
        'nodeId' => $approval->node_id,
    ]);
}
```

### Phase 2: Frontend Designer (Wash)

**File:** `app/assets/js/controllers/workflow-designer-controller.js`

1. `getNodePorts()` — change `case 'approval': return { inputs: 1, outputs: 3 }`
2. `getPortLabel()` — change `approval: ['approved', 'rejected', 'on_each_approval']`
3. `buildNodeHTML()` — update port labels rendering for 3-port approval:
   ```javascript
   approval: ['Approved', 'Rejected', 'Each Step'],
   ```
   Update the port label HTML to handle 3 labels (current code only handles 2-port pairs).

### Phase 3: Tests (Jayne)

1. **Unit test:** `fireIntermediateApprovalActions()` with a 3-approval serial workflow. Verify:
   - After 1st approval: `on_each_approval` targets execute, approval node stays WAITING
   - After 2nd approval: `on_each_approval` targets execute again, approval node stays WAITING
   - After 3rd (final) approval: `approved` targets execute, workflow completes
2. **Unit test:** Parallel approval with `requiredCount: 3`. Same pattern — intermediate actions fire on approvals 1 and 2, `approved` fires on approval 3.
3. **Unit test:** Approval node with no `on_each_approval` edges — intermediate approvals still work (backward compat), no errors.
4. **Unit test:** Rejection fires `rejected` port immediately (existing behavior preserved), does NOT fire `on_each_approval`.
5. **Integration test:** Activities authorization workflow with `on_each_approval` wired to NotifyRequester and NotifyApprover actions. Verify emails queue on intermediate steps.

### Phase 4: Seed Migration Update (Kaylee)

Update the seeded Activities authorization workflow to wire `on_each_approval` to notification actions:
```php
'approval-gate' => [
    'type' => 'approval',
    'outputs' => [
        ['port' => 'approved', 'target' => 'action-activate'],
        ['port' => 'rejected', 'target' => 'action-deny'],
        ['port' => 'on_each_approval', 'target' => 'action-notify-step'],
    ],
],
'action-notify-step' => [
    'type' => 'action',
    'label' => 'Notify Step Progress',
    'config' => [
        'action' => 'Activities.NotifyRequester',
        'params' => [
            'activityId' => '$.trigger.activityId',
            'requesterId' => '$.trigger.memberId',
            'approverId' => '$.nodes.approval-gate.approverId',
            'status' => 'Pending',
            'nextApproverId' => '$.nodes.approval-gate.nextApproverId',
        ],
    ],
],
```

---

## Key Design Decisions

1. **Port name `on_each_approval` not `on_intermediate`:** "Intermediate" is vague. "Each approval" clearly communicates it fires on every non-final approval. If someone wires it and there's only 1 required approval, it simply never fires (0 intermediate steps). No confusion.

2. **New engine method, not modifying `resumeWorkflow()`:** `resumeWorkflow()` finalizes the approval node — removes from active_nodes, marks log complete, follows terminal port. Intermediate actions need the opposite — keep node active, keep log waiting, follow a different port. Mixing these semantics into one method would be fragile. Separate method = clear separation of concerns.

3. **Controller drives the decision, not the engine:** The controller already has the `if approved/rejected → resumeWorkflow` logic. Adding `if intermediate → fireIntermediateApprovalActions` keeps the orchestration logic in one place. The engine doesn't need to know about approval resolution semantics — it just follows ports.

4. **Context injection includes `nextApproverId`:** For serial pick-next, the `nextApproverId` is available from the approval response. Injecting it into the node context means downstream `on_each_approval` actions can reference `$.nodes.approval-gate.nextApproverId` to send the "you're up next" notification. This is the critical piece that makes the Activities notification pattern work generically.

5. **Backward compatibility:** Existing 2-port approval nodes keep working. No `on_each_approval` edges → `getNodeOutputTargets()` returns empty → method does nothing except inject context. Zero risk to existing workflows.
