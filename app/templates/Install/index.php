<?php

/**
 * @var \App\View\AppView $this
 * @var array<int, array{label:string,ok:bool,detail:string}> $preflightChecks
 * @var array<string, string> $defaults
 * @var string $currentBannerLogo
 * @var string $currentStep
 * @var array<int, array{key:string,label:string,index:int}> $wizardSteps
 */
$this->extend('/layout/TwitterBootstrap/signin');

echo $this->KMP->startBlock('title');
echo 'KMP Installer';
$this->KMP->endBlock();

$stepIndex = 0;
foreach ($wizardSteps as $idx => $step) {
    if ($step['key'] === $currentStep) {
        $stepIndex = $idx;
        break;
    }
}

$totalSteps = count($wizardSteps);
$stepIcons  = [
    'preflight'      => 'bi-shield-check',
    'database'       => 'bi-database',
    'communications' => 'bi-envelope',
    'branding'       => 'bi-palette',
];
?>

<style>
/* ── Layout reset for installer (signin layout centres everything) ── */
body.Install.index {
    align-items: stretch !important;
    padding-top: 0 !important;
    background: #eef2f7;
}

/* ── Outer shell ── */
.kmp-installer {
    width: 100%;
    max-width: 860px;
    margin: 2.5rem auto 3rem;
    padding: 0 1rem;
}

/* ── Header banner ── */
.kmp-header {
    background: linear-gradient(135deg, #0d47a1 0%, #1565c0 55%, #1976d2 100%);
    border-radius: 12px 12px 0 0;
    padding: 2rem 2rem 0;
    color: #fff;
    box-shadow: 0 4px 20px rgba(13,71,161,.22);
}
.kmp-header h1 {
    font-size: 1.5rem;
    font-weight: 700;
    margin: 0 0 .3rem;
    letter-spacing: -.02em;
}
.kmp-header p {
    opacity: .82;
    font-size: .9rem;
    margin: 0 0 1.25rem;
}

/* ── Step track ── */
.kmp-track {
    display: flex;
    position: relative;
    padding-bottom: 0;
}
.kmp-track::before {
    content: '';
    position: absolute;
    top: 19px;
    left: calc(100% / <?= $totalSteps ?> / 2);
    right: calc(100% / <?= $totalSteps ?> / 2);
    height: 2px;
    background: rgba(255,255,255,.2);
}
.kmp-track-step {
    flex: 1;
    text-align: center;
    position: relative;
}
.kmp-track-step a { text-decoration: none; color: inherit; }
.kmp-bubble {
    width: 38px;
    height: 38px;
    border-radius: 50%;
    border: 2px solid rgba(255,255,255,.35);
    background: rgba(255,255,255,.12);
    color: #fff;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: .95rem;
    transition: background .2s, border-color .2s, box-shadow .2s;
}
.kmp-track-step.done   .kmp-bubble { background: #43a047; border-color: #43a047; }
.kmp-track-step.active .kmp-bubble { background: #fff; border-color: #fff; color: #1565c0; box-shadow: 0 0 0 5px rgba(255,255,255,.2); }
.kmp-track-label {
    display: block;
    font-size: .7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .05em;
    margin-top: .3rem;
    margin-bottom: .5rem;
    opacity: .65;
}
.kmp-track-step.active .kmp-track-label { opacity: 1; }
.kmp-track-step.done   .kmp-track-label { opacity: .8; }

/* ── Step-indicator bottom bar (active step coloured) ── */
.kmp-track-step .kmp-tab-bar {
    height: 3px;
    background: transparent;
    margin-top: .15rem;
}
.kmp-track-step.active .kmp-tab-bar { background: #fff; border-radius: 3px 3px 0 0; }

/* ── Content card ── */
.kmp-card {
    background: #fff;
    border-radius: 0 0 10px 10px;
    box-shadow: 0 4px 20px rgba(13,71,161,.1);
    overflow: hidden;
}
.kmp-card-header {
    padding: 1rem 1.75rem;
    background: #f5f8fc;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    gap: .65rem;
}
.kmp-card-header-icon {
    width: 32px; height: 32px;
    border-radius: 8px;
    background: #dbeafe;
    color: #1d4ed8;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 1rem;
    flex-shrink: 0;
}
.kmp-card-header h2 {
    font-size: 1rem;
    font-weight: 700;
    margin: 0;
    color: #1e293b;
}
.kmp-card-footer {
    padding: .9rem 1.75rem;
    background: #f5f8fc;
    border-top: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: .75rem;
}

/* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
   FIELD TABLE  –  the core "table-structured" layout
   ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */
.kmp-field-table {
    width: 100%;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    overflow: hidden;
    margin-bottom: 1.25rem;
}
.kmp-field-row {
    display: grid;
    grid-template-columns: 200px 1fr;
    border-bottom: 1px solid #e8edf4;
    min-height: 52px;
}
.kmp-field-row:last-child { border-bottom: none; }
.kmp-field-row:nth-child(even) { background: #fafbfd; }

.kmp-field-label {
    padding: .65rem 1rem .65rem 1.1rem;
    font-weight: 600;
    font-size: .83rem;
    color: #374151;
    background: #f5f8fc;
    border-right: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    line-height: 1.3;
}
.kmp-field-label .bi {
    font-size: .85rem;
    color: #6b7280;
    margin-right: .35rem;
    flex-shrink: 0;
}
.kmp-field-label .req {
    color: #ef4444;
    margin-left: .15rem;
}

.kmp-field-input {
    padding: .45rem .85rem;
    display: flex;
    align-items: center;
    gap: .5rem;
    flex-wrap: wrap;
}
.kmp-field-input .form-control,
.kmp-field-input .form-select {
    border: 1px solid #d1d9e0;
    border-radius: 6px;
    font-size: .875rem;
    padding: .36rem .65rem;
    background: #fff;
    transition: border-color .15s, box-shadow .15s;
    min-width: 0;
    flex: 1;
}
.kmp-field-input .form-control:focus,
.kmp-field-input .form-select:focus {
    border-color: #2563eb;
    box-shadow: 0 0 0 3px rgba(37,99,235,.15);
    outline: none;
}
/* Port / short inputs */
.kmp-field-input .input-short { max-width: 90px; flex: 0 0 90px; }
.kmp-field-input .input-medium { max-width: 180px; flex: 0 0 180px; }

/* Inline label inside multi-input cells */
.kmp-field-input .sub-label {
    font-size: .75rem;
    font-weight: 600;
    color: #6b7280;
    white-space: nowrap;
    flex-shrink: 0;
}

/* Checkbox rows */
.kmp-field-row.kmp-check-row .kmp-field-input {
    align-items: center;
}
.kmp-field-row.kmp-check-row .form-check {
    margin: 0;
    display: flex;
    align-items: center;
    gap: .4rem;
}
.kmp-field-row.kmp-check-row .form-check-label {
    font-size: .875rem;
    color: #374151;
    margin-bottom: 0;
}

/* Section heading above a table */
.kmp-section-title {
    font-size: .78rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: #64748b;
    padding: 1.1rem 1.75rem .4rem;
    display: flex;
    align-items: center;
    gap: .4rem;
}
.kmp-section-title .bi { color: #2563eb; }

/* ── Preflight table ── */
.kmp-preflight-table {
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    overflow: hidden;
    margin: 0;
}
.kmp-preflight-table thead th {
    background: #f5f8fc;
    border-bottom: 2px solid #e2e8f0;
    font-size: .75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: #64748b;
    padding: .7rem 1.1rem;
}
.kmp-preflight-table td {
    vertical-align: middle;
    padding: .65rem 1.1rem;
    font-size: .875rem;
    border-bottom: 1px solid #e8edf4;
}
.kmp-preflight-table tr:last-child td { border-bottom: none; }
.kmp-preflight-table .pass-row td { background: #f0fdf4; }
.kmp-preflight-table .fail-row td { background: #fff1f2; }
.kmp-preflight-table td.check-name { font-weight: 600; color: #1e293b; }
.kmp-preflight-table td.check-detail { color: #4b5563; font-size: .83rem; }

/* ── Logo hint ── */
.logo-hint {
    background: #f8fafc;
    border: 1.5px dashed #cbd5e1;
    border-radius: 7px;
    padding: .5rem .85rem;
    font-size: .82rem;
    color: #6b7280;
    display: flex;
    align-items: center;
    gap: .35rem;
    flex: 1;
}

/* ── Buttons ── */
.btn-kmp-back {
    background: transparent;
    border: 1.5px solid #cbd5e1;
    color: #4b5563;
    font-weight: 600;
    padding: .45rem 1rem;
    border-radius: 7px;
    font-size: .875rem;
}
.btn-kmp-back:hover { border-color: #94a3b8; background: #f1f5f9; color: #1e293b; }

.btn-kmp-primary {
    background: #1d4ed8;
    border-color: #1d4ed8;
    color: #fff;
    font-weight: 600;
    padding: .45rem 1.15rem;
    border-radius: 7px;
    font-size: .875rem;
}
.btn-kmp-primary:hover { background: #1e40af; border-color: #1e40af; color: #fff; }

.btn-kmp-success {
    background: #16a34a;
    border-color: #16a34a;
    color: #fff;
    font-weight: 600;
    padding: .45rem 1.35rem;
    border-radius: 7px;
    font-size: .875rem;
}
.btn-kmp-success:hover { background: #15803d; border-color: #15803d; color: #fff; }

/* ── Responsive ── */
@media (max-width: 600px) {
    .kmp-track::before { display: none; }
    .kmp-track-label   { display: none; }
    .kmp-field-row     { grid-template-columns: 1fr; }
    .kmp-field-label   { border-right: none; border-bottom: 1px solid #e2e8f0; background: #eef2f7; }
    .kmp-card-footer   { flex-direction: column-reverse; }
    .kmp-card-footer > * { width: 100%; text-align: center; }
}
</style>

<div class="kmp-installer">

    <!-- ── Header + step track ── -->
    <div class="kmp-header">
        <h1><i class="bi bi-gear-fill me-2"></i><?= __('KMP Installer') ?></h1>
        <p><?= __('Complete each step below to configure a fresh KMP installation.') ?></p>

        <nav class="kmp-track">
            <?php foreach ($wizardSteps as $idx => $s):
                $isDone   = $idx < $stepIndex;
                $isActive = $s['key'] === $currentStep;
                $cls      = $isDone ? 'done' : ($isActive ? 'active' : '');
                $icon     = $stepIcons[$s['key']] ?? 'bi-circle';
            ?>
            <div class="kmp-track-step <?= $cls ?>">
                <a href="<?= $this->Url->build(['action' => 'index', '?' => ['step' => $s['key']]]) ?>">
                    <div class="kmp-bubble">
                        <i class="bi <?= $isDone ? 'bi-check-lg' : $icon ?>"></i>
                    </div>
                    <span class="kmp-track-label"><?= h($s['label']) ?></span>
                </a>
                <div class="kmp-tab-bar"></div>
            </div>
            <?php endforeach; ?>
        </nav>
    </div>

    <?php echo $this->Flash->render(); ?>

    <!-- ████  STEP 1: PREFLIGHT  ████ -->
    <?php if ($currentStep === 'preflight'): ?>
    <div class="kmp-card">
        <div class="kmp-card-header">
            <span class="kmp-card-header-icon"><i class="bi bi-shield-check"></i></span>
            <h2><?= __('Preflight checks') ?></h2>
        </div>
        <div style="padding:1.5rem 1.75rem">
            <table class="kmp-preflight-table w-100">
                <thead>
                    <tr>
                        <th style="width:35%"><?= __('Check') ?></th>
                        <th style="width:12%"><?= __('Result') ?></th>
                        <th><?= __('Details') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($preflightChecks as $check): ?>
                    <tr class="<?= $check['ok'] ? 'pass-row' : 'fail-row' ?>">
                        <td class="check-name"><?= h($check['label']) ?></td>
                        <td>
                            <?php if ($check['ok']): ?>
                                <span style="display:inline-flex;align-items:center;gap:.3rem;font-size:.78rem;font-weight:700;color:#15803d;background:#dcfce7;border-radius:999px;padding:.2em .65em">
                                    <i class="bi bi-check-circle-fill"></i><?= __('Pass') ?>
                                </span>
                            <?php else: ?>
                                <span style="display:inline-flex;align-items:center;gap:.3rem;font-size:.78rem;font-weight:700;color:#b91c1c;background:#fee2e2;border-radius:999px;padding:.2em .65em">
                                    <i class="bi bi-x-circle-fill"></i><?= __('Fail') ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="check-detail"><?= h($check['detail']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="kmp-card-footer justify-content-end">
            <?= $this->Form->create(null, ['url' => ['action' => 'index']]) ?>
            <?= $this->Form->hidden('current_step', ['value' => 'preflight']) ?>
            <?= $this->Form->hidden('next_step',    ['value' => 'database']) ?>
            <?= $this->Form->button(
                __('Continue to Database') . ' <i class="bi bi-arrow-right ms-1"></i>',
                ['class' => 'btn btn-kmp-primary', 'escapeTitle' => false]
            ) ?>
            <?= $this->Form->end() ?>
        </div>
    </div>

    <!-- ████  STEP 2: DATABASE  ████ -->
    <?php elseif ($currentStep === 'database'): ?>
    <div class="kmp-card">
        <div class="kmp-card-header">
            <span class="kmp-card-header-icon"><i class="bi bi-database"></i></span>
            <h2><?= __('Database configuration') ?></h2>
        </div>

        <?= $this->Form->create(null, ['url' => ['action' => 'index']]) ?>
        <?= $this->Form->hidden('current_step', ['value' => 'database']) ?>
        <?= $this->Form->hidden('next_step',    ['value' => 'communications']) ?>

        <p style="padding:1rem 1.75rem .25rem; font-size:.875rem; color:#64748b; margin:0">
            <?= __('Enter the connection details for the MySQL / MariaDB database KMP will use.') ?>
        </p>

        <!-- Connection -->
        <div class="kmp-section-title"><i class="bi bi-plug"></i><?= __('Connection') ?></div>
        <div style="padding:0 1.75rem 1rem">
            <div class="kmp-field-table">

                <!-- Host + Port on one row -->
                <div class="kmp-field-row">
                    <div class="kmp-field-label">
                        <i class="bi bi-hdd-network"></i><?= __('Host') ?><span class="req">*</span>
                    </div>
                    <div class="kmp-field-input">
                        <?= $this->Form->input('db_host', [
                            'label' => false,
                            'class' => 'form-control',
                            'required' => true,
                            'value' => $defaults['db_host'] ?? 'localhost',
                            'placeholder' => 'localhost',
                        ]) ?>
                        <span class="sub-label"><?= __('Port') ?></span>
                        <?= $this->Form->input('db_port', [
                            'label' => false,
                            'class' => 'form-control input-short',
                            'required' => true,
                            'value' => $defaults['db_port'] ?? '3306',
                        ]) ?>
                    </div>
                </div>

                <!-- Database name -->
                <div class="kmp-field-row">
                    <div class="kmp-field-label">
                        <i class="bi bi-database"></i><?= __('Database name') ?><span class="req">*</span>
                    </div>
                    <div class="kmp-field-input">
                        <?= $this->Form->input('db_name', [
                            'label' => false,
                            'class' => 'form-control',
                            'required' => true,
                            'value' => $defaults['db_name'] ?? '',
                            'placeholder' => 'kmp_production',
                        ]) ?>
                    </div>
                </div>

                <!-- Username + Password on one row -->
                <div class="kmp-field-row">
                    <div class="kmp-field-label">
                        <i class="bi bi-person"></i><?= __('Username') ?><span class="req">*</span>
                    </div>
                    <div class="kmp-field-input">
                        <?= $this->Form->input('db_user', [
                            'label' => false,
                            'class' => 'form-control',
                            'required' => true,
                            'value' => $defaults['db_user'] ?? '',
                        ]) ?>
                        <span class="sub-label"><?= __('Password') ?></span>
                        <?= $this->Form->input('db_password', [
                            'type' => 'password',
                            'label' => false,
                            'class' => 'form-control',
                            'value' => $defaults['db_password'] ?? '',
                        ]) ?>
                    </div>
                </div>

                <!-- Create database checkbox -->
                <div class="kmp-field-row kmp-check-row">
                    <div class="kmp-field-label">
                        <i class="bi bi-plus-circle"></i><?= __('Create database') ?>
                    </div>
                    <div class="kmp-field-input">
                        <div class="form-check">
                            <?= $this->Form->checkbox('db_create_database', [
                                'class' => 'form-check-input',
                                'id' => 'db_create_database',
                                'checked' => ($defaults['db_create_database'] ?? '1') === '1',
                            ]) ?>
                            <label class="form-check-label" for="db_create_database">
                                <?= __('Create the database automatically if it does not exist') ?>
                            </label>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <div class="kmp-card-footer">
            <a class="btn btn-kmp-back" href="<?= $this->Url->build(['action' => 'index', '?' => ['step' => 'preflight']]) ?>">
                <i class="bi bi-arrow-left me-1"></i><?= __('Back') ?>
            </a>
            <?= $this->Form->button(
                __('Continue to Email &amp; Storage') . ' <i class="bi bi-arrow-right ms-1"></i>',
                ['class' => 'btn btn-kmp-primary', 'escapeTitle' => false]
            ) ?>
        </div>
        <?= $this->Form->end() ?>
    </div>

    <!-- ████  STEP 3: COMMUNICATIONS  ████ -->
    <?php elseif ($currentStep === 'communications'): ?>
    <div class="kmp-card">
        <div class="kmp-card-header">
            <span class="kmp-card-header-icon"><i class="bi bi-envelope"></i></span>
            <h2><?= __('Email &amp; Document Storage') ?></h2>
        </div>

        <?= $this->Form->create(null, ['url' => ['action' => 'index']]) ?>
        <?= $this->Form->hidden('current_step', ['value' => 'communications']) ?>
        <?= $this->Form->hidden('next_step',    ['value' => 'branding']) ?>

        <!-- Outgoing email -->
        <div class="kmp-section-title"><i class="bi bi-send"></i><?= __('Outgoing email (SMTP)') ?></div>
        <div style="padding:0 1.75rem 1rem">
            <div class="kmp-field-table">

                <div class="kmp-field-row">
                    <div class="kmp-field-label"><i class="bi bi-hdd-network"></i><?= __('SMTP host') ?></div>
                    <div class="kmp-field-input">
                        <?= $this->Form->input('email_smtp_host', ['label' => false, 'class' => 'form-control', 'value' => $defaults['email_smtp_host'] ?? '', 'placeholder' => 'smtp.example.org']) ?>
                        <span class="sub-label"><?= __('Port') ?></span>
                        <?= $this->Form->input('email_smtp_port', ['label' => false, 'class' => 'form-control input-short', 'value' => $defaults['email_smtp_port'] ?? '587']) ?>
                    </div>
                </div>

                <div class="kmp-field-row">
                    <div class="kmp-field-label"><i class="bi bi-person"></i><?= __('SMTP username') ?></div>
                    <div class="kmp-field-input">
                        <?= $this->Form->input('email_smtp_username', ['label' => false, 'class' => 'form-control', 'value' => $defaults['email_smtp_username'] ?? '']) ?>
                        <span class="sub-label"><?= __('Password') ?></span>
                        <?= $this->Form->input('email_smtp_password', ['type' => 'password', 'label' => false, 'class' => 'form-control', 'value' => $defaults['email_smtp_password'] ?? '']) ?>
                    </div>
                </div>

                <div class="kmp-field-row">
                    <div class="kmp-field-label"><i class="bi bi-envelope-at"></i><?= __('From address') ?></div>
                    <div class="kmp-field-input">
                        <?= $this->Form->input('system_email_from', ['label' => false, 'class' => 'form-control', 'value' => $defaults['system_email_from'] ?? '', 'placeholder' => 'noreply@yourkingdom.org']) ?>
                    </div>
                </div>

            </div>
        </div>

        <!-- Document storage adapter -->
        <div class="kmp-section-title"><i class="bi bi-hdd-stack"></i><?= __('Document storage') ?></div>
        <div style="padding:0 1.75rem 1rem">
            <div class="kmp-field-table">

                <div class="kmp-field-row">
                    <div class="kmp-field-label"><i class="bi bi-layers"></i><?= __('Adapter') ?></div>
                    <div class="kmp-field-input">
                        <?= $this->Form->select('storage_adapter', [
                            'local' => __('Local filesystem'),
                            'azure' => __('Azure Blob Storage'),
                            's3'    => __('Amazon S3 / compatible'),
                        ], ['id' => 'storage_adapter', 'label' => false, 'class' => 'form-select input-medium', 'value' => $defaults['storage_adapter'] ?? 'local']) ?>
                        <span id="storage-adapter-hint" class="text-muted" style="font-size:.8rem"></span>
                    </div>
                </div>

            </div>
        </div>

        <!-- Azure -->
        <div id="storage-azure-wrap">
        <div class="kmp-section-title" style="padding-top:.5rem"><i class="bi bi-cloud"></i><?= __('Azure Blob Storage') ?></div>
        <div style="padding:0 1.75rem 1rem">
            <div class="kmp-field-table">

                <div class="kmp-field-row">
                    <div class="kmp-field-label"><i class="bi bi-key"></i><?= __('Connection string') ?></div>
                    <div class="kmp-field-input">
                        <?= $this->Form->input('azure_connection_string', ['label' => false, 'class' => 'form-control', 'value' => $defaults['azure_connection_string'] ?? '', 'placeholder' => 'DefaultEndpointsProtocol=https;AccountName=...']) ?>
                    </div>
                </div>

                <div class="kmp-field-row">
                    <div class="kmp-field-label"><i class="bi bi-box"></i><?= __('Container') ?></div>
                    <div class="kmp-field-input">
                        <?= $this->Form->input('azure_container', ['label' => false, 'class' => 'form-control input-medium', 'value' => $defaults['azure_container'] ?? 'documents']) ?>
                        <span class="sub-label"><?= __('Prefix') ?></span>
                        <?= $this->Form->input('azure_prefix', ['label' => false, 'class' => 'form-control input-medium', 'value' => $defaults['azure_prefix'] ?? '']) ?>
                    </div>
                </div>

            </div>
        </div>
        </div><!-- /storage-azure-wrap -->

        <!-- S3 -->
        <div id="storage-s3-wrap">
        <div class="kmp-section-title" style="padding-top:.5rem"><i class="bi bi-cloud-arrow-up"></i><?= __('Amazon S3 / compatible') ?></div>
        <div style="padding:0 1.75rem 1.5rem">
            <div class="kmp-field-table">

                <div class="kmp-field-row">
                    <div class="kmp-field-label"><i class="bi bi-bucket"></i><?= __('Bucket') ?></div>
                    <div class="kmp-field-input">
                        <?= $this->Form->input('s3_bucket', ['label' => false, 'class' => 'form-control input-medium', 'value' => $defaults['s3_bucket'] ?? '']) ?>
                        <span class="sub-label"><?= __('Region') ?></span>
                        <?= $this->Form->input('s3_region', ['label' => false, 'class' => 'form-control input-medium', 'value' => $defaults['s3_region'] ?? 'us-east-1']) ?>
                    </div>
                </div>

                <div class="kmp-field-row">
                    <div class="kmp-field-label"><i class="bi bi-key"></i><?= __('Access key') ?></div>
                    <div class="kmp-field-input">
                        <?= $this->Form->input('s3_key', ['label' => false, 'class' => 'form-control', 'value' => $defaults['s3_key'] ?? '']) ?>
                        <span class="sub-label"><?= __('Secret key') ?></span>
                        <?= $this->Form->input('s3_secret', ['type' => 'password', 'label' => false, 'class' => 'form-control', 'value' => $defaults['s3_secret'] ?? '']) ?>
                    </div>
                </div>

                <div class="kmp-field-row">
                    <div class="kmp-field-label"><i class="bi bi-link-45deg"></i><?= __('Endpoint') ?></div>
                    <div class="kmp-field-input">
                        <?= $this->Form->input('s3_endpoint', ['label' => false, 'class' => 'form-control', 'value' => $defaults['s3_endpoint'] ?? '', 'placeholder' => __('Leave blank for AWS default')]) ?>
                    </div>
                </div>

                <div class="kmp-field-row">
                    <div class="kmp-field-label"><i class="bi bi-ticket-perforated"></i><?= __('Session token') ?></div>
                    <div class="kmp-field-input">
                        <?= $this->Form->input('s3_session_token', ['type' => 'password', 'label' => false, 'class' => 'form-control', 'value' => $defaults['s3_session_token'] ?? '']) ?>
                        <span class="sub-label"><?= __('Prefix') ?></span>
                        <?= $this->Form->input('s3_prefix', ['label' => false, 'class' => 'form-control input-medium', 'value' => $defaults['s3_prefix'] ?? '']) ?>
                    </div>
                </div>

                <div class="kmp-field-row kmp-check-row">
                    <div class="kmp-field-label"><i class="bi bi-sliders"></i><?= __('Path-style endpoint') ?></div>
                    <div class="kmp-field-input">
                        <div class="form-check">
                            <?= $this->Form->checkbox('s3_use_path_style_endpoint', [
                                'class' => 'form-check-input',
                                'id' => 's3_use_path_style_endpoint',
                                'checked' => filter_var((string)($defaults['s3_use_path_style_endpoint'] ?? 'false'), FILTER_VALIDATE_BOOLEAN),
                            ]) ?>
                            <label class="form-check-label" for="s3_use_path_style_endpoint">
                                <?= __('Use path-style S3 endpoint (required for MinIO and some S3-compatible stores)') ?>
                            </label>
                        </div>
                    </div>
                </div>

            </div>
        </div>
        </div><!-- /storage-s3-wrap -->

        <script>
        (function () {
            var sel   = document.getElementById('storage_adapter');
            var azure = document.getElementById('storage-azure-wrap');
            var s3    = document.getElementById('storage-s3-wrap');
            var hint  = document.getElementById('storage-adapter-hint');
            var labels = {
                local: '<?= addslashes(__('No additional configuration needed for local storage.')) ?>',
                azure: '<?= addslashes(__('Complete the Azure section below.')) ?>',
                s3:    '<?= addslashes(__('Complete the S3 section below.')) ?>'
            };
            function update() {
                var v = sel.value;
                azure.style.display = (v === 'azure') ? '' : 'none';
                s3.style.display    = (v === 's3')    ? '' : 'none';
                hint.textContent    = labels[v] || '';
            }
            sel.addEventListener('change', update);
            update();
        })();
        </script>

        <div class="kmp-card-footer">
            <a class="btn btn-kmp-back" href="<?= $this->Url->build(['action' => 'index', '?' => ['step' => 'database']]) ?>">
                <i class="bi bi-arrow-left me-1"></i><?= __('Back') ?>
            </a>
            <?= $this->Form->button(
                __('Continue to Branding') . ' <i class="bi bi-arrow-right ms-1"></i>',
                ['class' => 'btn btn-kmp-primary', 'escapeTitle' => false]
            ) ?>
        </div>
        <?= $this->Form->end() ?>
    </div>

    <!-- ████  STEP 4: BRANDING  ████ -->
    <?php else: ?>
    <div class="kmp-card">
        <div class="kmp-card-header">
            <span class="kmp-card-header-icon"><i class="bi bi-palette"></i></span>
            <h2><?= __('Branding &amp; Finalize') ?></h2>
        </div>

        <?= $this->Form->create(null, [
            'url'  => ['controller' => 'Install', 'action' => 'finalize', 'plugin' => null],
            'type' => 'file',
        ]) ?>

        <!-- Site identity -->
        <div class="kmp-section-title"><i class="bi bi-globe2"></i><?= __('Site identity') ?></div>
        <div style="padding:0 1.75rem 1rem">
            <div class="kmp-field-table">

                <div class="kmp-field-row">
                    <div class="kmp-field-label"><i class="bi bi-building"></i><?= __('Kingdom name') ?><span class="req">*</span></div>
                    <div class="kmp-field-input">
                        <?= $this->Form->input('kingdom_name', ['label' => false, 'class' => 'form-control', 'required' => true, 'value' => $defaults['kingdom_name'] ?? '', 'placeholder' => 'Kingdom of Ansteorra']) ?>
                    </div>
                </div>

                <div class="kmp-field-row">
                    <div class="kmp-field-label"><i class="bi bi-card-heading"></i><?= __('Full site title') ?><span class="req">*</span></div>
                    <div class="kmp-field-input">
                        <?= $this->Form->input('long_site_title', ['label' => false, 'class' => 'form-control', 'required' => true, 'value' => $defaults['long_site_title'] ?? 'Kingdom Management Portal']) ?>
                        <span class="sub-label"><?= __('Short') ?></span>
                        <?= $this->Form->input('short_site_title', ['label' => false, 'class' => 'form-control input-short', 'required' => true, 'value' => $defaults['short_site_title'] ?? 'KMP']) ?>
                    </div>
                </div>

                <div class="kmp-field-row">
                    <div class="kmp-field-label"><i class="bi bi-clock-history"></i><?= __('Default timezone') ?></div>
                    <div class="kmp-field-input">
                        <?= $this->Form->select('default_timezone',
                            array_combine(timezone_identifiers_list(), timezone_identifiers_list()),
                            ['label' => false, 'class' => 'form-select', 'value' => $defaults['default_timezone'] ?? 'America/Chicago']
                        ) ?>
                    </div>
                </div>

            </div>
        </div>

        <!-- Banner logo -->
        <div class="kmp-section-title"><i class="bi bi-image"></i><?= __('Banner logo') ?></div>
        <div style="padding:0 1.75rem 1.5rem">
            <div class="kmp-field-table">

                <div class="kmp-field-row">
                    <div class="kmp-field-label"><i class="bi bi-images"></i><?= __('Logo source') ?></div>
                    <div class="kmp-field-input">
                        <?= $this->Form->select('banner_logo_mode', [
                            'packaged' => __('Use packaged default'),
                            'upload'   => __('Upload an image'),
                            'external' => __('External URL or path'),
                        ], ['label' => false, 'class' => 'form-select input-medium', 'value' => $defaults['banner_logo_mode'] ?? 'packaged']) ?>
                    </div>
                </div>

                <div class="kmp-field-row">
                    <div class="kmp-field-label"><i class="bi bi-link-45deg"></i><?= __('External URL / path') ?></div>
                    <div class="kmp-field-input">
                        <?= $this->Form->input('banner_logo_external_url', ['label' => false, 'class' => 'form-control', 'value' => $defaults['banner_logo_external_url'] ?? '', 'placeholder' => 'https://example.org/logo.png']) ?>
                    </div>
                </div>

                <div class="kmp-field-row">
                    <div class="kmp-field-label"><i class="bi bi-upload"></i><?= __('Upload file') ?></div>
                    <div class="kmp-field-input" style="flex-wrap:nowrap; gap:.75rem">
                        <?= $this->Form->file('banner_logo_upload', ['class' => 'form-control', 'accept' => '.png,.jpg,.jpeg,.gif,.webp,.svg']) ?>
                        <div class="logo-hint" style="flex:0 1 auto; white-space:nowrap">
                            <i class="bi bi-image-alt"></i>
                            <?= __('Current:') ?> <strong><?= h($currentBannerLogo) ?></strong>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <div class="alert alert-info d-flex gap-2 mx-4 mb-4" style="border-radius:8px; font-size:.85rem; padding:.75rem 1rem">
            <i class="bi bi-info-circle-fill flex-shrink-0 mt-1"></i>
            <span><?= __('<strong>Finalize</strong> will write your configuration to <code>.env</code>, run all database migrations, and lock the installer. This may take up to a minute.', [], true) ?></span>
        </div>

        <div class="kmp-card-footer">
            <a class="btn btn-kmp-back" href="<?= $this->Url->build(['action' => 'index', '?' => ['step' => 'communications']]) ?>">
                <i class="bi bi-arrow-left me-1"></i><?= __('Back') ?>
            </a>
            <?= $this->Form->button(
                '<i class="bi bi-check2-circle me-1"></i>' . __('Finalize installation'),
                ['class' => 'btn btn-kmp-success', 'escapeTitle' => false]
            ) ?>
        </div>
        <?= $this->Form->end() ?>
    </div>
    <?php endif; ?>

</div>
