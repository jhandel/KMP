<?php
/**
 * All Authorizations Report - Dataverse Grid View
 *
 * @var \App\View\AppView $this
 */
$this->extend("/layout/TwitterBootstrap/dashboard");

echo $this->KMP->startBlock("title");
echo h((string)$this->KMP->getAppSetting("KMP.ShortSiteTitle")) . ': All Authorizations';
$this->KMP->endBlock();
?>

<div class="row align-items-start mb-3">
    <div class="col">
        <h3>All Authorizations</h3>
    </div>
</div>

<?= $this->element('dv_grid', [
    'gridKey' => 'Activities.Reports.allAuthorizations',
    'frameId' => 'all-authorizations-grid',
    'dataUrl' => $this->Url->build(['action' => 'allAuthorizationsGridData']),
]) ?>

<?php
echo $this->KMP->startBlock("modals");
// Workflow Audit Modal
echo $this->Modal->create("Workflow Audit", [
    "id" => "workflowAuditModal",
    "close" => true,
    "size" => "lg",
]);
?>
<div data-controller="workflow-audit"
     data-workflow-audit-url-value="<?= $this->Url->build(['plugin' => 'Activities', 'controller' => 'AuthorizationApprovals', 'action' => 'workflowAudit']) ?>">
    <div data-workflow-audit-target="loading" class="text-center p-4">
        <div class="spinner-border text-primary" role="status">
            <span class="visually-hidden">Loading...</span>
        </div>
    </div>
    <div data-workflow-audit-target="content" class="d-none">
        <div class="row mb-3">
            <div class="col-md-6">
                <strong>Member:</strong> <span data-workflow-audit-target="memberName"></span><br>
                <strong>Activity:</strong> <span data-workflow-audit-target="activityName"></span><br>
                <strong>Entity Status:</strong> <span data-workflow-audit-target="entityStatus"></span>
            </div>
            <div class="col-md-6">
                <strong>Workflow State:</strong> <span data-workflow-audit-target="workflowState"></span><br>
                <strong>Instance Status:</strong> <span data-workflow-audit-target="instanceStatus"></span><br>
                <strong>Started:</strong> <span data-workflow-audit-target="startedAt"></span>
            </div>
        </div>
        <div data-workflow-audit-target="gateApprovalsSection" class="d-none mb-3">
            <h6>Approval Chain</h6>
            <table class="table table-sm table-bordered">
                <thead><tr><th>Approver</th><th>Decision</th><th>Date</th><th>Notes</th></tr></thead>
                <tbody data-workflow-audit-target="gateApprovalsBody"></tbody>
            </table>
        </div>
        <div data-workflow-audit-target="transitionsSection" class="d-none">
            <h6>Transition History</h6>
            <div data-workflow-audit-target="timeline" class="list-group"></div>
        </div>
        <div data-workflow-audit-target="migratedNotice" class="d-none">
            <small class="text-muted"><em>This workflow instance was created by data migration. Transition history was reconstructed from the approval audit trail.</em></small>
        </div>
    </div>
    <div data-workflow-audit-target="error" class="d-none">
        <div class="alert alert-warning mb-0">No workflow data found for this authorization.</div>
    </div>
</div>
<?php
echo $this->Modal->end([
    $this->Form->button("Close", [
        "data-bs-dismiss" => "modal",
        "type" => "button",
        "class" => "btn btn-secondary",
    ]),
]);
$this->KMP->endBlock();
?>
