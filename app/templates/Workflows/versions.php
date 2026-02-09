<?php

/**
 * Workflow Version History
 *
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\WorkflowDefinition $workflow
 * @var \App\Model\Entity\WorkflowVersion[] $versions
 */

$this->extend("/layout/TwitterBootstrap/dashboard");

echo $this->KMP->startBlock("title");
echo $this->KMP->getAppSetting("KMP.ShortSiteTitle") . ': Versions - ' . h($workflow->name);
$this->KMP->endBlock();

$statusBadge = function (string $status): string {
    $map = [
        'draft' => 'secondary',
        'published' => 'success',
        'archived' => 'dark',
    ];
    $color = $map[$status] ?? 'light';
    return '<span class="badge bg-' . $color . '">' . h($status) . '</span>';
};

$compareUrl = $this->Url->build(['action' => 'compareVersions']);
$csrfToken = $this->request->getAttribute('csrfToken');
?>

<div class="workflows versions content">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3>
            <?= $this->element('backButton') ?>
            <?= __('Versions: {0}', h($workflow->name)) ?>
        </h3>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-outline-success btn-sm" id="wf-create-draft-btn"
                data-workflow-id="<?= h($workflow->id) ?>"
                data-url="<?= h($this->Url->build(['action' => 'createDraft'])) ?>">
                <i class="bi bi-plus-lg me-1"></i><?= __('Create New Draft') ?>
            </button>
            <?= $this->Html->link(
                '<i class="bi bi-pencil-square me-1"></i>' . __('Open Designer'),
                ['action' => 'designer', $workflow->id],
                ['class' => 'btn btn-primary btn-sm', 'escape' => false]
            ) ?>
        </div>
    </div>

    <!-- Version Compare Controls -->
    <?php if (count($versions) >= 2) : ?>
    <div class="card mb-3">
        <div class="card-body py-2">
            <div class="row align-items-center g-2">
                <div class="col-auto">
                    <label class="form-label mb-0 fw-semibold small"><?= __('Compare') ?></label>
                </div>
                <div class="col-auto">
                    <select class="form-select form-select-sm" id="wf-compare-v1" style="width:auto">
                        <option value=""><?= __('Select version...') ?></option>
                        <?php foreach ($versions as $v) : ?>
                        <option value="<?= h($v->id) ?>">v<?= h($v->version_number) ?> (<?= h($v->status) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-auto"><span class="text-muted"><?= __('vs') ?></span></div>
                <div class="col-auto">
                    <select class="form-select form-select-sm" id="wf-compare-v2" style="width:auto">
                        <option value=""><?= __('Select version...') ?></option>
                        <?php foreach ($versions as $v) : ?>
                        <option value="<?= h($v->id) ?>">v<?= h($v->version_number) ?> (<?= h($v->status) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-auto">
                    <button type="button" class="btn btn-sm btn-outline-primary" id="wf-compare-btn" disabled>
                        <i class="bi bi-arrow-left-right me-1"></i><?= __('Compare') ?>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Diff Results -->
    <div id="wf-diff-results" class="mb-3" style="display:none">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><?= __('Version Differences') ?></h6>
                <button type="button" class="btn-close btn-sm" id="wf-diff-close"></button>
            </div>
            <div class="card-body p-0" id="wf-diff-body"></div>
        </div>
    </div>
    <?php endif; ?>

    <div class="table-responsive">
        <table class="table table-striped table-hover">
            <thead>
                <tr>
                    <th><?= __('Version') ?></th>
                    <th><?= __('Status') ?></th>
                    <th><?= __('Published At') ?></th>
                    <th><?= __('Change Notes') ?></th>
                    <th><?= __('Created') ?></th>
                    <th class="text-end"><?= __('Actions') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($versions as $version) : ?>
                <tr>
                    <td>v<?= h($version->version_number) ?></td>
                    <td><?= $statusBadge($version->status) ?></td>
                    <td><?= $version->published_at ? h($version->published_at) : '—' ?></td>
                    <td><?= h($version->change_notes) ?: '—' ?></td>
                    <td><?= h($version->created) ?></td>
                    <td class="text-end">
                        <?php if ($version->status === 'published') : ?>
                        <button type="button" class="btn btn-outline-warning btn-sm wf-migrate-btn"
                            data-version-id="<?= h($version->id) ?>"
                            data-url="<?= h($this->Url->build(['action' => 'migrateInstances'])) ?>"
                            title="<?= __('Migrate Running Instances') ?>">
                            <i class="bi bi-arrow-repeat"></i>
                        </button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($versions)) : ?>
                <tr>
                    <td colspan="6" class="text-center text-muted py-4">
                        <?= __('No versions found.') ?>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const compareBtn = document.getElementById('wf-compare-btn');
    const v1Select = document.getElementById('wf-compare-v1');
    const v2Select = document.getElementById('wf-compare-v2');
    const diffResults = document.getElementById('wf-diff-results');
    const diffBody = document.getElementById('wf-diff-body');
    const diffClose = document.getElementById('wf-diff-close');

    if (v1Select && v2Select && compareBtn) {
        const updateBtn = () => {
            compareBtn.disabled = !(v1Select.value && v2Select.value && v1Select.value !== v2Select.value);
        };
        v1Select.addEventListener('change', updateBtn);
        v2Select.addEventListener('change', updateBtn);

        compareBtn.addEventListener('click', async () => {
            compareBtn.disabled = true;
            compareBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
            try {
                const url = '<?= $compareUrl ?>' + '?v1=' + v1Select.value + '&v2=' + v2Select.value;
                const resp = await fetch(url, { headers: { 'Accept': 'application/json' } });
                const diff = await resp.json();
                renderDiff(diff);
                diffResults.style.display = '';
            } catch (e) {
                console.error('Compare failed:', e);
            } finally {
                compareBtn.disabled = false;
                compareBtn.innerHTML = '<i class="bi bi-arrow-left-right me-1"></i><?= __("Compare") ?>';
            }
        });
    }

    if (diffClose) {
        diffClose.addEventListener('click', () => { diffResults.style.display = 'none'; });
    }

    function renderDiff(diff) {
        let html = '<table class="table table-sm mb-0"><thead><tr><th>Node</th><th>Change</th><th>Details</th></tr></thead><tbody>';
        if (diff.added && diff.added.length) {
            diff.added.forEach(n => {
                html += '<tr class="table-success"><td>' + escHtml(n.key || n) + '</td><td><span class="badge bg-success">Added</span></td><td>' + escHtml(n.type || '') + '</td></tr>';
            });
        }
        if (diff.removed && diff.removed.length) {
            diff.removed.forEach(n => {
                html += '<tr class="table-danger"><td>' + escHtml(n.key || n) + '</td><td><span class="badge bg-danger">Removed</span></td><td>' + escHtml(n.type || '') + '</td></tr>';
            });
        }
        if (diff.modified && diff.modified.length) {
            diff.modified.forEach(n => {
                html += '<tr class="table-warning"><td>' + escHtml(n.key || n) + '</td><td><span class="badge bg-warning text-dark">Modified</span></td><td>' + escHtml(n.changes || '') + '</td></tr>';
            });
        }
        if ((!diff.added || !diff.added.length) && (!diff.removed || !diff.removed.length) && (!diff.modified || !diff.modified.length)) {
            html += '<tr><td colspan="3" class="text-center text-muted py-3">No differences found.</td></tr>';
        }
        html += '</tbody></table>';
        diffBody.innerHTML = html;
    }

    function escHtml(s) {
        const d = document.createElement('div');
        d.textContent = String(s);
        return d.innerHTML;
    }

    // Create New Draft
    const createDraftBtn = document.getElementById('wf-create-draft-btn');
    if (createDraftBtn) {
        createDraftBtn.addEventListener('click', async () => {
            if (!confirm('<?= __("Create a new draft from the published version?") ?>')) return;
            createDraftBtn.disabled = true;
            try {
                const resp = await fetch(createDraftBtn.dataset.url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': '<?= h($csrfToken) ?>',
                    },
                    body: JSON.stringify({ workflowId: createDraftBtn.dataset.workflowId }),
                });
                if (resp.ok) {
                    window.location.reload();
                } else {
                    alert('<?= __("Failed to create draft.") ?>');
                }
            } catch (e) {
                alert('<?= __("Error creating draft.") ?>');
            } finally {
                createDraftBtn.disabled = false;
            }
        });
    }

    // Migrate Running Instances
    document.querySelectorAll('.wf-migrate-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
            if (!confirm('<?= __("Migrate all running instances to this version?") ?>')) return;
            btn.disabled = true;
            try {
                const resp = await fetch(btn.dataset.url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': '<?= h($csrfToken) ?>',
                    },
                    body: JSON.stringify({ versionId: btn.dataset.versionId }),
                });
                if (resp.ok) {
                    const result = await resp.json();
                    alert(result.message || '<?= __("Migration complete.") ?>');
                } else {
                    alert('<?= __("Migration failed.") ?>');
                }
            } catch (e) {
                alert('<?= __("Error during migration.") ?>');
            } finally {
                btn.disabled = false;
            }
        });
    });
});
</script>
