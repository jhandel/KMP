<?php

$revokeButton = [
    "type" => "button",
    "verify" => true,
    "label" => "Revoke",
    "controller" => "Authorizations",
    "action" => "revoke",
    "options" => [
        "class" => "btn btn-danger revoke-btn",
        "data-bs-toggle" => "modal",
        "data-bs-target" => "#revokeModal",
        "data-controller" => "grid-btn",
        "data-action" => "click->grid-btn#fireNotice",
        "data-grid-btn-row-data-value" => '{ "id":{{id}} }',
    ],
];
$columnTemplate = [
    "Member" => "member->sca_name",
];
if ($state == "current") {
    $columnTemplate["Start Date"] = "start_on";
    $columnTemplate["End Date"] = "expires_on";
    $columnTemplate["Actions"] = [
        $revokeButton
    ];
}
if ($state == "pending") {
    $columnTemplate["Requested Date"] = "current_pending_approval->requested_on";
    $columnTemplate["Assigned To"] = "current_pending_approval->approver->sca_name";
}
if ($state == "previous") {
    $columnTemplate["Start Date"] = "start_on";
    $columnTemplate["End Date"] = "expires_on";
    $columnTemplate["Reason"] = "revoked_reason";
}

$tableData = [
    "label" => __("Active"),
    "id" => $turboFrameId,
    "columns" => $columnTemplate,
    "data" => $authorizations,
    "usePagination" => true,
];

echo $this->element('turboSubTable', ['user' => $user, 'tableConfig' => $tableData]);