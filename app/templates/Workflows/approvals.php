<?php

/**
 * Workflow Approvals Dashboard
 *
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\WorkflowApproval[] $pendingApprovals
 * @var iterable<\App\Model\Entity\WorkflowApproval> $recentApprovals
 */

$this->extend("/layout/TwitterBootstrap/dashboard");

echo $this->KMP->startBlock("title");
echo $this->KMP->getAppSetting("KMP.ShortSiteTitle") . ': Approvals';
$this->KMP->endBlock();

$this->assign('title', __('My Approvals'));

?>

<div class="workflows approvals content">
    <h3><i class="bi bi-check2-square me-2"></i><?= __('My Approvals') ?></h3>

    <!-- Pending Approvals -->
    <h4 class="mt-4 mb-3"><?= __('Pending Approvals') ?>
        <?php if (!empty($pendingApprovals)) : ?>
            <span class="badge bg-danger"><?= count($pendingApprovals) ?></span>
        <?php endif; ?>
    </h4>

    <?php if (!empty($pendingApprovals)) : ?>
    <div class="row">
        <?php foreach ($pendingApprovals as $approval) : ?>
        <?php
            $workflowName = $approval->workflow_instance->workflow_definition->name ?? __('Workflow');
            $entityCtx = $approval->_entityContext ?? [];
            $triggerData = $approval->_triggerData ?? [];
            $entityName = $entityCtx['entityName'] ?? null;
            $startedBy = $entityCtx['startedBy'] ?? null;
            $entityType = $entityCtx['entityType'] ?? null;
        ?>
        <div class="col-md-6 col-lg-4 mb-3">
            <div class="card border-warning shadow-sm">
                <div class="card-header bg-warning bg-opacity-10 d-flex justify-content-between align-items-center">
                    <strong><i class="bi bi-diagram-3 me-1"></i><?= h($workflowName) ?></strong>
                    <?= $this->KMP->workflowStatusBadge('pending') ?>
                </div>
                <div class="card-body">
                    <dl class="row mb-2 small">
                        <?php if ($entityName) : ?>
                        <dt class="col-sm-4 text-muted"><?= __('Entity') ?></dt>
                        <dd class="col-sm-8"><?= h($entityName) ?></dd>
                        <?php endif; ?>
                        <?php if ($startedBy) : ?>
                        <dt class="col-sm-4 text-muted"><?= __('Requested by') ?></dt>
                        <dd class="col-sm-8"><?= h($startedBy) ?></dd>
                        <?php endif; ?>
                        <dt class="col-sm-4 text-muted"><?= __('Created') ?></dt>
                        <dd class="col-sm-8"><?= h(\App\KMP\TimezoneHelper::formatDateTime($approval->created)) ?></dd>
                        <?php if ($approval->deadline) : ?>
                        <dt class="col-sm-4 text-muted"><?= __('Deadline') ?></dt>
                        <dd class="col-sm-8"><?= h(\App\KMP\TimezoneHelper::formatDateTime($approval->deadline)) ?></dd>
                        <?php endif; ?>
                        <dt class="col-sm-4 text-muted"><?= __('Progress') ?></dt>
                        <dd class="col-sm-8"><?= h($approval->approved_count) ?> / <?= h($approval->required_count) ?> <?= __('approvals') ?></dd>
                    </dl>
                    <?= $this->Form->create(null, [
                        'url' => ['action' => 'recordApproval'],
                    ]) ?>
                    <?= $this->Form->hidden('approvalId', ['value' => $approval->id]) ?>
                    <div class="mb-2">
                        <?= $this->Form->control('comment', [
                            'type' => 'textarea',
                            'rows' => 2,
                            'label' => __('Comment (optional)'),
                            'required' => false,
                            'class' => 'form-control form-control-sm',
                        ]) ?>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" name="decision" value="approve" class="btn btn-success btn-sm flex-fill">
                            <i class="bi bi-check-lg me-1"></i><?= __('Approve') ?>
                        </button>
                        <button type="submit" name="decision" value="reject" class="btn btn-danger btn-sm flex-fill">
                            <i class="bi bi-x-lg me-1"></i><?= __('Reject') ?>
                        </button>
                    </div>
                    <?= $this->Form->end() ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php else : ?>
    <div class="alert alert-info">
        <i class="bi bi-info-circle me-1"></i><?= __('No pending approvals. You\'re all caught up!') ?>
    </div>
    <?php endif; ?>

    <!-- Recent Decisions -->
    <h4 class="mt-4 mb-3"><?= __('Recent Decisions') ?></h4>
    <div class="table-responsive">
        <table class="table table-striped table-hover">
            <thead>
                <tr>
                    <th><?= __('Workflow') ?></th>
                    <th><?= __('Status') ?></th>
                    <th><?= __('Approvals') ?></th>
                    <th><?= __('Resolved') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentApprovals as $approval) : ?>
                <tr>
                    <td><?= h($approval->workflow_instance->workflow_definition->name ?? '—') ?></td>
                    <td><?= $this->KMP->workflowStatusBadge($approval->status) ?></td>
                    <td><?= h($approval->approved_count) ?>/<?= h($approval->required_count) ?></td>
                    <td><?= h(\App\KMP\TimezoneHelper::formatDateTime($approval->modified)) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($recentApprovals) || (is_object($recentApprovals) && $recentApprovals->count() === 0)) : ?>
                <tr>
                    <td colspan="4" class="text-center text-muted py-3">
                        <?= __('No recent decisions.') ?>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
