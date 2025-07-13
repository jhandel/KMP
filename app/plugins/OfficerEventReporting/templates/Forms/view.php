<?php

/**
 * @var \App\View\AppView $this
 * @var \OfficerEventReporting\Model\Entity\Form $form
 */
?>
<?php $this->extend("/layout/TwitterBootstrap/dashboard");

echo $this->KMP->startBlock("title");
echo $this->KMP->getAppSetting("KMP.ShortSiteTitle") . ': ' . h($form->title);
$this->KMP->endBlock(); ?>

<div class="row">
    <div class="col-md-12">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h3><?= h($form->title) ?></h3>
            <div>
                <?= $this->Html->link(
                    '<i class="fas fa-arrow-left"></i> Back to Forms',
                    ['action' => 'index'],
                    ['class' => 'btn btn-secondary', 'escape' => false]
                ) ?>
                
                <?php if ($this->Identity->can('edit', $form)): ?>
                    <?= $this->Html->link(
                        '<i class="fas fa-edit"></i> Edit Form',
                        ['action' => 'edit', $form->id],
                        ['class' => 'btn btn-primary', 'escape' => false]
                    ) ?>
                <?php endif; ?>
                
                <?php 
                $user = $this->Identity->getIdentity();
                $userId = $user ? $user->getIdentifier() : null;
                if ($userId && $form->isAvailableToUser($userId)): 
                ?>
                    <?= $this->Html->link(
                        '<i class="fas fa-file-plus"></i> Submit This Form',
                        ['controller' => 'Submissions', 'action' => 'add', $form->id],
                        ['class' => 'btn btn-success', 'escape' => false]
                    ) ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="row">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header">
                        <h5>Form Details</h5>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($form->description)): ?>
                            <p class="card-text"><?= nl2br(h($form->description)) ?></p>
                        <?php endif; ?>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <strong>Type:</strong>
                                <span class="badge badge-secondary ml-1">
                                    <?= h(ucwords(str_replace('-', ' ', $form->form_type))) ?>
                                </span>
                            </div>
                            <div class="col-md-6">
                                <strong>Assignment:</strong>
                                <?php
                                $assignmentClass = [
                                    'open' => 'success',
                                    'assigned' => 'info',
                                    'office-specific' => 'warning'
                                ][$form->assignment_type] ?? 'secondary';
                                ?>
                                <span class="badge badge-<?= $assignmentClass ?> ml-1">
                                    <?= h(ucwords(str_replace('-', ' ', $form->assignment_type))) ?>
                                </span>
                            </div>
                        </div>
                        
                        <div class="row mt-2">
                            <div class="col-md-6">
                                <strong>Status:</strong>
                                <?php
                                $statusClass = [
                                    'active' => 'success',
                                    'inactive' => 'secondary',
                                    'archived' => 'dark'
                                ][$form->status] ?? 'secondary';
                                ?>
                                <span class="badge badge-<?= $statusClass ?> ml-1">
                                    <?= h(ucfirst($form->status)) ?>
                                </span>
                            </div>
                            <div class="col-md-6">
                                <strong>Created:</strong> <?= h($form->created->format('M j, Y g:i A')) ?>
                            </div>
                        </div>
                        
                        <?php if (!empty($form->assigned_members_array) && $form->assignment_type === 'assigned'): ?>
                            <div class="mt-2">
                                <strong>Assigned Members:</strong>
                                <span class="text-muted"><?= count($form->assigned_members_array) ?> member(s)</span>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($form->assigned_offices_array) && $form->assignment_type === 'office-specific'): ?>
                            <div class="mt-2">
                                <strong>Assigned Offices:</strong>
                                <span class="text-muted"><?= count($form->assigned_offices_array) ?> office(s)</span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (!empty($form->form_fields)): ?>
                    <div class="card mt-3">
                        <div class="card-header">
                            <h5>Form Fields (<?= count($form->form_fields) ?>)</h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead>
                                        <tr>
                                            <th>Order</th>
                                            <th>Field Name</th>
                                            <th>Label</th>
                                            <th>Type</th>
                                            <th>Required</th>
                                            <th>Options</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($form->form_fields as $field): ?>
                                            <tr>
                                                <td><?= h($field->sort_order) ?></td>
                                                <td><code><?= h($field->field_name) ?></code></td>
                                                <td><?= h($field->field_label) ?></td>
                                                <td>
                                                    <span class="badge badge-light">
                                                        <?= h(ucfirst($field->field_type)) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if ($field->is_required): ?>
                                                        <i class="fas fa-check text-success" title="Required"></i>
                                                    <?php else: ?>
                                                        <i class="fas fa-times text-muted" title="Optional"></i>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($field->requiresOptions()): ?>
                                                        <?php $options = $field->getSelectOptions(); ?>
                                                        <?php if (!empty($options)): ?>
                                                            <small class="text-muted">
                                                                <?= count($options) ?> option(s)
                                                            </small>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <div class="col-md-4">
                <div class="card">
                    <div class="card-header">
                        <h6>Created By</h6>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($form->created_by_member)): ?>
                            <p class="mb-1">
                                <?= h($form->created_by_member->first_name . ' ' . $form->created_by_member->last_name) ?>
                            </p>
                        <?php else: ?>
                            <p class="text-muted">Unknown</p>
                        <?php endif; ?>
                        <small class="text-muted">
                            <?= h($form->created->format('M j, Y g:i A')) ?>
                        </small>
                    </div>
                </div>

                <?php if (!empty($form->modified_by_member)): ?>
                    <div class="card mt-3">
                        <div class="card-header">
                            <h6>Last Modified By</h6>
                        </div>
                        <div class="card-body">
                            <p class="mb-1">
                                <?= h($form->modified_by_member->first_name . ' ' . $form->modified_by_member->last_name) ?>
                            </p>
                            <small class="text-muted">
                                <?= h($form->modified->format('M j, Y g:i A')) ?>
                            </small>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="card mt-3">
                    <div class="card-header">
                        <h6>Submissions</h6>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($form->submissions)): ?>
                            <p class="mb-1">
                                <strong><?= count($form->submissions) ?></strong> submission(s)
                            </p>
                            <?= $this->Html->link(
                                'View Submissions',
                                ['controller' => 'Submissions', 'action' => 'index', '?' => ['form_id' => $form->id]],
                                ['class' => 'btn btn-sm btn-outline-primary']
                            ) ?>
                        <?php else: ?>
                            <p class="text-muted">No submissions yet</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>