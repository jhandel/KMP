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

$statusBadge = function (string $status): string {
    $map = [
        'pending' => 'warning',
        'approved' => 'success',
        'rejected' => 'danger',
        'cancelled' => 'secondary',
    ];
    $color = $map[$status] ?? 'light';
    return '<span class="badge bg-' . $color . '">' . h($status) . '</span>';
};
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
        <div class="col-md-6 col-lg-4 mb-3">
            <div class="card border-warning">
                <div class="card-header bg-warning bg-opacity-10 d-flex justify-content-between">
                    <strong><?= h($approval->workflow_instance->workflow_definition->name ?? __('Workflow')) ?></strong>
                    <?= $statusBadge('pending') ?>
                </div>
                <div class="card-body">
                    <p class="small text-muted mb-2">
                        <?= __('Node:') ?> <?= h($approval->node_id) ?><br>
                        <?= __('Created:') ?> <?= h($approval->created) ?>
                    </p>
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
                        ]) ?>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" name="decision" value="approved" class="btn btn-success btn-sm">
                            <i class="bi bi-check-lg me-1"></i><?= __('Approve') ?>
                        </button>
                        <button type="submit" name="decision" value="rejected" class="btn btn-danger btn-sm">
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
        <i class="bi bi-info-circle me-1"></i><?= __('No pending approvals.') ?>
    </div>
    <?php endif; ?>

    <!-- Recent Decisions -->
    <h4 class="mt-4 mb-3"><?= __('Recent Decisions') ?></h4>
    <div class="table-responsive">
        <table class="table table-striped table-hover">
            <thead>
                <tr>
                    <th><?= __('Workflow') ?></th>
                    <th><?= __('Node') ?></th>
                    <th><?= __('Status') ?></th>
                    <th><?= __('Resolved') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentApprovals as $approval) : ?>
                <tr>
                    <td><?= h($approval->workflow_instance->workflow_definition->name ?? '—') ?></td>
                    <td><?= h($approval->node_id) ?></td>
                    <td><?= $statusBadge($approval->status) ?></td>
                    <td><?= h($approval->modified) ?></td>
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
