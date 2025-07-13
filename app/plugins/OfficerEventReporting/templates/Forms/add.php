<?php

/**
 * @var \App\View\AppView $this
 * @var \OfficerEventReporting\Model\Entity\Form $form
 * @var array $formTypes
 * @var array $assignmentTypes  
 * @var array $fieldTypes
 */
?>
<?php $this->extend("/layout/TwitterBootstrap/dashboard");

echo $this->KMP->startBlock("title");
echo $this->KMP->getAppSetting("KMP.ShortSiteTitle") . ': Create Report Form';
$this->KMP->endBlock(); ?>

<div class="row">
    <div class="col-md-12">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h3>Create Report Form</h3>
            <?= $this->Html->link(
                '<i class="fas fa-arrow-left"></i> Back to Forms',
                ['action' => 'index'],
                ['class' => 'btn btn-secondary', 'escape' => false]
            ) ?>
        </div>

        <?= $this->Form->create($form, ['data-controller' => 'dynamic-form']) ?>
        
        <div class="card">
            <div class="card-header">
                <h5>Form Details</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <?= $this->Form->control('title', [
                            'label' => 'Form Title',
                            'class' => 'form-control',
                            'required' => true,
                            'placeholder' => 'Enter a descriptive title for this form'
                        ]) ?>
                    </div>
                    <div class="col-md-6">
                        <?= $this->Form->control('form_type', [
                            'type' => 'select',
                            'options' => $formTypes,
                            'class' => 'form-control',
                            'label' => 'Form Type',
                            'empty' => 'Select a form type'
                        ]) ?>
                    </div>
                </div>

                <div class="form-group">
                    <?= $this->Form->control('description', [
                        'type' => 'textarea',
                        'label' => 'Description',
                        'class' => 'form-control',
                        'rows' => 3,
                        'placeholder' => 'Provide instructions or context for this form'
                    ]) ?>
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <?= $this->Form->control('assignment_type', [
                            'type' => 'select',
                            'options' => $assignmentTypes,
                            'class' => 'form-control',
                            'label' => 'Assignment Type',
                            'data-action' => 'change->dynamic-form#toggleAssignmentFields'
                        ]) ?>
                    </div>
                    <div class="col-md-6">
                        <?= $this->Form->control('status', [
                            'type' => 'select',
                            'options' => [
                                'active' => 'Active',
                                'inactive' => 'Inactive'
                            ],
                            'class' => 'form-control',
                            'label' => 'Status',
                            'default' => 'active'
                        ]) ?>
                    </div>
                </div>

                <div id="assignment-fields" data-dynamic-form-target="assignmentFields" style="display: none;">
                    <div class="form-group">
                        <?= $this->Form->control('assigned_members', [
                            'type' => 'textarea',
                            'label' => 'Assigned Members (JSON array of member IDs)',
                            'class' => 'form-control',
                            'rows' => 2,
                            'placeholder' => '[1, 2, 3] or leave empty',
                            'help' => 'Enter member IDs as a JSON array, e.g., [1, 2, 3]'
                        ]) ?>
                    </div>
                    
                    <div class="form-group">
                        <?= $this->Form->control('assigned_offices', [
                            'type' => 'textarea',
                            'label' => 'Assigned Offices (JSON array of office IDs)',
                            'class' => 'form-control',
                            'rows' => 2,
                            'placeholder' => '[1, 2, 3] or leave empty',
                            'help' => 'Enter office IDs as a JSON array, e.g., [1, 2, 3]'
                        ]) ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mt-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5>Form Fields</h5>
                <button type="button" class="btn btn-success btn-sm" data-action="click->dynamic-form#addField">
                    <i class="fas fa-plus"></i> Add Field
                </button>
            </div>
            <div class="card-body">
                <div id="form-fields" data-dynamic-form-target="fieldsContainer">
                    <!-- Fields will be added dynamically -->
                </div>
                
                <div class="alert alert-info" id="no-fields-message" data-dynamic-form-target="noFieldsMessage">
                    <i class="fas fa-info-circle"></i>
                    No fields added yet. Click "Add Field" to create form fields.
                </div>
            </div>
        </div>

        <div class="form-group mt-3">
            <?= $this->Form->button(__('Create Form'), [
                'class' => 'btn btn-primary'
            ]) ?>
            <?= $this->Html->link(__('Cancel'), ['action' => 'index'], [
                'class' => 'btn btn-secondary ml-2'
            ]) ?>
        </div>

        <?= $this->Form->end() ?>
    </div>
</div>

<!-- Field Template (hidden) -->
<div id="field-template" style="display: none;" data-dynamic-form-target="fieldTemplate">
    <div class="card mb-3 field-card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span class="field-title">New Field</span>
            <button type="button" class="btn btn-danger btn-sm" data-action="click->dynamic-form#removeField">
                <i class="fas fa-trash"></i>
            </button>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-4">
                    <div class="form-group">
                        <label>Field Name</label>
                        <input type="text" class="form-control field-name" 
                               placeholder="field_name" 
                               data-action="input->dynamic-form#updateFieldTitle">
                        <small class="form-text text-muted">Alphanumeric characters only</small>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="form-group">
                        <label>Field Label</label>
                        <input type="text" class="form-control field-label" 
                               placeholder="Field Label">
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="form-group">
                        <label>Field Type</label>
                        <select class="form-control field-type" data-action="change->dynamic-form#toggleFieldOptions">
                            <?php foreach ($fieldTypes as $value => $label): ?>
                                <option value="<?= h($value) ?>"><?= h($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-6">
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input field-required">
                        <label class="form-check-label">Required Field</label>
                    </div>
                </div>
            </div>
            
            <div class="field-options mt-3" style="display: none;">
                <div class="form-group">
                    <label>Options (one per line)</label>
                    <textarea class="form-control options-text" rows="3" 
                              placeholder="Option 1&#10;Option 2&#10;Option 3"></textarea>
                    <small class="form-text text-muted">For select, radio, and checkbox fields</small>
                </div>
            </div>
        </div>
    </div>
</div>