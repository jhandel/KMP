<?php

/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\ActivityGroup $authorizationGroup
 */
?>
<?php

$this->extend("/layout/TwitterBootstrap/view_record");

echo $this->KMP->startBlock("title");
echo $this->KMP->getAppSetting("KMP.ShortSiteTitle") . ': View Department - ' . h($department->name);
$this->KMP->endBlock();

echo $this->KMP->startBlock("pageTitle") ?>
<?= h($department->name) ?>
<?php $this->KMP->endBlock() ?>
<?= $this->KMP->startBlock("recordActions") ?>
<button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#editModal">Edit</button>
<?php $this->KMP->endBlock() ?>
<?php $this->KMP->startBlock("recordDetails") ?>
<tr scope="row">
    <th class="col"><?= __("Email Domain") ?></th>
    <td class="col-10"><?= $department->domain ?></td>
</tr>
<?php $this->KMP->endBlock() ?>
<?php $this->KMP->startBlock("tabButtons") ?>
<button class="nav-link" id="nav-Offices-tab" data-bs-toggle="tab" data-bs-target="#nav-Offices" type="button"
    role="tab" aria-controls="nav-Offices" aria-selected="false"
    data-detail-tabs-target='tabBtn'><?= __("Related Offices") ?>
</button>
<?php $this->KMP->endBlock() ?>
<?php $this->KMP->startBlock("tabContent") ?>
<div class="related tab-pane fade active m-3" id="nav-Offices" role="tabpanel" aria-labelledby="nav-Offices-tab"
    data-detail-tabs-target="tabContent">
    <?php
    $offices = $department->offices;
    if (!empty($offices)) : ?>
    <table class="table table-striped">
        <thead>
            <tr>
                <th scope="col"><?= __("name") ?></th>
                <th scope="col" class="text-center"><?= __("Term (Months)") ?></th>
                <th scope="col" class="text-center"><?= __("Required") ?></th>
                <th scope="col" class="text-center"><?= __("Skip Report") ?></th>
                <th scope="col" class="text-center"><?= __("Warrant") ?></th>
                <th scope="col" class="text-center"><?= __(
                                                            "One Per Branch",
                                                        ) ?></th>
                <th scope="col" class="text-center"><?= __("Reports To") ?></th>
                <th scope="col" class="text-center"><?= __("Grants Role") ?></th>
                <th scope="col" class="actions"></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($offices as $office) : ?>
            <tr>
                <td><?= h($office->name) ?></td>
                <td class="text-center"><?= h($office->term_length) ?></td>
                <td class="text-center"><?= $this->Kmp->bool(
                                                    $office->required_office,
                                                    $this->Html,
                                                ) ?></td>
                <td class="text-center"><?= $this->Kmp->bool(
                                                    $office->can_skip_report,
                                                    $this->Html,
                                                ) ?></td>
                <td class="text-center"><?= $this->Kmp->bool(
                                                    $office->requires_warrant,
                                                    $this->Html,
                                                ) ?></td>
                <td class="text-center"><?= $this->Kmp->bool(
                                                    $office->only_one_per_branch,
                                                    $this->Html,
                                                ) ?></td>
                <td class="text-center"><?= h(
                                                    $office->deputy_to === null
                                                        ? ""
                                                        : $office->deputy_to->name,
                                                ) ?></td>

                <td class="text-center"><?= h(
                                                    $office->grants_role === null
                                                        ? ""
                                                        : $office->grants_role->name,
                                                ) ?></td>
                <td class="actions text-end text-nowrap">
                    <?= $this->Html->link(
                                __(""),
                                ["action" => "view", "controller" => "offices", $office->id],
                                ["title" => __("View"), "class" => "btn-sm btn btn-secondary bi bi-binoculars-fill"],
                            ) ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php else : ?>
    <p>No Offices Assigned</p>
    <?php endif; ?>
</div>

<?php $this->KMP->endBlock() ?>
<?php
echo $this->KMP->startBlock("modals");
echo $this->Form->create($department, [
    "id" => "edit_entity",
    "url" => [
        "controller" => "Departments",
        "action" => "edit",
        $department->id,
    ],
]);
echo $this->Modal->create("Edit Department", [
    "id" => "editModal",
    "close" => true,
]);
?>
<fieldset>
    <?php

    echo $this->Form->control("name");
    echo $this->Form->control("domain", ['label' => 'Email Domain', 'placeholder' => 'e.g. branch.example.com']);

    ?>
</fieldset>
<?php echo $this->Modal->end([
    $this->Form->button("Submit", [
        "class" => "btn btn-primary",
        "id" => "edit_entity__submit"
    ]),
    $this->Form->button("Close", [
        "data-bs-dismiss" => "modal",
        "type" => "button",
    ]),
]);
echo $this->Form->end(); ?>

<?php //finish writing to modal block in layout
$this->KMP->endBlock(); ?>