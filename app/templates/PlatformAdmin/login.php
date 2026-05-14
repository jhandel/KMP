<?php
/**
 * @var \Cake\View\View $this
 */
?>
<div class="row justify-content-center">
    <div class="col-md-5">
        <div class="card shadow-sm">
            <div class="card-header">
                <h1 class="h4 mb-0">Platform Admin Login</h1>
            </div>
            <div class="card-body">
                <?= $this->Form->create(null) ?>
                <?= $this->Form->control('email', ['type' => 'email', 'required' => true]) ?>
                <?= $this->Form->control('password', ['type' => 'password', 'required' => true]) ?>
                <?= $this->Form->control('mfa_code', [
                    'label' => 'One-time MFA/recovery code',
                    'required' => true,
                    'autocomplete' => 'one-time-code',
                ]) ?>
                <?= $this->Form->button('Sign in', ['class' => 'btn btn-primary w-100']) ?>
                <?= $this->Form->end() ?>
                <p class="small text-muted mt-3 mb-0">
                    Lost platform admin access requires a trusted shell reset:
                    <code>bin/cake platform_admin:reset_password email@example.org</code>.
                </p>
            </div>
        </div>
    </div>
</div>
