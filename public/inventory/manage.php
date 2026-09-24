<?php
/**
 * CASMS — Add, edit, and remove inventory items (FR-6.8)
 */

declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';

require_role(['staff', 'admin']);

$itemId = get_id('id');
$isEdit = $itemId !== null;

$item = null;
if ($isEdit) {
    $item = fetch_one('SELECT * FROM inventory_items WHERE item_id = ?', [$itemId]);
    if ($item === null) {
        http_response_code(404);
        abort_page(404, 'Item not found.');
    }
}

$categories = fetch_all('SELECT inv_category_id, name FROM inventory_categories ORDER BY name');
$errors     = [];

if (is_post()) {
    csrf_verify();
    $action = post('action', 'save');

    // ---------------------------------------------------------------- Delete
    if ($action === 'delete' && $isEdit) {
        // An item with history stays in the catalog: deleting it would orphan
        // the borrowing records that reference it.
        $borrowCount = (int) fetch_value('SELECT COUNT(*) FROM borrowings WHERE item_id = ?', [$itemId]);
        if ($borrowCount > 0) {
            flash('error', 'This item has ' . $borrowCount . ' borrowing record(s) and cannot be deleted. '
                . 'Mark it "unavailable" instead so the history is preserved.');
            redirect('inventory/manage.php?id=' . $itemId);
        }

        query('DELETE FROM inventory_items WHERE item_id = ?', [$itemId]);
        delete_upload($item['photo_path']);
        audit_log('delete', 'inventory_item', $itemId, 'Deleted item: ' . $item['name']);
        flash('success', 'Item deleted.');
        redirect('inventory/index.php');
    }

    // ------------------------------------------------------------ Save
    $categoryIn = post('inv_category_id');
    $itemCode   = post('item_code');
    $name       = post('name');
    $description = post('description');
    $size       = post('size');
    $unit       = post('unit', 'pc');
    $quantityIn = post('quantity_total');
    $condition  = post('condition_note');
    $location   = post('storage_location');
    $statusIn   = post('status', 'available');

    if ($name === '')      { $errors[] = 'Item name is required.'; }
    if ($itemCode === '')  { $errors[] = 'Item code is required.'; }
    if ($categoryIn === '' || !ctype_digit($categoryIn)) { $errors[] = 'Category is required.'; }
    if (!ctype_digit($quantityIn) || (int) $quantityIn < 1) {
        $errors[] = 'Quantity must be a whole number of at least 1.';
    }
    if (!in_array($statusIn, inventory_statuses(), true)) {
        $errors[] = 'Please choose a valid status.';
    }

    // item_code is UNIQUE — a clear message beats a constraint violation.
    if ($errors === [] && $itemCode !== '') {
        $clash = fetch_value(
            'SELECT 1 FROM inventory_items WHERE item_code = ? AND item_id <> ?',
            [$itemCode, $itemId ?? 0]
        );
        if ($clash) {
            $errors[] = 'Another item already uses the code "' . $itemCode . '".';
        }
    }

    // Reducing the quantity below what is currently out would make the
    // available count negative.
    if ($errors === [] && $isEdit) {
        $outNow = (int) fetch_value(
            'SELECT COALESCE(SUM(quantity), 0) FROM borrowings WHERE item_id = ? AND returned_at IS NULL',
            [$itemId]
        );
        if ((int) $quantityIn < $outNow) {
            $errors[] = 'There are ' . $outNow . ' unit(s) currently on loan, so the total cannot be set below that.';
        }
    }

    // ------------------------------------------------------------- Photo
    // Only stored once every other field is valid, so a rejected form never
    // leaves an orphaned file behind.
    $currentPhoto = $item['photo_path'] ?? null;
    $newPhoto     = null;
    $photoUpload  = $_FILES['photo'] ?? null;

    if ($errors === [] && is_array($photoUpload)
        && (int) ($photoUpload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $stored = store_upload($photoUpload, 'inventory');
        if (!$stored['ok']) {
            $errors[] = 'Photo: ' . $stored['error'];
        } elseif (!str_starts_with((string) $stored['mime'], 'image/')) {
            delete_upload($stored['path']);
            $errors[] = 'The photo must be a JPG or PNG image.';
        } else {
            $newPhoto = $stored['path'];
        }
    }

    $photoPath = $newPhoto ?? (post('remove_photo') === '1' ? null : $currentPhoto);

    if ($errors === []) {
        if ($isEdit) {
            query(
                'UPDATE inventory_items
                    SET inv_category_id = ?, item_code = ?, name = ?, description = ?, size = ?,
                        unit = ?, quantity_total = ?, condition_note = ?, storage_location = ?, status = ?,
                        photo_path = ?
                  WHERE item_id = ?',
                [
                    (int) $categoryIn, $itemCode, $name,
                    $description !== '' ? $description : null,
                    $size !== '' ? $size : null,
                    $unit !== '' ? $unit : 'pc',
                    (int) $quantityIn,
                    $condition !== '' ? $condition : null,
                    $location !== '' ? $location : null,
                    $statusIn, $photoPath, $itemId,
                ]
            );
            if ($photoPath !== $currentPhoto) {
                delete_upload($currentPhoto);
            }
            audit_log('update', 'inventory_item', $itemId, 'Updated item: ' . $name, $item,
                      ['name' => $name, 'quantity_total' => (int) $quantityIn, 'status' => $statusIn]);
            flash('success', 'Item updated.');
            redirect('inventory/index.php');
        }

        query(
            'INSERT INTO inventory_items
                 (inv_category_id, item_code, name, description, size, unit,
                  quantity_total, condition_note, storage_location, status, photo_path)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                (int) $categoryIn, $itemCode, $name,
                $description !== '' ? $description : null,
                $size !== '' ? $size : null,
                $unit !== '' ? $unit : 'pc',
                (int) $quantityIn,
                $condition !== '' ? $condition : null,
                $location !== '' ? $location : null,
                $statusIn, $newPhoto,
            ]
        );
        $newId = (int) db()->lastInsertId();
        audit_log('create', 'inventory_item', $newId, 'Added item: ' . $name);

        clear_old_input();
        flash('success', 'Item added to the catalog.');
        redirect('inventory/index.php');
    }

    remember_input($_POST);
}

/** Submitted value wins, then the stored row, then a default. */
function item_field(string $key, ?array $row, string $default = ''): string
{
    $remembered = old($key);
    if ($remembered !== '') {
        return $remembered;
    }
    $value = $row[$key] ?? '';
    return $value === '' || $value === null ? $default : (string) $value;
}

$pageTitle = $isEdit ? 'Edit item' : 'New item';
require __DIR__ . '/../../includes/layout/header.php';
?>

<div class="page-head">
    <div>
        <a class="crumb" href="<?= url('inventory/index.php') ?>">&larr; Back to inventory</a>
        <h1><?= $isEdit ? 'Edit item' : 'New item' ?></h1>
        <p><?= $isEdit ? e($item['name']) : 'Add a costume or piece of equipment to the catalog.' ?></p>
    </div>
</div>

<?php if ($errors !== []): ?>
    <div class="alert alert-error">
        <?php foreach ($errors as $error): ?><div><?= e($error) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">

    <section class="card">
        <div class="card-head"><h2>Item details</h2></div>

        <div class="form-grid form-grid-2">
            <div class="form-row">
                <label for="item_code">Item code <span class="req">*</span></label>
                <input type="text" id="item_code" name="item_code" required
                       placeholder="e.g. COS-001" value="<?= e(item_field('item_code', $item)) ?>">
            </div>
            <div class="form-row">
                <label for="inv_category_id">Category <span class="req">*</span></label>
                <select id="inv_category_id" name="inv_category_id" required>
                    <option value="">Select a category</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= (int) $category['inv_category_id'] ?>"
                            <?= item_field('inv_category_id', $item) === (string) $category['inv_category_id'] ? 'selected' : '' ?>>
                            <?= e($category['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="form-row">
            <label for="name">Item name <span class="req">*</span></label>
            <input type="text" id="name" name="name" required value="<?= e(item_field('name', $item)) ?>">
        </div>

        <div class="form-row">
            <label for="description">Description <span class="optional">(optional)</span></label>
            <textarea id="description" name="description"><?= e(item_field('description', $item)) ?></textarea>
        </div>
    </section>

    <section class="card">
        <div class="card-head"><h2>Stock and condition</h2></div>

        <div class="form-grid form-grid-2">
            <div class="form-row">
                <label for="quantity_total">Quantity owned <span class="req">*</span></label>
                <input type="number" id="quantity_total" name="quantity_total" min="1" required
                       value="<?= e(item_field('quantity_total', $item, '1')) ?>">
            </div>
            <div class="form-row">
                <label for="unit">Unit</label>
                <input type="text" id="unit" name="unit" placeholder="pc, set, pair"
                       value="<?= e(item_field('unit', $item, 'pc')) ?>">
            </div>
            <div class="form-row">
                <label for="size">Size <span class="optional">(optional)</span></label>
                <input type="text" id="size" name="size" placeholder="S, M, L for costumes"
                       value="<?= e(item_field('size', $item)) ?>">
            </div>
            <div class="form-row">
                <label for="storage_location">Storage location <span class="optional">(optional)</span></label>
                <input type="text" id="storage_location" name="storage_location"
                       value="<?= e(item_field('storage_location', $item)) ?>">
            </div>
        </div>

        <div class="form-row">
            <label for="status">Status <span class="req">*</span></label>
            <select id="status" name="status" required>
                <?php
                $currentStatus = item_field('status', $item, 'available');
                foreach (inventory_statuses() as $option): ?>
                    <option value="<?= e($option) ?>" <?= $currentStatus === $option ? 'selected' : '' ?>>
                        <?= e(ucwords(str_replace('_', ' ', $option))) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <div class="hint">
                Available, reserved, and borrowed are kept up to date automatically as items move.
                Set damaged, under maintenance, or unavailable by hand.
            </div>
        </div>

        <div class="form-row">
            <label for="condition_note">Condition note <span class="optional">(optional)</span></label>
            <input type="text" id="condition_note" name="condition_note"
                   placeholder="e.g. Zipper needs repair" value="<?= e(item_field('condition_note', $item)) ?>">
        </div>
    </section>

    <section class="card">
        <div class="card-head">
            <h2>Photo</h2>
            <p class="hint">Students browse the catalog by photo, so a clear picture of the item helps them find it.</p>
        </div>

        <div class="photo-field">
            <?php if (!empty($item['photo_path'])): ?>
                <img class="item-photo" src="<?= url('inventory/photo.php?id=' . (int) $item['item_id']) ?>"
                     alt="Current photo of <?= e($item['name']) ?>">
            <?php endif; ?>
            <div class="photo-field-inputs">
                <div class="form-row">
                    <label for="photo">
                        <?= !empty($item['photo_path']) ? 'Replace photo' : 'Upload a photo' ?>
                        <span class="optional">(optional)</span>
                    </label>
                    <input type="file" id="photo" name="photo" accept="image/jpeg,image/png" aria-describedby="photo-hint">
                    <p class="hint" id="photo-hint">JPG or PNG, up to <?= (int) (MAX_UPLOAD_BYTES / 1024 / 1024) ?> MB.</p>
                </div>
                <?php if (!empty($item['photo_path'])): ?>
                    <label class="check">
                        <input type="checkbox" name="remove_photo" value="1"> Remove the current photo
                    </label>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Save changes' : 'Add item' ?></button>
        <a class="btn btn-outline" href="<?= url('inventory/index.php') ?>">Cancel</a>
    </div>
</form>

<?php if ($isEdit): ?>
    <section class="card mt-6">
        <div class="card-head">
            <h2>Delete this item</h2>
            <p class="hint">Only items with no borrowing history can be deleted. Otherwise, set the status to unavailable.</p>
        </div>
        <form method="post"
              onsubmit="return confirm('Delete this item permanently? This cannot be undone.');">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <button type="submit" class="btn btn-danger btn-sm">Delete this item</button>
        </form>
    </section>
<?php endif; ?>

<?php
require __DIR__ . '/../../includes/layout/footer.php';
clear_old_input();
