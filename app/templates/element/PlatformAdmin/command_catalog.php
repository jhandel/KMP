<?php
/**
 * @var \Cake\View\View $this
 * @var array<int, array{command: array<string, mixed>, can_invoke: bool, unmet_capability: string|null}> $catalogRows
 */
?>
<div class="card mb-4">
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead>
                <tr>
                    <th>Command</th>
                    <th>Parameters</th>
                    <th>Target</th>
                    <th>Approval</th>
                    <th>Capability / Roles</th>
                    <th>Validation hints</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($catalogRows as $row) : ?>
                    <?php $command = $row['command']; ?>
                    <?php $approvalPolicy = is_array($command['approval_policy'] ?? null) ? $command['approval_policy'] : []; ?>
                    <?php $approvalMode = (string)($approvalPolicy['mode'] ?? 'none'); ?>
                    <?php $requiredApprovals = max(0, (int)($approvalPolicy['required_approvals'] ?? 0)); ?>
                    <tr>
                        <td>
                            <code><?= h((string)$command['id']) ?></code>
                            <div><?= h((string)$command['name']) ?></div>
                        </td>
                        <td>
                            <div><strong>Required:</strong> <?= h($command['required_parameters'] === [] ? '-' : implode(', ', (array)$command['required_parameters'])) ?></div>
                            <div><strong>Optional:</strong> <?= h($command['optional_parameters'] === [] ? '-' : implode(', ', (array)$command['optional_parameters'])) ?></div>
                            <?php if ((array)$command['allowed_values'] !== []) : ?>
                                <div class="small text-muted">Allowed values: <?= h((string)json_encode($command['allowed_values'], JSON_UNESCAPED_SLASHES)) ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div><?= h((string)$command['target_scope']) ?></div>
                            <small class="text-muted"><?= h(implode(', ', (array)$command['target_modes'])) ?></small>
                            <div class="small text-muted mt-1">
                                <strong>Target modes:</strong> single affects one tenant; selected/all-tenant fan out through child jobs when supported.
                            </div>
                        </td>
                        <td>
                            <span class="badge <?= $requiredApprovals > 0 ? 'text-bg-warning' : 'text-bg-success' ?>">
                                <?= h($approvalMode) ?>
                            </span>
                            <div><small class="text-muted">Required approvals: <?= h((string)$requiredApprovals) ?></small></div>
                            <?php if ($requiredApprovals > 0) : ?>
                                <div><small class="text-muted">Distinct approvers: <?= !empty($approvalPolicy['require_distinct_approvers']) ? 'yes' : 'no' ?></small></div>
                                <div><small class="text-muted">Requester separation: <?= !empty($approvalPolicy['require_requester_separation']) ? 'yes' : 'no' ?></small></div>
                            <?php endif; ?>
                            <div><small class="text-muted">Idempotency: <?= h((string)$command['idempotency_scope']) ?></small></div>
                            <div class="small text-muted mt-1">
                                Approval behavior is per command; the queue shows the exact reason when an approve/reject button is disabled.
                            </div>
                        </td>
                        <td>
                            <?php if ((string)($command['required_capability'] ?? '') !== '') : ?>
                                <div><code><?= h((string)$command['required_capability']) ?></code></div>
                            <?php else : ?>
                                <div class="text-muted">-</div>
                            <?php endif; ?>
                            <?php if ((array)$command['allowed_roles'] !== []) : ?>
                                <small class="text-muted"><?= h(implode(', ', (array)$command['allowed_roles'])) ?></small>
                            <?php endif; ?>
                            <?php if (!$row['can_invoke']) : ?>
                                <div class="small text-danger mt-1">Unavailable to your role.</div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ((array)$command['preflight_hints'] === []) : ?>
                                <span class="text-muted">-</span>
                            <?php else : ?>
                                <ul class="mb-0 ps-3">
                                    <?php foreach ((array)$command['preflight_hints'] as $hint) : ?>
                                        <li><small><?= h((string)$hint) ?></small></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($catalogRows === []) : ?>
                    <tr><td colspan="6" class="text-center text-muted py-3">No gateway commands are currently enabled.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
