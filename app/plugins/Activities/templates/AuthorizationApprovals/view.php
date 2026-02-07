<?php

/**
 * @var \App\View\AppView $this
 * @var string $queueFor
 * @var bool $isMyQueue
 * @var int|string $id
 */

// Use Kmp helper for common utilities

$this->extend("/layout/TwitterBootstrap/dashboard");

echo $this->KMP->startBlock("title");
echo $this->KMP->getAppSetting("KMP.ShortSiteTitle") . ': View Authorization Queue for ' . $queueFor;
$this->KMP->endBlock();

$this->extend("/layout/TwitterBootstrap/view_record");

echo $this->KMP->startBlock("pageTitle") ?>
<?= $this->KMP->makePossessive($queueFor) ?> Auth Request Queue
<?php $this->KMP->endBlock() ?>

<?= $this->KMP->startBlock("recordActions") ?>
<?php $this->KMP->endBlock() ?>

<?php $this->KMP->startBlock("recordDetails") ?>
<?php $this->KMP->endBlock() ?>

<?php $this->KMP->startBlock("tabButtons") ?>
<?php $this->KMP->endBlock() ?>

<?php $this->KMP->startBlock("tabContent") ?>
<div class="tab-pane fade show active m-3" role="tabpanel" tabindex="0">
    <?= $this->element('dv_grid', [
        'gridKey' => 'Activities.AuthorizationApprovals.view',
        'frameId' => 'view-queue-grid',
        'dataUrl' => $this->Url->build(['action' => 'viewGridData', $id]),
    ]) ?>
</div>
<?php $this->KMP->endBlock() ?>

<?php
echo $this->KMP->startBlock("modals");
echo $this->Form->create(null, [
    "url" => ["controller" => "AuthorizationApprovals", "action" => "deny"],
    "data-controller" => "revoke-form",
    "data-revoke-form-outlet-btn-outlet" => ".deny-btn",
    "data-turbo" => "false",
]);
echo $this->Modal->create("Deny Authorization", [
    "id" => "denyModal",
    "close" => true,
]);
?>
<fieldset>
    <?php
    echo $this->Form->control("id", [
        "type" => "hidden",
        "data-revoke-form-target" => "id",
    ]);
    echo $this->Form->control("approver_notes", [
        "label" => "Reason for Denial",
        "data-revoke-form-target" => "reason",
        "data-action" => "input->revoke-form#checkReadyToSubmit",
        "help" => "This message will be visible to the requester"
    ]);
    ?>
</fieldset>
<?php
echo $this->Modal->end([
    $this->Form->button("Submit", [
        "class" => "btn btn-primary",
        "data-revoke-form-target" => "submitBtn",
    ]),
    $this->Form->button("Close", [
        "data-bs-dismiss" => "modal",
        "type" => "button",
    ]),
]);
echo $this->Form->end();
echo $this->Form->create(null, [
    "url" => [
        "controller" => "AuthorizationApprovals",
        "action" => "Approve",
    ],
    "data-controller" => "activities-approve-and-assign-auth",
    "data-activities-approve-and-assign-auth-outlet-btn-outlet" => ".approve-btn",
    "data-activities-approve-and-assign-auth-url-value" => $this->Url->build(['plugin' => 'activities', 'controller' => 'AuthorizationApprovals', 'action' => 'AvailableApproversList']),
    "data-turbo" => "false",
]);
echo $this->Modal->create("Approve and Assign to next", [
    "id" => "approveAndAssignModal",
    "close" => true,
]);
?>
<fieldset>
    <?php
    echo $this->Form->control("id", [
        "type" => "hidden",
        "data-activities-approve-and-assign-auth-target" => 'id'
    ]);
    echo $this->KMP->comboBoxControl(
        $this->Form,
        'next_approver_name',
        'next_approver_id',
        [],
        "Forward to",
        true,
        false,
        [
            'data-activities-approve-and-assign-auth-target' => 'approvers',
            'data-action' => 'change->activities-approve-and-assign-auth#checkReadyToSubmit'
        ]
    );
    ?>
</fieldset>
<?php echo $this->Modal->end([
    $this->Form->button("Submit", [
        "class" => "btn btn-primary",
        "data-activities-approve-and-assign-auth-target" => 'submitBtn'
    ]),
    $this->Form->button("Close", [
        "data-bs-dismiss" => "modal",
        "type" => "button",
    ]),
]);
echo $this->Form->end();

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
$this->KMP->endBlock(); ?>