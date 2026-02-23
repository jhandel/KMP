<?php

/**
 * System Update dashboard — view current version, check for updates, trigger upgrades.
 *
 * @var \App\View\AppView $this
 * @var array{version: string, imageTag: string, channel: string, registry: string, provider: string} $currentInfo
 * @var bool $supportsWebUpdate
 * @var iterable<\App\Model\Entity\SystemUpdate> $recentUpdates
 * @var \App\Model\Entity\SystemUpdate|null $lastSuccess
 */

$this->extend("/layout/TwitterBootstrap/dashboard");

echo $this->KMP->startBlock("title");
echo $this->KMP->getAppSetting("KMP.ShortSiteTitle") . ': System Update';
$this->KMP->endBlock();

$this->assign('title', __('System Update'));

$channelColors = [
    'release' => 'success',
    'beta' => 'warning',
    'dev' => 'info',
    'nightly' => 'secondary',
];
$channelColor = $channelColors[$currentInfo['channel']] ?? 'secondary';
?>

<div class="container-fluid"
    data-controller="system-update"
    data-system-update-check-url-value="<?= h($this->Url->build(['action' => 'check'])) ?>"
    data-system-update-trigger-url-value="<?= h($this->Url->build(['action' => 'trigger'])) ?>"
    data-system-update-status-url-value="<?= h($this->Url->build(['action' => 'status'])) ?>"
    data-system-update-rollback-url-value="<?= h($this->Url->build(['action' => 'rollback'])) ?>"
    data-system-update-supports-web-update-value="<?= $supportsWebUpdate ? 'true' : 'false' ?>">

    <div class="row">
        <!-- Current Version Card -->
        <div class="col-md-4 mb-4">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0"><i class="bi bi-info-circle"></i> <?= __('Current Version') ?></h5>
                </div>
                <div class="card-body">
                    <table class="table table-sm table-borderless mb-0">
                        <tr>
                            <th class="text-muted"><?= __('Version') ?></th>
                            <td><code><?= h($currentInfo['version']) ?></code></td>
                        </tr>
                        <tr>
                            <th class="text-muted"><?= __('Image Tag') ?></th>
                            <td><code><?= h($currentInfo['imageTag']) ?></code></td>
                        </tr>
                        <tr>
                            <th class="text-muted"><?= __('Channel') ?></th>
                            <td>
                                <span class="badge bg-<?= $channelColor ?>">
                                    <?= h(ucfirst($currentInfo['channel'])) ?>
                                </span>
                            </td>
                        </tr>
                        <tr>
                            <th class="text-muted"><?= __('Registry') ?></th>
                            <td><small class="text-muted"><?= h($currentInfo['registry']) ?></small></td>
                        </tr>
                        <tr>
                            <th class="text-muted"><?= __('Provider') ?></th>
                            <td>
                                <i class="bi bi-<?= $currentInfo['provider'] === 'railway' ? 'train-front' : 'hdd-stack' ?>"></i>
                                <?= h(ucfirst($currentInfo['provider'])) ?>
                            </td>
                        </tr>
                    </table>

                    <?php if (!$supportsWebUpdate): ?>
                        <div class="alert alert-warning mt-3 mb-0 py-2 px-3">
                            <i class="bi bi-exclamation-triangle"></i>
                            <?= __('Web-triggered updates are not available. Use the CLI installer: {0}', '<code>kmp update</code>') ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Rollback Card -->
            <?php if ($lastSuccess): ?>
            <div class="card mt-3">
                <div class="card-header">
                    <h5 class="card-title mb-0"><i class="bi bi-arrow-counterclockwise"></i> <?= __('Rollback') ?></h5>
                </div>
                <div class="card-body">
                    <p class="text-muted mb-2">
                        <?= __('Last successful update: {0} → {1}', '<code>' . h($lastSuccess->from_tag) . '</code>', '<code>' . h($lastSuccess->to_tag) . '</code>') ?>
                    </p>
                    <?php if ($supportsWebUpdate && $lastSuccess->from_tag !== $currentInfo['imageTag']): ?>
                        <button class="btn btn-outline-warning btn-sm"
                            data-action="click->system-update#rollback"
                            data-system-update-rollback-tag-param="<?= h($lastSuccess->from_tag) ?>">
                            <i class="bi bi-arrow-counterclockwise"></i>
                            <?= __('Rollback to {0}', h($lastSuccess->from_tag)) ?>
                        </button>
                    <?php else: ?>
                        <small class="text-muted"><?= __('Already on the rollback target or web updates unavailable.') ?></small>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Available Updates -->
        <div class="col-md-8 mb-4">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0"><i class="bi bi-cloud-download"></i> <?= __('Available Updates') ?></h5>
                    <button class="btn btn-outline-primary btn-sm"
                        data-action="click->system-update#checkForUpdates"
                        data-system-update-target="checkBtn">
                        <i class="bi bi-arrow-clockwise"></i> <?= __('Check Now') ?>
                    </button>
                </div>
                <div class="card-body">
                    <div data-system-update-target="loading" class="text-center py-4 d-none">
                        <div class="spinner-border spinner-border-sm text-primary" role="status"></div>
                        <span class="ms-2 text-muted"><?= __('Checking for updates...') ?></span>
                    </div>

                    <div data-system-update-target="error" class="alert alert-danger d-none"></div>

                    <div data-system-update-target="versions">
                        <p class="text-muted"><?= __('Click "Check Now" to query the container registry for available versions.') ?></p>
                    </div>
                </div>
            </div>

            <!-- Update History -->
            <div class="card mt-3">
                <div class="card-header">
                    <h5 class="card-title mb-0"><i class="bi bi-clock-history"></i> <?= __('Update History') ?></h5>
                </div>
                <div class="card-body p-0">
                    <table class="table table-sm table-striped mb-0">
                        <thead>
                            <tr>
                                <th><?= __('Date') ?></th>
                                <th><?= __('From') ?></th>
                                <th><?= __('To') ?></th>
                                <th><?= __('Status') ?></th>
                                <th><?= __('By') ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recentUpdates) || $recentUpdates->isEmpty()): ?>
                                <tr><td colspan="5" class="text-muted text-center py-3"><?= __('No update history') ?></td></tr>
                            <?php else: ?>
                                <?php foreach ($recentUpdates as $update): ?>
                                    <?php
                                    $statusColors = [
                                        'completed' => 'success',
                                        'failed' => 'danger',
                                        'running' => 'primary',
                                        'pending' => 'secondary',
                                        'rolled_back' => 'warning',
                                    ];
                                    $statusColor = $statusColors[$update->status] ?? 'secondary';
                                    ?>
                                    <tr>
                                        <td><small><?= h($update->created->nice()) ?></small></td>
                                        <td><code class="small"><?= h($update->from_tag) ?></code></td>
                                        <td><code class="small"><?= h($update->to_tag) ?></code></td>
                                        <td>
                                            <span class="badge bg-<?= $statusColor ?>">
                                                <?= h(ucfirst(str_replace('_', ' ', $update->status))) ?>
                                            </span>
                                            <?php if ($update->error_message): ?>
                                                <i class="bi bi-exclamation-circle text-danger"
                                                   title="<?= h($update->error_message) ?>"
                                                   data-bs-toggle="tooltip"></i>
                                            <?php endif; ?>
                                        </td>
                                        <td><small><?= h($update->member->sca_name ?? $update->initiated_by) ?></small></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Update Progress Modal -->
    <div class="modal fade" id="updateProgressModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false"
         data-system-update-target="progressModal">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-arrow-repeat"></i> <?= __('Updating...') ?></h5>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <div class="progress" style="height: 24px;">
                            <div class="progress-bar progress-bar-striped progress-bar-animated"
                                 role="progressbar"
                                 data-system-update-target="progressBar"
                                 style="width: 0%">0%</div>
                        </div>
                    </div>
                    <div data-system-update-target="progressMessage" class="text-muted small">
                        <?= __('Preparing update...') ?>
                    </div>
                    <div data-system-update-target="progressResult" class="mt-3 d-none"></div>
                </div>
            </div>
        </div>
    </div>
</div>
