<?php
/**
 * @var \Cake\View\View $this
 * @var array<int, array<string, mixed>> $operationRows
 * @var array<string, string> $operationStateOptions
 * @var array<string, string> $operationSortOptions
 * @var array<string, string> $operationTenantOptions
 * @var array{state: string, tenant: string, sort: string, limit: int, correlation: string} $filters
 * @var array<string, bool>|null $capabilities
 */
$showTenantFilter = count($operationTenantOptions) > 1;
$canOperateTenants = (bool)($capabilities['operate_tenants'] ?? false);
$stateBadge = [
    'queued' => 'text-bg-secondary',
    'approval_required' => 'text-bg-warning',
    'approved' => 'text-bg-info',
    'running' => 'text-bg-primary',
    'hold' => 'text-bg-dark',
    'blocked' => 'text-bg-warning',
    'completed' => 'text-bg-success',
    'failed' => 'text-bg-danger',
    'cancelled' => 'text-bg-secondary',
];
?>
<div class="alert alert-light border">
    <strong>Queue state quick guide:</strong>
    <code>approval_required</code> needs enough eligible approvers, <code>approved</code> is waiting for a worker,
    <code>running</code> has or recently had a lease, <code>hold</code>/<code>blocked</code> need operator review,
    and <code>completed</code>/<code>failed</code>/<code>cancelled</code> are terminal.
    A stale lock means a running job's lease has expired; do not treat an active lock as stale work.
</div>
<div class="card mb-3">
    <div class="card-body">
        <?= $this->Form->create(null, ['type' => 'get', 'class' => 'row g-2 align-items-end']) ?>
        <div class="col-md-3">
            <?= $this->Form->control('state', [
                'label' => 'State',
                'options' => $operationStateOptions,
                'value' => $filters['state'],
                'help' => 'Use failed, blocked, hold, or approval_required to find work needing attention.',
            ]) ?>
        </div>
        <?php if ($showTenantFilter) : ?>
            <div class="col-md-3">
                <?= $this->Form->control('tenant', [
                    'label' => 'Tenant',
                    'options' => $operationTenantOptions,
                    'value' => $filters['tenant'],
                    'help' => 'Limit the queue to one tenant when investigating tenant-specific incidents.',
                ]) ?>
            </div>
        <?php endif; ?>
        <div class="col-md-3">
            <?= $this->Form->control('sort', [
                'label' => 'Sort',
                'options' => $operationSortOptions,
                'value' => $filters['sort'],
                'help' => 'Newest first is best for active incidents; oldest first helps drain backlog.',
            ]) ?>
        </div>
        <div class="col-md-2">
            <?= $this->Form->control('limit', [
                'label' => 'Rows',
                'type' => 'number',
                'min' => 10,
                'max' => 100,
                'step' => 5,
                'value' => $filters['limit'],
                'help' => 'Dashboard results are capped at 100 rows.',
            ]) ?>
        </div>
        <div class="col-md-3">
            <?= $this->Form->control('correlation', [
                'label' => 'Correlation',
                'value' => $filters['correlation'],
                'placeholder' => 'req-abc123',
                'help' => 'Paste a correlation ID from logs, audit, or a deployment run.',
            ]) ?>
        </div>
        <div class="col-md-1 d-grid">
            <?= $this->Form->button('Apply', ['class' => 'btn btn-primary']) ?>
        </div>
        <?= $this->Form->end() ?>
    </div>
</div>
<div class="card">
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Operation</th>
                    <th>Tenant</th>
                    <th>State</th>
                    <th>Progress</th>
                    <th>Correlation</th>
                    <th>Linkage</th>
                    <th>Summary</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($operationRows as $row) : ?>
                    <?php $job = $row['job']; ?>
                    <tr>
                        <td>#<?= h($row['id']) ?></td>
                        <td><?= h($job->operation) ?></td>
                        <td>
                            <?php if ($row['tenant_slug'] !== '') : ?>
                                <?= $this->Html->link($row['tenant_slug'], ['action' => 'viewTenant', $row['tenant_slug']]) ?>
                            <?php else : ?>
                                -
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge <?= h($stateBadge[$row['state']] ?? 'text-bg-secondary') ?>"><?= h($row['state']) ?></span>
                            <?php if ($row['has_active_lease']) : ?>
                                <span class="badge text-bg-primary">Lock active</span>
                            <?php elseif ($row['is_stale_lease']) : ?>
                                <span class="badge text-bg-danger">Stale lock</span>
                            <?php elseif ($row['has_lease']) : ?>
                                <span class="badge text-bg-secondary">Lease recorded</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= $row['progress_percent'] === null ? '-' : h($row['progress_percent']) . '%' ?>
                            <?php if ($row['progress_phase'] !== '') : ?>
                                <br><small class="text-muted"><?= h($row['progress_phase']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($row['correlation_id'] !== '') : ?>
                                <code><?= h($row['correlation_id']) ?></code>
                                <div>
                                    <?= $this->Html->link(
                                        'Audit',
                                        ['controller' => 'PlatformAdmin', 'action' => 'audit', '?' => ['correlation_id' => $row['correlation_id']]],
                                        ['class' => 'small'],
                                    ) ?>
                                </div>
                            <?php else : ?>
                                <code>-</code>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($row['is_child_operation']) : ?>
                                <small class="d-block">
                                    Child of #<?= h((string)$row['parent_job_id']) ?>
                                    <?php if ($row['parent_operation'] !== '') : ?>
                                        (<?= h($row['parent_operation']) ?>)
                                    <?php endif; ?>
                                </small>
                            <?php endif; ?>
                            <?php if ($row['child_total'] > 0) : ?>
                                <small class="d-block">Parent with <?= h((string)$row['child_total']) ?> child job(s)</small>
                                <?php if ($row['child_state_counts'] !== []) : ?>
                                    <small class="text-muted">
                                        <?= h((string)json_encode($row['child_state_counts'], JSON_UNESCAPED_SLASHES)) ?>
                                    </small>
                                <?php endif; ?>
                            <?php elseif (!$row['is_child_operation']) : ?>
                                <span class="text-muted">Standalone</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($row['error_summary'] !== '') : ?>
                                <div class="text-danger"><?= h($row['error_summary']) ?></div>
                            <?php elseif ($row['result_summary'] !== '') : ?>
                                <div><?= h($row['result_summary']) ?></div>
                            <?php else : ?>
                                <span class="text-muted">No output</span>
                            <?php endif; ?>
                            <?php if ((int)$row['approvals_required'] > 0) : ?>
                                <small class="d-block text-muted">
                                    Approvals: <?= h((string)$row['approvals_received']) ?>/<?= h((string)$row['approvals_required']) ?>
                                    <?php if ((int)$row['approvals_pending'] > 0) : ?>
                                        (<?= h((string)$row['approvals_pending']) ?> pending)
                                    <?php endif; ?>
                                </small>
                                <small class="d-block text-muted">Policy: <?= h((string)$row['approval_policy_mode']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td class="text-nowrap">
                            <?php if ($canOperateTenants && $row['actions']['approve']['enabled']) : ?>
                                <?= $this->Form->postLink('Approve', ['action' => 'approveOperation', $row['id']], [
                                    'class' => 'btn btn-outline-success btn-sm me-1',
                                    'confirm' => __('Record an approval for this operation?'),
                                ]) ?>
                            <?php else : ?>
                                <button class="btn btn-outline-secondary btn-sm me-1" disabled title="<?= h($canOperateTenants ? $row['actions']['approve']['reason'] : 'Insufficient role for operation controls.') ?>">Approve</button>
                            <?php endif; ?>
                            <?php if ($canOperateTenants && $row['actions']['reject']['enabled']) : ?>
                                <?= $this->Form->postLink('Reject', ['action' => 'rejectOperation', $row['id']], [
                                    'class' => 'btn btn-outline-warning btn-sm me-1',
                                    'confirm' => __('Reject this operation request?'),
                                ]) ?>
                            <?php else : ?>
                                <button class="btn btn-outline-secondary btn-sm me-1" disabled title="<?= h($canOperateTenants ? $row['actions']['reject']['reason'] : 'Insufficient role for operation controls.') ?>">Reject</button>
                            <?php endif; ?>
                            <?php if ($canOperateTenants && $row['actions']['retry']['enabled']) : ?>
                                <?= $this->Form->postLink('Retry', ['action' => 'retryOperation', $row['id']], [
                                    'class' => 'btn btn-outline-primary btn-sm me-1',
                                    'confirm' => __('Queue a retry operation?'),
                                ]) ?>
                            <?php else : ?>
                                <button class="btn btn-outline-secondary btn-sm me-1" disabled title="<?= h($canOperateTenants ? $row['actions']['retry']['reason'] : 'Insufficient role for operation controls.') ?>">Retry</button>
                            <?php endif; ?>
                            <?php if ($canOperateTenants && $row['actions']['cancel']['enabled']) : ?>
                                <?= $this->Form->postLink('Cancel', ['action' => 'cancelOperation', $row['id']], [
                                    'class' => 'btn btn-outline-danger btn-sm',
                                    'confirm' => __('Request cancellation for this operation?'),
                                ]) ?>
                            <?php else : ?>
                                <button class="btn btn-outline-secondary btn-sm" disabled title="<?= h($canOperateTenants ? $row['actions']['cancel']['reason'] : 'Insufficient role for operation controls.') ?>">Cancel</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td colspan="9" class="bg-light">
                            <small class="d-block text-muted">
                                Created: <?= h((string)$job->created) ?> |
                                Started: <?= h((string)($job->started_at ?? '-')) ?> |
                                Heartbeat: <?= h((string)($job->heartbeat_at ?? '-')) ?> |
                                Lease expires: <?= h((string)($job->lease_expires_at ?? '-')) ?> |
                                Cancelled: <?= h((string)($job->cancelled_at ?? '-')) ?> |
                                Completed: <?= h((string)($job->completed_at ?? '-')) ?>
                            </small>
                            <?php if ($row['status_message'] !== '') : ?>
                                <small class="d-block"><strong>Status:</strong> <?= h($row['status_message']) ?></small>
                            <?php endif; ?>
                            <?php if ($row['lock_owner'] !== '') : ?>
                                <small class="d-block">
                                    <strong>Lock owner:</strong> <?= h($row['lock_owner']) ?>
                                    <span class="text-muted">(active leases mean a worker may still be running)</span>
                                </small>
                            <?php endif; ?>
                            <?php if (!$canOperateTenants || !$row['actions']['retry']['enabled']) : ?>
                                <small class="d-block text-muted">Retry unavailable: <?= h($canOperateTenants ? $row['actions']['retry']['reason'] : 'Insufficient role for operation controls.') ?></small>
                            <?php endif; ?>
                            <?php if (!$canOperateTenants || !$row['actions']['cancel']['enabled']) : ?>
                                <small class="d-block text-muted">Cancel unavailable: <?= h($canOperateTenants ? $row['actions']['cancel']['reason'] : 'Insufficient role for operation controls.') ?></small>
                            <?php endif; ?>
                            <?php if (!$canOperateTenants || !$row['actions']['approve']['enabled']) : ?>
                                <small class="d-block text-muted">Approve unavailable: <?= h($canOperateTenants ? $row['actions']['approve']['reason'] : 'Insufficient role for operation controls.') ?></small>
                            <?php endif; ?>
                            <?php if (!$canOperateTenants || !$row['actions']['reject']['enabled']) : ?>
                                <small class="d-block text-muted">Reject unavailable: <?= h($canOperateTenants ? $row['actions']['reject']['reason'] : 'Insufficient role for operation controls.') ?></small>
                            <?php endif; ?>
                            <?php if ((string)($job->approval_rejection_reason ?? '') !== '') : ?>
                                <small class="d-block text-danger">Rejection reason: <?= h((string)$job->approval_rejection_reason) ?></small>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($operationRows === []) : ?>
                    <tr><td colspan="9" class="text-center text-muted py-3">No operations matched the selected filters.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
