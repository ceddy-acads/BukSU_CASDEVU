<?php
/**
 * CASMS — Venue management (supports FR-7.1, FR-7.3)
 *
 * Venues drive both activity scheduling and the double-booking check. Before
 * this screen existed they could only be created with direct SQL, which left
 * the conflict check unreachable on a fresh install.
 */

declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';

require_role(['staff', 'admin']);

$errors = [];

if (is_post()) {
    csrf_verify();

    $action  = post('action');
    $venueId = (int) post('venue_id');

    // ------------------------------------------------------------ Add / edit
    if ($action === 'save') {
        $name       = post('name');
        $location   = post('location');
        $capacityIn = post('capacity');
        $isActive   = post('is_active') === '1' ? 1 : 0;

        if ($name === '') {
            $errors[] = 'Venue name is required.';
        }
        if ($capacityIn !== '' && (!ctype_digit($capacityIn) || (int) $capacityIn < 1)) {
            $errors[] = 'Capacity must be a whole number of at least 1, or left blank.';
        }

        // `name` is UNIQUE in the schema — catch it here for a clear message.
        if ($errors === [] && $name !== '') {
            $clash = fetch_value(
                'SELECT 1 FROM venues WHERE name = ? AND venue_id <> ?',
                [$name, $venueId]
            );
            if ($clash) {
                $errors[] = 'A venue named "' . $name . '" already exists.';
            }
        }

        if ($errors === []) {
            $capacity = $capacityIn !== '' ? (int) $capacityIn : null;

            if ($venueId > 0) {
                $before = fetch_one('SELECT * FROM venues WHERE venue_id = ?', [$venueId]);
                if ($before === null) {
                    flash('error', 'That venue no longer exists.');
                    redirect('admin/venues.php');
                }

                query(
                    'UPDATE venues SET name = ?, location = ?, capacity = ?, is_active = ?
                      WHERE venue_id = ?',
                    [$name, $location !== '' ? $location : null, $capacity, $isActive, $venueId]
                );
                audit_log('update', 'venue', $venueId, 'Updated venue: ' . $name, $before,
                          ['name' => $name, 'is_active' => $isActive]);
                flash('success', 'Venue updated.');
            } else {
                query(
                    'INSERT INTO venues (name, location, capacity, is_active) VALUES (?, ?, ?, ?)',
                    [$name, $location !== '' ? $location : null, $capacity, $isActive]
                );
                $newId = (int) db()->lastInsertId();
                audit_log('create', 'venue', $newId, 'Added venue: ' . $name);
                flash('success', 'Venue added.');
            }

            clear_old_input();
            redirect('admin/venues.php');
        }

        remember_input($_POST);
        $_SESSION['old_input']['venue_id'] = (string) $venueId;
    }

    // --------------------------------------------------- Archive / reactivate
    // Venues are never deleted: activities reference them historically.
    if ($action === 'toggle') {
        $venue = fetch_one('SELECT * FROM venues WHERE venue_id = ?', [$venueId]);
        if ($venue === null) {
            flash('error', 'That venue no longer exists.');
            redirect('admin/venues.php');
        }

        $newState = (int) $venue['is_active'] === 1 ? 0 : 1;
        query('UPDATE venues SET is_active = ? WHERE venue_id = ?', [$newState, $venueId]);
        audit_log('update', 'venue', $venueId,
                  ($newState === 1 ? 'Reactivated venue: ' : 'Archived venue: ') . $venue['name']);

        flash('success', $venue['name'] . ($newState === 1
            ? ' is active again and can be selected for activities.'
            : ' has been archived. It stays on past activities but cannot be chosen for new ones.'));
        redirect('admin/venues.php');
    }
}

// ------------------------------------------------------------------- Listing
// upcoming_count tells the office whether archiving a venue would strand
// anything already scheduled there.
$venues = fetch_all(
    "SELECT v.*,
            (SELECT COUNT(*) FROM activities a WHERE a.venue_id = v.venue_id) AS activity_count,
            (SELECT COUNT(*) FROM activities a2
              WHERE a2.venue_id = v.venue_id
                AND a2.status IN ('upcoming','ongoing')) AS upcoming_count
       FROM venues v
      ORDER BY v.is_active DESC, v.name"
);

// Editing one? Pre-fill the form.
$editId  = get_id('edit');
$editing = $editId !== null
    ? fetch_one('SELECT * FROM venues WHERE venue_id = ?', [$editId])
    : null;

/** Submitted value wins, then the stored row, then a default. */
function venue_field(string $key, ?array $row, string $default = ''): string
{
    $remembered = old($key);
    if ($remembered !== '') {
        return $remembered;
    }
    $value = $row[$key] ?? '';
    return $value === '' || $value === null ? $default : (string) $value;
}

$activeCount = count(array_filter($venues, static fn(array $v): bool => (int) $v['is_active'] === 1));

$pageTitle = 'Venues';
require __DIR__ . '/../../includes/layout/header.php';
?>

<div class="page-head">
    <div>
        <h1>Venues</h1>
        <p><?= $activeCount ?> active of <?= count($venues) ?> total. Active venues can be chosen when scheduling an activity.</p>
    </div>
    <div class="btn-row">
        <a class="btn btn-outline" href="<?= url('activities/index.php') ?>">Activities</a>
    </div>
</div>

<?php if ($errors !== []): ?>
    <div class="alert alert-error">
        <?php foreach ($errors as $error): ?><div><?= e($error) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($venues === []): ?>
    <div class="alert alert-warning">
        No venues exist yet. Activities cannot record a location, and venue
        double-booking checks will not run until at least one venue is added.
    </div>
<?php endif; ?>

<div class="split">
    <section class="card<?= $venues === [] ? '' : ' card-flush' ?>">
        <div class="card-head"><h2>All venues</h2></div>

        <?php if ($venues === []): ?>
            <div class="empty">
                <strong>No venues yet</strong>
                Add the first one with the form on this page.
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data table-stack">
                    <thead>
                        <tr><th>Venue</th><th class="num">Capacity</th><th class="num">Activities</th><th>Status</th><th class="actions">Actions</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($venues as $venue): ?>
                        <tr>
                            <td data-label="Venue">
                                <div>
                                    <strong><?= e($venue['name']) ?></strong>
                                    <?php if ($venue['location']): ?>
                                        <div class="hint"><?= e($venue['location']) ?></div>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="num" data-label="Capacity"><?= $venue['capacity'] ? (int) $venue['capacity'] : '<span class="muted">Not set</span>' ?></td>
                            <td class="num" data-label="Activities">
                                <div>
                                    <?= (int) $venue['activity_count'] ?>
                                    <?php if ((int) $venue['upcoming_count'] > 0): ?>
                                        <div class="hint"><?= (int) $venue['upcoming_count'] ?> upcoming</div>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td data-label="Status">
                                <div>
                                    <?php if ((int) $venue['is_active'] === 1): ?>
                                        <span class="badge badge-success">Active</span>
                                    <?php else: ?>
                                        <span class="badge badge-muted">Archived</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="actions">
                                <div class="btn-row">
                                    <a class="btn btn-outline btn-sm"
                                       href="<?= url('admin/venues.php?edit=' . (int) $venue['venue_id']) ?>"
                                       aria-label="Edit <?= e($venue['name']) ?>">Edit</a>
                                    <form method="post" class="inline-form"
                                          <?= (int) $venue['is_active'] === 1 && (int) $venue['upcoming_count'] > 0
                                              ? 'onsubmit="return confirm(\'This venue has upcoming activities scheduled. Archive it anyway?\');"'
                                              : '' ?>>
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="venue_id" value="<?= (int) $venue['venue_id'] ?>">
                                        <button type="submit" class="btn btn-outline btn-sm"
                                                aria-label="<?= (int) $venue['is_active'] === 1 ? 'Archive' : 'Reactivate' ?> <?= e($venue['name']) ?>">
                                            <?= (int) $venue['is_active'] === 1 ? 'Archive' : 'Reactivate' ?>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="card" id="venue-form">
        <div class="card-head">
            <h2><?= $editing ? 'Edit venue' : 'Add a venue' ?></h2>
        </div>

        <form method="post" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="venue_id" value="<?= $editing ? (int) $editing['venue_id'] : 0 ?>">

            <div class="form-row">
                <label for="name">Venue name <span class="req">*</span></label>
                <input type="text" id="name" name="name" required
                       placeholder="e.g. University Gymnasium"
                       value="<?= e(venue_field('name', $editing)) ?>">
            </div>

            <div class="form-row">
                <label for="location">Location <span class="optional">(optional)</span></label>
                <input type="text" id="location" name="location"
                       placeholder="e.g. Main Campus"
                       value="<?= e(venue_field('location', $editing)) ?>">
            </div>

            <div class="form-row">
                <label for="capacity">Capacity <span class="optional">(optional)</span></label>
                <input type="number" id="capacity" name="capacity" min="1"
                       value="<?= e(venue_field('capacity', $editing)) ?>">
                <div class="hint">Leave blank if not applicable.</div>
            </div>

            <div class="form-row">
                <label class="check">
                    <input type="checkbox" name="is_active" value="1"
                        <?= !$editing || (int) $editing['is_active'] === 1 ? 'checked' : '' ?>>
                    <span>Active: can be selected when scheduling an activity</span>
                </label>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">
                    <?= $editing ? 'Save changes' : 'Add venue' ?>
                </button>
                <?php if ($editing): ?>
                    <a class="btn btn-outline" href="<?= url('admin/venues.php') ?>">Cancel</a>
                <?php endif; ?>
            </div>
        </form>
    </section>
</div>

<?php
require __DIR__ . '/../../includes/layout/footer.php';
clear_old_input();
