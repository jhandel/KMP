<?php

/**
 * All Authorizations Grid Table Element
 *
 * Custom table element for the all-authorizations admin report.
 *
 * @var \App\View\AppView $this
 * @var iterable $data The authorization data
 * @var array $gridState Grid state object
 */

$visibleColumns = $gridState['columns']['visible'] ?? [];
$allColumns = $gridState['columns']['all'] ?? [];
?>

<div class="table-responsive">
    <table class="table table-striped">
        <thead>
            <tr>
                <?php foreach ($visibleColumns as $columnKey): ?>
                <?php $column = $allColumns[$columnKey] ?? null; ?>
                <?php if ($column): ?>
                <th scope="col">
                    <?= h($column['label'] ?? $columnKey) ?>
                </th>
                <?php endif; ?>
                <?php endforeach; ?>
                <th scope="col" class="actions"></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($data) || (is_countable($data) && count($data) === 0)): ?>
            <tr>
                <td colspan="<?= count($visibleColumns) + 1 ?>"
                    class="text-center text-muted py-4">
                    No authorizations found.
                </td>
            </tr>
            <?php else: ?>
            <?php foreach ($data as $authorization): ?>
            <tr>
                <?php foreach ($visibleColumns as $columnKey): ?>
                <?php $column = $allColumns[$columnKey] ?? null; ?>
                <?php if ($column): ?>
                <td>
                    <?php
                    $value = null;
                    $renderField = $column['renderField'] ?? null;

                    if ($renderField) {
                        $parts = explode('.', $renderField);
                        $value = $authorization;
                        foreach ($parts as $part) {
                            if (is_object($value) && isset($value->{$part})) {
                                $value = $value->{$part};
                            } elseif (is_array($value) && isset($value[$part])) {
                                $value = $value[$part];
                            } else {
                                $value = null;
                                break;
                            }
                        }
                    } else {
                        $value = $authorization->{$columnKey} ?? null;
                    }

                    $type = $column['type'] ?? 'string';
                    $clickAction = $column['clickAction'] ?? null;

                    if ($clickAction && $value !== null):
                        $actionUrl = preg_replace_callback('/:([a-z_]+)/', function ($m) use ($authorization) {
                            return h($authorization->{$m[1]} ?? '');
                        }, str_replace('navigate:', '', $clickAction));
                    ?>
                        <?= $this->Html->link(h($value), $actionUrl, ['data-turbo-frame' => '_top']) ?>
                    <?php elseif ($value === null):
                        echo '';
                    elseif ($type === 'date' || $type === 'datetime'):
                        if ($value instanceof \DateTimeInterface) {
                            echo $this->Timezone->format($value, null, null, \IntlDateFormatter::SHORT);
                        } else {
                            echo h($value);
                        }
                    else:
                        echo h($value);
                    endif;
                    ?>
                </td>
                <?php endif; ?>
                <?php endforeach; ?>
                <td class="actions text-end text-nowrap">
                    <button type="button" class="btn-sm btn btn-outline-info"
                        data-bs-toggle="modal" data-bs-target="#workflowAuditModal"
                        data-auth-id="<?= $authorization->id ?>"
                        title="View workflow audit trail">
                        <i class="bi bi-clock-history"></i> Audit
                    </button>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Pagination -->
<div class="paginator">
    <ul class="pagination">
        <?= $this->Paginator->first('<< ' . __('first')) ?>
        <?= $this->Paginator->prev('< ' . __('previous')) ?>
        <?= $this->Paginator->numbers() ?>
        <?= $this->Paginator->next(__('next') . ' >') ?>
        <?= $this->Paginator->last(__('last') . ' >>') ?>
    </ul>
    <p><?= $this->Paginator->counter(__('Page {{page}} of {{pages}}, showing {{current}} record(s) out of {{count}} total')) ?>
    </p>
</div>
