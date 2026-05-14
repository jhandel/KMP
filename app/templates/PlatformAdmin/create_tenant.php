<?php
/**
 * @var \Cake\View\View $this
 */
?>
<h1>Create Tenant</h1>
<div class="alert alert-warning">
    Tenant creation is a privileged platform operation. Use either existing secret references like
    <code>env:TENANT_SMTP_PASSWORD</code> or enter secret values to store encrypted platform-managed references.
    Raw secret values are never displayed after saving.
</div>
<?= $this->Form->create(null) ?>
<div class="row">
    <div class="col-md-6">
        <?= $this->Form->control('slug', ['required' => true]) ?>
        <?= $this->Form->control('display_name', ['required' => true]) ?>
        <?= $this->Form->control('primary_host', ['required' => true]) ?>
        <?= $this->Form->control('aliases', ['type' => 'textarea', 'label' => 'Host aliases, one per line']) ?>
        <?= $this->Form->control('driver', ['default' => 'Cake\Database\Driver\Mysql']) ?>
        <?= $this->Form->control('database_host', ['required' => true]) ?>
        <?= $this->Form->control('database_port') ?>
        <?= $this->Form->control('database_name', ['required' => true]) ?>
        <?= $this->Form->control('database_username') ?>
        <?= $this->Form->control('database_secret_reference') ?>
        <?= $this->Form->control('database_secret_value', [
            'type' => 'password',
            'value' => '',
            'autocomplete' => 'new-password',
            'label' => 'Database secret value',
            'help' => 'Stored encrypted as a managed platform secret if provided.',
        ]) ?>
    </div>
    <div class="col-md-6">
        <?= $this->Form->control('email_config_json', ['type' => 'textarea', 'label' => 'Tenant email config JSON']) ?>
        <?= $this->Form->control('email_secret_reference') ?>
        <?= $this->Form->control('email_secret_value', [
            'type' => 'password',
            'value' => '',
            'autocomplete' => 'new-password',
            'label' => 'Email secret value',
        ]) ?>
        <?= $this->Form->control('storage_adapter', [
            'options' => ['' => '', 'local' => 'local', 'azure' => 'azure', 's3' => 's3'],
        ]) ?>
        <?= $this->Form->control('storage_config_json', [
            'type' => 'textarea',
            'label' => 'Tenant storage config JSON',
        ]) ?>
        <?= $this->Form->control('storage_secret_reference') ?>
        <?= $this->Form->control('storage_secret_value', [
            'type' => 'password',
            'value' => '',
            'autocomplete' => 'new-password',
            'label' => 'Storage secret value',
        ]) ?>
        <?= $this->Form->control('create_database', ['type' => 'checkbox']) ?>
        <?= $this->Form->control('migrate', ['type' => 'checkbox', 'checked' => true]) ?>
        <?= $this->Form->control('activate', ['type' => 'checkbox', 'checked' => true]) ?>
        <hr>
        <?= $this->Form->control('verify_password', ['type' => 'password', 'required' => true]) ?>
        <?= $this->Form->control('verify_mfa_code', [
            'label' => 'One-time action verification code',
            'required' => true,
            'help' => 'Use an unused code; each login and high-risk action consumes one code.',
        ]) ?>
    </div>
</div>
<?= $this->Form->button('Create Tenant', ['class' => 'btn btn-danger']) ?>
<?= $this->Form->end() ?>
