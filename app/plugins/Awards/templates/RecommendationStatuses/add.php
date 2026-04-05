<?php

/**
 * @var \App\View\AppView $this
 * @var \Awards\Model\Entity\RecommendationStatus $status
 */
?>
<?php $this->extend("/layout/TwitterBootstrap/dashboard");

echo $this->KMP->startBlock("title");
echo $this->KMP->getAppSetting("KMP.ShortSiteTitle") . ': Add Recommendation Status';
$this->KMP->endBlock(); ?>

<div class="recommendationStatuses form content">
    <?= $this->Form->create($status) ?>
    <fieldset>
        <legend><?= $this->element('backButton') ?> <?= __("Add Recommendation Status") ?></legend>
        <?php
        echo $this->Form->control("name");
        echo $this->Form->control("sort_order", ['type' => 'number', 'default' => 0]);
        ?>
    </fieldset>
    <div class='text-end'><?= $this->Form->button(__("Submit"), [
                                "class" => "btn-primary",
                            ]) ?></div>
    <?= $this->Form->end() ?>
</div>
