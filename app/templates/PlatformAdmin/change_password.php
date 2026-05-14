<?php
/**
 * @var \Cake\View\View $this
 * @var \App\Model\Entity\PlatformAdmin $admin
 * @var array<int, string> $recoveryCodes
 */
?>
<div class="row justify-content-center">
    <div class="col-md-6">
        <div class="card shadow-sm">
            <div class="card-header">
                <h1 class="h4 mb-0">Change Platform Admin Password</h1>
            </div>
            <div class="card-body">
                <?php if (!empty($recoveryCodes)) : ?>
                    <div class="alert alert-warning">
                        <strong>Save these replacement one-time MFA/recovery codes now.</strong>
                        They will not be shown again, and your previous codes no longer work.
                    </div>
                    <pre class="bg-light border rounded p-3"><?php foreach ($recoveryCodes as $code) : ?><?= h($code) . "\n" ?><?php endforeach; ?></pre>
                    <?= $this->Html->link('Return to login', ['action' => 'login'], ['class' => 'btn btn-primary w-100']) ?>
                <?php else : ?>
                    <?php if ((bool)$admin->require_password_change) : ?>
                        <p class="text-muted">This account must choose a new password before using the platform console.</p>
                    <?php endif; ?>
                    <?= $this->Form->create(null) ?>
                    <?= $this->Form->control('current_password', ['type' => 'password', 'required' => true]) ?>
                    <?= $this->Form->control('new_password', [
                        'type' => 'password',
                        'required' => true,
                        'help' => 'Use at least 14 characters.',
                    ]) ?>
                    <?= $this->Form->control('confirm_password', ['type' => 'password', 'required' => true]) ?>
                    <?= $this->Form->button('Change password', ['class' => 'btn btn-primary w-100']) ?>
                    <?= $this->Form->end() ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
