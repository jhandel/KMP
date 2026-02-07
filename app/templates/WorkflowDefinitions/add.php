<?php

/**
 * WorkflowDefinitions add template
 *
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\WorkflowDefinition $definition
 */

$this->extend("/layout/TwitterBootstrap/dashboard");

echo $this->KMP->startBlock("title");
echo $this->KMP->getAppSetting("KMP.ShortSiteTitle") . ': Create Workflow';
$this->KMP->endBlock();
?>

<div class="workflowDefinitions form content">
    <?= $this->Form->create($definition) ?>
    <fieldset>
        <legend><?= $this->element('backButton') ?> <?= __('Create Workflow Definition') ?></legend>
        <?php
        echo $this->Form->control('name', ['label' => __('Name')]);
        echo $this->Form->control('slug', ['label' => __('Slug')]);
        echo $this->Form->control('description', ['label' => __('Description'), 'type' => 'textarea']);
        echo $this->Form->control('entity_type', ['label' => __('Entity Type')]);
        echo $this->Form->control('plugin_name', ['label' => __('Plugin Name'), 'empty' => true]);
        ?>
    </fieldset>
    <?= $this->Form->button(__('Submit'), ['class' => 'btn btn-primary']) ?>
    <?= $this->Form->end() ?>
</div>
