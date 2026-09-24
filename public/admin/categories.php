<?php
/**
 * CASMS — Category management (FR-9.2)
 *
 * Covers both activity categories (Culture, Arts, Sports…) and inventory
 * categories (Costume, Equipment…). Until now these were seed-data only.
 */

declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';

require_role(['admin']);

$errors = [];

if (is_post()) {
    csrf_verify();

    $action = post('action');
    $kind   = post('kind');                      // 'activity' | 'inventory'

    if (!in_array($kind, ['activity', 'inventory'], true)) {
        flash('error', 'Unknown category type.');
        redirect('admin/categories.php');
    }

    // Table and key are chosen from this fixed map, never from user input.
    $table  = $kind === 'activity' ? 'activity_categories' : 'inventory_categories';
    $keyCol = $kind === 'activity' ? 'category_id'         : 'inv_category_id';

    // ------------------------------------------------------------ Add / edit
    if ($action === 'save') {
        $categoryId  = (int) post('category_id');
        $name        = post('name');
        $description = post('description');
        $colour      = post('color_hex');
        $isActive    = post('is_active') === '1' ? 1 : 0;

        if ($name === '') {
            $errors[] = 'Category name is required.';
        }
        if ($kind === 'activity' && $colour !== '' && !preg_match('/^#[0-9A-Fa-f]{6}$/', $colour)) {
            $errors[] = 'Colour must be a six-digit hex value such as #2980B9.';
        }

        if ($errors === []) {
            $clash = fetch_value(
                "SELECT 1 FROM `$table` WHERE name = ? AND `$keyCol` <> ?",
                [$name, $categoryId]
            );
            if ($clash) {
                $errors[] = 'A category named "' . $name . '" already exists.';
            }
        }

        if ($errors === []) {
            if ($categoryId > 0) {
                if ($kind === 'activity') {
                    query(
                        "UPDATE `$table` SET name = ?, description = ?, color_hex = ?, is_active = ?
                          WHERE `$keyCol` = ?",
                        [$name, $description !== '' ? $description : null,
                         $colour !== '' ? $colour : null, $isActive, $categoryId]
                    );
                } else {
                    query(
                        "UPDATE `$table` SET name = ?, description = ? WHERE `$keyCol` = ?",
                        [$name, $description !== '' ? $description : null, $categoryId]
                    );
                }
                audit_log('update', $kind . '_category', $categoryId, 'Updated category: ' . $name);
                flash('success', 'Category updated.');
            } else {
                if ($kind === 'activity') {
                    query(
                        "INSERT INTO `$table` (name, description, color_hex, is_active) VALUES (?, ?, ?, ?)",
                        [$name, $description !== '' ? $description : null,
                         $colour !== '' ? $colour : null, $isActive]
                    );
                } else {
                    query(
                        "INSERT INTO `$table` (name, description) VALUES (?, ?)",
                        [$name, $description !== '' ? $description : null]
                    );
                }
                audit_log('create', $kind . '_category', (int) db()->lastInsertId(),
                          'Added category: ' . $name);
                flash('success', 'Category added.');
            }

            clear_old_input();
            redirect('admin/categories.php');
        }

        remember_input($_POST);
    }

    // ---------------------------------------------------------------- Delete
    if ($action === 'delete') {
        $categoryId = (int) post('category_id');

        $category = fetch_one("SELECT * FROM `$table` WHERE `$keyCol` = ?", [$categoryId]);
        if ($category === null) {
            flash('error', 'That category no longer exists.');
            redirect('admin/categories.php');
        }

        // A category in use cannot be removed without orphaning records.
        $inUse = $kind === 'activity'
            ? (int) fetch_value('SELECT COUNT(*) FROM activities WHERE category_id = ?', [$categoryId])
            : (int) fetch_value('SELECT COUNT(*) FROM inventory_items WHERE inv_category_id = ?', [$categoryId]);

        if ($inUse > 0) {
            flash('error', '"' . $category['name'] . '" is used by ' . $inUse . ' record(s) and cannot be deleted.'
                . ($kind === 'activity' ? ' Mark it inactive instead.' : ''));
            redirect('admin/categories.php');
        }

        query("DELETE FROM `$table` WHERE `$keyCol` = ?", [$categoryId]);
        audit_log('delete', $kind . '_category', $categoryId, 'Deleted category: ' . $category['name']);
        flash('success', 'Category deleted.');
        redirect('admin/categories.php');
    }
}

$activityCategories = fetch_all(
    'SELECT c.*, (SELECT COUNT(*) FROM activities a WHERE a.category_id = c.category_id) AS usage_count
       FROM activity_categories c ORDER BY c.name'
);
$inventoryCategories = fetch_all(
    'SELECT c.*, (SELECT COUNT(*) FROM inventory_items i
                   WHERE i.inv_category_id = c.inv_category_id) AS usage_count
       FROM inventory_categories c ORDER BY c.name'
);

// Editing one?
$editKind = get('kind');
$editId   = get_id('edit');
$editing  = null;
if ($editId !== null && in_array($editKind, ['activity', 'inventory'], true)) {
    $editing = $editKind === 'activity'
        ? fetch_one('SELECT * FROM activity_categories WHERE category_id = ?', [$editId])
        : fetch_one('SELECT * FROM inventory_categories WHERE inv_category_id = ?', [$editId]);
}

function category_field(string $key, ?array $row, string $default = ''): string
{
    $remembered = old($key);
    if ($remembered !== '') {
        return $remembered;
    }
    $value = $row[$key] ?? '';
    return $value === '' || $value === null ? $default : (string) $value;
}

$formKind = $editing !== null ? (string) $editKind : (old('kind') !== '' ? old('kind') : 'activity');

$pageTitle = 'Categories';
require __DIR__ . '/../../includes/layout/header.php';
?>

<div class="page-head">
    <div>
        <h1>Categories</h1>
        <p>Activity categories drive the calendar colours and filters; inventory categories group the catalog.</p>
    </div>
    <div class="btn-row">
        <a class="btn btn-outline" href="<?= url('admin/users.php') ?>">User accounts</a>
    </div>
</div>

<?php if ($errors !== []): ?>
    <div class="alert alert-error">
        <?php foreach ($errors as $error): ?><div><?= e($error) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="split">
    <div class="stack">
        <section class="card<?= $activityCategories === [] ? '' : ' card-flush' ?>">
            <div class="card-head"><h2>Activity categories</h2></div>
            <?php if ($activityCategories === []): ?>
                <div class="empty">
                    <strong>No activity categories yet</strong>
                    Add one with the form on this page so activities can be classified.
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data">
                        <thead>
                            <tr><th>Name</th><th>Colour</th><th class="num">Used by</th><th>Status</th><th class="actions">Actions</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($activityCategories as $category): ?>
                            <tr>
                                <td>
                                    <strong><?= e($category['name']) ?></strong>
                                    <?php if ($category['description']): ?>
                                        <div class="hint"><?= e($category['description']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="nowrap">
                                    <?php if ($category['color_hex']): ?>
                                        <span class="swatch" style="background: <?= e($category['color_hex']) ?>"></span>
                                        <?= e($category['color_hex']) ?>
                                    <?php else: ?>
                                        <span class="muted">Not set</span>
                                    <?php endif; ?>
                                </td>
                                <td class="num"><?= (int) $category['usage_count'] ?></td>
                                <td><?= (int) $category['is_active'] === 1
                                        ? '<span class="badge badge-success">Active</span>'
                                        : '<span class="badge badge-muted">Inactive</span>' ?></td>
                                <td class="actions">
                                    <div class="btn-row">
                                        <a class="btn btn-outline btn-sm"
                                           href="<?= url('admin/categories.php?kind=activity&edit=' . (int) $category['category_id']) ?>"
                                           aria-label="Edit <?= e($category['name']) ?>">Edit</a>
                                        <?php if ((int) $category['usage_count'] === 0): ?>
                                            <form method="post" class="inline-form"
                                                  onsubmit="return confirm('Delete this category?');">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="kind" value="activity">
                                                <input type="hidden" name="category_id" value="<?= (int) $category['category_id'] ?>">
                                                <button type="submit" class="btn btn-danger btn-sm"
                                                        aria-label="Delete <?= e($category['name']) ?>">Delete</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <section class="card<?= $inventoryCategories === [] ? '' : ' card-flush' ?>">
            <div class="card-head"><h2>Inventory categories</h2></div>
            <?php if ($inventoryCategories === []): ?>
                <div class="empty">
                    <strong>No inventory categories yet</strong>
                    Add one with the form on this page to group the inventory catalog.
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data">
                        <thead><tr><th>Name</th><th class="num">Items</th><th class="actions">Actions</th></tr></thead>
                        <tbody>
                        <?php foreach ($inventoryCategories as $category): ?>
                            <tr>
                                <td>
                                    <strong><?= e($category['name']) ?></strong>
                                    <?php if ($category['description']): ?>
                                        <div class="hint"><?= e($category['description']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="num"><?= (int) $category['usage_count'] ?></td>
                                <td class="actions">
                                    <div class="btn-row">
                                        <a class="btn btn-outline btn-sm"
                                           href="<?= url('admin/categories.php?kind=inventory&edit=' . (int) $category['inv_category_id']) ?>"
                                           aria-label="Edit <?= e($category['name']) ?>">Edit</a>
                                        <?php if ((int) $category['usage_count'] === 0): ?>
                                            <form method="post" class="inline-form"
                                                  onsubmit="return confirm('Delete this category?');">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="kind" value="inventory">
                                                <input type="hidden" name="category_id" value="<?= (int) $category['inv_category_id'] ?>">
                                                <button type="submit" class="btn btn-danger btn-sm"
                                                        aria-label="Delete <?= e($category['name']) ?>">Delete</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <section class="card" id="category-form">
        <div class="card-head"><h2><?= $editing ? 'Edit category' : 'Add a category' ?></h2></div>

        <form method="post" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="category_id"
                   value="<?= $editing ? (int) ($editing['category_id'] ?? $editing['inv_category_id']) : 0 ?>">

            <div class="form-row">
                <label for="kind">Category type <span class="req">*</span></label>
                <select id="kind" name="kind" required <?= $editing ? 'disabled' : '' ?>>
                    <option value="activity"  <?= $formKind === 'activity'  ? 'selected' : '' ?>>Activity category</option>
                    <option value="inventory" <?= $formKind === 'inventory' ? 'selected' : '' ?>>Inventory category</option>
                </select>
                <?php if ($editing): ?>
                    <input type="hidden" name="kind" value="<?= e((string) $editKind) ?>">
                    <div class="hint">The type cannot be changed once created.</div>
                <?php endif; ?>
            </div>

            <div class="form-row">
                <label for="name">Name <span class="req">*</span></label>
                <input type="text" id="name" name="name" required
                       value="<?= e(category_field('name', $editing)) ?>">
            </div>

            <div class="form-row">
                <label for="description">Description <span class="optional">(optional)</span></label>
                <input type="text" id="description" name="description"
                       value="<?= e(category_field('description', $editing)) ?>">
            </div>

            <div class="form-row">
                <label for="color_hex">Colour <span class="optional">(activity categories only)</span></label>
                <input type="text" id="color_hex" name="color_hex" placeholder="#2980B9"
                       value="<?= e(category_field('color_hex', $editing)) ?>">
                <div class="hint">Six-digit hex, used to colour the activity list.</div>
            </div>

            <div class="form-row">
                <label class="check">
                    <input type="checkbox" name="is_active" value="1"
                        <?= !$editing || (int) ($editing['is_active'] ?? 1) === 1 ? 'checked' : '' ?>>
                    <span>Active: can be chosen when creating an activity</span>
                </label>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary"><?= $editing ? 'Save changes' : 'Add category' ?></button>
                <?php if ($editing): ?>
                    <a class="btn btn-outline" href="<?= url('admin/categories.php') ?>">Cancel</a>
                <?php endif; ?>
            </div>
        </form>
    </section>
</div>

<?php
require __DIR__ . '/../../includes/layout/footer.php';
clear_old_input();
