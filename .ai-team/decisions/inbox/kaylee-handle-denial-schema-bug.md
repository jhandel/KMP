### 2026-02-13: HandleDenial inputSchema Bug — authorizationApprovalId vs authorizationId
**By:** Kaylee
**Requested by:** Josh Handel
**What:** The `Activities.HandleDenial` action registration declares `authorizationApprovalId` in its inputSchema, but the actual implementation (`handleDenial()`) uses `authorizationId` and looks up the pending approval internally. The seed workflow also passes `authorizationId`. The inputSchema is wrong.
**Impact:** Low — the field shows up in the workflow designer's config panel as "Authorization Approval ID *" (required), which is confusing. The seed definition never maps this field. If someone manually wires it in the designer, the action would silently ignore it since the code resolves `config['authorizationId']`, not `config['authorizationApprovalId']`.

**Fix needed (code change — not done per investigation-only scope):**
In `ActivitiesWorkflowProvider.php`, the `Activities.HandleDenial` inputSchema should be:
```php
'inputSchema' => [
    'authorizationId' => ['type' => 'integer', 'label' => 'Authorization ID', 'required' => true],
    'approverId' => ['type' => 'integer', 'label' => 'Approver ID', 'required' => true],
    'denyReason' => ['type' => 'string', 'label' => 'Deny Reason', 'required' => true],
],
```
Instead of current:
```php
'inputSchema' => [
    'authorizationApprovalId' => ['type' => 'integer', 'label' => 'Authorization Approval ID', 'required' => true],
    ...
],
```

**No changes needed to:**
- Workflow engine APPROVAL_OUTPUT_SCHEMA
- Seed workflow definition (already correct)
- DefaultWorkflowEngine or DefaultWorkflowApprovalManager
- ActivitiesWorkflowActions.php (implementation is correct)

**Key insight:** The Activities workflow denial path deliberately avoids needing the `authorization_approvals.id`. Unlike the old controller flow which received the approval ID directly from the route, the workflow action takes the `authorization_id` (from trigger context) and queries for the pending approval record. This is the right design — it decouples the workflow from Activities' internal approval tracking table.
