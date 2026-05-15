<?php
/**
 * @var \Cake\View\View $this
 * @var array<int, array{command: array<string, mixed>, can_invoke: bool, unmet_capability: string|null}> $catalogRows
 */
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="mb-0">Operation Command Catalog</h1>
    <?= $this->Html->link('Back to Tenants', ['action' => 'index'], ['class' => 'btn btn-outline-secondary']) ?>
</div>
<p class="text-muted">
    Gateway-approved operations, required parameters, target modes, approval policy, and role guidance for safe invocation.
    JSON API: <code>/platform-admin/operations/catalog.json</code>
</p>
<div class="alert alert-info">
    This catalog is the replacement for ad hoc production shell commands. If an operation is not listed here,
    it is not approved for browser-driven production execution. Add new work as a catalog command with validation,
    RBAC, approval policy, idempotency, and audit correlation.
</div>
<?= $this->element('PlatformAdmin/command_catalog', ['catalogRows' => $catalogRows]) ?>
