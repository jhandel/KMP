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
Hello <?= $displayName ?>,

Your platform admin <?= $purpose === 'action' ? 'action verification' : 'sign-in' ?> code is:

<?= $code ?>

This code expires at <?= $expiresAt->format('Y-m-d H:i:s T') ?>.

Requested action: <?= $actionLabel ?>
IP address: <?= $ipAddress ?: 'unknown' ?>
User agent: <?= $userAgent ?: 'unknown' ?>

If you did not request this code, reset your platform admin password from a trusted shell.
