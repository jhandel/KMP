<?php

/**
 * @var \App\View\AppView $this
 * @var \OfficerEventReporting\Model\Entity\Form[]|\Cake\Collection\CollectionInterface $forms
 */
?>
<?php $this->extend("/layout/TwitterBootstrap/dashboard");

echo $this->KMP->startBlock("title");
echo $this->KMP->getAppSetting("KMP.ShortSiteTitle") . ': Report Forms';
$this->KMP->endBlock(); ?>

<div class="row">
    <div class="col-md-12">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h3>Report Forms</h3>
            <?php if ($this->Identity->can('add', 'OfficerEventReporting.Forms')): ?>
                <?= $this->Html->link(
                    '<i class="fas fa-plus"></i> Create New Form',
                    ['action' => 'add'],
                    ['class' => 'btn btn-primary', 'escape' => false]
                ) ?>
            <?php endif; ?>
        </div>

        <?php if (empty($forms)): ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i>
                No report forms found. 
                <?php if ($this->Identity->can('add', 'OfficerEventReporting.Forms')): ?>
                    <?= $this->Html->link('Create the first one', ['action' => 'add']) ?>.
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th scope="col"><?= $this->Paginator->sort("title") ?></th>
                            <th scope="col"><?= $this->Paginator->sort("form_type", "Type") ?></th>
                            <th scope="col"><?= $this->Paginator->sort("assignment_type", "Assignment") ?></th>
                            <th scope="col"><?= $this->Paginator->sort("status") ?></th>
                            <th scope="col"><?= $this->Paginator->sort("created", "Created") ?></th>
                            <th scope="col" class="actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($forms as $form): ?>
                            <tr>
                                <td>
                                    <?= $this->Html->link(
                                        h($form->title),
                                        ['action' => 'view', $form->id]
                                    ) ?>
                                </td>
                                <td>
                                    <span class="badge badge-secondary">
                                        <?= h(ucwords(str_replace('-', ' ', $form->form_type))) ?>
                                    </span>
                                </td>
                                <td>
                                    <?php
                                    $assignmentClass = [
                                        'open' => 'success',
                                        'assigned' => 'info',
                                        'office-specific' => 'warning'
                                    ][$form->assignment_type] ?? 'secondary';
                                    ?>
                                    <span class="badge badge-<?= $assignmentClass ?>">
                                        <?= h(ucwords(str_replace('-', ' ', $form->assignment_type))) ?>
                                    </span>
                                </td>
                                <td>
                                    <?php
                                    $statusClass = [
                                        'active' => 'success',
                                        'inactive' => 'secondary',
                                        'archived' => 'dark'
                                    ][$form->status] ?? 'secondary';
                                    ?>
                                    <span class="badge badge-<?= $statusClass ?>">
                                        <?= h(ucfirst($form->status)) ?>
                                    </span>
                                </td>
                                <td>
                                    <?= h($form->created->format('M j, Y')) ?>
                                </td>
                                <td class="actions">
                                    <?= $this->Html->link(
                                        '<i class="fas fa-eye"></i>',
                                        ['action' => 'view', $form->id],
                                        ['escape' => false, 'class' => 'btn btn-sm btn-outline-primary', 'title' => 'View']
                                    ) ?>
                                    
                                    <?php if ($this->Identity->can('edit', $form)): ?>
                                        <?= $this->Html->link(
                                            '<i class="fas fa-edit"></i>',
                                            ['action' => 'edit', $form->id],
                                            ['escape' => false, 'class' => 'btn btn-sm btn-outline-secondary', 'title' => 'Edit']
                                        ) ?>
                                    <?php endif; ?>
                                    
                                    <?php if ($this->Identity->can('delete', $form)): ?>
                                        <?= $this->Form->postLink(
                                            '<i class="fas fa-trash"></i>',
                                            ['action' => 'delete', $form->id],
                                            [
                                                'escape' => false,
                                                'class' => 'btn btn-sm btn-outline-danger',
                                                'title' => 'Delete',
                                                'confirm' => __('Are you sure you want to delete "{0}"?', $form->title)
                                            ]
                                        ) ?>
                                    <?php endif; ?>
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
        <?php endif; ?>
    </div>
</div>