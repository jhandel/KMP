<?php

/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\WorkflowDefinition $definition
 */
?>
<?php
$this->extend("/layout/TwitterBootstrap/view_record");

echo $this->KMP->startBlock("title");
echo $this->KMP->getAppSetting("KMP.ShortSiteTitle") . ': View Workflow Definition - ' . $definition->name;
$this->KMP->endBlock();

echo $this->KMP->startBlock("pageTitle") ?>
<?= h($definition->name) ?>
<?php $this->KMP->endBlock() ?>

<?= $this->KMP->startBlock("recordActions") ?>
<?php if ($user->checkCan("view", $definition)) : ?>
    <?= $this->Html->link(__('Analytics'), ['action' => 'analytics', $definition->id], ['class' => 'btn btn-secondary btn-sm']) ?>
<?php endif; ?>
<?php if ($user->checkCan("edit", $definition)) : ?>
    <?= $this->Html->link(__('Edit'), ['action' => 'edit', $definition->id], ['class' => 'btn btn-primary btn-sm']) ?>
    <?= $this->Form->postLink(
        __('Duplicate'),
        ['action' => 'duplicate', $definition->id],
        [
            'confirm' => __('Create a copy of "{0}"?', $definition->name),
            'class' => 'btn btn-info btn-sm',
        ]
    ) ?>
<?php endif; ?>
<?php if ($user->checkCan("delete", $definition)) : ?>
    <?= $this->Form->postLink(
        __('Delete'),
        ['action' => 'delete', $definition->id],
        [
            'confirm' => __('Are you sure you want to delete "{0}"?', $definition->name),
            'class' => 'btn btn-danger btn-sm',
        ]
    ) ?>
<?php endif; ?>
<?php $this->KMP->endBlock() ?>

<?php $this->KMP->startBlock("recordDetails") ?>
<tr scope="row">
    <th class="col"><?= __('Name') ?></th>
    <td class="col-10"><?= h($definition->name) ?></td>
</tr>
<tr scope="row">
    <th class="col"><?= __('Slug') ?></th>
    <td class="col-10"><code><?= h($definition->slug) ?></code></td>
</tr>
<tr scope="row">
    <th class="col"><?= __('Description') ?></th>
    <td class="col-10"><?= h($definition->description) ?></td>
</tr>
<tr scope="row">
    <th class="col"><?= __('Entity Type') ?></th>
    <td class="col-10"><?= h($definition->entity_type) ?></td>
</tr>
<tr scope="row">
    <th class="col"><?= __('Plugin') ?></th>
    <td class="col-10"><?= h($definition->plugin_name) ?></td>
</tr>
<tr scope="row">
    <th class="col"><?= __('Version') ?></th>
    <td class="col-10"><?= h($definition->version) ?></td>
</tr>
<tr scope="row">
    <th class="col"><?= __('Active') ?></th>
    <td class="col-10"><?= $this->KMP->bool($definition->is_active, $this->Html) ?></td>
</tr>
<tr scope="row">
    <th class="col"><?= __('Default') ?></th>
    <td class="col-10"><?= $this->KMP->bool($definition->is_default, $this->Html) ?></td>
</tr>
<tr scope="row">
    <th class="col"><?= __('Created') ?></th>
    <td class="col-10"><?= $this->Timezone->format($definition->created, null, null, \IntlDateFormatter::SHORT, \IntlDateFormatter::SHORT) ?></td>
</tr>
<tr scope="row">
    <th class="col"><?= __('Modified') ?></th>
    <td class="col-10"><?= $definition->modified ? $this->Timezone->format($definition->modified, null, null, \IntlDateFormatter::SHORT, \IntlDateFormatter::SHORT) : '' ?></td>
</tr>
<?php $this->KMP->endBlock() ?>

<?php $this->KMP->startBlock("tabButtons") ?>
<button class="nav-link active" id="nav-states-tab" data-bs-toggle="tab" data-bs-target="#nav-states" type="button"
    role="tab" aria-controls="nav-states" aria-selected="true"
    data-detail-tabs-target='tabBtn'
    data-tab-order="10"
    style="order: 10;"><?= __("States") ?>
</button>
<button class="nav-link" id="nav-transitions-tab" data-bs-toggle="tab" data-bs-target="#nav-transitions" type="button"
    role="tab" aria-controls="nav-transitions" aria-selected="false"
    data-detail-tabs-target='tabBtn'
    data-tab-order="20"
    style="order: 20;"><?= __("Transitions") ?>
</button>
<?php $this->KMP->endBlock() ?>

<?php $this->KMP->startBlock("tabContent") ?>
<div class="related tab-pane fade show active m-3" id="nav-states" role="tabpanel" aria-labelledby="nav-states-tab"
    data-detail-tabs-target="tabContent"
    data-tab-order="10"
    style="order: 10;">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0"><?= __('States') ?></h4>
        <?php if ($user->checkCan("edit", $definition)) : ?>
            <?= $this->Html->link(__('Add State'), ['controller' => 'WorkflowStates', 'action' => 'add', $definition->id], ['class' => 'btn btn-primary btn-sm']) ?>
        <?php endif; ?>
    </div>
    <?php if (!empty($definition->workflow_states)) : ?>
        <div class="table-responsive">
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th><?= __('Name') ?></th>
                        <th><?= __('Slug') ?></th>
                        <th><?= __('Label') ?></th>
                        <th><?= __('Type') ?></th>
                        <th><?= __('Status Category') ?></th>
                        <?php if ($user->checkCan("edit", $definition)) : ?>
                            <th class="actions"><?= __('Actions') ?></th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($definition->workflow_states as $state) : ?>
                        <tr>
                            <td><?= h($state->name) ?></td>
                            <td><code><?= h($state->slug) ?></code></td>
                            <td><?= h($state->label) ?></td>
                            <td>
                                <?php
                                $badgeClass = match ($state->state_type) {
                                    'initial' => 'bg-success',
                                    'final' => 'bg-danger',
                                    default => 'bg-secondary',
                                };
                                ?>
                                <span class="badge <?= $badgeClass ?>"><?= h($state->state_type) ?></span>
                            </td>
                            <td><?= h($state->status_category) ?></td>
                            <?php if ($user->checkCan("edit", $definition)) : ?>
                                <td class="actions">
                                    <?= $this->Html->link(__('Edit'), ['controller' => 'WorkflowStates', 'action' => 'edit', $state->id], ['class' => 'btn btn-secondary btn-sm']) ?>
                                    <?= $this->Form->postLink(
                                        __('Delete'),
                                        ['controller' => 'WorkflowStates', 'action' => 'delete', $state->id],
                                        [
                                            'confirm' => __('Are you sure you want to delete state "{0}"?', $state->name),
                                            'class' => 'btn btn-danger btn-sm',
                                        ]
                                    ) ?>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else : ?>
        <p class="text-muted"><?= __('No states defined for this workflow.') ?></p>
    <?php endif; ?>
</div>

<div class="related tab-pane fade m-3" id="nav-transitions" role="tabpanel" aria-labelledby="nav-transitions-tab"
    data-detail-tabs-target="tabContent"
    data-tab-order="20"
    style="order: 20;">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0"><?= __('Transitions') ?></h4>
        <?php if ($user->checkCan("edit", $definition)) : ?>
            <?= $this->Html->link(__('Add Transition'), ['controller' => 'WorkflowTransitions', 'action' => 'add', $definition->id], ['class' => 'btn btn-primary btn-sm']) ?>
        <?php endif; ?>
    </div>
    <?php if (!empty($definition->workflow_transitions)) : ?>
        <div class="table-responsive">
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th><?= __('Name') ?></th>
                        <th><?= __('Label') ?></th>
                        <th><?= __('From State') ?></th>
                        <th><?= __('To State') ?></th>
                        <th><?= __('Trigger Type') ?></th>
                        <th><?= __('Automatic') ?></th>
                        <th><?= __('Priority') ?></th>
                        <?php if ($user->checkCan("edit", $definition)) : ?>
                            <th class="actions"><?= __('Actions') ?></th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($definition->workflow_transitions as $transition) : ?>
                        <tr>
                            <td><?= h($transition->name) ?></td>
                            <td><?= h($transition->label) ?></td>
                            <td><?= $transition->hasValue('from_state') ? h($transition->from_state->name) : '' ?></td>
                            <td><?= $transition->hasValue('to_state') ? h($transition->to_state->name) : '' ?></td>
                            <td><?= h($transition->trigger_type) ?></td>
                            <td><?= $this->KMP->bool($transition->is_automatic, $this->Html) ?></td>
                            <td><?= h($transition->priority) ?></td>
                            <?php if ($user->checkCan("edit", $definition)) : ?>
                                <td class="actions">
                                    <?= $this->Html->link(__('Edit'), ['controller' => 'WorkflowTransitions', 'action' => 'edit', $transition->id], ['class' => 'btn btn-secondary btn-sm']) ?>
                                    <?= $this->Form->postLink(
                                        __('Delete'),
                                        ['controller' => 'WorkflowTransitions', 'action' => 'delete', $transition->id],
                                        [
                                            'confirm' => __('Are you sure you want to delete transition "{0}"?', $transition->name),
                                            'class' => 'btn btn-danger btn-sm',
                                        ]
                                    ) ?>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else : ?>
        <p class="text-muted"><?= __('No transitions defined for this workflow.') ?></p>
    <?php endif; ?>
</div>
<?php $this->KMP->endBlock() ?>
