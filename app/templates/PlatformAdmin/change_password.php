<?php
/**
 * @var \Cake\View\View $this
 * @var \App\Model\Entity\PlatformAdmin $admin
 */
?>
<div class="row justify-content-center">
    <div class="col-md-6">
        <div class="card shadow-sm">
            <div class="card-header">
                <h1 class="h4 mb-0">Change Platform Admin Password</h1>
            </div>
            <div class="card-body">
                <?php if ((bool)$admin->require_password_change) : ?>
                    <p class="text-muted">This account must choose a new password before using the platform console.</p>
                <?php endif; ?>
                <?= $this->Form->create(null) ?>
                <?= $this->Form->control('current_password', [
                    'type' => 'password',
                    'required' => true,
                    'autocomplete' => 'current-password',
                ]) ?>
                <?= $this->Form->control('new_password', [
                    'type' => 'password',
                    'required' => true,
                    'autocomplete' => 'new-password',
                    'help' => 'Use at least 14 characters.',
                ]) ?>
                <?= $this->Form->control('confirm_password', [
                    'type' => 'password',
                    'required' => true,
                    'autocomplete' => 'new-password',
                ]) ?>
                <?= $this->Form->button('Change password', ['class' => 'btn btn-primary w-100']) ?>
                <?= $this->Form->end() ?>
            </div>
        </div>
    </div>
</div>
