<?php
/**
 * @var \Cake\View\View $this
 * @var array<int, array{event: \App\Model\Entity\PlatformAuditEvent, correlation_id: string, operation_id: string, jobs: array<int, \App\Model\Entity\TenantOperationJob>}> $auditRows
 * @var array{correlation_id: string, operation_id: string} $filters
 */
?>
<h1>Platform Audit</h1>
<div class="alert alert-light border">
    Audit records are the incident trail for platform-admin decisions: approvals, denials, secret changes,
    backups, restores, doctor remediation, and operation lifecycle events. Use correlation ID first when you have it;
    use operation ID when you are starting from a queue row.
</div>
<div class="card mb-3">
    <div class="card-body">
        <?= $this->Form->create(null, ['type' => 'get', 'class' => 'row g-2 align-items-end']) ?>
        <div class="col-md-4">
            <?= $this->Form->control('correlation_id', [
                'label' => 'Correlation ID',
                'value' => $filters['correlation_id'],
                'help' => 'Matches operation queue correlation IDs and structured logs.',
            ]) ?>
        </div>
        <div class="col-md-3">
            <?= $this->Form->control('operation_id', [
                'label' => 'Operation ID',
                'value' => $filters['operation_id'],
                'help' => 'Tenant operation job ID, shown as #123 in the queue.',
            ]) ?>
        </div>
        <div class="col-md-2 d-grid">
            <?= $this->Form->button('Apply', ['class' => 'btn btn-primary']) ?>
        </div>
        <?= $this->Form->end() ?>
    </div>
</div>
<div class="card">
    <div class="table-responsive">
        <table class="table table-sm mb-0">
            <thead><tr><th>When</th><th>Admin</th><th>Tenant</th><th>Action</th><th>Result</th><th>Correlation</th><th>Operation Links</th></tr></thead>
            <tbody>
                <?php foreach ($auditRows as $row) : ?>
                    <?php $event = $row['event']; ?>
                    <tr>
                        <td><?= h($event->created) ?></td>
                        <td><?= h($event->platform_admin->email ?? '-') ?></td>
                        <td><?= h($event->tenant->slug ?? '-') ?></td>
                        <td><?= h($event->action) ?></td>
                        <td><?= h($event->result) ?></td>
                        <td>
                            <?php if ($row['correlation_id'] !== '') : ?>
                                <code><?= h($row['correlation_id']) ?></code>
                            <?php else : ?>
                                -
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($row['jobs'] !== []) : ?>
                                <?php foreach ($row['jobs'] as $job) : ?>
                                    <div>
                                        <?= $this->Html->link(
                                            sprintf('Job #%d', (int)$job->id),
                                            ['controller' => 'PlatformAdmin', 'action' => 'index', '?' => ['correlation' => (string)$job->operation_correlation_id]],
                                        ) ?>
                                        <?php if (!empty($job->tenant?->slug)) : ?>
                                            (<?= $this->Html->link((string)$job->tenant->slug, ['controller' => 'PlatformAdmin', 'action' => 'viewTenant', (string)$job->tenant->slug]) ?>)
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php elseif ($row['operation_id'] !== '') : ?>
                                <span class="text-muted">Operation #<?= h($row['operation_id']) ?> (not found)</span>
                            <?php else : ?>
                                -
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($auditRows === []) : ?>
                    <tr><td colspan="7" class="text-center text-muted py-3">No audit events matched the selected filters.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
