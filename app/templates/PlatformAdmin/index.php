<?php
/**
 * @var \Cake\View\View $this
 * @var array<int, \App\Model\Entity\Tenant> $tenants
 * @var array<int, \App\Model\Entity\TenantOperationJob> $jobs
 */
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1>Tenants</h1>
    <?= $this->Html->link('Create Tenant', ['action' => 'createTenant'], ['class' => 'btn btn-primary']) ?>
</div>
<div class="card mb-4">
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
<h2 class="h4">Recent Operations</h2>
<div class="card">
    <div class="table-responsive">
        <table class="table table-sm mb-0">
            <thead><tr><th>Operation</th><th>Status</th><th>Tenant</th><th>Created</th></tr></thead>
            <tbody>
                <?php foreach ($jobs as $job) : ?>
                    <tr>
                        <td><?= h($job->operation) ?></td>
                        <td><?= h($job->status) ?></td>
                        <td><?= h($job->tenant->slug ?? '-') ?></td>
                        <td><?= h($job->created) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
