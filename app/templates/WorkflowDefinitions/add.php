<?php

/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\WorkflowDefinition $definition
 */
?>
<?php
$this->extend("/layout/TwitterBootstrap/dashboard");

echo $this->KMP->startBlock("title");
echo $this->KMP->getAppSetting("KMP.ShortSiteTitle") . ': Add Workflow Definition';
$this->KMP->endBlock();
?>

<div class="workflowDefinitions form content">
    <?= $this->Form->create($definition) ?>
    <fieldset>
        <legend><?= $this->element('backButton') ?> <?= __('Add Workflow Definition') ?></legend>
        <div class="mb-3">
            <?= $this->Form->control('name', [
                'required' => true,
                'class' => 'form-control',
            ]) ?>
        </div>
        <div class="mb-3">
            <?= $this->Form->control('slug', [
                'required' => true,
                'class' => 'form-control',
                'help' => __('URL-safe identifier (e.g., member-onboarding).'),
            ]) ?>
        </div>
        <div class="mb-3">
            <?= $this->Form->control('description', [
                'type' => 'textarea',
                'rows' => 3,
                'class' => 'form-control',
            ]) ?>
        </div>
        <div class="mb-3">
            <?= $this->Form->control('entity_type', [
                'required' => true,
                'class' => 'form-control',
                'help' => __('The entity class this workflow applies to (e.g., Members).'),
            ]) ?>
        </div>
        <div class="mb-3">
            <?= $this->Form->control('plugin_name', [
                'class' => 'form-control',
                'help' => __('Optional plugin that owns this workflow.'),
            ]) ?>
        </div>
        <div class="mb-3">
            <?= $this->Form->control('version', [
                'type' => 'number',
                'required' => true,
                'default' => 1,
                'class' => 'form-control',
            ]) ?>
        </div>
        <?= $this->Form->control('is_active', [
            'label' => __('Active'),
            'switch' => true,
        ]) ?>
        <?= $this->Form->control('is_default', [
            'label' => __('Default'),
            'switch' => true,
        ]) ?>
    </fieldset>

    <?= $this->Form->button(__('Submit'), ['class' => 'btn btn-primary']) ?>
    <?= $this->Form->end() ?>
</div>
