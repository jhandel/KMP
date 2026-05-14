<?php
/**
 * @var \Cake\View\View $this
 * @var iterable<\App\Model\Entity\PlatformAuditEvent> $events
 */
?>
<h1>Platform Audit</h1>
<div class="card">
    <div class="table-responsive">
        <table class="table table-sm mb-0">
            <thead><tr><th>When</th><th>Admin</th><th>Tenant</th><th>Action</th><th>Result</th></tr></thead>
            <tbody>
                <?php foreach ($events as $event) : ?>
                    <tr>
                        <td><?= h($event->created) ?></td>
                        <td><?= h($event->platform_admin->email ?? '-') ?></td>
                        <td><?= h($event->tenant->slug ?? '-') ?></td>
                        <td><?= h($event->action) ?></td>
                        <td><?= h($event->result) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
