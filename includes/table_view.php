<?php
$editData = [];
if (isset($_GET['edit'])) {
    $editData = getById($pdo, $table, $_GET['edit'], $idCol);
}
$canDelete = isAdmin();
?>
<?php if (isset($_SESSION['rental_error'])): ?>
    <p class="table-error"><?= htmlspecialchars($_SESSION['rental_error']) ?></p>
    <?php unset($_SESSION['rental_error']); ?>
<?php endif; ?>

<div class="table-heading">
    <h3><?= htmlspecialchars(ucfirst($table)) ?> List</h3>
    <?php if ($canManageTable): ?>
        <button type="button" class="primary-button" id="open-record-dialog">+ Add <?= htmlspecialchars(rtrim(ucfirst($table), 's')) ?></button>
    <?php endif; ?>
</div>

<form id="table-filters" method="GET">
    <input type="hidden" name="table" value="<?= htmlspecialchars($table) ?>">
    <input type="hidden" name="page" value="1">
    <?php if ($sortColumn): ?>
        <input type="hidden" name="sort" value="<?= htmlspecialchars($sortColumn) ?>">
    <?php endif; ?>
</form>

<div class="table-scroll">
    <table class="data-table">
        <thead>
            <tr>
                <?php foreach ($columns as $column): ?>
                    <th data-column="<?= htmlspecialchars($column) ?>">
                        <a class="sort-link" href="<?= htmlspecialchars(tableUrl(['sort' => $column, 'page' => 1])) ?>">
                            <?= htmlspecialchars(str_replace('_', ' ', $column)) ?>
                            <?php if ($sortColumn === $column): ?><span class="sort-arrow" aria-label="ascending">↑</span><?php endif; ?>
                        </a>
                        <button type="button" class="column-visibility" data-toggle-column="<?= htmlspecialchars($column) ?>" aria-label="Hide <?= htmlspecialchars($column) ?>" title="Hide column">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5c-5 0-9.27 3.11-11 7 1.73 3.89 6 7 11 7s9.27-3.11 11-7c-1.73-3.89-6-7-11-7zm0 11a4 4 0 1 1 0-8 4 4 0 0 1 0 8zm0-2a2 2 0 1 0 0-4 2 2 0 0 0 0 4z"/></svg>
                        </button>
                    </th>
                <?php endforeach; ?>
                <th class="actions-heading">Actions</th>
            </tr>
            <tr class="filter-row">
                <?php foreach ($columns as $column):
                    $isNumeric = in_array($column, $numericColumns[$table], true);
                    $filter = isset($submittedFilters[$column]) && is_array($submittedFilters[$column]) ? $submittedFilters[$column] : [];
                    $filterOperator = isset($filter['op']) && is_scalar($filter['op']) ? (string)$filter['op'] : ($isNumeric ? 'eq' : 'contains');
                    $filterValue = isset($filter['value']) && is_scalar($filter['value']) ? (string)$filter['value'] : '';
                ?>
                    <th data-column="<?= htmlspecialchars($column) ?>">
                        <div class="column-filter">
                            <select name="filters[<?= htmlspecialchars($column) ?>][op]" form="table-filters" aria-label="<?= htmlspecialchars($column) ?> filter type">
                                <?php if ($isNumeric): ?>
                                    <?php foreach (['eq' => 'eq', 'neq' => 'neq', 'gt' => 'gt', 'lt' => 'lt', 'gte' => 'gte', 'lte' => 'lte'] as $value => $label): ?>
                                        <option value="<?= $value ?>" <?= $filterOperator === $value ? 'selected' : '' ?>><?= $label ?></option>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <?php foreach (['starts' => 'starts with', 'ends' => 'ends with', 'contains' => 'contains', 'not_contains' => "doesn't contain", 'null' => 'null', 'not_null' => 'not null'] as $value => $label): ?>
                                        <option value="<?= $value ?>" <?= $filterOperator === $value ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                            <input
                                type="<?= $isNumeric ? 'number' : 'search' ?>"
                                <?= $isNumeric ? 'step="any"' : '' ?>
                                name="filters[<?= htmlspecialchars($column) ?>][value]"
                                form="table-filters"
                                value="<?= htmlspecialchars((string)$filterValue) ?>"
                                aria-label="Filter <?= htmlspecialchars($column) ?>"
                                placeholder="Filter"
                            >
                        </div>
                    </th>
                <?php endforeach; ?>
                <th class="actions-heading"></th>
            </tr>
        </thead>
        <tbody>
            <?php if ($rows): ?>
                <?php foreach ($rows as $record): ?>
                    <?php
                    $canSendToMaintenance = $table === 'cars' && (isAdmin() || isAgent());
                    $canMarkDone = $table === 'maintenance'
                        && (isAdmin() || isMechanic())
                        && ($record['status'] ?? '') !== 'completed';
                    $hasRowActions = $canManageTable || $canDelete || $canSendToMaintenance || $canMarkDone;
                    ?>
                    <tr class="data-row" data-row-id="<?= htmlspecialchars((string)$record[$idCol]) ?>">
                        <?php foreach ($columns as $column):
                            $value = $record[$column] ?? null;
                            $stringValue = (string)($value ?? '');
                            $lowerValue = strtolower($stringValue);
                        ?>
                            <td data-column="<?= htmlspecialchars($column) ?>">
                                <?php if ($table === 'cars' && $column === 'image_url' && $stringValue !== ''):
                                    $imageScheme = strtolower((string)parse_url($stringValue, PHP_URL_SCHEME));
                                    if (filter_var($stringValue, FILTER_VALIDATE_URL) && in_array($imageScheme, ['http', 'https'], true)):
                                ?>
                                    <a href="<?= htmlspecialchars($stringValue) ?>" target="_blank" rel="noopener noreferrer" aria-label="Open car image">
                                        <img class="car-thumbnail" src="<?= htmlspecialchars($stringValue) ?>" alt="<?= htmlspecialchars(trim(($record['brand'] ?? '') . ' ' . ($record['model'] ?? ''))) ?>">
                                    </a>
                                <?php else: ?>
                                    <?= htmlspecialchars($stringValue) ?>
                                <?php endif; ?>
                                <?php elseif (in_array($lowerValue, ['available', 'completed', 'paid'], true)): ?>
                                    <span class="status-badge status-green"><?= htmlspecialchars($stringValue) ?></span>
                                <?php elseif (in_array($lowerValue, ['rented', 'ongoing', 'pending', 'future', 'in_progress'], true)): ?>
                                    <span class="status-badge status-orange"><?= htmlspecialchars($stringValue) ?></span>
                                <?php elseif (in_array($lowerValue, ['maintenance', 'cancelled', 'refunded'], true)): ?>
                                    <span class="status-badge status-red"><?= htmlspecialchars($stringValue) ?></span>
                                <?php else: ?>
                                    <?= htmlspecialchars($stringValue) ?>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                        <td class="row-actions">
                            <?php if ($hasRowActions): ?>
                                <button type="button" class="action-menu-button" aria-label="Actions for row <?= htmlspecialchars((string)$record[$idCol]) ?>" aria-haspopup="true" aria-expanded="false">⋮</button>
                                <div class="action-menu" role="menu">
                                    <?php if ($canManageTable): ?>
                                        <a role="menuitem" href="<?= htmlspecialchars(tableUrl(['edit' => $record[$idCol], 'page' => null])) ?>">Edit</a>
                                    <?php endif; ?>
                                    <?php if ($canDelete): ?>
                                        <a role="menuitem" href="<?= htmlspecialchars(tableUrl(['delete' => $record[$idCol], 'page' => null])) ?>" data-confirm="Delete this record?">Delete</a>
                                    <?php endif; ?>
                                    <?php if ($canSendToMaintenance): ?>
                                        <a role="menuitem" href="?table=maintenance&amp;add_from_car=<?= urlencode((string)$record['car_id']) ?>">Send to maintenance</a>
                                    <?php endif; ?>
                                    <?php if ($canMarkDone): ?>
                                        <a role="menuitem" href="<?= htmlspecialchars(tableUrl(['mark_done' => $record['maintenance_id'], 'page' => null])) ?>">Mark as done</a>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td class="empty-state" colspan="<?= count($columns) + 1 ?>">No data available.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<div class="table-footer">
    <label>Rows per page
        <select name="per_page" form="table-filters" id="rows-per-page">
            <?php foreach ($pageSizeOptions as $option): ?>
                <option value="<?= $option ?>" <?= $pageSize === $option ? 'selected' : '' ?>><?= $option ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <span><?= $totalRows ? ($offset + 1) . '–' . min($offset + $pageSize, $totalRows) : 0 ?> of <?= $totalRows ?></span>
    <nav class="pagination" aria-label="Table pages">
        <a href="<?= htmlspecialchars(tableUrl(['page' => max(1, $page - 1)])) ?>" aria-label="Previous page" class="<?= $page <= 1 ? 'disabled' : '' ?>">‹</a>
        <span>Page <?= $page ?> of <?= $pageCount ?></span>
        <a href="<?= htmlspecialchars(tableUrl(['page' => min($pageCount, $page + 1)])) ?>" aria-label="Next page" class="<?= $page >= $pageCount ? 'disabled' : '' ?>">›</a>
    </nav>
</div>

<?php if ($canManageTable): ?>
    <dialog id="record-dialog" class="record-dialog" <?= isset($_GET['edit']) || isset($_GET['add_from_car']) ? 'open' : '' ?>>
        <div class="dialog-heading">
            <h3><?= isset($_GET['edit']) ? 'Edit' : 'Add' ?> <?= htmlspecialchars(rtrim(ucfirst($table), 's')) ?></h3>
            <button type="button" class="dialog-close" aria-label="Close dialog">×</button>
        </div>
        <?php include __DIR__ . '/table_form.php'; ?>
    </dialog>
<?php endif; ?>
