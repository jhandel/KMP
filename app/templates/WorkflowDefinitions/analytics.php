<?php

/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\WorkflowDefinition $definition
 * @var array<string, int> $instancesByState
 * @var int $activeCount
 * @var int $completedCount
 */

$totalCount = $activeCount + $completedCount;
?>

<?php
$this->extend("/layout/TwitterBootstrap/dashboard");

echo $this->KMP->startBlock("title");
echo $this->KMP->getAppSetting("KMP.ShortSiteTitle") . ': Workflow Analytics - ' . $definition->name;
$this->KMP->endBlock();

$this->assign('title', __('Workflow Analytics'));
?>

<div class="workflowDefinitions analytics content">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h3><?= h($definition->name) ?> &mdash; <?= __('Analytics') ?></h3>
            <?php if ($definition->description) : ?>
                <p class="text-muted mb-0"><?= h($definition->description) ?></p>
            <?php endif; ?>
        </div>
        <div>
            <?= $this->Html->link(
                __('Back to Definition'),
                ['action' => 'view', $definition->id],
                ['class' => 'btn btn-outline-secondary btn-sm']
            ) ?>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="card text-center">
                <div class="card-body">
                    <h5 class="card-title text-muted"><?= __('Total Instances') ?></h5>
                    <p class="display-6 mb-0"><?= $totalCount ?></p>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card text-center">
                <div class="card-body">
                    <h5 class="card-title text-muted"><?= __('Active') ?></h5>
                    <p class="display-6 mb-0 text-primary"><?= $activeCount ?></p>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card text-center">
                <div class="card-body">
                    <h5 class="card-title text-muted"><?= __('Completed') ?></h5>
                    <p class="display-6 mb-0 text-success"><?= $completedCount ?></p>
                </div>
            </div>
        </div>
    </div>

    <!-- Instances by Current State -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0"><?= __('Instances by Current State') ?></h5>
        </div>
        <div class="card-body">
            <?php if (!empty($instancesByState)) : ?>
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th><?= __('State') ?></th>
                                <th><?= __('Count') ?></th>
                                <th><?= __('Percentage') ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($instancesByState as $stateLabel => $count) : ?>
                                <tr>
                                    <td><?= h($stateLabel) ?></td>
                                    <td><?= $count ?></td>
                                    <td>
                                        <?php $pct = $totalCount > 0 ? round(($count / $totalCount) * 100, 1) : 0; ?>
                                        <div class="progress" style="height: 20px;">
                                            <div class="progress-bar" role="progressbar"
                                                style="width: <?= $pct ?>%"
                                                aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100">
                                                <?= $pct ?>%
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else : ?>
                <p class="text-muted mb-0"><?= __('No instances found for this workflow.') ?></p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Recent Instances -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0"><?= __('Recent Instances') ?></h5>
        </div>
        <div class="card-body">
            <?php if (!empty($definition->workflow_instances)) : ?>
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th><?= __('ID') ?></th>
                                <th><?= __('Entity Type') ?></th>
                                <th><?= __('Entity ID') ?></th>
                                <th><?= __('Current State') ?></th>
                                <th><?= __('Started At') ?></th>
                                <th><?= __('Status') ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (array_slice($definition->workflow_instances, 0, 50) as $instance) : ?>
                                <tr>
                                    <td><?= h($instance->id) ?></td>
                                    <td><?= h($instance->entity_type) ?></td>
                                    <td><?= h($instance->entity_id) ?></td>
                                    <td><?= h($instance->current_state->label ?? __('Unknown')) ?></td>
                                    <td><?= $instance->started_at ? $this->Timezone->format($instance->started_at, null, null, \IntlDateFormatter::SHORT, \IntlDateFormatter::SHORT) : '' ?></td>
                                    <td>
                                        <?php if ($instance->completed_at === null) : ?>
                                            <span class="badge bg-primary"><?= __('Active') ?></span>
                                        <?php else : ?>
                                            <span class="badge bg-success"><?= __('Completed') ?></span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else : ?>
                <p class="text-muted mb-0"><?= __('No instances found for this workflow.') ?></p>
            <?php endif; ?>
        </div>
    </div>
</div>
