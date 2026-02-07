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

// Category color map (same as visualizer)
$catStyles = [
    'In Progress' => ['bg' => '#fff3cd', 'bd' => '#ffc107', 'tx' => '#664d03'],
    'Pending'     => ['bg' => '#fff3cd', 'bd' => '#ffc107', 'tx' => '#664d03'],
    'Draft'       => ['bg' => '#fff3cd', 'bd' => '#e5a100', 'tx' => '#664d03'],
    'Review'      => ['bg' => '#cfe2ff', 'bd' => '#0d6efd', 'tx' => '#052c65'],
    'In Review'   => ['bg' => '#cfe2ff', 'bd' => '#0d6efd', 'tx' => '#052c65'],
    'Approval'    => ['bg' => '#d0e4ff', 'bd' => '#3b82f6', 'tx' => '#1e3a5f'],
    'Scheduling'  => ['bg' => '#e0cffc', 'bd' => '#6f42c1', 'tx' => '#3d1f7c'],
    'To Give'     => ['bg' => '#cff4fc', 'bd' => '#0dcaf0', 'tx' => '#055160'],
    'Active'      => ['bg' => '#d1e7dd', 'bd' => '#198754', 'tx' => '#0a3622'],
    'Completed'   => ['bg' => '#d1e7dd', 'bd' => '#20c997', 'tx' => '#0a3622'],
    'Closed'      => ['bg' => '#e2e3e5', 'bd' => '#6c757d', 'tx' => '#41464b'],
    'Rejected'    => ['bg' => '#f8d7da', 'bd' => '#dc3545', 'tx' => '#58151c'],
    'Cancelled'   => ['bg' => '#e2e3e5', 'bd' => '#adb5bd', 'tx' => '#495057'],
];
$defaultCat = ['bg' => '#f8f9fa', 'bd' => '#adb5bd', 'tx' => '#495057'];
?>

<div class="workflowDefinitions index content">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="mb-1"><?= __('Workflow Definitions') ?></h3>
            <p class="text-muted mb-0 small"><?= __('Manage state machines that control how items move through your processes.') ?></p>
        </div>
        <div class="d-flex gap-2">
            <?php if ($user->checkCan("add", "WorkflowDefinitions")) : ?>
                <?php if (!empty($templates)) : ?>
                    <div class="dropdown">
                        <button class="btn btn-outline-success dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-file-earmark-code"></i> <?= __('From Template') ?>
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
                    '<i class="bi bi-plus-circle"></i> ' . __('New Workflow'),
                    ['action' => 'add'],
                    ['class' => 'btn btn-primary', 'escape' => false]
                ) ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Card Grid -->
    <div class="row g-4">
        <?php foreach ($definitions as $definition) :
            // Build pipeline from states
            $pipeline = [];
            $catOrder = ['In Progress', 'Pending', 'Draft', 'Review', 'In Review', 'Approval', 'Scheduling', 'To Give', 'Active', 'Completed', 'Closed', 'Rejected', 'Cancelled'];
            foreach ($definition->workflow_states ?? [] as $state) {
                $cat = $state->status_category ?: 'Other';
                if (!isset($pipeline[$cat])) $pipeline[$cat] = 0;
                $pipeline[$cat]++;
            }
            // Sort by category order
            $orderedPipeline = [];
            foreach ($catOrder as $c) {
                if (isset($pipeline[$c])) $orderedPipeline[$c] = $pipeline[$c];
            }
            foreach ($pipeline as $c => $n) {
                if (!isset($orderedPipeline[$c])) $orderedPipeline[$c] = $n;
            }
            $stateCount = count($definition->workflow_states ?? []);
        ?>
        <div class="col-md-6 col-xl-4">
            <div class="card wf-card h-100">
                <div class="card-body d-flex flex-column">
                    <!-- Title + Status -->
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <div>
                            <h5 class="card-title mb-1">
                                <?= $this->Html->link(
                                    h($definition->name),
                                    ['action' => 'view', $definition->id],
                                    ['class' => 'text-decoration-none text-dark stretched-link']
                                ) ?>
                            </h5>
                            <?php if ($definition->description) : ?>
                                <p class="card-text text-muted small mb-0" style="display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden;">
                                    <?= h($definition->description) ?>
                                </p>
                            <?php endif; ?>
                        </div>
                        <div class="d-flex flex-column align-items-end gap-1 flex-shrink-0 ms-2" style="position:relative; z-index:2;">
                            <span class="badge <?= $definition->is_active ? 'bg-success' : 'bg-warning text-dark' ?>">
                                <?= $definition->is_active ? __('Active') : __('Draft') ?>
                            </span>
                            <?php if ($definition->is_default) : ?>
                                <span class="badge bg-primary"><?= __('Default') ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Pipeline visualization -->
                    <?php if (!empty($orderedPipeline)) : ?>
                    <div class="wf-pipeline my-2">
                        <?php $i = 0; foreach ($orderedPipeline as $cat => $count) :
                            $cs = $catStyles[$cat] ?? $defaultCat;
                            if ($i > 0) : ?>
                                <span class="wf-arrow"><i class="bi bi-chevron-right"></i></span>
                            <?php endif; ?>
                            <span class="wf-stage" style="background:<?= $cs['bg'] ?>; color:<?= $cs['tx'] ?>; border:1px solid <?= $cs['bd'] ?>;">
                                <?= h($cat) ?>
                                <span class="badge rounded-pill" style="background:<?= $cs['bd'] ?>; color:#fff; font-size:.65rem;"><?= $count ?></span>
                            </span>
                        <?php $i++; endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <!-- Meta row -->
                    <div class="mt-auto pt-2 d-flex justify-content-between align-items-center text-muted small">
                        <div class="d-flex gap-3">
                            <span><i class="bi bi-circle-fill" style="font-size:.5rem; vertical-align:middle;"></i> <?= $stateCount ?> <?= __('states') ?></span>
                            <?php if ($definition->plugin_name) : ?>
                                <span class="badge bg-light text-dark border"><?= h($definition->plugin_name) ?></span>
                            <?php endif; ?>
                        </div>
                        <span>v<?= h($definition->version) ?></span>
                    </div>
                </div>

                <!-- Quick actions -->
                <?php if ($user->checkCan("add", "WorkflowDefinitions")) : ?>
                <div class="card-footer bg-transparent border-top-0 pt-0" style="position:relative; z-index:2;">
                    <div class="d-flex gap-1 justify-content-end">
                        <?= $this->Html->link(
                            '<i class="bi bi-binoculars"></i>',
                            ['action' => 'view', $definition->id],
                            ['escape' => false, 'title' => __('View'), 'class' => 'btn btn-sm btn-outline-primary']
                        ) ?>
                        <?= $this->Html->link(
                            '<i class="bi bi-pencil"></i>',
                            ['action' => 'edit', $definition->id],
                            ['escape' => false, 'title' => __('Edit'), 'class' => 'btn btn-sm btn-outline-secondary']
                        ) ?>
                        <?= $this->Form->postLink(
                            '<i class="bi bi-trash"></i>',
                            ['action' => 'delete', $definition->id],
                            [
                                'confirm' => __('Delete "{0}"?', $definition->name),
                                'escape' => false,
                                'title' => __('Delete'),
                                'class' => 'btn btn-sm btn-outline-danger',
                            ]
                        ) ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php if (empty($definitions->toArray())) : ?>
        <div class="text-center py-5 text-muted">
            <i class="bi bi-diagram-3 fs-1 d-block mb-2"></i>
            <p><?= __('No workflow definitions yet.') ?></p>
            <?php if ($user->checkCan("add", "WorkflowDefinitions")) : ?>
                <p><?= __('Create one from scratch or use a template to get started.') ?></p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="paginator mt-4">
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
