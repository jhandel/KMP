<?php
echo $this->Form->create(null, [
    "url" => ["controller" => "MemberRoles", "action" => "add"],
    "data-role-add-member-target" => "form",
    "data-controller" => "role-add-member",
]);

echo $this->Modal->create("Add Member to Role", [
    "id" => "addMemberModal",
    "close" => true,
]);
?>
<fieldset>
    <?php
    $url = $this->Url->build([
        'controller' => 'Members',
        'action' => 'AutoComplete',
        'plugin' => null
    ]);
    $this->KMP->autoCompleteControl(
        $this->Form,
        'sca_name',
        'member_id',
        $url,
        "SCA Name",
        true,
        false,
        3,
        [
            'data-role-add-member-target' => 'scaMember',
            'data-action' => 'change->role-add-member#checkSubmitEnable',
        ]
    );
    echo $this->Form->control("role_id", [
        "type" => "hidden",
        "value" => $role->id,
    ]);
    ?>
</fieldset>
<?php echo $this->Modal->end([
    $this->Form->button("Submit", [
        "class" => "btn btn-primary",
        "disabled" => "disabled",
        'data-role-add-member-target' => 'submitBtn',
    ]),
    $this->Form->button("Close", [
        "data-bs-dismiss" => "modal",
        "type" => "button",
    ]),
]);
echo $this->Form->end();
?>