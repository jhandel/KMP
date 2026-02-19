<?php

/**
 * @var \App\View\AppView $this
 * @var string $currentVersion
 * @var string $channel
 * @var string $githubRepository
 * @var string $githubApiBaseUrl
 * @var string $installedReleaseHash
 * @var string $installedReleaseTag
 * @var array<string, string> $availableChannels
 */
$this->extend('/layout/TwitterBootstrap/dashboard');

echo $this->KMP->startBlock('title');
echo $this->KMP->getAppSetting('KMP.ShortSiteTitle') . ': Updates';
$this->KMP->endBlock();

$this->assign('title', __('Updates'));
?>

<div class="updates index content"
    data-controller="updates-check"
    data-updates-check-github-api-base-url-value="<?= h($githubApiBaseUrl) ?>"
    data-updates-check-github-repository-value="<?= h($githubRepository) ?>"
    data-updates-check-channel-value="<?= h($channel) ?>"
    data-updates-check-installed-release-hash-value="<?= h($installedReleaseHash) ?>"
    data-updates-check-installed-release-tag-value="<?= h($installedReleaseTag) ?>"
    data-updates-check-set-channel-url-value="<?= h($this->Url->build(['controller' => 'Updates', 'action' => 'setChannel', 'plugin' => null])) ?>">
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
                <dt class="col-sm-3"><?= __('GitHub API URL') ?></dt>
                <dd class="col-sm-9"><?= h($githubApiBaseUrl) ?></dd>
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
            <div class="row g-2 align-items-end">
                <div class="col-sm-4">
                    <?= $this->Form->control('channel', [
                        'label' => __('Update channel'),
                        'type' => 'select',
                        'options' => $availableChannels,
                        'value' => $channel,
                        'empty' => false,
                        'data-updates-check-target' => 'channelSelect',
                        'data-action' => 'change->updates-check#channelChanged',
                    ]) ?>
                </div>
                <div class="col-sm-8 mb-3">
                    <small class="text-muted" data-updates-check-target="statusMessage">
                        <?= __('Checking latest release...') ?>
                    </small>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header"><?= __('Latest release') ?></div>
        <div class="card-body">
            <dl class="row mb-0">
                <dt class="col-sm-3"><?= __('Version') ?></dt>
                <dd class="col-sm-9" data-updates-check-target="latestVersion"><?= __('Checking...') ?></dd>
                <dt class="col-sm-3"><?= __('Tag') ?></dt>
                <dd class="col-sm-9" data-updates-check-target="latestTag"><?= __('Checking...') ?></dd>
                <dt class="col-sm-3"><?= __('Release hash') ?></dt>
                <dd class="col-sm-9">
                    <code data-updates-check-target="latestHash"><?= __('Checking...') ?></code>
                </dd>
                <dt class="col-sm-3"><?= __('Integrity') ?></dt>
                <dd class="col-sm-9" data-updates-check-target="latestIntegrity"><?= __('Checking...') ?></dd>
                <dt class="col-sm-3"><?= __('Published') ?></dt>
                <dd class="col-sm-9" data-updates-check-target="latestPublished"><?= __('Checking...') ?></dd>
                <dt class="col-sm-3"><?= __('Release URL') ?></dt>
                <dd class="col-sm-9">
                    <a href="#" target="_blank" rel="noopener noreferrer" data-updates-check-target="latestReleaseUrl">
                        <?= __('Checking...') ?>
                    </a>
                </dd>
                <dt class="col-sm-3"><?= __('Package URL') ?></dt>
                <dd class="col-sm-9">
                    <a href="#" target="_blank" rel="noopener noreferrer" data-updates-check-target="latestPackageUrl">
                        <?= __('Checking...') ?>
                    </a>
                </dd>
            </dl>
        </div>
    </div>

    <div class="alert d-none" data-updates-check-target="identityStatus"></div>

    <div class="d-none" data-updates-check-target="updateAction">
        <?= $this->Html->link(__('Update now'), [
            'controller' => 'Updates',
            'action' => 'apply',
            'plugin' => null,
        ], [
            'class' => 'btn btn-warning',
            'confirm' => __('Apply update workflow for the latest release?'),
        ]) ?>
    </div>
</div>
