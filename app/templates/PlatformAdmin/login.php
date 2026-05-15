<?php
/**
 * @var \Cake\View\View $this
 * @var string|null $pendingEmail
 */
?>
<div class="row justify-content-center">
    <div class="col-md-5">
        <div class="card shadow-sm">
            <div class="card-header">
                <h1 class="h4 mb-0">Platform Admin Login</h1>
            </div>
            <div class="card-body">
                <?php if (!empty($pendingEmail)) : ?>
                    <div class="alert alert-info" role="status">
                        Enter the verification code emailed to <?= h($pendingEmail) ?>.
                        It is a 6-digit code that expires after 10 minutes; request a new sign-in if the message is old.
                    </div>
                    <?= $this->Form->create(null) ?>
                    <?= $this->Form->control('email_code', [
                        'label' => 'Email verification code',
                        'required' => true,
                        'autocomplete' => 'one-time-code',
                        'inputmode' => 'numeric',
                        'pattern' => '[0-9]*',
                        'maxlength' => 6,
                        'help' => 'Use the 6-digit code from your platform admin email. Codes expire shortly.',
                    ]) ?>
                    <?= $this->Form->button('Verify code', ['class' => 'btn btn-primary w-100']) ?>
                    <?= $this->Form->end() ?>
                    <?= $this->Form->create(null, ['class' => 'mt-2']) ?>
                    <?= $this->Form->hidden('restart_login', ['value' => '1']) ?>
                    <?= $this->Form->button('Start sign-in again', ['class' => 'btn btn-link p-0']) ?>
                    <?= $this->Form->end() ?>
                <?php else : ?>
                    <?= $this->Form->create(null) ?>
                    <?= $this->Form->control('email', [
                        'type' => 'email',
                        'required' => true,
                        'autocomplete' => 'username',
                    ]) ?>
                    <?= $this->Form->control('password', [
                        'type' => 'password',
                        'required' => true,
                        'autocomplete' => 'current-password',
                    ]) ?>
                    <p class="small text-muted">
                        After your password is verified, a short-lived code will be emailed to your platform admin
                        address. Five failed password attempts lock the account for 15 minutes.
                    </p>
                    <?= $this->Form->button('Email verification code', ['class' => 'btn btn-primary w-100']) ?>
                    <?= $this->Form->end() ?>
                <?php endif; ?>
                <p class="small text-muted mt-3 mb-0">
                    Lost platform admin access requires a trusted shell reset:
                    <code>bin/cake platform_admin:reset_password email@example.org</code>.
                    Platform admin accounts are separate from tenant member accounts.
                </p>
            </div>
        </div>
    </div>
</div>
