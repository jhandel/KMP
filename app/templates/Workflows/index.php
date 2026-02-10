<?php

/**
 * Workflow Definitions Index
 *
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\WorkflowDefinition> $workflows
 */

$this->extend("/layout/TwitterBootstrap/dashboard");

echo $this->KMP->startBlock("title");
echo $this->KMP->getAppSetting("KMP.ShortSiteTitle") . ': Workflows';
$this->KMP->endBlock();

$this->assign('title', __('Workflows'));

$csrfToken = $this->request->getAttribute('csrfToken');
$toggleUrl = $this->Url->build(['action' => 'toggleActive', '__id__']);

?>

<div class="workflows index content"
    data-controller="workflow-index"
    data-workflow-index-toggle-url-value="<?= h($toggleUrl) ?>"
    data-workflow-index-csrf-value="<?= h($csrfToken) ?>">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3><?= __('Workflow Definitions') ?></h3>
        <div>
            <?= $this->Html->link(
                '<i class="bi bi-plus-circle me-1"></i>' . __('New Workflow'),
                ['action' => 'add'],
                ['class' => 'btn btn-primary', 'escape' => false]
            ) ?>
        </div>
    </div>

    <!-- Search/Filter -->
    <div class="mb-3">
        <input type="text" class="form-control"
            data-workflow-index-target="search"
            data-action="input->workflow-index#filter"
            placeholder="<?= __('Search workflows by name, slug, or trigger...') ?>">
    </div>

    <div class="table-responsive">
        <table class="table table-striped table-hover">
            <thead>
                <tr>
                    <th><?= __('Name') ?></th>
                    <th><?= __('Slug') ?></th>
                    <th><?= __('Active') ?></th>
                    <th><?= __('Version') ?></th>
                    <th><?= __('Trigger') ?></th>
                    <th><?= __('Entity Type') ?></th>
                    <th class="text-end"><?= __('Actions') ?></th>
                </tr>
            </thead>
            <tbody data-workflow-index-target="body">
                <?php foreach ($workflows as $workflow) : ?>
                <tr data-search-text="<?= h(strtolower($workflow->name . ' ' . $workflow->slug . ' ' . $workflow->trigger_type . ' ' . ($workflow->entity_type ?? ''))) ?>">
                    <td><strong><?= h($workflow->name) ?></strong></td>
                    <td><code><?= h($workflow->slug) ?></code></td>
                    <td>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox"
                                data-action="change->workflow-index#toggleActive"
                                data-workflow-id="<?= h($workflow->id) ?>"
                                <?= $workflow->is_active ? 'checked' : '' ?>>
                        </div>
                    </td>
                    <td>
                        <?php if ($workflow->current_version) : ?>
                            v<?= h($workflow->current_version->version_number) ?>
                            <?= $this->KMP->workflowStatusBadge($workflow->current_version->status) ?>
                        <?php else : ?>
                            <span class="badge bg-light text-dark"><?= __('No version') ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?= h($workflow->trigger_type) ?></td>
                    <td><?= h($workflow->entity_type) ?: '—' ?></td>
                    <td class="text-end">
                        <div class="btn-group btn-group-sm">
                            <?= $this->Html->link(
                                '<i class="bi bi-pencil-square"></i> ' . __('Design'),
                                ['action' => 'designer', $workflow->id],
                                ['class' => 'btn btn-outline-primary', 'escape' => false, 'title' => __('Designer')]
                            ) ?>
                            <?= $this->Html->link(
                                '<i class="bi bi-play-circle"></i> ' . __('Instances'),
                                ['action' => 'instances', $workflow->id],
                                ['class' => 'btn btn-outline-info', 'escape' => false, 'title' => __('Instances')]
                            ) ?>
                            <?= $this->Html->link(
                                '<i class="bi bi-clock-history"></i> ' . __('Versions'),
                                ['action' => 'versions', $workflow->id],
                                ['class' => 'btn btn-outline-secondary', 'escape' => false, 'title' => __('Versions')]
                            ) ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($workflows) || $workflows->count() === 0) : ?>
                <tr id="wf-empty-row">
                    <td colspan="7" class="text-center text-muted py-4">
                        <?= __('No workflow definitions found.') ?>
                        <?= $this->Html->link(__('Create one'), ['action' => 'add']) ?>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
