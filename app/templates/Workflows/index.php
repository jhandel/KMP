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

$versionStatusBadge = function (?object $version): string {
    if (!$version) {
        return '<span class="badge bg-light text-dark">' . __('No version') . '</span>';
    }
    $map = ['draft' => 'secondary', 'published' => 'success', 'archived' => 'dark'];
    $color = $map[$version->status] ?? 'light';
    return 'v' . h($version->version_number) . ' <span class="badge bg-' . $color . '">' . h($version->status) . '</span>';
};
?>

<div class="workflows index content">
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
        <input type="text" class="form-control" id="wf-search-input"
            placeholder="<?= __('Search workflows by name, slug, or trigger...') ?>">
    </div>

    <div class="table-responsive">
        <table class="table table-striped table-hover" id="wf-table">
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
            <tbody>
                <?php foreach ($workflows as $workflow) : ?>
                <tr data-search-text="<?= h(strtolower($workflow->name . ' ' . $workflow->slug . ' ' . $workflow->trigger_type . ' ' . ($workflow->entity_type ?? ''))) ?>">
                    <td><strong><?= h($workflow->name) ?></strong></td>
                    <td><code><?= h($workflow->slug) ?></code></td>
                    <td>
                        <div class="form-check form-switch">
                            <input class="form-check-input wf-toggle-active" type="checkbox"
                                data-workflow-id="<?= h($workflow->id) ?>"
                                <?= $workflow->is_active ? 'checked' : '' ?>>
                        </div>
                    </td>
                    <td><?= $versionStatusBadge($workflow->current_version ?? null) ?></td>
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

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Search/filter
    const searchInput = document.getElementById('wf-search-input');
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            const query = this.value.toLowerCase().trim();
            document.querySelectorAll('#wf-table tbody tr[data-search-text]').forEach(row => {
                const text = row.dataset.searchText || '';
                row.style.display = text.includes(query) ? '' : 'none';
            });
        });
    }

    // Toggle active status
    document.querySelectorAll('.wf-toggle-active').forEach(toggle => {
        toggle.addEventListener('change', async function() {
            const id = this.dataset.workflowId;
            const url = '<?= $toggleUrl ?>'.replace('__id__', id);
            try {
                const resp = await fetch(url, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-Token': '<?= h($csrfToken) ?>',
                        'Accept': 'application/json',
                    },
                });
                if (!resp.ok) {
                    this.checked = !this.checked;
                    alert('<?= __("Failed to update status.") ?>');
                }
            } catch (e) {
                this.checked = !this.checked;
                alert('<?= __("Error updating status.") ?>');
            }
        });
    });
});
</script>
