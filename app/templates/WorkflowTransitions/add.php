<?php

/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\WorkflowTransition $transition
 * @var string $workflowDefinitionId
 * @var array $states
 * @var array $triggerTypes
 */
?>
<?php
$this->extend("/layout/TwitterBootstrap/dashboard");

echo $this->KMP->startBlock("title");
echo $this->KMP->getAppSetting("KMP.ShortSiteTitle") . ': Add Workflow Transition';
$this->KMP->endBlock();
?>

<div class="workflowTransitions form content">
    <?= $this->Form->create($transition) ?>
    <fieldset>
        <legend><?= $this->element('backButton') ?> <?= __('Add Workflow Transition') ?></legend>
        <?= $this->Form->hidden('workflow_definition_id', ['value' => $workflowDefinitionId]) ?>
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
                'help' => __('URL-safe identifier (e.g., submit-for-review).'),
            ]) ?>
        </div>
        <div class="mb-3">
            <?= $this->Form->control('label', [
                'required' => true,
                'class' => 'form-control',
                'help' => __('Display label shown to users.'),
            ]) ?>
        </div>
        <div class="mb-3">
            <?= $this->Form->control('from_state_id', [
                'type' => 'select',
                'options' => $states,
                'empty' => __('-- Select From State --'),
                'required' => true,
                'class' => 'form-select',
                'label' => __('From State'),
            ]) ?>
        </div>
        <div class="mb-3">
            <?= $this->Form->control('to_state_id', [
                'type' => 'select',
                'options' => $states,
                'empty' => __('-- Select To State --'),
                'required' => true,
                'class' => 'form-select',
                'label' => __('To State'),
            ]) ?>
        </div>
        <div class="mb-3">
            <?= $this->Form->control('trigger_type', [
                'type' => 'select',
                'options' => $triggerTypes,
                'required' => true,
                'class' => 'form-select',
                'help' => __('How this transition is triggered.'),
            ]) ?>
        </div>
        <div class="mb-3">
            <?= $this->Form->control('priority', [
                'type' => 'number',
                'default' => 0,
                'class' => 'form-control',
                'help' => __('Higher priority transitions are evaluated first.'),
            ]) ?>
        </div>
        <div class="mb-3">
            <?= $this->Form->control('is_automatic', [
                'type' => 'checkbox',
                'class' => 'form-check-input',
                'label' => __('Automatic Transition'),
            ]) ?>
        </div>
        <div class="mb-3">
            <?= $this->Form->control('conditions', [
                'type' => 'textarea',
                'rows' => 3,
                'class' => 'form-control',
                'help' => __('Optional JSON conditions for this transition.'),
            ]) ?>
        </div>
        <div class="mb-3">
            <?= $this->Form->control('actions', [
                'type' => 'textarea',
                'rows' => 3,
                'class' => 'form-control',
                'help' => __('Optional JSON actions to run when this transition fires.'),
            ]) ?>
        </div>
        <div class="mb-3">
            <?= $this->Form->control('description', [
                'type' => 'textarea',
                'rows' => 3,
                'class' => 'form-control',
            ]) ?>
        </div>
    </fieldset>

    <div class="form-group">
        <?= $this->Form->button(__('Submit'), ['class' => 'btn btn-primary']) ?>
        <?= $this->Html->link(__('Cancel'), ['controller' => 'WorkflowDefinitions', 'action' => 'view', $workflowDefinitionId], ['class' => 'btn btn-secondary']) ?>
    </div>
    <?= $this->Form->end() ?>
</div>
