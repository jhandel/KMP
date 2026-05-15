<?php
/**
 * @var \Cake\View\View $this
 * @var array<int, \App\Model\Entity\Tenant> $tenants
 * @var array<int, \App\Model\Entity\TenantOperationJob> $jobs
 * @var array<int, array<string, mixed>> $operationRows
 * @var array<string, string> $operationStateOptions
 * @var array<string, string> $operationSortOptions
 * @var array<string, string> $operationTenantOptions
 * @var array{state: string, tenant: string, sort: string, limit: int, correlation: string} $filters
 * @var array<string, bool> $capabilities
 * @var array<string, mixed> $tenantHealth
 * @var array{state: string, tenant_state: string, correlation: string, limit: int} $deploymentMigrationFilters
 * @var array<string, mixed> $deploymentMigrationDashboard
 */
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h1>Tenants</h1>
        <p class="text-muted mb-0">
            Platform control plane for tenant routing, health, migration orchestration, and queued operations.
            Use correlation IDs to move between queue rows and audit records during an incident.
        </p>
    </div>
    <?php if (!empty($capabilities['provision_tenants'])) : ?>
        <?= $this->Html->link('Create Tenant', ['action' => 'createTenant'], ['class' => 'btn btn-primary']) ?>
    <?php endif; ?>
</div>
<div class="alert alert-info">
    <strong>Rare-use console reminder:</strong> platform admin accounts are separate from tenant members.
    High-risk actions require a fresh sign-in, current password, and an emailed action code before they queue work.
</div>
<div class="card mb-4">
    <div class="card-header">
        Tenant registry
        <small class="text-muted d-block">
            Status and host data come from the platform datastore, not from a tenant database connection.
        </small>
    </div>
    <div class="table-responsive">
        <table class="table table-striped mb-0">
            <thead>
                <tr>
                    <th>Slug</th>
                    <th>Name</th>
                    <th>Status</th>
                    <th>Host</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tenants as $tenant) : ?>
                    <tr>
                        <td><?= h($tenant->slug) ?></td>
                        <td><?= h($tenant->display_name) ?></td>
                        <td><span class="badge text-bg-secondary"><?= h($tenant->status) ?></span></td>
                        <td><?= h($tenant->primary_host) ?></td>
                        <td><?= $this->Html->link('Open', ['action' => 'viewTenant', $tenant->slug]) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<h2 class="h4">Tenant Health</h2>
<div class="card mb-4">
    <div class="card-body">
        <?php if (!empty($tenantHealth['available'])) : ?>
            <div class="alert alert-light border">
                <strong>How to read this panel:</strong>
                backlog is the count of non-terminal operation jobs in
                <code>queued</code>, <code>approval_required</code>, <code>approved</code>,
                <code>running</code>, <code>hold</code>, or <code>blocked</code>.
                Backlog during a deployment can be normal; backlog that persists outside a maintenance window
                usually points to missing approvals, stopped workers, stale leases, or failed operations.
            </div>
            <div class="row row-cols-1 row-cols-md-3 g-3 mb-3">
                <div class="col">
                    <div class="border rounded p-3 h-100">
                        <h3 class="h6 mb-2">Migration state</h3>
                        <div class="small text-muted mb-1">Target: <?= h((string)($tenantHealth['migration']['target_schema_version'] ?? 'unknown')) ?></div>
                        <div class="small">Up to date: <?= h((string)($tenantHealth['migration']['up_to_date'] ?? 0)) ?></div>
                        <div class="small">Outdated: <?= h((string)($tenantHealth['migration']['outdated'] ?? 0)) ?></div>
                        <div class="small">Unknown: <?= h((string)($tenantHealth['migration']['unknown'] ?? 0)) ?></div>
                        <div class="small text-muted mt-2">Outdated tenants should normally clear after deployment migrations finish.</div>
                    </div>
                </div>
                <div class="col">
                    <div class="border rounded p-3 h-100">
                        <h3 class="h6 mb-2">Operations</h3>
                        <div class="small">Backlog: <?= h((string)($tenantHealth['operation_counts']['backlog'] ?? 0)) ?></div>
                        <div class="small">Recent failures (24h): <?= h((string)($tenantHealth['operation_counts']['recent_failures_24h'] ?? 0)) ?></div>
                        <div class="small">Stale running leases: <?= h((string)($tenantHealth['operation_counts']['stale_running_leases'] ?? 0)) ?></div>
                        <div class="small text-muted mt-2">Start with failed or stale rows in the operation queue below.</div>
                    </div>
                </div>
                <div class="col">
                    <div class="border rounded p-3 h-100">
                        <h3 class="h6 mb-2">Drain and invalidation</h3>
                        <div class="small">Draining tenants: <?= h((string)($tenantHealth['drain_status']['draining'] ?? 0)) ?></div>
                        <div class="small">Tracked invalidation versions: <?= h((string)($tenantHealth['invalidation_lag']['tracked_tenants'] ?? 0)) ?></div>
                        <div class="small">P95 lag (seconds): <?= h((string)($tenantHealth['invalidation_lag']['p95_seconds'] ?? '-')) ?></div>
                        <div class="small text-muted mt-2">Drain should be temporary; high invalidation lag can indicate stale pod/runtime caches.</div>
                    </div>
                </div>
            </div>
            <?php $tenantRows = is_array($tenantHealth['tenant_rows'] ?? null) ? $tenantHealth['tenant_rows'] : []; ?>
            <?php if ($tenantRows !== []) : ?>
                <h3 class="h6">Tenants needing attention</h3>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead>
                            <tr>
                                <th>Tenant</th>
                                <th>Status</th>
                                <th>Migration</th>
                                <th>Backlog</th>
                                <th>Failures (24h)</th>
                                <th>Drain</th>
                                <th>Invalidation lag</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tenantRows as $row) : ?>
                                <tr>
                                    <td><?= h((string)($row['slug'] ?? '')) ?></td>
                                    <td><?= h((string)($row['status'] ?? '')) ?></td>
                                    <td><?= h((string)($row['migration_state'] ?? 'unknown')) ?></td>
                                    <td><?= h((string)($row['operation_backlog'] ?? 0)) ?></td>
                                    <td><?= h((string)($row['recent_failures_24h'] ?? 0)) ?></td>
                                    <td><?= !empty($row['is_draining']) ? 'yes' : 'no' ?></td>
                                    <td><?= h((string)($row['invalidation_lag_seconds'] ?? '-')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else : ?>
                <p class="text-muted mb-0">No tenant-level warning signals detected.</p>
            <?php endif; ?>
        <?php else : ?>
            <p class="text-muted mb-0">Tenant health signals are currently unavailable.</p>
        <?php endif; ?>
    </div>
</div>
<h2 class="h4">Deployment Migration Dashboard</h2>
<p class="text-muted">
    Deployment migrations are parent <code>tenant_migrate_all</code> operations with child jobs per tenant.
    Resume only after reviewing failed child errors and confirming the target deployment is still the intended one.
</p>
<?= $this->element('PlatformAdmin/deployment_migration_dashboard', [
    'deploymentMigrationFilters' => $deploymentMigrationFilters,
    'deploymentMigrationDashboard' => $deploymentMigrationDashboard,
    'capabilities' => $capabilities,
]) ?>
<h2 class="h4 mt-4">Operation Queue</h2>
<p class="text-muted">
    Need invocation guidance? <?= $this->Html->link('Browse the operation command catalog', ['action' => 'commandCatalog']) ?>.
    Approval rules are operation-specific; disabled buttons explain whether the blocker is state, role, requester separation,
    or a terminal operation.
</p>
<?= $this->element('PlatformAdmin/operation_queue', [
    'operationRows' => $operationRows,
    'operationStateOptions' => $operationStateOptions,
    'operationSortOptions' => $operationSortOptions,
    'operationTenantOptions' => $operationTenantOptions,
    'filters' => $filters,
    'capabilities' => $capabilities,
]) ?>
