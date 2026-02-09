<?php

/**
 * Workflow Visual Designer
 *
 * Full-page canvas for designing workflow graphs with drag-and-drop
 * node palette, connection wiring, and node configuration panel.
 *
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\WorkflowDefinition|null $workflow
 * @var \App\Model\Entity\WorkflowVersion|null $draftVersion
 */

$this->extend("/layout/TwitterBootstrap/dashboard");

echo $this->KMP->startBlock("title");
echo $this->KMP->getAppSetting("KMP.ShortSiteTitle") . ': Workflow Designer';
$this->KMP->endBlock();
?>

<div class="workflows designer content"
    data-controller="workflow-designer"
    data-workflow-designer-save-url-value="<?= $this->Url->build(['action' => 'save']) ?>"
    data-workflow-designer-publish-url-value="<?= $this->Url->build(['action' => 'publish']) ?>"
    data-workflow-designer-registry-url-value="<?= $this->Url->build(['action' => 'registry']) ?>"
    <?php if ($draftVersion) : ?>
    data-workflow-designer-load-url-value="<?= $this->Url->build(['action' => 'loadVersion', $draftVersion->id]) ?>"
    <?php endif; ?>
    data-workflow-designer-workflow-id-value="<?= $workflow ? h($workflow->id) : '' ?>"
    data-workflow-designer-version-id-value="<?= $draftVersion ? h($draftVersion->id) : '' ?>"
    data-workflow-designer-csrf-token-value="<?= $this->request->getAttribute('csrfToken') ?>">

    <!-- Toolbar -->
    <div class="workflow-toolbar d-flex align-items-center border-bottom p-2 bg-light">
        <h5 class="mb-0 me-3">
            <?php if ($workflow) : ?>
                <i class="bi bi-diagram-3 me-1"></i><?= h($workflow->name) ?>
                <?php if ($draftVersion) : ?>
                    <span class="badge bg-warning text-dark ms-2"><?= __('Draft v{0}', $draftVersion->version_number) ?></span>
                <?php endif; ?>
            <?php else : ?>
                <i class="bi bi-diagram-3 me-1"></i><?= __('New Workflow') ?>
            <?php endif; ?>
        </h5>
        <div class="ms-auto d-flex gap-2">
            <?php if ($workflow) : ?>
                <?= $this->Html->link(
                    '<i class="bi bi-arrow-left me-1"></i>' . __('Back'),
                    ['action' => 'index'],
                    ['class' => 'btn btn-sm btn-outline-secondary', 'escape' => false]
                ) ?>
            <?php endif; ?>
            <button class="btn btn-sm btn-outline-secondary" data-action="workflow-designer#save">
                <i class="bi bi-save me-1"></i><?= __('Save Draft') ?>
            </button>
            <button class="btn btn-sm btn-primary" data-action="workflow-designer#publish">
                <i class="bi bi-rocket me-1"></i><?= __('Publish') ?>
            </button>
        </div>
    </div>

    <!-- Main Designer Area -->
    <div class="workflow-designer-container d-flex" style="height: calc(100vh - 180px);">
        <!-- Left: Node Palette -->
        <div class="workflow-palette border-end bg-white p-2" style="width: 220px; overflow-y: auto;"
            data-workflow-designer-target="nodePalette">
            <h6 class="text-uppercase text-muted small mb-2"><?= __('Node Palette') ?></h6>
            <p class="text-muted small"><?= __('Loading node types...') ?></p>
        </div>

        <!-- Center: Canvas -->
        <div class="workflow-canvas flex-grow-1 position-relative bg-light"
            data-workflow-designer-target="canvas"
            data-action="drop->workflow-designer#onCanvasDrop dragover->workflow-designer#onCanvasDragOver">
            <p class="text-muted text-center pt-5"><?= __('Drag nodes from the palette to start designing') ?></p>
        </div>

        <!-- Right: Config Panel -->
        <div class="workflow-config-panel border-start bg-white p-3" style="width: 300px; overflow-y: auto;"
            data-workflow-designer-target="nodeConfig">
            <h6 class="text-uppercase text-muted small mb-2"><?= __('Node Configuration') ?></h6>
            <p class="text-muted small"><?= __('Select a node to configure it') ?></p>
        </div>
    </div>
</div>
