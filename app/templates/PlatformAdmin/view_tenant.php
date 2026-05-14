<?php
/**
 * @var \Cake\View\View $this
 * @var \App\Model\Entity\Tenant $tenant
 * @var array<string, array<string, mixed>> $doctor
 * @var array<int, \App\Model\Entity\TenantOperationJob> $jobs
 * @var array<int, \App\Model\Entity\Backup> $backups
 */
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1><?= h($tenant->display_name) ?></h1>
    <span class="badge text-bg-secondary"><?= h($tenant->status) ?></span>
</div>
<dl class="row">
    <dt class="col-sm-3">Slug</dt><dd class="col-sm-9"><?= h($tenant->slug) ?></dd>
    <dt class="col-sm-3">Primary host</dt><dd class="col-sm-9"><?= h($tenant->primary_host) ?></dd>
    <dt class="col-sm-3">Schema version</dt><dd class="col-sm-9"><?= h($tenant->schema_version) ?></dd>
</dl>
<h2 class="h4">Doctor</h2>
<div class="card mb-4">
    <table class="table table-sm mb-0">
        <?php foreach ($doctor as $name => $check) : ?>
            <tr>
                <th><?= h($name) ?></th>
                <td><?= !empty($check['ok']) ? 'OK' : 'Needs attention' ?></td>
                <td><?= h($check['message'] ?? '') ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
</div>
<h2 class="h4">Managed Secrets</h2>
<div class="card mb-4">
    <div class="card-body">
        <p class="text-muted">
            Store encrypted platform-managed secrets for cloud deployments where admins cannot update environment
            variables on the host. Existing secret values are never displayed.
        </p>
        <?= $this->Form->create(null, ['url' => ['action' => 'updateTenantSecrets', $tenant->slug]]) ?>
        <div class="row">
            <div class="col-md-4">
                <?= $this->Form->control('database_secret_value', [
                    'type' => 'password',
                    'value' => '',
                    'autocomplete' => 'new-password',
                    'label' => 'Database password',
                ]) ?>
                <?= $this->Form->control('email_secret_value', [
                    'type' => 'password',
                    'value' => '',
                    'autocomplete' => 'new-password',
                    'label' => 'Email password/API secret',
                ]) ?>
                <?= $this->Form->control('storage_secret_value', [
                    'type' => 'password',
                    'value' => '',
                    'autocomplete' => 'new-password',
                    'label' => 'Storage secret',
                ]) ?>
            </div>
            <div class="col-md-4">
                <?= $this->Form->control('storage_adapter', [
                    'options' => ['' => 'Keep existing', 'local' => 'local', 'azure' => 'azure', 's3' => 's3'],
                ]) ?>
                <?= $this->Form->control('verify_password', ['type' => 'password', 'required' => true]) ?>
            </div>
            <div class="col-md-4">
                <?= $this->Form->control('verify_mfa_code', [
                    'label' => 'One-time action code',
                    'required' => true,
                    'help' => 'Use an unused code; each login and high-risk action consumes one code.',
                ]) ?>
            </div>
        </div>
        <?= $this->Form->button('Update Managed Secrets', ['class' => 'btn btn-warning']) ?>
        <?= $this->Form->end() ?>
    </div>
</div>
<h2 class="h4">Backups</h2>
<div class="card mb-4">
    <div class="card-body">
        <?= $this->Form->create(null, ['url' => ['action' => 'createBackup', $tenant->slug]]) ?>
        <div class="row">
            <div class="col-md-4">
                <?= $this->Form->control('backup_key', ['type' => 'password', 'required' => true]) ?>
            </div>
            <div class="col-md-4">
                <?= $this->Form->control('verify_password', ['type' => 'password', 'required' => true]) ?>
            </div>
            <div class="col-md-4">
                <?= $this->Form->control('verify_mfa_code', [
                    'label' => 'One-time action code',
                    'required' => true,
                    'help' => 'Use an unused code; each login and high-risk action consumes one code.',
                ]) ?>
            </div>
        </div>
        <?= $this->Form->button('Create Encrypted Backup', ['class' => 'btn btn-danger']) ?>
        <?= $this->Form->end() ?>
    </div>
    <table class="table table-sm mb-0">
        <thead><tr><th>Filename</th><th>Status</th><th>Rows</th><th>Created</th></tr></thead>
        <?php foreach ($backups as $backup) : ?>
            <tr>
                <td><?= h($backup->filename) ?></td>
                <td><?= h($backup->status) ?></td>
                <td><?= h($backup->row_count) ?></td>
                <td><?= h($backup->created) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
</div>
<h2 class="h4">Restore</h2>
<div class="card border-danger mb-4">
    <div class="card-body">
        <p class="text-danger">
            Restore always imports into a new database and cuts over only after validation succeeds.
            Do not reuse the current tenant database name.
        </p>
        <?= $this->Form->create(null, [
            'url' => ['action' => 'restoreBackup', $tenant->slug],
            'type' => 'file',
        ]) ?>
        <div class="row">
            <div class="col-md-4">
                <?= $this->Form->control('backup_file', ['type' => 'file', 'required' => true]) ?>
                <?= $this->Form->control('new_database_name', ['required' => true]) ?>
                <?= $this->Form->control('restore_key', ['type' => 'password', 'required' => true]) ?>
            </div>
            <div class="col-md-4">
                <?= $this->Form->control('verify_password', ['type' => 'password', 'required' => true]) ?>
            </div>
            <div class="col-md-4">
                <?= $this->Form->control('verify_mfa_code', [
                    'label' => 'One-time action code',
                    'required' => true,
                    'help' => 'Use an unused code; each login and high-risk action consumes one code.',
                ]) ?>
            </div>
        </div>
        <?= $this->Form->button('Restore and Cut Over', ['class' => 'btn btn-danger']) ?>
        <?= $this->Form->end() ?>
    </div>
</div>
<h2 class="h4">Status Actions</h2>
<div class="row">
    <?php foreach (['active', 'maintenance', 'disabled'] as $status) : ?>
        <div class="col-md-4">
            <div class="card mb-3">
                <div class="card-body">
                    <h3 class="h6">Set <?= h($status) ?></h3>
                    <?= $this->Form->create(null, ['url' => ['action' => 'setTenantStatus', $tenant->slug, $status]]) ?>
                    <?= $this->Form->control('verify_password', ['type' => 'password', 'required' => true]) ?>
                    <?= $this->Form->control('verify_mfa_code', [
                        'label' => 'One-time action code',
                        'required' => true,
                        'help' => 'Use an unused code; each login and high-risk action consumes one code.',
                    ]) ?>
                    <?= $this->Form->button('Apply', ['class' => 'btn btn-outline-danger']) ?>
                    <?= $this->Form->end() ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<h2 class="h4 mt-4">Recent Operations</h2>
<table class="table table-sm">
    <?php foreach ($jobs as $job) : ?>
        <tr><td><?= h($job->operation) ?></td><td><?= h($job->status) ?></td><td><?= h($job->created) ?></td></tr>
    <?php endforeach; ?>
</table>
