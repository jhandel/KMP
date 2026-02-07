<?php

/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\WorkflowState $state
 * @var string $workflowDefinitionId
 * @var array $stateTypes
 */
?>
<?php
$this->extend("/layout/TwitterBootstrap/dashboard");

echo $this->KMP->startBlock("title");
echo $this->KMP->getAppSetting("KMP.ShortSiteTitle") . ': Edit Workflow State';
$this->KMP->endBlock();
?>

<div class="workflowStates form content">
    <?= $this->Form->create($state) ?>
    <fieldset>
        <legend><?= $this->element('backButton') ?> <?= __('Edit Workflow State') ?></legend>
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
                'help' => __('URL-safe identifier (e.g., pending-review).'),
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
            <?= $this->Form->control('description', [
                'type' => 'textarea',
                'rows' => 3,
                'class' => 'form-control',
            ]) ?>
        </div>
        <div class="mb-3">
            <?= $this->Form->control('state_type', [
                'type' => 'select',
                'options' => $stateTypes,
                'required' => true,
                'class' => 'form-select',
                'help' => __('Initial = starting state, Final = end state, Approval = requires approval.'),
            ]) ?>
        </div>
        <div class="mb-3">
            <?= $this->Form->control('status_category', [
                'class' => 'form-control',
                'help' => __('Optional grouping category (e.g., active, closed).'),
            ]) ?>
        </div>
        <div class="mb-3">
            <?= $this->Form->control('metadata', [
                'type' => 'textarea',
                'rows' => 3,
                'class' => 'form-control',
                'help' => __('Optional JSON metadata for this state.'),
            ]) ?>
        </div>
        <div class="mb-3">
            <?= $this->Form->control('on_enter_actions', [
                'type' => 'textarea',
                'rows' => 3,
                'class' => 'form-control',
                'label' => __('On Enter Actions'),
                'help' => __('Optional JSON array of actions to run when entering this state.'),
            ]) ?>
        </div>
        <div class="mb-3">
            <?= $this->Form->control('on_exit_actions', [
                'type' => 'textarea',
                'rows' => 3,
                'class' => 'form-control',
                'label' => __('On Exit Actions'),
                'help' => __('Optional JSON array of actions to run when leaving this state.'),
            ]) ?>
        </div>
    </fieldset>

    <div class="form-group">
        <?= $this->Form->button(__('Submit'), ['class' => 'btn btn-primary']) ?>
        <?= $this->Form->postLink(
            __('Delete'),
            ['action' => 'delete', $state->id],
            [
                'confirm' => __('Are you sure you want to delete "{0}"?', $state->name),
                'class' => 'btn btn-danger',
            ]
        ) ?>
        <?= $this->Html->link(__('Cancel'), ['controller' => 'WorkflowDefinitions', 'action' => 'view', $workflowDefinitionId], ['class' => 'btn btn-secondary']) ?>
    </div>
    <?= $this->Form->end() ?>
</div>
