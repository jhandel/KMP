<?php

use Cake\I18n\Date;
use Cake\I18n\DateTime;

/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Branch $branch
 */

$this->extend("/layout/TwitterBootstrap/view_record");

echo $this->KMP->startBlock("pageTitle") ?>
<?= h($branch->name) ?>
<?php $this->KMP->endBlock() ?>
<?= $this->KMP->startBlock("recordActions") ?>
<button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#editModal">Edit</button>
<?php if (empty($branch->children) && empty($branch->members)) {
    echo $this->Form->postLink(
        __("Delete"),
        ["action" => "delete", $branch->id],
        [
            "confirm" => __(
                "Are you sure you want to delete {0}?",
                $branch->name,
            ),
            "title" => __("Delete"),
            "class" => "btn btn-danger btn-sm",
        ],
    );
} ?>
<?php $this->KMP->endBlock() ?>
<?php $this->KMP->startBlock("recordDetails") ?>
<tr scope="row">
    <th class="col"><?= __("Location") ?></th>
    <td class="col-10"><?= h($branch->location) ?></td>
</tr>
<tr scope="row">
    <th class="col"><?= __("Parent") ?></th>
    <td class="col-10"><?= $branch->parent === null
                            ? "Root"
                            : $this->Html->link(
                                __($branch->parent->name),
                                ["action" => "view", $branch->parent_id],
                                ["title" => __("View")],
                            ) ?></td>
</tr>
<?php $this->KMP->endBlock() ?>
<?php $this->KMP->startBlock("tabButtons") ?>
<button class="nav-link active" id="nav-members-tab" data-bs-toggle="tab" data-bs-target="#nav-members" type="button" role="tab" aria-controls="nav-members" aria-selected="false"><?= __("Members") ?>
</button>
<button class="nav-link" id="nav-branches-tab" data-bs-toggle="tab" data-bs-target="#nav-branches" type="button" role="tab" aria-controls="nav-branches" aria-selected="false"><?= __("Branches") ?>
</button>
<?php $this->KMP->endBlock() ?>
<?php $this->KMP->startBlock("tabContent") ?>
<div class="related tab-pane fade active m-3" id="nav-members" role="tabpanel" aria-labelledby="nav-members-tab">
    <?php if (!empty($branch->members)) : ?>
        <div class="table-responsive">
            <table class="table table-striped">
                <tr>
                    <th scope="col"><?= __("Name") ?></th>
                    <th scope="col"><?= __("Minor") ?></th>
                    <th scope="col"><?= __("Membership Number") ?></th>
                    <th scope="col"><?= __("Membership Exp. Date") ?></th>
                    <th scope="col"><?= __("Status") ?></th>
                    <th scope="col" class="actions"><?= __("Actions") ?></th>
                </tr>
                <?php foreach ($branch->members as $member) : ?>
                    <tr>
                        <td><?= h($member->sca_name) ?></td>
                        <td><?= $this->KMP->bool($member->age < 18, $this->Html) ?></td>
                        <td><?= h($member->membership_number) ?></td>
                        <td><?= h($member->membership_expires_on->toDateString()) ?></td>
                        <td><?= h($member->status) ?></td>
                        <td class="actions">
                            <?= $this->Html->link(
                                __("View"),
                                [
                                    "controller" => "members",
                                    "action" => "view",
                                    $member->id,
                                ],
                                [
                                    "title" => __("View"),
                                    "class" => "btn btn-secondary",
                                ],
                            ) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>
    <?php else : ?>
        <p><?= __("No members found") ?></p>
    <?php endif; ?>
</div>
<div class="related tab-pane fade m-3" id="nav-branches" role="tabpanel" aria-labelledby="nav-branches-tab">
    <?php if (!empty($branch->children)) : ?>
        <div class="table-responsive">
            <table class="table table-striped">
                <tr>
                    <th scope="col"><?= __("Name") ?></th>
                    <th scope="col"><?= __("Location") ?></th>
                    <th scope="col" class="actions"><?= __("Actions") ?></th>
                </tr>
                <?php foreach ($branch->children as $child) : ?>
                    <tr>
                        <td><?= h($child->name) ?></td>
                        <td><?= h($child->location) ?></td>
                        <td class="actions">
                            <?= $this->Html->link(
                                __("View"),
                                ["action" => "view", $child->id],
                                [
                                    "title" => __("View"),
                                    "class" => "btn btn-secondary",
                                ],
                            ) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>
    <?php else : ?>
        <p><?= __("No branches found") ?></p>
    <?php endif; ?>
</div>
<?php $this->KMP->endBlock() ?>
<?php
echo $this->KMP->startBlock("modals");

echo $this->element('branches/editModal', [
    'user' => $user,
]);

$this->KMP->endBlock(); ?>