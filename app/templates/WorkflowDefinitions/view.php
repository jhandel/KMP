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
    <th class="col"><?= __('Slug') ?></th>
    <td class="col-10"><code><?= h($definition->slug) ?></code></td>
</tr>
<tr scope="row">
    <th class="col"><?= __('Description') ?></th>
    <td class="col-10"><?= h($definition->description) ?></td>
</tr>
<tr scope="row">
    <th class="col"><?= __('Entity / Plugin') ?></th>
    <td class="col-10">
        <?= h($definition->entity_type) ?>
        <?php if ($definition->plugin_name) : ?>
            <span class="badge bg-secondary ms-1"><?= h($definition->plugin_name) ?></span>
        <?php endif; ?>
    </td>
</tr>
<tr scope="row">
    <th class="col"><?= __('Status') ?></th>
    <td class="col-10">
        <span class="badge <?= $definition->is_active ? 'bg-success' : 'bg-warning text-dark' ?>">
            <?= $definition->is_active ? __('Active') : __('Inactive') ?>
        </span>
        <?php if ($definition->is_default) : ?>
            <span class="badge bg-primary ms-1"><?= __('Default') ?></span>
        <?php endif; ?>
        <span class="text-muted ms-2">v<?= h($definition->version) ?></span>
    </td>
</tr>
<?php $this->KMP->endBlock() ?>

<?php
// Serialize state/transition data for the visualizer
$statesData = array_values(array_map(function ($s) {
    return [
        'id' => $s->id,
        'name' => $s->name,
        'slug' => $s->slug,
        'label' => $s->label,
        'state_type' => $s->state_type,
        'status_category' => $s->status_category,
    ];
}, $definition->workflow_states ?? []));

$transitionsData = array_values(array_map(function ($t) {
    return [
        'id' => $t->id,
        'name' => $t->name,
        'label' => $t->label,
        'from_state_id' => $t->from_state_id,
        'to_state_id' => $t->to_state_id,
        'is_automatic' => (bool)$t->is_automatic,
        'trigger_type' => $t->trigger_type ?? 'manual',
    ];
}, $definition->workflow_transitions ?? []));

// Collect unique status categories for the legend
$legendCategories = [];
foreach ($definition->workflow_states ?? [] as $st) {
    $cat = $st->status_category;
    if ($cat && !isset($legendCategories[$cat])) {
        $legendCategories[$cat] = true;
    }
}
$catStyles = [
    'In Progress' => ['bg' => '#fff3cd', 'bd' => '#ffc107'],
    'Pending'     => ['bg' => '#fff3cd', 'bd' => '#ffc107'],
    'Draft'       => ['bg' => '#fff3cd', 'bd' => '#e5a100'],
    'Review'      => ['bg' => '#cfe2ff', 'bd' => '#0d6efd'],
    'In Review'   => ['bg' => '#cfe2ff', 'bd' => '#0d6efd'],
    'Approval'    => ['bg' => '#d0e4ff', 'bd' => '#3b82f6'],
    'Scheduling'  => ['bg' => '#e0cffc', 'bd' => '#6f42c1'],
    'To Give'     => ['bg' => '#cff4fc', 'bd' => '#0dcaf0'],
    'Active'      => ['bg' => '#d1e7dd', 'bd' => '#198754'],
    'Completed'   => ['bg' => '#d1e7dd', 'bd' => '#20c997'],
    'Closed'      => ['bg' => '#e2e3e5', 'bd' => '#6c757d'],
    'Rejected'    => ['bg' => '#f8d7da', 'bd' => '#dc3545'],
    'Cancelled'   => ['bg' => '#e2e3e5', 'bd' => '#adb5bd'],
];
?>

<?php $this->KMP->startBlock("tabButtons") ?>
<button class="nav-link active" id="nav-flow-tab" data-bs-toggle="tab" data-bs-target="#nav-flow" type="button"
    role="tab" aria-controls="nav-flow" aria-selected="true"
    data-detail-tabs-target='tabBtn'
    data-tab-order="5"
    style="order: 5;"><i class="bi bi-diagram-3"></i> <?= __("Flow") ?>
</button>
<button class="nav-link" id="nav-states-tab" data-bs-toggle="tab" data-bs-target="#nav-states" type="button"
    role="tab" aria-controls="nav-states" aria-selected="false"
    data-detail-tabs-target='tabBtn'
    data-tab-order="10"
    style="order: 10;"><?= __("States") ?>
    <span class="badge bg-secondary ms-1"><?= count($definition->workflow_states ?? []) ?></span>
</button>
<button class="nav-link" id="nav-transitions-tab" data-bs-toggle="tab" data-bs-target="#nav-transitions" type="button"
    role="tab" aria-controls="nav-transitions" aria-selected="false"
    data-detail-tabs-target='tabBtn'
    data-tab-order="20"
    style="order: 20;"><?= __("Transitions") ?>
    <span class="badge bg-secondary ms-1"><?= count($definition->workflow_transitions ?? []) ?></span>
</button>
<?php $this->KMP->endBlock() ?>

<?php $this->KMP->startBlock("tabContent") ?>

<!-- ═══ Flow Diagram Tab ═══ -->
<div class="related tab-pane fade show active m-3" id="nav-flow" role="tabpanel" aria-labelledby="nav-flow-tab"
    data-detail-tabs-target="tabContent"
    data-tab-order="5"
    style="order: 5;">

    <div data-controller="workflow-visualizer"
         data-workflow-visualizer-states-value='<?= h(json_encode($statesData)) ?>'
         data-workflow-visualizer-transitions-value='<?= h(json_encode($transitionsData)) ?>'
         data-workflow-visualizer-mode-value="flow">

        <!-- Mode toggle -->
        <div class="d-flex justify-content-end mb-2">
            <div class="btn-group btn-group-sm wf-mode-toggle" role="group" aria-label="<?= __('View mode') ?>">
                <button type="button" class="btn btn-outline-secondary active"
                        data-action="click->workflow-visualizer#setFlowMode"
                        data-workflow-visualizer-target="modeBtn"
                        data-mode="flow">
                    <i class="bi bi-layout-text-window-reverse"></i> <?= __('Flow') ?>
                </button>
                <button type="button" class="btn btn-outline-secondary"
                        data-action="click->workflow-visualizer#setDiagramMode"
                        data-workflow-visualizer-target="modeBtn"
                        data-mode="diagram">
                    <i class="bi bi-diagram-3"></i> <?= __('Diagram') ?>
                </button>
            </div>
        </div>

        <div data-workflow-visualizer-target="canvas"></div>

        <?php if (!empty($legendCategories)) : ?>
        <div class="wf-legend mt-2">
            <span class="wf-legend-item">
                <svg width="14" height="14"><circle cx="7" cy="7" r="5" fill="#198754" opacity=".85"/><polygon points="5.5,4.5 9.5,7 5.5,9.5" fill="#fff"/></svg>
                <?= __('Start') ?>
            </span>
            <span class="wf-legend-item">
                <svg width="14" height="14"><circle cx="7" cy="7" r="6" fill="none" stroke="#6c757d" stroke-width="1.5"/><circle cx="7" cy="7" r="3" fill="#6c757d"/></svg>
                <?= __('End') ?>
            </span>
            <span class="text-muted mx-1">|</span>
            <?php foreach ($legendCategories as $cat => $_) :
                $cs = $catStyles[$cat] ?? ['bg' => '#f8f9fa', 'bd' => '#adb5bd'];
            ?>
            <span class="wf-legend-item">
                <span class="wf-legend-swatch" style="background:<?= $cs['bg'] ?>; border-color:<?= $cs['bd'] ?>;"></span>
                <?= h($cat) ?>
            </span>
            <?php endforeach; ?>
            <span class="text-muted mx-1">|</span>
            <span class="wf-legend-item">
                <svg width="28" height="10"><line x1="0" y1="5" x2="28" y2="5" stroke="#9ca3af" stroke-width="1.5"/></svg>
                <?= __('Manual') ?>
            </span>
            <span class="wf-legend-item">
                <svg width="28" height="10"><line x1="0" y1="5" x2="28" y2="5" stroke="#9ca3af" stroke-width="1.5" stroke-dasharray="5,3"/></svg>
                <?= __('Automatic') ?>
            </span>
        </div>
        <?php endif; ?>
    </div>

    <!-- Summary cards -->
    <div class="row mt-3 g-3">
        <div class="col-sm-4">
            <div class="card text-center border-0 bg-light">
                <div class="card-body py-2">
                    <div class="fs-4 fw-bold text-primary"><?= count($definition->workflow_states ?? []) ?></div>
                    <small class="text-muted"><?= __('States') ?></small>
                </div>
            </div>
        </div>
        <div class="col-sm-4">
            <div class="card text-center border-0 bg-light">
                <div class="card-body py-2">
                    <div class="fs-4 fw-bold text-primary"><?= count($definition->workflow_transitions ?? []) ?></div>
                    <small class="text-muted"><?= __('Transitions') ?></small>
                </div>
            </div>
        </div>
        <div class="col-sm-4">
            <div class="card text-center border-0 bg-light">
                <div class="card-body py-2">
                    <div class="fs-4 fw-bold text-primary"><?= count($legendCategories) ?></div>
                    <small class="text-muted"><?= __('Phases') ?></small>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ═══ States Tab ═══ -->
<div class="related tab-pane fade m-3" id="nav-states" role="tabpanel" aria-labelledby="nav-states-tab"
    data-detail-tabs-target="tabContent"
    data-tab-order="10"
    style="order: 10;">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0"><?= __('States') ?></h4>
        <?php if ($user->checkCan("edit", $definition)) : ?>
            <?= $this->Html->link(
                '<i class="bi bi-plus-circle"></i> ' . __('Add State'),
                ['controller' => 'WorkflowStates', 'action' => 'add', $definition->id],
                ['class' => 'btn btn-primary btn-sm', 'escape' => false]
            ) ?>
        <?php endif; ?>
    </div>
    <?php if (!empty($definition->workflow_states)) : ?>
        <div class="table-responsive">
            <table class="table table-striped align-middle">
                <thead>
                    <tr>
                        <th><?= __('Name') ?></th>
                        <th><?= __('Label') ?></th>
                        <th><?= __('Type') ?></th>
                        <th><?= __('Category') ?></th>
                        <?php if ($user->checkCan("edit", $definition)) : ?>
                            <th class="actions"><?= __('Actions') ?></th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($definition->workflow_states as $state) : ?>
                        <tr>
                            <td>
                                <code class="small"><?= h($state->slug) ?></code>
                            </td>
                            <td><?= h($state->label) ?></td>
                            <td>
                                <?php
                                $typeBadge = match ($state->state_type) {
                                    'initial' => 'bg-success',
                                    'final' => 'bg-dark',
                                    default => 'bg-secondary',
                                };
                                ?>
                                <span class="badge <?= $typeBadge ?>"><?= h($state->state_type) ?></span>
                            </td>
                            <td>
                                <?php if ($state->status_category) :
                                    $cs = $catStyles[$state->status_category] ?? ['bg' => '#f8f9fa', 'bd' => '#adb5bd'];
                                ?>
                                    <span class="badge" style="background:<?= $cs['bg'] ?>; color:<?= $cs['bd'] ?>; border:1px solid <?= $cs['bd'] ?>;">
                                        <?= h($state->status_category) ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <?php if ($user->checkCan("edit", $definition)) : ?>
                                <td class="actions">
                                    <?= $this->Html->link(
                                        '<i class="bi bi-pencil"></i>',
                                        ['controller' => 'WorkflowStates', 'action' => 'edit', $state->id],
                                        ['class' => 'btn btn-outline-secondary btn-sm', 'escape' => false, 'title' => __('Edit')]
                                    ) ?>
                                    <?= $this->Form->postLink(
                                        '<i class="bi bi-trash"></i>',
                                        ['controller' => 'WorkflowStates', 'action' => 'delete', $state->id],
                                        [
                                            'confirm' => __('Delete state "{0}"?', $state->name),
                                            'class' => 'btn btn-outline-danger btn-sm',
                                            'escape' => false,
                                            'title' => __('Delete'),
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

<!-- ═══ Transitions Tab ═══ -->
<div class="related tab-pane fade m-3" id="nav-transitions" role="tabpanel" aria-labelledby="nav-transitions-tab"
    data-detail-tabs-target="tabContent"
    data-tab-order="20"
    style="order: 20;">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0"><?= __('Transitions') ?></h4>
        <?php if ($user->checkCan("edit", $definition)) : ?>
            <?= $this->Html->link(
                '<i class="bi bi-plus-circle"></i> ' . __('Add Transition'),
                ['controller' => 'WorkflowTransitions', 'action' => 'add', $definition->id],
                ['class' => 'btn btn-primary btn-sm', 'escape' => false]
            ) ?>
        <?php endif; ?>
    </div>
    <?php if (!empty($definition->workflow_transitions)) : ?>
        <div class="table-responsive">
            <table class="table table-striped align-middle">
                <thead>
                    <tr>
                        <th><?= __('Label') ?></th>
                        <th><?= __('From → To') ?></th>
                        <th><?= __('Type') ?></th>
                        <th><?= __('Priority') ?></th>
                        <?php if ($user->checkCan("edit", $definition)) : ?>
                            <th class="actions"><?= __('Actions') ?></th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($definition->workflow_transitions as $transition) : ?>
                        <tr>
                            <td>
                                <strong><?= h($transition->label) ?></strong>
                                <br><code class="small text-muted"><?= h($transition->slug) ?></code>
                            </td>
                            <td>
                                <span class="badge bg-light text-dark border"><?= $transition->hasValue('from_state') ? h($transition->from_state->label) : '?' ?></span>
                                <i class="bi bi-arrow-right text-muted mx-1"></i>
                                <span class="badge bg-light text-dark border"><?= $transition->hasValue('to_state') ? h($transition->to_state->label) : '?' ?></span>
                            </td>
                            <td>
                                <?php if ($transition->is_automatic) : ?>
                                    <span class="badge bg-info text-dark"><?= __('Auto') ?></span>
                                <?php else : ?>
                                    <span class="badge bg-outline-secondary border"><?= h($transition->trigger_type ?? 'manual') ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?= h($transition->priority) ?></td>
                            <?php if ($user->checkCan("edit", $definition)) : ?>
                                <td class="actions">
                                    <?= $this->Html->link(
                                        '<i class="bi bi-pencil"></i>',
                                        ['controller' => 'WorkflowTransitions', 'action' => 'edit', $transition->id],
                                        ['class' => 'btn btn-outline-secondary btn-sm', 'escape' => false, 'title' => __('Edit')]
                                    ) ?>
                                    <?= $this->Form->postLink(
                                        '<i class="bi bi-trash"></i>',
                                        ['controller' => 'WorkflowTransitions', 'action' => 'delete', $transition->id],
                                        [
                                            'confirm' => __('Delete transition "{0}"?', $transition->name),
                                            'class' => 'btn btn-outline-danger btn-sm',
                                            'escape' => false,
                                            'title' => __('Delete'),
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
