<?php

/**
 * WorkflowDefinitions visual editor template
 *
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\WorkflowDefinition $definition
 */

$this->extend("/layout/TwitterBootstrap/dashboard");

echo $this->KMP->startBlock("title");
echo $this->KMP->getAppSetting("KMP.ShortSiteTitle") . ': Workflow Editor';
$this->KMP->endBlock();
?>

<div class="container-fluid"
     data-controller="workflow-editor"
     data-workflow-editor-definition-id-value="<?= $definition->id ?>"
     data-workflow-editor-api-url-value="/api/workflow-editor">

    <div class="row">
        <div class="col-12">
            <h2><?= h($definition->name) ?> - Workflow Editor</h2>
        </div>
    </div>

    <div class="row">
        <!-- Toolbar -->
        <div class="col-12 mb-3">
            <div class="btn-toolbar">
                <div class="btn-group me-2">
                    <button class="btn btn-success" data-action="workflow-editor#save">
                        <i class="bi bi-save"></i> Save
                    </button>
                    <button class="btn btn-primary" data-action="workflow-editor#validate">
                        <i class="bi bi-check-circle"></i> Validate
                    </button>
                    <button class="btn btn-warning" data-action="workflow-editor#publish">
                        <i class="bi bi-upload"></i> Publish
                    </button>
                </div>
                <div class="btn-group me-2">
                    <button class="btn btn-outline-secondary" data-action="workflow-editor#addState">
                        <i class="bi bi-plus-circle"></i> Add State
                    </button>
                    <button class="btn btn-outline-secondary" data-action="workflow-editor#exportJson">
                        <i class="bi bi-download"></i> Export
                    </button>
                </div>
                <div class="btn-group">
                    <a href="<?= $this->Url->build(['action' => 'index']) ?>" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i> Back to List
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="row" style="height: calc(100vh - 200px);">
        <!-- Canvas -->
        <div class="col-9 h-100">
            <div class="border rounded h-100 position-relative overflow-hidden bg-light"
                 data-workflow-editor-target="canvas"
                 id="workflow-canvas">
                <svg data-workflow-editor-target="svg"
                     width="100%" height="100%"
                     style="position: absolute; top: 0; left: 0;">
                    <!-- Transitions (edges) rendered here -->
                </svg>
                <div data-workflow-editor-target="nodesContainer"
                     style="position: absolute; top: 0; left: 0; width: 100%; height: 100%;">
                    <!-- State nodes rendered here -->
                </div>
            </div>
        </div>

        <!-- Property Panel -->
        <div class="col-3 h-100 overflow-auto">
            <div data-workflow-editor-target="propertyPanel" class="card">
                <div class="card-header">Properties</div>
                <div class="card-body">
                    <p class="text-muted">Select a state or transition to edit its properties.</p>
                </div>
            </div>
        </div>
    </div>
</div>
