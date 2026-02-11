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

echo $this->KMP->startBlock("css");
echo $this->Html->css('/css/drawflow.css');
echo $this->Html->css('/css/workflow-designer.css');
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
    <div class="workflow-toolbar">
        <h5 class="mb-0 me-2">
            <?php if ($workflow) : ?>
                <i class="bi bi-diagram-3 me-1"></i><?= h($workflow->name) ?>
                <?php if ($draftVersion) : ?>
                    <span class="badge bg-warning text-dark ms-2"><?= __('Draft v{0}', $draftVersion->version_number) ?></span>
                <?php endif; ?>
            <?php else : ?>
                <i class="bi bi-diagram-3 me-1"></i><?= __('New Workflow') ?>
            <?php endif; ?>
        </h5>

        <div class="wf-zoom-controls ms-3">
            <button class="btn btn-sm" data-action="workflow-designer#zoomOut" title="Zoom Out" aria-label="<?= __('Zoom Out') ?>">
                <i class="bi bi-dash"></i>
            </button>
            <span class="wf-zoom-level" data-workflow-designer-target="zoomLevel">100%</span>
            <button class="btn btn-sm" data-action="workflow-designer#zoomIn" title="Zoom In" aria-label="<?= __('Zoom In') ?>">
                <i class="bi bi-plus"></i>
            </button>
            <button class="btn btn-sm" data-action="workflow-designer#zoomReset" title="Reset Zoom" aria-label="<?= __('Reset Zoom') ?>">
                <i class="bi bi-arrows-angle-expand"></i>
            </button>
        </div>

        <div class="toolbar-separator"></div>

        <button class="btn btn-sm btn-outline-secondary" data-action="workflow-designer#undo" title="Undo (Ctrl+Z)" aria-label="<?= __('Undo') ?>">
            <i class="bi bi-arrow-counterclockwise"></i>
        </button>
        <button class="btn btn-sm btn-outline-secondary" data-action="workflow-designer#redo" title="Redo (Ctrl+Y)" aria-label="<?= __('Redo') ?>">
            <i class="bi bi-arrow-clockwise"></i>
        </button>

        <div class="toolbar-separator"></div>

        <button class="btn btn-sm btn-outline-secondary" data-action="workflow-designer#validateWorkflow" title="Validate">
            <i class="bi bi-check-circle me-1"></i><?= __('Validate') ?>
        </button>

        <div class="ms-auto d-flex gap-2">
            <?php if ($workflow) : ?>
                <?= $this->Html->link(
                    '<i class="bi bi-arrow-left me-1"></i>' . __('Back'),
                    ['action' => 'index'],
                    ['class' => 'btn btn-sm btn-outline-secondary', 'escape' => false]
                ) ?>
            <?php endif; ?>
            <button class="btn btn-sm btn-outline-primary" data-action="workflow-designer#save">
                <i class="bi bi-save me-1"></i><?= __('Save Draft') ?>
            </button>
            <button class="btn btn-sm btn-primary" data-action="workflow-designer#publish">
                <i class="bi bi-rocket-takeoff me-1"></i><?= __('Publish') ?>
            </button>
        </div>
    </div>

    <!-- Validation Results (initially hidden) -->
    <div data-workflow-designer-target="validationResults" class="wf-validation-results" aria-live="polite" style="display:none;"></div>

    <!-- Main Designer Area -->
    <div class="workflow-designer-container wf-designer-container">
        <!-- Left: Node Palette -->
        <div class="workflow-palette wf-palette"
            data-workflow-designer-target="nodePalette">
            <div style="padding: 0.25rem 0 0.5rem;">
                <span style="font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: #667085;">
                    <i class="bi bi-grid-3x3-gap me-1"></i><?= __('Nodes') ?>
                </span>
            </div>
            <p class="text-muted small"><?= __('Loading...') ?></p>
        </div>

        <!-- Center: Canvas -->
        <div class="workflow-canvas"
            data-workflow-designer-target="canvas"
            data-action="drop->workflow-designer#onCanvasDrop dragover->workflow-designer#onCanvasDragOver">
        </div>

        <!-- Right: Config Panel -->
        <div class="workflow-config-panel wf-config-panel"
            data-workflow-designer-target="nodeConfig">
            <div class="config-panel-header">
                <h6><i class="bi bi-sliders me-1"></i><?= __('Configuration') ?></h6>
            </div>
            <div class="config-panel-empty">
                <i class="bi bi-hand-index"></i>
                <p><?= __('Select a node on the canvas to configure it') ?></p>
            </div>
        </div>
    </div>

</div>
