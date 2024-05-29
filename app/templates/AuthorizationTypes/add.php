<?php

/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\AuthorizationType $authorizationType
 * @var \App\Model\Entity\AuthorizationGroup[]|\Cake\Collection\CollectionInterface $AuthorizationGroups
 * @var \App\Model\Entity\MemberAuthorizationType[]|\Cake\Collection\CollectionInterface $MemberAuthorizationTypes
 * @var \App\Model\Entity\PendingAuthorization[]|\Cake\Collection\CollectionInterface $pendingAuthorizations
 * @var \App\Model\Entity\Permission[]|\Cake\Collection\CollectionInterface $permissions
 */
?>
<?php $this->extend("/layout/TwitterBootstrap/dashboard"); ?>

<div class="authorizationTypes form content">
    <?= $this->Form->create($authorizationType) ?>
    <fieldset>
        <legend><?= __("Add Authorization Type") ?></legend>
        <?php
        echo $this->Form->control("name");
        echo $this->Form->control("authorization_groups_id", [
            "options" => $AuthorizationGroups,
        ]);
        echo $this->Form->control("length", [
            "label" => "Duration (years)",
            "type" => "number",
        ]);
        echo $this->Form->control("minimum_age", ["type" => "number"]);
        echo $this->Form->control("maximum_age", ["type" => "number"]);
        echo $this->Form->control("num_required_authorizors", [
            "label" => "# of Approvers",
            "type" => "number",
        ]);
        ?>
    </fieldset>
    <div class='text-end'><?= $this->Form->button(__("Submit"), [
                                "class" => "btn-primary",
                            ]) ?></div>
    <?= $this->Form->end() ?>
</div>