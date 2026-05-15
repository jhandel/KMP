<?php
/**
 * @var \Cake\View\View $this
 * @var \App\Model\Entity\Tenant $tenant
 * @var array<string, array<string, mixed>> $doctor
 * @var array<string, array<string, mixed>> $doctorFindings
 * @var array<int, \App\Model\Entity\TenantOperationJob> $jobs
 * @var array<int, array<string, mixed>> $operationRows
 * @var array<string, string> $operationStateOptions
 * @var array<string, string> $operationSortOptions
 * @var array<string, string> $operationTenantOptions
 * @var array{state: string, tenant: string, sort: string, limit: int, correlation: string} $filters
 * @var array<int, \App\Model\Entity\Backup> $backups
 * @var array<string, bool> $capabilities
 * @var array<int, array<string, mixed>> $secretRotationRows
 */
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1><?= h($tenant->display_name) ?></h1>
    <span class="badge text-bg-secondary"><?= h($tenant->status) ?></span>
</div>
<div class="alert alert-info">
    This page is the tenant operations runbook. Start with Doctor, check active operations before changing state or
    secrets, and use the audit/queue correlation ID for incident notes. Action codes expire after 10 minutes, and
    sensitive actions require a sign-in from the last 15 minutes.
</div>
<dl class="row">
    <dt class="col-sm-3">Slug</dt><dd class="col-sm-9"><?= h($tenant->slug) ?></dd>
    <dt class="col-sm-3">Primary host</dt><dd class="col-sm-9"><?= h($tenant->primary_host) ?></dd>
    <dt class="col-sm-3">Schema version</dt><dd class="col-sm-9"><?= h($tenant->schema_version) ?></dd>
</dl>
<h2 class="h4">Doctor</h2>
<p class="text-muted">
    Doctor summarizes tenant readiness from platform metadata and tenant checks. Automated remediation queues catalog
    operations; it does not run inline in the browser request.
</p>
<div class="card mb-4">
    <table class="table table-sm mb-0 align-middle">
        <thead>
            <tr>
                <th>Finding</th>
                <th>Status</th>
                <th>Message</th>
                <th>Remediation guidance</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($doctorFindings as $name => $finding) : ?>
            <?php
            $actions = (array)($finding['actions'] ?? []);
            $statusOk = !empty($finding['ok']);
            ?>
            <tr>
                <th><?= h($name) ?></th>
                <td><?= $statusOk ? 'OK' : 'Needs attention' ?></td>
                <td><?= h($finding['message'] ?? '') ?></td>
                <td><?= h($finding['remediation_guidance'] ?? '') ?></td>
                <td class="small">
                    <?php if ($actions === []) : ?>
                        <span class="text-muted">No automated remediation.</span>
                    <?php else : ?>
                        <?php foreach ($actions as $action) : ?>
                            <?php
                            $policy = (array)($action['approval_policy'] ?? []);
                            $requiredApprovals = (int)($policy['required_approvals'] ?? 0);
                            $mode = (string)($policy['mode'] ?? 'none');
                            ?>
                            <div class="mb-3">
                                <div class="fw-semibold"><?= h($action['label'] ?? '') ?></div>
                                <div class="text-muted mb-1">
                                    Operation: <code><?= h($action['operation'] ?? '') ?></code>
                                    · Approval: <?= $requiredApprovals > 0 ? h($mode . ' (' . $requiredApprovals . ')') : 'none' ?>
                                </div>
                                <div class="small text-muted mb-2">
                                    Request the remediation code, then submit with your current platform admin password.
                                    Additional approval may still be required before a worker runs the job.
                                </div>
                                <?php if (!empty($action['enabled'])) : ?>
                                    <?= $this->Form->create(null, ['url' => ['action' => 'requestActionCode']]) ?>
                                    <?= $this->Form->hidden(
                                        'action_label',
                                        ['value' => (string)($action['action_label'] ?? '')],
                                    ) ?>
                                    <?= $this->Form->button(
                                        'Email remediation code',
                                        ['class' => 'btn btn-outline-primary btn-sm mb-2'],
                                    ) ?>
                                    <?= $this->Form->end() ?>
                                    <?= $this->Form->create(null, ['url' => [
                                        'action' => 'remediateDoctorFinding',
                                        $tenant->slug,
                                        $name,
                                        (string)($action['id'] ?? ''),
                                    ]]) ?>
                                    <?= $this->Form->control('verify_password', [
                                        'type' => 'password',
                                        'required' => true,
                                        'label' => 'Verify password',
                                    ]) ?>
                                    <?= $this->Form->control('verify_email_code', [
                                        'label' => 'Email action verification code',
                                        'required' => true,
                                        'autocomplete' => 'one-time-code',
                                        'inputmode' => 'numeric',
                                        'pattern' => '[0-9]*',
                                        'maxlength' => 6,
                                    ]) ?>
                                    <?= $this->Form->button('Queue remediation', ['class' => 'btn btn-warning btn-sm']) ?>
                                    <?= $this->Form->end() ?>
                                <?php else : ?>
                                    <span class="text-muted">
                                        <?= h($action['reason'] ?? 'Action unavailable for current role.') ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php if (!empty($capabilities['manage_secrets'])) : ?>
    <h2 class="h4">Managed Secrets</h2>
    <div class="card mb-4">
        <div class="card-body">
        <div class="alert alert-warning">
            <strong>Break-glass only:</strong> managed secret updates require the break-glass role, a fresh sign-in,
            current password, and an emailed action code. Existing secret values are never displayed.
            Database password changes are saved as a new managed reference and then queued for worker verification;
            saving this form does not mean the tenant has already cut over to the new database credential.
        </div>
            <?= $this->Form->create(null, ['url' => ['action' => 'requestActionCode']]) ?>
            <?= $this->Form->hidden('action_label', ['value' => 'Update managed secrets for tenant ' . $tenant->slug]) ?>
            <?= $this->Form->button('Email secrets update code', ['class' => 'btn btn-outline-primary mb-3']) ?>
            <?= $this->Form->end() ?>
            <?= $this->Form->create(null, ['url' => ['action' => 'updateTenantSecrets', $tenant->slug]]) ?>
            <div class="row">
                <div class="col-md-4">
                    <?= $this->Form->control('database_secret_value', [
                        'type' => 'password',
                        'value' => '',
                        'autocomplete' => 'new-password',
                        'label' => 'Database password',
                        'help' => 'Queues tenant_rotate_db_secret for verification and rollback protection.',
                    ]) ?>
                    <?= $this->Form->control('email_secret_value', [
                        'type' => 'password',
                        'value' => '',
                        'autocomplete' => 'new-password',
                        'label' => 'Email password/API secret',
                        'help' => 'Updates the tenant email secret reference after save.',
                    ]) ?>
                    <?= $this->Form->control('storage_secret_value', [
                        'type' => 'password',
                        'value' => '',
                        'autocomplete' => 'new-password',
                        'label' => 'Storage secret',
                        'help' => 'Updates the tenant storage secret reference after save.',
                    ]) ?>
                </div>
                <div class="col-md-4">
                    <?= $this->Form->control('storage_adapter', [
                        'options' => ['' => 'Keep existing', 'local' => 'local', 'azure' => 'azure', 's3' => 's3'],
                        'help' => 'Leave as Keep existing unless changing the storage backend metadata.',
                    ]) ?>
                    <?= $this->Form->control('verify_password', [
                        'type' => 'password',
                        'required' => true,
                        'help' => 'Current platform admin password.',
                    ]) ?>
                </div>
                <div class="col-md-4">
                    <?= $this->Form->control('verify_email_code', [
                        'label' => 'Email action verification code',
                        'required' => true,
                        'autocomplete' => 'one-time-code',
                        'inputmode' => 'numeric',
                        'pattern' => '[0-9]*',
                        'maxlength' => 6,
                        'help' => 'Use the 6-digit code emailed to your platform admin address.',
                    ]) ?>
                </div>
            </div>
        <?= $this->Form->button('Update Managed Secrets / Queue DB Rotation', ['class' => 'btn btn-warning']) ?>
        <?= $this->Form->end() ?>
        </div>
    </div>
<?php endif; ?>
<?php if (!empty($capabilities['manage_recovery'])) : ?>
<h2 class="h4">Backups</h2>
<div class="card mb-4">
    <div class="card-body">
        <div class="alert alert-light border">
            Backups are queued operation jobs. Keep the encryption key outside KMP; losing it means the backup cannot
            be restored from this UI.
        </div>
        <?= $this->Form->create(null, ['url' => ['action' => 'requestActionCode']]) ?>
        <?= $this->Form->hidden('action_label', ['value' => 'Create backup for tenant ' . $tenant->slug]) ?>
        <?= $this->Form->button('Email backup verification code', ['class' => 'btn btn-outline-primary mb-3']) ?>
        <?= $this->Form->end() ?>
        <?= $this->Form->create(null, ['url' => ['action' => 'createBackup', $tenant->slug]]) ?>
        <div class="row">
            <div class="col-md-4">
                <?= $this->Form->control('backup_key', [
                    'type' => 'password',
                    'required' => true,
                    'help' => 'Encryption key used for this backup. Store it securely before queueing.',
                ]) ?>
            </div>
            <div class="col-md-4">
                <?= $this->Form->control('verify_password', [
                    'type' => 'password',
                    'required' => true,
                    'help' => 'Current platform admin password.',
                ]) ?>
            </div>
            <div class="col-md-4">
                <?= $this->Form->control('verify_email_code', [
                    'label' => 'Email action verification code',
                    'required' => true,
                    'autocomplete' => 'one-time-code',
                    'inputmode' => 'numeric',
                    'pattern' => '[0-9]*',
                    'maxlength' => 6,
                    'help' => 'Use the 6-digit code emailed to your platform admin address.',
                ]) ?>
            </div>
        </div>
        <?= $this->Form->button('Queue Encrypted Backup', ['class' => 'btn btn-danger']) ?>
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
        <div class="alert alert-danger">
            <strong>Break-glass restore:</strong> restore always imports into a new database and cuts over only after
            validation succeeds. Do not reuse the current tenant database name, any database already registered to a
            tenant, or a restore target name used in the last 30 days.
        </div>
        <?= $this->Form->create(null, ['url' => ['action' => 'requestActionCode']]) ?>
        <?= $this->Form->hidden('action_label', ['value' => 'Restore backup for tenant ' . $tenant->slug]) ?>
        <?= $this->Form->button('Email restore verification code', ['class' => 'btn btn-outline-primary mb-3']) ?>
        <?= $this->Form->end() ?>
        <?= $this->Form->create(null, [
            'url' => ['action' => 'restoreBackup', $tenant->slug],
            'type' => 'file',
        ]) ?>
        <div class="row">
            <div class="col-md-4">
                <?= $this->Form->control('backup_file', [
                    'type' => 'file',
                    'required' => true,
                    'help' => 'Encrypted backup file to import into the new tenant database.',
                ]) ?>
                <?= $this->Form->control('new_database_name', [
                    'required' => true,
                    'help' => 'Alphanumeric/underscore database name. Must be unique and not recently used for restore.',
                ]) ?>
                <?= $this->Form->control('restore_key', [
                    'type' => 'password',
                    'required' => true,
                    'help' => 'Encryption key for the uploaded backup.',
                ]) ?>
            </div>
            <div class="col-md-4">
                <?= $this->Form->control('verify_password', [
                    'type' => 'password',
                    'required' => true,
                    'help' => 'Current platform admin password.',
                ]) ?>
            </div>
            <div class="col-md-4">
                <?= $this->Form->control('verify_email_code', [
                    'label' => 'Email action verification code',
                    'required' => true,
                    'autocomplete' => 'one-time-code',
                    'inputmode' => 'numeric',
                    'pattern' => '[0-9]*',
                    'maxlength' => 6,
                    'help' => 'Use the 6-digit code emailed to your platform admin address.',
                ]) ?>
            </div>
        </div>
        <?= $this->Form->button('Queue Restore and Cut Over', ['class' => 'btn btn-danger']) ?>
        <?= $this->Form->end() ?>
    </div>
</div>
<?php endif; ?>
<h2 class="h4">Secret Rotation Verification</h2>
<p class="text-muted">
    Rotation confidence checks summarize recent database secret rotations using worker state plus linked audit events.
    Secret values are never shown.
    <?= $this->Html->link('JSON API', ['action' => 'secretRotationStatus', $tenant->slug, '_ext' => 'json']) ?>.
</p>
<div class="card mb-4">
    <div class="table-responsive">
        <table class="table table-sm mb-0">
            <thead>
                <tr>
                    <th>Queued</th>
                    <th>Verification</th>
                    <th>Confidence</th>
                    <th>Actor</th>
                    <th>Correlation</th>
                    <th>Scope</th>
                    <th>Guidance</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($secretRotationRows as $row) : ?>
                    <?php
                    $status = (string)($row['verification_status'] ?? '');
                    $statusBadge = match ($status) {
                        'success' => 'text-bg-success',
                        'rollback' => 'text-bg-warning',
                        'failure' => 'text-bg-danger',
                        default => 'text-bg-secondary',
                    };
                    $confidence = (string)($row['confidence'] ?? 'low');
                    $confidenceBadge = match ($confidence) {
                        'high' => 'text-bg-success',
                        'medium' => 'text-bg-warning',
                        default => 'text-bg-secondary',
                    };
    ?>
                    <tr>
                        <td>
                            <?= h((string)($row['queued_at'] ?? '-')) ?>
                            <?php if (!empty($row['operation_id'])) : ?>
                                <div class="small text-muted">#<?= h((string)$row['operation_id']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge <?= h($statusBadge) ?>"><?= h(ucwords(str_replace('_', ' ', $status))) ?></span></td>
                        <td><span class="badge <?= h($confidenceBadge) ?>"><?= h(strtoupper($confidence)) ?></span></td>
                        <td><?= h((string)($row['actor'] ?? '-')) ?></td>
                        <td>
                            <?php if (!empty($row['correlation_id'])) : ?>
                                <code><?= h((string)$row['correlation_id']) ?></code>
                            <?php else : ?>
                                -
                            <?php endif; ?>
                        </td>
                        <td><?= h(implode(', ', (array)($row['affected_scope'] ?? []))) ?></td>
                        <td>
                            <?= h((string)($row['next_steps'] ?? '')) ?>
                            <?php if (!empty($row['error_summary'])) : ?>
                                <div class="small text-danger"><?= h((string)$row['error_summary']) ?></div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($secretRotationRows === []) : ?>
                    <tr><td colspan="7" class="text-center text-muted py-3">No recent database secret rotation jobs were found for this tenant.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<h2 class="h4">Status Actions</h2>
<div class="alert alert-light border">
    Queue submissions run command-catalog preflight checks for target mode, parameters, and role capability.
    <strong>Status meanings:</strong> active serves traffic; draining should be temporary during cutover/maintenance;
    maintenance blocks normal use for planned work; disabled stops tenant access.
    <?= $this->Html->link('Review command catalog', ['action' => 'commandCatalog']) ?> before submitting changes.
</div>
<?php if (!empty($capabilities['operate_tenants'])) : ?>
    <div class="row">
        <?php foreach (['active', 'draining', 'maintenance', 'disabled'] as $status) : ?>
            <div class="col-md-4">
                <div class="card mb-3">
                    <div class="card-body">
                        <h3 class="h6">Set <?= h($status) ?></h3>
                        <?= $this->Form->create(null, ['url' => ['action' => 'requestActionCode']]) ?>
                        <?= $this->Form->hidden(
                            'action_label',
                            ['value' => 'Set tenant ' . $tenant->slug . ' status to ' . $status],
                        ) ?>
                        <?= $this->Form->button('Email status code', ['class' => 'btn btn-outline-primary btn-sm mb-2']) ?>
                        <?= $this->Form->end() ?>
                        <?= $this->Form->create(null, ['url' => ['action' => 'setTenantStatus', $tenant->slug, $status]]) ?>
                        <?= $this->Form->control('verify_password', [
                            'type' => 'password',
                            'required' => true,
                            'help' => 'Current platform admin password.',
                        ]) ?>
                        <?= $this->Form->control('verify_email_code', [
                            'label' => 'Email action verification code',
                            'required' => true,
                            'autocomplete' => 'one-time-code',
                            'inputmode' => 'numeric',
                            'pattern' => '[0-9]*',
                            'maxlength' => 6,
                            'help' => 'Use the 6-digit code emailed to your platform admin address.',
                        ]) ?>
                        <?= $this->Form->button('Queue Status Change', ['class' => 'btn btn-outline-danger']) ?>
                        <?= $this->Form->end() ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<h2 class="h4 mt-4">Operation Queue</h2>
<p class="text-muted">
    This queue is scoped to <?= h($tenant->slug) ?>. Use it to confirm whether requested changes are waiting for
    approval, worker execution, retry, or stale-lock recovery.
</p>
<?= $this->element('PlatformAdmin/operation_queue', [
    'operationRows' => $operationRows,
    'operationStateOptions' => $operationStateOptions,
    'operationSortOptions' => $operationSortOptions,
    'operationTenantOptions' => $operationTenantOptions,
    'filters' => $filters,
    'capabilities' => $capabilities,
]) ?>
