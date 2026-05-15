<?php
/**
 * @var string $displayName
 * @var string $code
 * @var string $purpose
 * @var string $actionLabel
 * @var \Cake\I18n\DateTime $expiresAt
 * @var string|null $ipAddress
 * @var string $userAgent
 */
?>
<p>Hello <?= h($displayName) ?>,</p>

<p>Your platform admin <?= $purpose === 'action' ? 'action verification' : 'sign-in' ?> code is:</p>

<p style="font-size: 1.5rem; letter-spacing: 0.2rem;"><strong><?= h($code) ?></strong></p>

<p>This code expires at <?= h($expiresAt->format('Y-m-d H:i:s T')) ?>.</p>

<dl>
    <dt>Requested action</dt>
    <dd><?= h($actionLabel) ?></dd>
    <dt>IP address</dt>
    <dd><?= h($ipAddress ?: 'unknown') ?></dd>
    <dt>User agent</dt>
    <dd><?= h($userAgent ?: 'unknown') ?></dd>
</dl>

<p>If you did not request this code, reset your platform admin password from a trusted shell.</p>
