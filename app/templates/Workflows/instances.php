<?php

/**
 * Workflow Instances List
 *
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\WorkflowInstance> $instances
 * @var int|null $definitionId
 */

$this->extend("/layout/TwitterBootstrap/dashboard");

echo $this->KMP->startBlock("title");
echo $this->KMP->getAppSetting("KMP.ShortSiteTitle") . ': Workflow Instances';
$this->KMP->endBlock();

$this->assign('title', __('Workflow Instances'));

$statusBadge = function (string $status): string {
    $map = [
        'running' => 'primary',
        'completed' => 'success',
        'failed' => 'danger',
        'cancelled' => 'secondary',
        'waiting' => 'warning',
    ];
    $color = $map[$status] ?? 'light';
    return '<span class="badge bg-' . $color . '">' . h($status) . '</span>';
};
?>

<div class="workflows instances content">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3>
            <?= $this->element('backButton') ?>
            <?= __('Workflow Instances') ?>
            <?php if ($definitionId) : ?>
                <small class="text-muted"><?= __('(filtered)') ?></small>
            <?php endif; ?>
        </h3>
        <div>
            <?php if ($definitionId) : ?>
                <?= $this->Html->link(
                    __('Show All'),
                    ['action' => 'instances'],
                    ['class' => 'btn btn-outline-secondary btn-sm']
                ) ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-striped table-hover">
            <thead>
                <tr>
                    <th><?= __('ID') ?></th>
                    <th><?= __('Workflow') ?></th>
                    <th><?= __('Version') ?></th>
                    <th><?= __('Entity') ?></th>
                    <th><?= __('Status') ?></th>
                    <th><?= __('Started') ?></th>
                    <th><?= __('Completed') ?></th>
                    <th class="text-end"><?= __('Actions') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($instances as $instance) : ?>
                <tr>
                    <td><?= h($instance->id) ?></td>
                    <td><?= h($instance->workflow_definition->name ?? '—') ?></td>
                    <td>v<?= h($instance->workflow_version->version_number ?? '?') ?></td>
                    <td>
                        <?php if ($instance->entity_type) : ?>
                            <?= h($instance->entity_type) ?>#<?= h($instance->entity_id) ?>
                        <?php else : ?>
                            —
                        <?php endif; ?>
                    </td>
                    <td><?= $statusBadge($instance->status) ?></td>
                    <td><?= h($instance->created) ?></td>
                    <td><?= $instance->completed_at ? h($instance->completed_at) : '—' ?></td>
                    <td class="text-end">
                        <?= $this->Html->link(
                            '<i class="bi bi-eye"></i> ' . __('View'),
                            ['action' => 'viewInstance', $instance->id],
                            ['class' => 'btn btn-sm btn-outline-primary', 'escape' => false]
                        ) ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($instances) || (is_object($instances) && $instances->count() === 0)) : ?>
                <tr>
                    <td colspan="8" class="text-center text-muted py-4">
                        <?= __('No workflow instances found.') ?>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
