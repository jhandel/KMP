<?php

/**
 * WorkflowDefinitions analytics template
 *
 * @var \App\View\AppView $this
 * @var array $stats
 */

$this->extend("/layout/TwitterBootstrap/dashboard");

echo $this->KMP->startBlock("title");
echo $this->KMP->getAppSetting("KMP.ShortSiteTitle") . ': Workflow Analytics';
$this->KMP->endBlock();

$this->assign('title', __('Workflow Analytics'));
?>

<div class="workflowDefinitions analytics content">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3><?= __('Workflow Analytics') ?></h3>
        <div>
            <?= $this->Html->link(
                __(' Back to Workflows'),
                ['action' => 'index'],
                ['class' => 'btn btn-outline-secondary bi bi-arrow-left']
            ) ?>
        </div>
    </div>

    <?php
    $totalActive = array_sum(array_column($stats, 'active_instances'));
    $totalCompleted = array_sum(array_column($stats, 'completed_instances'));
    $totalTransitions = array_sum(array_column($stats, 'total_transitions'));
    ?>
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="card text-bg-primary">
                <div class="card-body text-center">
                    <h5 class="card-title"><?= __('Active Instances') ?></h5>
                    <p class="display-6"><?= $this->Number->format($totalActive) ?></p>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card text-bg-success">
                <div class="card-body text-center">
                    <h5 class="card-title"><?= __('Completed Instances') ?></h5>
                    <p class="display-6"><?= $this->Number->format($totalCompleted) ?></p>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card text-bg-info">
                <div class="card-body text-center">
                    <h5 class="card-title"><?= __('Total Transitions') ?></h5>
                    <p class="display-6"><?= $this->Number->format($totalTransitions) ?></p>
                </div>
            </div>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-striped">
            <thead>
                <tr>
                    <th><?= __('Workflow') ?></th>
                    <th><?= __('Entity Type') ?></th>
                    <th><?= __('Plugin') ?></th>
                    <th class="text-center"><?= __('Active') ?></th>
                    <th class="text-center"><?= __('Completed') ?></th>
                    <th class="text-center"><?= __('Total Transitions') ?></th>
                    <th class="actions"><?= __('Actions') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($stats as $stat) : ?>
                <tr>
                    <td><?= h($stat['definition']->name) ?></td>
                    <td><code><?= h($stat['definition']->entity_type) ?></code></td>
                    <td><?= h($stat['definition']->plugin_name) ?></td>
                    <td class="text-center">
                        <?php if ($stat['active_instances'] > 0) : ?>
                            <span class="badge bg-primary"><?= $this->Number->format($stat['active_instances']) ?></span>
                        <?php else : ?>
                            <span class="text-muted">0</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <?php if ($stat['completed_instances'] > 0) : ?>
                            <span class="badge bg-success"><?= $this->Number->format($stat['completed_instances']) ?></span>
                        <?php else : ?>
                            <span class="text-muted">0</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <?= $this->Number->format($stat['total_transitions']) ?>
                    </td>
                    <td class="actions">
                        <?= $this->Html->link(
                            __('Editor'),
                            ['action' => 'editor', $stat['definition']->id],
                            ['class' => 'btn btn-sm btn-outline-primary']
                        ) ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
