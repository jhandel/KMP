<?php

/**
 * WorkflowDefinitions index template
 *
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
        <div>
            <?= $this->Html->link(
                __(' Create New Workflow'),
                ['action' => 'add'],
                ['class' => 'btn btn-primary bi bi-plus-circle']
            ) ?>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-striped">
            <thead>
                <tr>
                    <th><?= __('Name') ?></th>
                    <th><?= __('Slug') ?></th>
                    <th><?= __('Entity Type') ?></th>
                    <th><?= __('Plugin') ?></th>
                    <th><?= __('Version') ?></th>
                    <th><?= __('Active') ?></th>
                    <th><?= __('Default') ?></th>
                    <th class="actions"><?= __('Actions') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($definitions as $definition) : ?>
                <tr>
                    <td><?= h($definition->name) ?></td>
                    <td><code><?= h($definition->slug) ?></code></td>
                    <td><?= h($definition->entity_type) ?></td>
                    <td><?= h($definition->plugin_name) ?></td>
                    <td><?= $this->Number->format($definition->version) ?></td>
                    <td>
                        <?php if ($definition->is_active) : ?>
                            <span class="badge bg-success"><?= __('Yes') ?></span>
                        <?php else : ?>
                            <span class="badge bg-secondary"><?= __('No') ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($definition->is_default) : ?>
                            <span class="badge bg-primary"><?= __('Yes') ?></span>
                        <?php else : ?>
                            <span class="badge bg-secondary"><?= __('No') ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="actions">
                        <?= $this->Html->link(
                            __('Edit'),
                            ['action' => 'editor', $definition->id],
                            ['class' => 'btn btn-sm btn-outline-primary']
                        ) ?>
                        <?= $this->Form->postLink(
                            __('Delete'),
                            ['action' => 'delete', $definition->id],
                            [
                                'confirm' => __('Are you sure you want to delete {0}?', $definition->name),
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
            <?= $this->Paginator->first('<< ' . __('first')) ?>
            <?= $this->Paginator->prev('< ' . __('previous')) ?>
            <?= $this->Paginator->numbers() ?>
            <?= $this->Paginator->next(__('next') . ' >') ?>
            <?= $this->Paginator->last(__('last') . ' >>') ?>
        </ul>
        <p><?= $this->Paginator->counter(__('Page {{page}} of {{pages}}, showing {{current}} record(s) out of {{count}} total')) ?></p>
    </div>
</div>
