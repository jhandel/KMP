<?php
/**
 * @var \Cake\View\View $this
 */
?>
<h1>Create Tenant</h1>
<div class="alert alert-warning">
    <strong>Before you submit:</strong> tenant creation/update changes routing and runtime metadata in the platform
    datastore. Use this page for planned onboarding or metadata repair, not for ad hoc SQL access. If you are unsure
    whether this is a tenant app issue or platform registry issue, run tenant doctor from the tenant detail page first.
</div>
<div class="card mb-3">
    <div class="card-body">
        <p class="text-muted mb-2">
            Request an emailed action verification code before submitting this high-risk operation.
            The code expires after 10 minutes and sensitive actions require a sign-in from the last 15 minutes.
        </p>
        <?= $this->Form->create(null, ['url' => ['action' => 'requestActionCode']]) ?>
        <?= $this->Form->hidden('action_label', ['value' => 'Create or update tenant']) ?>
        <?= $this->Form->button('Email action verification code', ['class' => 'btn btn-outline-primary']) ?>
        <?= $this->Form->end() ?>
    </div>
</div>
<?= $this->Form->create(null) ?>
<div class="row">
    <div class="col-md-6">
        <h2 class="h5">Identity and routing</h2>
        <?= $this->Form->control('slug', [
            'required' => true,
            'help' => 'Stable lowercase tenant identifier used in platform metadata and storage paths.',
        ]) ?>
        <?= $this->Form->control('display_name', [
            'required' => true,
            'help' => 'Human-readable tenant name shown in platform admin.',
        ]) ?>
        <?= $this->Form->control('primary_host', [
            'required' => true,
            'help' => 'Canonical hostname used to resolve requests to this tenant.',
        ]) ?>
        <?= $this->Form->control('aliases', [
            'type' => 'textarea',
            'label' => 'Host aliases, one per line',
            'help' => 'Optional extra hostnames that should resolve to this same tenant.',
        ]) ?>
        <h2 class="h5 mt-4">Tenant database</h2>
        <?= $this->Form->control('driver', [
            'default' => 'Cake\Database\Driver\Mysql',
            'help' => 'Use the driver that matches the tenant database engine, for example Cake\Database\Driver\Postgres on Azure PostgreSQL Flex.',
        ]) ?>
        <?= $this->Form->control('database_host', [
            'required' => true,
            'help' => 'Database server hostname reachable from the app and operation worker.',
        ]) ?>
        <?= $this->Form->control('database_port', [
            'help' => 'Leave blank to use the driver default port.',
        ]) ?>
        <?= $this->Form->control('database_name', [
            'required' => true,
            'help' => 'Tenant application database name. Do not use the platform database.',
        ]) ?>
        <?= $this->Form->control('database_username', [
            'help' => 'Database principal used by this tenant runtime.',
        ]) ?>
        <?= $this->Form->control('database_secret_reference', [
            'help' => 'Existing reference such as env:TENANT_DB_PASSWORD or managed:tenant/<id>/database/password.',
        ]) ?>
        <?= $this->Form->control('database_secret_value', [
            'type' => 'password',
            'value' => '',
            'autocomplete' => 'new-password',
            'label' => 'Database secret value',
            'help' => 'If provided, stored encrypted as a managed platform secret. Raw values are never displayed after saving.',
        ]) ?>
    </div>
    <div class="col-md-6">
        <h2 class="h5">Tenant email</h2>
        <?= $this->Form->control('email_config_json', [
            'type' => 'textarea',
            'label' => 'Tenant email config JSON',
            'help' => 'JSON metadata merged into this tenant email transport/config; keep secrets out of JSON.',
        ]) ?>
        <?= $this->Form->control('email_secret_reference', [
            'help' => 'Optional email password/API secret reference. Use env: or managed: references.',
        ]) ?>
        <?= $this->Form->control('email_secret_value', [
            'type' => 'password',
            'value' => '',
            'autocomplete' => 'new-password',
            'label' => 'Email secret value',
            'help' => 'If provided, stored encrypted and referenced from tenant email config.',
        ]) ?>
        <h2 class="h5 mt-4">Tenant storage</h2>
        <?= $this->Form->control('storage_adapter', [
            'options' => ['' => '', 'local' => 'local', 'azure' => 'azure', 's3' => 's3'],
            'help' => 'Choose the document storage adapter for this tenant.',
        ]) ?>
        <?= $this->Form->control('storage_config_json', [
            'type' => 'textarea',
            'label' => 'Tenant storage config JSON',
            'help' => 'Adapter metadata only. Store connection strings or keys as secret references.',
        ]) ?>
        <?= $this->Form->control('storage_secret_reference', [
            'help' => 'Optional storage credential reference. Use env: or managed: references.',
        ]) ?>
        <?= $this->Form->control('storage_secret_value', [
            'type' => 'password',
            'value' => '',
            'autocomplete' => 'new-password',
            'label' => 'Storage secret value',
            'help' => 'If provided, stored encrypted for the selected storage adapter.',
        ]) ?>
        <h2 class="h5 mt-4">Provisioning options</h2>
        <?= $this->Form->control('create_database', [
            'type' => 'checkbox',
            'help' => 'Create the tenant database before writing registry metadata when supported by the configured driver/user.',
        ]) ?>
        <?= $this->Form->control('migrate', [
            'type' => 'checkbox',
            'checked' => true,
            'help' => 'Run tenant migrations as part of provisioning so schema starts at the target version.',
        ]) ?>
        <?= $this->Form->control('activate', [
            'type' => 'checkbox',
            'checked' => true,
            'help' => 'Mark the tenant active after provisioning. Leave unchecked for staged onboarding.',
        ]) ?>
        <hr>
        <h2 class="h5">Step-up verification</h2>
        <p class="small text-muted">
            Use the same action label/code requested above. If your sign-in is older than 15 minutes, sign in again
            before requesting a new code.
        </p>
        <?= $this->Form->control('verify_password', [
            'type' => 'password',
            'required' => true,
            'help' => 'Current platform admin password, not a tenant member password.',
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
    </div>
</div>
<?= $this->Form->button('Queue Tenant Create/Update', ['class' => 'btn btn-danger']) ?>
<?= $this->Form->end() ?>
