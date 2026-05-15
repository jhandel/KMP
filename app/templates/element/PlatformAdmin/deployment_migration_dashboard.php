<?php
/**
 * @var \Cake\View\View $this
 * @var array{state: string, tenant_state: string, correlation: string, limit: int} $deploymentMigrationFilters
 * @var array<string, mixed> $deploymentMigrationDashboard
 * @var array<string, bool> $capabilities
 */
$rows = is_array($deploymentMigrationDashboard['rows'] ?? null) ? $deploymentMigrationDashboard['rows'] : [];
$stateOptions = is_array($deploymentMigrationDashboard['state_options'] ?? null) ? $deploymentMigrationDashboard['state_options'] : [];
$tenantStateOptions = is_array($deploymentMigrationDashboard['tenant_state_options'] ?? null) ? $deploymentMigrationDashboard['tenant_state_options'] : [];
$canOperateTenants = (bool)($capabilities['operate_tenants'] ?? false);
?>
<div class="alert alert-light border">
    <strong>Deployment migration model:</strong>
    a parent run records platform-first orchestration and child jobs record per-tenant migration results.
    Child counters may not add up to the total because queued, approval-required, approved, or cancelled children are not
    all shown as separate summary buckets.
</div>
<div class="card mb-3">
    <div class="card-body">
        <?= $this->Form->create(null, ['type' => 'get', 'class' => 'row g-2 align-items-end']) ?>
        <div class="col-md-3">
            <?= $this->Form->control('migration_state', [
                'label' => 'Parent state',
                'options' => $stateOptions,
                'value' => $deploymentMigrationFilters['state'],
                'help' => 'Filter the parent orchestration job state.',
            ]) ?>
        </div>
        <div class="col-md-3">
            <?= $this->Form->control('migration_tenant_state', [
                'label' => 'Child tenant state',
                'options' => $tenantStateOptions,
                'value' => $deploymentMigrationFilters['tenant_state'],
                'help' => 'Filter per-tenant child jobs by state.',
            ]) ?>
        </div>
        <div class="col-md-3">
            <?= $this->Form->control('migration_correlation', [
                'label' => 'Correlation',
                'value' => $deploymentMigrationFilters['correlation'],
                'placeholder' => 'req-abc123',
                'help' => 'Use the same correlation ID in Platform Audit.',
            ]) ?>
        </div>
        <div class="col-md-2">
            <?= $this->Form->control('migration_limit', [
                'label' => 'Runs',
                'type' => 'number',
                'min' => 1,
                'max' => 25,
                'step' => 1,
                'value' => $deploymentMigrationFilters['limit'],
                'help' => 'Show up to 25 parent runs.',
            ]) ?>
        </div>
        <div class="col-md-1 d-grid">
            <?= $this->Form->button('Apply', ['class' => 'btn btn-primary']) ?>
        </div>
        <?= $this->Form->end() ?>
    </div>
</div>

<?php foreach ($rows as $row) : ?>
    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <div>
                <strong>Parent #<?= h((string)($row['parent_job_id'] ?? '')) ?></strong>
                <span class="badge text-bg-secondary ms-2"><?= h((string)($row['state'] ?? '')) ?></span>
                <span class="badge text-bg-info ms-1"><?= h((string)($row['stage'] ?? '')) ?></span>
                <?php if (!empty($row['is_held'])) : ?><span class="badge text-bg-warning ms-1">Hold/Blocked</span><?php endif; ?>
                <?php if (!empty($row['has_failure'])) : ?><span class="badge text-bg-danger ms-1">Failure</span><?php endif; ?>
            </div>
            <?php if (!empty($row['can_resume'])) : ?>
                <?php if ($canOperateTenants && !empty($row['resume_enabled'])) : ?>
                    <?= $this->Form->postLink('Resume', ['controller' => 'PlatformAdmin', 'action' => 'resumeOperation', (int)$row['parent_job_id']], [
                        'class' => 'btn btn-outline-primary btn-sm',
                        'confirm' => __('Resume deployment migration parent #{0}?', (int)$row['parent_job_id']),
                    ]) ?>
                <?php else : ?>
                    <button class="btn btn-outline-secondary btn-sm" disabled title="Insufficient role or state for resume controls.">Resume</button>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <?php if (!empty($row['can_resume'])) : ?>
                <div class="alert alert-warning py-2">
                    Resume continues the recorded parent operation. Confirm the failed child errors are understood,
                    workers are healthy, and the deployment image still matches this migration target.
                </div>
            <?php endif; ?>
            <div class="small text-muted mb-2">
                Correlation: <code><?= h((string)($row['correlation_id'] ?? '-')) ?></code> |
                Target schema: <?= h((string)($row['target_schema_version'] ?? '-')) ?> |
                Progress: <?= h((string)($row['progress_percent'] ?? '-')) ?>%
            </div>
            <div class="small mb-3">
                Child jobs: <?= h((string)($row['child_total'] ?? 0)) ?> total,
                <?= h((string)($row['child_completed'] ?? 0)) ?> completed,
                <?= h((string)($row['child_running'] ?? 0)) ?> running,
                <?= h((string)($row['child_failed'] ?? 0)) ?> failed,
                <?= h((string)($row['child_hold_or_blocked'] ?? 0)) ?> hold/blocked.
            </div>
            <?php if (!empty($row['status_message'])) : ?>
                <div class="small mb-2"><strong>Status:</strong> <?= h((string)$row['status_message']) ?></div>
            <?php endif; ?>
            <?php $tenantRows = is_array($row['tenant_rows'] ?? null) ? $row['tenant_rows'] : []; ?>
            <?php if ($tenantRows === []) : ?>
                <p class="text-muted mb-0">No tenant child jobs matched the selected filters.</p>
            <?php else : ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Tenant</th>
                                <th>State</th>
                                <th>Schema</th>
                                <th>Attempts</th>
                                <th>Duration</th>
                                <th>Summary</th>
                                <th>Error</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tenantRows as $tenantRow) : ?>
                                <tr>
                                    <td><?= h((string)($tenantRow['tenant_slug'] ?? '')) ?></td>
                                    <td><?= h((string)($tenantRow['state'] ?? '')) ?></td>
                                    <td>
                                        <small><?= h((string)($tenantRow['schema_before'] ?? '')) ?> → <?= h((string)($tenantRow['schema_after'] ?? '')) ?></small>
                                        <div class="text-muted small">target <?= h((string)($tenantRow['target_schema_version'] ?? '')) ?></div>
                                    </td>
                                    <td><?= h((string)($tenantRow['attempt_count'] ?? 0)) ?>/<?= h((string)($tenantRow['max_attempts'] ?? '-')) ?></td>
                                    <td><?= h((string)($tenantRow['duration_ms'] ?? '-')) ?>ms</td>
                                    <td><?= h((string)($tenantRow['result_summary'] ?? '')) ?></td>
                                    <td class="text-danger"><?= h((string)($tenantRow['error_summary'] ?? '')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endforeach; ?>
<?php if ($rows === []) : ?>
    <div class="card">
        <div class="card-body text-muted">No deployment migration parent jobs matched the selected filters.</div>
    </div>
<?php endif; ?>
