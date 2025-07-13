<?php

/**
 * @var \App\View\AppView $this
 * @var \OfficerEventReporting\Model\Entity\Submission[]|\Cake\Collection\CollectionInterface $submissions
 * @var bool $canViewAll
 */
?>
<?php $this->extend("/layout/TwitterBootstrap/dashboard");

echo $this->KMP->startBlock("title");
echo $this->KMP->getAppSetting("KMP.ShortSiteTitle") . ': My Reports';
$this->KMP->endBlock(); ?>

<div class="row">
    <div class="col-md-12">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h3><?= $canViewAll ? 'All Report Submissions' : 'My Report Submissions' ?></h3>
            <?= $this->Html->link(
                '<i class="fas fa-file-alt"></i> Available Forms',
                ['controller' => 'Forms', 'action' => 'index'],
                ['class' => 'btn btn-primary', 'escape' => false]
            ) ?>
        </div>

        <?php if (empty($submissions)): ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i>
                No submissions found. 
                <?= $this->Html->link(
                    'Browse available forms',
                    ['controller' => 'Forms', 'action' => 'index']
                ) ?> to submit a report.
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th scope="col"><?= $this->Paginator->sort("Forms.title", "Form") ?></th>
                            <?php if ($canViewAll): ?>
                                <th scope="col"><?= $this->Paginator->sort("SubmittedBy.first_name", "Submitted By") ?></th>
                            <?php endif; ?>
                            <th scope="col"><?= $this->Paginator->sort("status") ?></th>
                            <th scope="col"><?= $this->Paginator->sort("created", "Submitted") ?></th>
                            <th scope="col"><?= $this->Paginator->sort("reviewed_at", "Reviewed") ?></th>
                            <th scope="col" class="actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($submissions as $submission): ?>
                            <tr>
                                <td>
                                    <?= $this->Html->link(
                                        h($submission->form->title),
                                        ['action' => 'view', $submission->id]
                                    ) ?>
                                </td>
                                <?php if ($canViewAll): ?>
                                    <td>
                                        <?= h($submission->submitted_by_member->first_name . ' ' . $submission->submitted_by_member->last_name) ?>
                                    </td>
                                <?php endif; ?>
                                <td>
                                    <?php
                                    $statusClass = [
                                        'submitted' => 'info',
                                        'reviewed' => 'secondary',
                                        'approved' => 'success',
                                        'rejected' => 'danger'
                                    ][$submission->status] ?? 'secondary';
                                    ?>
                                    <span class="badge badge-<?= $statusClass ?>">
                                        <?= h(ucfirst($submission->status)) ?>
                                    </span>
                                </td>
                                <td>
                                    <?= h($submission->created->format('M j, Y g:i A')) ?>
                                </td>
                                <td>
                                    <?php if ($submission->reviewed_at): ?>
                                        <?= h($submission->reviewed_at->format('M j, Y g:i A')) ?>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="actions">
                                    <?= $this->Html->link(
                                        '<i class="fas fa-eye"></i>',
                                        ['action' => 'view', $submission->id],
                                        ['escape' => false, 'class' => 'btn btn-sm btn-outline-primary', 'title' => 'View']
                                    ) ?>
                                    
                                    <?php if ($canViewAll && $submission->canBeReviewed()): ?>
                                        <?= $this->Html->link(
                                            '<i class="fas fa-check"></i>',
                                            ['action' => 'review', $submission->id],
                                            ['escape' => false, 'class' => 'btn btn-sm btn-outline-success', 'title' => 'Review']
                                        ) ?>
                                    <?php endif; ?>
                                    
                                    <?php if ($this->Identity->can('edit', $submission)): ?>
                                        <?= $this->Html->link(
                                            '<i class="fas fa-edit"></i>',
                                            ['action' => 'edit', $submission->id],
                                            ['escape' => false, 'class' => 'btn btn-sm btn-outline-secondary', 'title' => 'Edit']
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