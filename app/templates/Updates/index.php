<?php

/**
 * @var \App\View\AppView $this
 * @var string $currentVersion
 * @var string $channel
 * @var string $githubRepository
 * @var string $installedReleaseHash
 * @var string $installedReleaseTag
 * @var string|null $releaseIdentityStatus
 * @var array<string, string> $availableChannels
 * @var array<string, mixed>|null $availableRelease
 * @var array<string, mixed>|null $manifest
 */
$this->extend('/layout/TwitterBootstrap/dashboard');

echo $this->KMP->startBlock('title');
echo $this->KMP->getAppSetting('KMP.ShortSiteTitle') . ': Updates';
$this->KMP->endBlock();

$this->assign('title', __('Updates'));
?>

<div class="updates index content">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3><?= __('Updates') ?></h3>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <dl class="row mb-0">
                <dt class="col-sm-3"><?= __('Current version') ?></dt>
                <dd class="col-sm-9"><?= h($currentVersion) ?></dd>
                <dt class="col-sm-3"><?= __('Channel') ?></dt>
                <dd class="col-sm-9"><?= h($channel) ?></dd>
                <dt class="col-sm-3"><?= __('GitHub repository') ?></dt>
                <dd class="col-sm-9">
                    <?= $githubRepository !== '' ? h($githubRepository) : __('Not configured') ?>
                </dd>
                <dt class="col-sm-3"><?= __('Installed release tag') ?></dt>
                <dd class="col-sm-9"><?= $installedReleaseTag !== '' ? h($installedReleaseTag) : __('Not set') ?></dd>
                <dt class="col-sm-3"><?= __('Installed release hash') ?></dt>
                <dd class="col-sm-9">
                    <code><?= $installedReleaseHash !== '' ? h($installedReleaseHash) : __('Not set') ?></code>
                </dd>
            </dl>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <?= $this->Form->create(null, [
                'url' => ['controller' => 'Updates', 'action' => 'setChannel', 'plugin' => null],
                'class' => 'row g-2 align-items-end',
            ]) ?>
            <div class="col-sm-4">
                <?= $this->Form->control('channel', [
                    'label' => __('Update channel'),
                    'type' => 'select',
                    'options' => $availableChannels,
                    'value' => $channel,
                    'empty' => false,
                ]) ?>
            </div>
            <div class="col-sm-auto mb-3">
                <?= $this->Form->button(__('Save channel'), ['class' => 'btn btn-secondary']) ?>
            </div>
            <?= $this->Form->end() ?>
        </div>
    </div>

    <div class="mb-3">
        <?= $this->Form->create(null, [
            'url' => ['controller' => 'Updates', 'action' => 'check', 'plugin' => null],
        ]) ?>
        <?= $this->Form->button(__('Check for updates'), ['class' => 'btn btn-primary']) ?>
        <?= $this->Form->end() ?>
    </div>

    <?php if (!empty($availableRelease)): ?>
    <div class="card mb-3">
        <div class="card-header"><?= __('Latest release') ?></div>
        <div class="card-body">
            <dl class="row mb-0">
                <dt class="col-sm-3"><?= __('Version') ?></dt>
                <dd class="col-sm-9"><?= h((string)($availableRelease['version'] ?? 'unknown')) ?></dd>
                <dt class="col-sm-3"><?= __('Tag') ?></dt>
                <dd class="col-sm-9"><?= h((string)($availableRelease['tag'] ?? 'unknown')) ?></dd>
                <dt class="col-sm-3"><?= __('Release hash') ?></dt>
                <dd class="col-sm-9">
                    <?php $releaseHash = (string)($availableRelease['releaseHash'] ?? ''); ?>
                    <code><?= $releaseHash !== '' ? h($releaseHash) : __('Not provided') ?></code>
                </dd>
                <dt class="col-sm-3"><?= __('Published') ?></dt>
                <dd class="col-sm-9"><?= h((string)($availableRelease['publishedAt'] ?? 'unknown')) ?></dd>
                <dt class="col-sm-3"><?= __('Release URL') ?></dt>
                <dd class="col-sm-9">
                    <?php $releaseUrl = (string)($availableRelease['releaseUrl'] ?? ''); ?>
                    <?= $releaseUrl !== '' ? h($releaseUrl) : __('Not provided') ?>
                </dd>
                <dt class="col-sm-3"><?= __('Package URL') ?></dt>
                <dd class="col-sm-9">
                    <?php $packageUrl = (string)($availableRelease['package']['url'] ?? ''); ?>
                    <?= $packageUrl !== '' ? h($packageUrl) : __('Not provided') ?>
                </dd>
            </dl>
        </div>
    </div>

    <?php if ($releaseIdentityStatus === 'same'): ?>
    <div class="alert alert-success"><?= __('You are on this release.') ?></div>
    <?php elseif ($releaseIdentityStatus === 'different'): ?>
    <div class="alert alert-warning"><?= __('Installed release identity differs from the latest release.') ?></div>
    <?php endif; ?>

    <?= $this->Form->create(null, [
            'url' => ['controller' => 'Updates', 'action' => 'apply', 'plugin' => null],
        ]) ?>
    <?= $this->Form->button(__('Update now'), [
            'class' => 'btn btn-warning',
            'confirm' => __('Apply update workflow for the latest release?'),
        ]) ?>
    <?= $this->Form->end() ?>
    <?php endif; ?>
</div>
