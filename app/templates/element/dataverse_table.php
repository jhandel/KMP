<?php

/**
 * Dataverse Table Element
 * 
 * Renders a data table with sortable headers, custom cell renderers,
 * row actions, and integration with the grid-view Stimulus controller.
 * 
 * @var \App\View\AppView $this
 * @var array $columns Column metadata from GridColumns class
 * @var array $visibleColumns Currently visible column keys
 * @var array $data The result set to display
 * @var array $currentSort Current sort configuration ['field' => 'column_key', 'direction' => 'asc|desc']
 * @var string $controllerName The Stimulus controller identifier
 * @var string $primaryKey The primary key field for row identification (default: 'id')
 * @var string|null $gridKey Grid key for component uniqueness (optional)
 * @var array $rowActions Row action configurations (optional)
 * @var \Authorization\Identity|null $user Current user for permission checks (optional)
 * @var bool $enableBulkSelection Whether to show row selection checkboxes (optional)
 * @var array $bulkSelection Bulk selection label configuration (optional)
 */

use App\KMP\StaticHelpers;

$controllerName = $controllerName ?? 'grid-view';
$primaryKey = $primaryKey ?? 'id';
$currentSort = $currentSort ?? [];
$gridKey = $gridKey ?? 'grid';
$rowActions = $rowActions ?? [];
$user = $user ?? $this->request->getAttribute('identity');
$enableColumnPicker = $enableColumnPicker ?? true;
$enableBulkSelection = $enableBulkSelection ?? false;
$bulkSelection = $bulkSelection ?? [];

$selectAllBulkSelectionLabel = $bulkSelection['selectAllLabel'] ?? __('Select all rows on this page');
$rowBulkSelectionLabelTemplate = $bulkSelection['rowLabelTemplate'] ?? null;

$getRowValue = function ($row, string $path) use (&$getRowValue, $columns) {
    if (isset($columns[$path]['renderField'])) {
        return $getRowValue($row, $columns[$path]['renderField']);
    }

    if (str_contains($path, '.')) {
        $value = $row;
        foreach (explode('.', $path) as $part) {
            $value = $getRowValue($value, $part);
            if ($value === null) {
                return null;
            }
        }

        return $value;
    }

    if (is_array($row) && array_key_exists($path, $row)) {
        return $row[$path];
    }

    if ($row instanceof ArrayAccess && isset($row[$path])) {
        return $row[$path];
    }

    if (is_object($row)) {
        if (method_exists($row, 'has') && method_exists($row, 'get') && $row->has($path)) {
            return $row->get($path);
        }

        if (isset($row->{$path})) {
            return $row->{$path};
        }
    }

    return null;
};

$formatBulkSelectionLabel = function (string $template, $row) use ($getRowValue): string {
    return preg_replace_callback('/\{([A-Za-z0-9_.-]+)\}/', function (array $matches) use ($row, $getRowValue): string {
        $value = $getRowValue($row, $matches[1]);

        if (is_bool($value)) {
            return $value ? __('Yes') : __('No');
        }

        if (is_scalar($value)) {
            return (string)$value;
        }

        if ($value instanceof Stringable) {
            return (string)$value;
        }

        return '';
    }, $template);
};

// Show actions column if column picker is enabled OR there are row actions
$showActionsColumn = $enableColumnPicker || !empty($rowActions);

// Calculate total column count for empty state colspan
$totalColumns = count($visibleColumns) + ($showActionsColumn ? 1 : 0) + ($enableBulkSelection ? 1 : 0);
?>

<div class="table-responsive">
    <table class="table table-striped table-hover" data-<?= h($controllerName) ?>-target="gridTable">
        <thead class="table-light">
            <tr>
                <?php if ($enableBulkSelection): ?>
                    <th scope="col" style="width: 40px; text-align: center;">
                        <input type="checkbox" 
                                class="form-check-input" 
                                data-<?= h($controllerName) ?>-target="selectAllCheckbox"
                                data-action="change-><?= h($controllerName) ?>#toggleAllSelection"
                                aria-label="<?= h($selectAllBulkSelectionLabel) ?>">
                    </th>
                <?php endif; ?>
                <?php foreach ($visibleColumns as $columnKey): ?>
                    <?php if (!isset($columns[$columnKey])) continue; ?>
                    <?php $column = $columns[$columnKey]; ?>
                    <?php
                    $isSorted = isset($currentSort['field']) && $currentSort['field'] === $columnKey;
                    $sortDirection = $isSorted ? $currentSort['direction'] : null;
                    $sortable = $column['sortable'] ?? true;
                    ?>
                    <th scope="col"
                        class="<?= $sortable ? 'sortable-header' : '' ?> <?= $isSorted ? 'sorted-' . $sortDirection : '' ?>"
                        style="<?= !empty($column['width']) ? 'width: ' . h($column['width']) . ';' : '' ?> text-align: <?= h($column['alignment'] ?? 'left') ?>;"
                        <?php if ($sortable): ?>
                        aria-sort="<?= $isSorted ? h($sortDirection === 'asc' ? 'ascending' : 'descending') : 'none' ?>"
                        <?php endif; ?>>
                        <?php if ($sortable): ?>
                            <button type="button"
                                class="sortable-header-button"
                                data-action="click-><?= h($controllerName) ?>#applySort"
                                data-column-key="<?= h($columnKey) ?>">
                                <span><?= h($column['label']) ?></span>
                                <span class="visually-hidden">
                                    <?php if ($isSorted): ?>
                                        <?= h(__('sorted {0}', $sortDirection === 'asc' ? __('ascending') : __('descending'))) ?>
                                    <?php else: ?>
                                        <?= h(__('not sorted')) ?>
                                    <?php endif; ?>
                                </span>
                            </button>
                        <?php else: ?>
                            <?= h($column['label']) ?>
                        <?php endif; ?>
                        <?php if ($sortable): ?>
                            <span class="sort-indicator ms-1" aria-hidden="true">
                                <?php if ($isSorted): ?>
                                    <?php if ($sortDirection === 'asc'): ?>
                                        <i class="bi bi-caret-up-fill"></i>
                                    <?php else: ?>
                                        <i class="bi bi-caret-down-fill"></i>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <i class="bi bi-caret-down text-muted"></i>
                                <?php endif; ?>
                            </span>
                        <?php endif; ?>
                    </th>
                <?php endforeach; ?>
                <?php if ($showActionsColumn): ?>
                    <th scope="col" class="text-end" style="width: 70px;">
                        <?php if ($enableColumnPicker): ?>
                            <button type="button"
                                class="btn btn-sm btn-outline-secondary"
                                aria-label="<?= h(__('Show or hide columns')) ?>"
                                data-bs-toggle="modal"
                                data-bs-target="#columnPickerModal-<?= h($gridKey) ?>">
                                <i class="bi bi-list-columns" aria-hidden="true"></i>
                            </button>
                        <?php endif; ?>
                    </th>
                <?php endif; ?>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($data)): ?>
                <tr>
                    <td colspan="<?= $totalColumns ?>" class="text-center text-muted py-4">
                        No records found.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($data as $row): ?>
                    <tr data-id="<?= h($row[$primaryKey]) ?>">
                        <?php if ($enableBulkSelection): ?>
                            <td style="text-align: center;">
                                <input type="checkbox" 
                                       class="form-check-input" 
                                        value="<?= h($row[$primaryKey]) ?>"
                                        data-<?= h($controllerName) ?>-target="rowCheckbox"
                                        data-action="change-><?= h($controllerName) ?>#toggleRowSelection"
                                        aria-label="<?= h($rowBulkSelectionLabelTemplate
                                            ? $formatBulkSelectionLabel($rowBulkSelectionLabelTemplate, $row)
                                            : __('Select row {0}', $row[$primaryKey])) ?>">
                            </td>
                        <?php endif; ?>
                        <?php foreach ($visibleColumns as $columnKey): ?>
                            <?php if (!isset($columns[$columnKey])) continue; ?>
                            <?php $column = $columns[$columnKey]; ?>
                            <td style="text-align: <?= h($column['alignment'] ?? 'left') ?>;">
                                <?php
                                // Get the cell value
                                $value = $row[$columnKey] ?? null;

                                // Check if column has a clickAction
                                $hasClickAction = !empty($column['clickAction']);
                                $clickAction = $column['clickAction'] ?? null;

                                // Apply custom cell renderer if specified
                                if (!empty($column['cellRenderer']) && is_callable($column['cellRenderer'])) {
                                    $renderedContent = $column['cellRenderer']($value, $row, $this);

                                    // Wrap with click action if specified
                                    if ($hasClickAction) {
                                        echo $this->element('dataverse_table_cell_action', [
                                            'content' => $renderedContent,
                                            'clickAction' => $clickAction,
                                            'clickActionPermission' => $column['clickActionPermission'] ?? null,
                                            'clickActionPermissionArgs' => $column['clickActionPermissionArgs'] ?? [],
                                            'row' => $row,
                                            'user' => $user,
                                            'primaryKey' => $primaryKey,
                                            'columnKey' => $columnKey,
                                        ]);
                                    } else {
                                        echo $renderedContent;
                                    }
                                } else {
                                    // Default rendering based on type
                                    $type = $column['type'] ?? 'string';

                                    // Render the content
                                    ob_start();
                                    switch ($type) {
                                        case 'relation':
                                            // Handle relation fields (e.g., branch_id should show branch.name)
                                            if (!empty($column['renderField'])) {
                                                // Parse dot notation for nested fields (e.g., 'branch.name')
                                                $relationParts = explode('.', $column['renderField']);
                                                $relationValue = $row;
                                                foreach ($relationParts as $part) {
                                                    if (is_array($relationValue) && isset($relationValue[$part])) {
                                                        $relationValue = $relationValue[$part];
                                                    } elseif (is_object($relationValue) && isset($relationValue->{$part})) {
                                                        $relationValue = $relationValue->{$part};
                                                    } else {
                                                        $relationValue = null;
                                                        break;
                                                    }
                                                }
                                                echo $relationValue ? h($relationValue) : '<span class="text-muted">—</span>';
                                            } else {
                                                // Fallback to showing the ID if no renderField specified
                                                echo $value !== null ? h($value) : '<span class="text-muted">—</span>';
                                            }
                                            break;
                                        case 'boolean':
                                            echo $value
                                                ? '<i class="bi bi-check-circle-fill text-success" aria-hidden="true"></i> <span class="visually-hidden">' . h(__('Yes')) . '</span>'
                                                : '<i class="bi bi-x-circle-fill text-danger" aria-hidden="true"></i> <span class="visually-hidden">' . h(__('No')) . '</span>';
                                            break;
                                        case 'badge':
                                            // Render as colored badges using badgeConfig
                                            $badgeConfig = $column['badgeConfig'] ?? null;
                                            if ($badgeConfig) {
                                                // Use configured badge rendering
                                                if ($value === null || $value === '') {
                                                    $config = $badgeConfig['nullValue'] ?? ['text' => 'None', 'class' => 'bg-secondary'];
                                                } else {
                                                    $config = $badgeConfig['hasValue'] ?? ['text' => 'Set', 'class' => 'bg-primary'];
                                                }
                                                echo '<span class="badge ' . h($config['class']) . '">' . h($config['text']) . '</span>';
                                            } else {
                                                // Fallback: boolean-style badge
                                                if ($value) {
                                                    echo '<span class="badge bg-success">Active</span>';
                                                } else {
                                                    echo '<span class="badge bg-secondary">Inactive</span>';
                                                }
                                            }
                                            break;
                                        case 'date':
                                            if ($value instanceof \Cake\I18n\DateTime) {
                                                // Use Timezone helper to apply user's timezone preference
                                                echo h($this->Timezone->date($value));
                                            } elseif ($value) {
                                                echo h($value);
                                            } else {
                                                echo '<span class="text-muted">—</span>';
                                            }
                                            break;
                                        case 'datetime':
                                            if ($value instanceof \Cake\I18n\DateTime) {
                                                // Use Timezone helper to apply user's timezone preference
                                                echo h($this->Timezone->format($value));
                                            } elseif ($value) {
                                                echo h($value);
                                            } else {
                                                echo '<span class="text-muted">—</span>';
                                            }
                                            break;
                                        case 'number':
                                        case 'integer':
                                            echo $value !== null ? h($value) : '<span class="text-muted">—</span>';
                                            break;
                                        case 'html':
                                            // Render HTML content without escaping (used for pre-formatted links, etc.)
                                            if ($value !== null && $value !== '') {
                                                echo $value;
                                            } else {
                                                echo '<span class="text-muted">—</span>';
                                            }
                                            break;
                                        case 'email':
                                            // Render email as a mailto link
                                            if ($value !== null && $value !== '') {
                                                echo '<a href="mailto:' . h($value) . '">' . h($value) . '</a>';
                                            } else {
                                                echo '<span class="text-muted">—</span>';
                                            }
                                            break;
                                        case 'string':
                                        default:
                                            if ($value !== null && $value !== '') {
                                                echo h($value);
                                            } else {
                                                echo '<span class="text-muted">—</span>';
                                            }
                                            break;
                                    }
                                    $renderedContent = ob_get_clean();

                                    // Wrap with click action if specified
                                    if ($hasClickAction) {
                                        echo $this->element('dataverse_table_cell_action', [
                                            'content' => $renderedContent,
                                            'clickAction' => $clickAction,
                                            'clickActionPermission' => $column['clickActionPermission'] ?? null,
                                            'clickActionPermissionArgs' => $column['clickActionPermissionArgs'] ?? [],
                                            'row' => $row,
                                            'user' => $user,
                                            'primaryKey' => $primaryKey,
                                            'columnKey' => $columnKey,
                                        ]);
                                    } else {
                                        echo $renderedContent;
                                    }
                                }
                                ?>
                            </td>
                        <?php endforeach; ?>
                        <?php if ($showActionsColumn): ?>
                            <td class="text-end text-nowrap">
                                <?php
                                // Render row actions
                                if (!empty($rowActions)) {
                                    echo $this->element('dataverse_table_row_actions', [
                                        'actions' => $rowActions,
                                        'row' => $row,
                                        'user' => $user,
                                    ]);
                                }
                                ?>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<style>
    .sortable-header {
        user-select: none;
    }

    .sortable-header:hover,
    .sortable-header:focus-within {
        background-color: rgba(0, 0, 0, 0.05);
    }

    .sortable-header-button {
        align-items: center;
        background: transparent;
        border: 0;
        color: inherit;
        cursor: pointer;
        display: inline-flex;
        font: inherit;
        margin: 0;
        padding: 0;
        text-align: inherit;
    }

    .sortable-header-button:focus-visible {
        outline: 2px solid var(--bs-primary);
        outline-offset: 2px;
    }

    .sorted-asc,
    .sorted-desc {
        background-color: rgba(13, 110, 253, 0.1);
    }

    .sort-indicator {
        font-size: 0.875rem;
    }
</style>
