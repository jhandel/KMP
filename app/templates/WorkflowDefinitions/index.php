<?php

/**
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\WorkflowDefinition> $definitions
 */

$this->extend("/layout/TwitterBootstrap/dashboard");

echo $this->KMP->startBlock("title");
echo $this->KMP->getAppSetting("KMP.ShortSiteTitle") . ': Workflow Definitions';
$this->KMP->endBlock();

$this->assign('title', __('Workflow Definitions'));
?>

<div class="workflowDefinitions index content">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3><?= __('Workflow Definitions') ?></h3>
        <div class="d-flex gap-2">
            <?php if ($user->checkCan("add", "WorkflowDefinitions")) : ?>
                <?php if (!empty($templates)) : ?>
                    <div class="dropdown">
                        <button class="btn btn-success dropdown-toggle bi bi-file-earmark-code" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <?= __(' Create from Template') ?>
                        </button>
                        <ul class="dropdown-menu">
                            <?php foreach ($templates as $template) : ?>
                                <li>
                                    <?= $this->Form->postLink(
                                        h($template['name']),
                                        ['action' => 'createFromTemplate', $template['file']],
                                        ['class' => 'dropdown-item']
                                    ) ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                <?= $this->Html->link(
                    __(' Add Workflow Definition'),
                    ['action' => 'add'],
                    ['class' => 'btn btn-primary bi bi-plus-circle']
                ) ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-striped">
            <thead>
                <tr>
                    <th><?= __('Name') ?></th>
                    <th><?= __('Entity Type') ?></th>
                    <th><?= __('Plugin') ?></th>
                    <th><?= __('Version') ?></th>
                    <th><?= __('Active') ?></th>
                    <th><?= __('Default') ?></th>
                    <th><?= __('# States') ?></th>
                    <th class="actions"><?= __('Actions') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($definitions as $definition) : ?>
                    <tr>
                        <td><?= h($definition->name) ?></td>
                        <td><?= h($definition->entity_type) ?></td>
                        <td><?= h($definition->plugin_name) ?></td>
                        <td><?= h($definition->version) ?></td>
                        <td><?= $this->KMP->bool($definition->is_active, $this->Html) ?></td>
                        <td><?= $this->KMP->bool($definition->is_default, $this->Html) ?></td>
                        <td><?= count($definition->workflow_states ?? []) ?></td>
                        <td class="actions">
                            <?= $this->Html->link(
                                '<i class="bi bi-binoculars-fill"></i>',
                                ['action' => 'view', $definition->id],
                                ['escape' => false, 'title' => __('View'), 'class' => 'btn btn-sm btn-outline-primary']
                            ) ?>
                            <?= $this->Html->link(
                                '<i class="bi bi-pencil-fill"></i>',
                                ['action' => 'edit', $definition->id],
                                ['escape' => false, 'title' => __('Edit'), 'class' => 'btn btn-sm btn-outline-secondary']
                            ) ?>
                            <?= $this->Form->postLink(
                                '<i class="bi bi-trash-fill"></i>',
                                ['action' => 'delete', $definition->id],
                                [
                                    'confirm' => __('Are you sure you want to delete "{0}"?', $definition->name),
                                    'escape' => false,
                                    'title' => __('Delete'),
                                    'class' => 'btn btn-sm btn-outline-danger',
                                ]
                            ) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="paginator">
        <ul class="pagination">
            <?= $this->Paginator->first('<< ' . __('First')) ?>
            <?= $this->Paginator->prev('< ' . __('Previous')) ?>
            <?= $this->Paginator->numbers() ?>
            <?= $this->Paginator->next(__('Next') . ' >') ?>
            <?= $this->Paginator->last(__('Last') . ' >>') ?>
        </ul>
        <p><?= $this->Paginator->counter(__('Page {{page}} of {{pages}}, showing {{current}} record(s) out of {{count}} total')) ?></p>
    </div>
</div>
