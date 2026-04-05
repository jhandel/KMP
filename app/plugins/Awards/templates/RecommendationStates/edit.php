<?php

/**
 * @var \App\View\AppView $this
 * @var \Awards\Model\Entity\RecommendationState $state
 * @var array $statuses
 */
?>
<?php $this->extend("/layout/TwitterBootstrap/dashboard");

echo $this->KMP->startBlock("title");
echo $this->KMP->getAppSetting("KMP.ShortSiteTitle") . ': Edit Recommendation State';
$this->KMP->endBlock(); ?>

<div class="recommendationStates form content">
    <?= $this->Form->create($state, [
        'url' => ['action' => 'edit', $state->id],
    ]) ?>
    <fieldset>
        <legend><?= $this->element('backButton') ?> <?= __("Edit Recommendation State") ?></legend>
        <?php
        echo $this->Form->control("name");
        echo $this->Form->control("status_id", ['options' => $statuses, 'empty' => '-- Select Status --']);
        echo $this->Form->control("sort_order", ['type' => 'number']);
        echo $this->Form->control("supports_gathering", ['type' => 'checkbox', 'switch' => true, 'label' => __('Supports Gathering Assignment')]);
        echo $this->Form->control("is_hidden", ['type' => 'checkbox', 'switch' => true, 'label' => __('Hidden (requires permission to view)')]);
        ?>
    </fieldset>
    <div class='text-end'><?= $this->Form->button(__("Submit"), [
                                "class" => "btn-primary",
                            ]) ?></div>
    <?= $this->Form->end() ?>
</div>
