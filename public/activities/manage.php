<?php
/**
 * CASMS — Create and edit an activity (FR-2.3, FR-2.4)
 *
 * One form serves both: an `id` in the query string switches to edit mode.
 */

declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';

$activityId = get_id('id');
$isEdit     = $activityId !== null;

// Creating is staff/admin only; editing also allows an assigned coordinator.
if ($isEdit) {
    require_activity_access($activityId);
} else {
    require_role(['staff', 'admin']);
}

$categories = fetch_all('SELECT category_id, name FROM activity_categories WHERE is_active = 1 ORDER BY name');
$statuses   = ['draft', 'upcoming', 'ongoing', 'completed', 'cancelled', 'closed'];

$activity = null;
if ($isEdit) {
    $activity = fetch_one('SELECT * FROM activities WHERE activity_id = ?', [$activityId]);
    if ($activity === null) {
        http_response_code(404);
        exit('404 — Activity not found.');
    }
}

// Only active venues may be chosen. An activity already pointing at an archived
// venue keeps it in the list, marked, so editing something else does not
// silently clear the location.
$currentVenueId = $isEdit ? (int) ($activity['venue_id'] ?? 0) : 0;
$venues = fetch_all(
    'SELECT venue_id, name, location, is_active FROM venues
      WHERE is_active = 1 OR venue_id = ?
      ORDER BY is_active DESC, name',
    [$currentVenueId]
);

$errors = [];

if (is_post()) {
    csrf_verify();

    // ---------------------------------------------------------------- Delete
    // Administrators only (role matrix). Deleting cascades to registrations,
    // requirements and submitted files, so the uploads are removed from disk
    // first — the database cascade would otherwise orphan them.
    if (post('action') === 'delete' && $isEdit) {
        if (!has_role('admin')) {
            http_response_code(403);
            exit('403 — Only an administrator may delete an activity.');
        }

        $files = fetch_all(
            'SELECT rs.file_path
               FROM requirement_submissions rs
               JOIN registrations r ON r.registration_id = rs.registration_id
              WHERE r.activity_id = ?',
            [$activityId]
        );
        foreach ($files as $file) {
            delete_upload($file['file_path']);
        }

        $participantCount = (int) fetch_value(
            'SELECT COUNT(*) FROM registrations WHERE activity_id = ?', [$activityId]
        );

        query('DELETE FROM activities WHERE activity_id = ?', [$activityId]);
        audit_log('delete', 'activity', $activityId,
                  'Deleted activity: ' . $activity['title']
                  . ' (' . $participantCount . ' registration(s), ' . count($files) . ' file(s))');

        flash('success', '"' . $activity['title'] . '" was deleted, along with '
            . $participantCount . ' registration(s) and ' . count($files) . ' submitted file(s).');
        redirect('activities/index.php');
    }

    $title          = post('title');
    $categoryIdIn   = post('category_id');
    $venueIdIn      = post('venue_id');
    $description    = post('description');
    $eligibility    = post('eligibility');
    $instructions   = post('instructions');
    $startAt        = post('start_at');
    $endAt          = post('end_at');
    $regOpens       = post('registration_opens_at');
    $regCloses      = post('registration_closes_at');
    $maxIn          = post('max_participants');
    $statusIn       = post('status');

    // ------------------------------------------------------------ Validation
    if ($title === '') {
        $errors[] = 'Title is required.';
    }
    if ($categoryIdIn === '' || !ctype_digit($categoryIdIn)) {
        $errors[] = 'Category is required.';
    }
    if ($startAt === '' || $endAt === '') {
        $errors[] = 'Start and end date/time are required.';
    } elseif (strtotime($endAt) < strtotime($startAt)) {
        $errors[] = 'The end date/time cannot be earlier than the start.';
    }
    if ($regOpens !== '' && $regCloses !== '' && strtotime($regCloses) < strtotime($regOpens)) {
        $errors[] = 'Registration cannot close before it opens.';
    }
    if ($maxIn !== '' && (!ctype_digit($maxIn) || (int) $maxIn < 1)) {
        $errors[] = 'Maximum participants must be a positive whole number, or left blank for unlimited.';
    }
    if (!in_array($statusIn, $statuses, true)) {
        $errors[] = 'Please choose a valid status.';
    }

    // The venue must exist and be active — unless it is the one this activity
    // already had, which may since have been archived. Checked server-side so
    // a crafted POST cannot attach an archived venue.
    if ($venueIdIn !== '') {
        if (!ctype_digit($venueIdIn)) {
            $errors[] = 'Please choose a valid venue.';
        } else {
            $chosen = fetch_one('SELECT name, is_active FROM venues WHERE venue_id = ?', [(int) $venueIdIn]);
            if ($chosen === null) {
                $errors[] = 'That venue does not exist.';
            } elseif ((int) $chosen['is_active'] !== 1 && (int) $venueIdIn !== $currentVenueId) {
                $errors[] = '"' . $chosen['name'] . '" is archived and cannot be assigned to an activity.';
            }
        }
    }

    // ------------------------------------------- Venue double-booking (FR-7.3)
    // A warning, not a hard block — the office may deliberately overlap events.
    $venueWarning = null;
    if ($errors === [] && $venueIdIn !== '' && ctype_digit($venueIdIn)) {
        $clash = fetch_one(
            "SELECT title, start_at FROM activities
              WHERE venue_id = ?
                AND activity_id <> ?
                AND status NOT IN ('cancelled','completed','draft')
                AND start_at < ? AND end_at > ?
              LIMIT 1",
            [(int) $venueIdIn, $activityId ?? 0, $endAt, $startAt]
        );
        if ($clash) {
            $venueWarning = 'Note: "' . $clash['title'] . '" is already scheduled at this venue during that time.';
        }
    }

    // ------------------------------------------------------------ Persistence
    if ($errors === []) {
        $categoryId = (int) $categoryIdIn;
        $venueId    = $venueIdIn !== '' && ctype_digit($venueIdIn) ? (int) $venueIdIn : null;
        $maxCount   = $maxIn !== '' ? (int) $maxIn : null;

        if ($isEdit) {
            query(
                'UPDATE activities
                    SET category_id = ?, venue_id = ?, title = ?, description = ?, eligibility = ?,
                        instructions = ?, start_at = ?, end_at = ?, registration_opens_at = ?,
                        registration_closes_at = ?, max_participants = ?, status = ?
                  WHERE activity_id = ?',
                [
                    $categoryId, $venueId, $title,
                    $description !== ''  ? $description  : null,
                    $eligibility !== ''  ? $eligibility  : null,
                    $instructions !== '' ? $instructions : null,
                    $startAt, $endAt,
                    $regOpens  !== '' ? $regOpens  : null,
                    $regCloses !== '' ? $regCloses : null,
                    $maxCount, $statusIn, $activityId,
                ]
            );

            audit_log('update', 'activity', $activityId, 'Updated activity: ' . $title,
                      $activity, ['title' => $title, 'status' => $statusIn]);

            flash('success', 'Activity updated.' . ($venueWarning ? ' ' . $venueWarning : ''));
            redirect('activities/view.php?id=' . $activityId);
        }

        // ------------------------------------------------------------ Create
        query(
            'INSERT INTO activities
                 (category_id, venue_id, title, slug, description, eligibility, instructions,
                  start_at, end_at, registration_opens_at, registration_closes_at,
                  max_participants, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $categoryId, $venueId, $title, make_slug($title),
                $description !== ''  ? $description  : null,
                $eligibility !== ''  ? $eligibility  : null,
                $instructions !== '' ? $instructions : null,
                $startAt, $endAt,
                $regOpens  !== '' ? $regOpens  : null,
                $regCloses !== '' ? $regCloses : null,
                $maxCount, $statusIn, current_user_id(),
            ]
        );

        $newId = (int) db()->lastInsertId();
        audit_log('create', 'activity', $newId, 'Created activity: ' . $title);

        // Announce it to students the moment it goes live (FR-3.3).
        if ($statusIn === 'upcoming') {
            notify_many(
                all_active_student_ids(),
                'activity',
                'New activity: ' . $title,
                'Registration details are now available.',
                url('activities/view.php?id=' . $newId)
            );
        }

        clear_old_input();
        flash('success', 'Activity created.' . ($venueWarning ? ' ' . $venueWarning : ''));
        redirect('activities/view.php?id=' . $newId);
    }

    remember_input($_POST);
}

/**
 * Field value: a failed submission wins, then the stored row, then blank.
 * datetime-local inputs need 'Y-m-d\TH:i'.
 */
function field(string $key, ?array $row, bool $isDateTime = false): string
{
    $remembered = old($key);
    if ($remembered !== '') {
        return $remembered;
    }
    $value = $row[$key] ?? '';
    if ($value === '' || $value === null) {
        return '';
    }
    return $isDateTime ? date('Y-m-d\TH:i', strtotime((string) $value)) : (string) $value;
}

$pageTitle = $isEdit ? 'Edit activity' : 'New activity';
require __DIR__ . '/../../includes/layout/header.php';
?>

<div class="page-head">
    <div>
        <h1><?= $isEdit ? 'Edit activity' : 'New activity' ?></h1>
        <p><?= $isEdit ? e($activity['title']) : 'Publish a culture, arts, or sports activity.' ?></p>
    </div>
    <a class="btn btn-outline"
       href="<?= $isEdit ? url('activities/view.php?id=' . $activityId) : url('activities/index.php') ?>">
        Cancel
    </a>
</div>

<?php if ($errors !== []): ?>
    <div class="alert alert-error">
        <?php foreach ($errors as $error): ?>
            <div><?= e($error) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<form method="post" novalidate>
    <?= csrf_field() ?>

    <section class="card">
        <div class="card-head"><h2>Basic information</h2></div>

        <div class="form-row">
            <label for="title">Title <span class="req">*</span></label>
            <input type="text" id="title" name="title" value="<?= e(field('title', $activity)) ?>" required>
        </div>

        <div class="form-grid form-grid-2">
            <div class="form-row">
                <label for="category_id">Category <span class="req">*</span></label>
                <select id="category_id" name="category_id" required>
                    <option value="">— Select category —</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= (int) $category['category_id'] ?>"
                            <?= field('category_id', $activity) === (string) $category['category_id'] ? 'selected' : '' ?>>
                            <?= e($category['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row">
                <label for="venue_id">Venue</label>
                <select id="venue_id" name="venue_id">
                    <option value="">— Not yet assigned —</option>
                    <?php foreach ($venues as $venue): ?>
                        <option value="<?= (int) $venue['venue_id'] ?>"
                            <?= field('venue_id', $activity) === (string) $venue['venue_id'] ? 'selected' : '' ?>>
                            <?= e($venue['name']) ?>
                            <?= $venue['location'] ? ' — ' . e($venue['location']) : '' ?>
                            <?= (int) $venue['is_active'] !== 1 ? ' (archived)' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($venues === []): ?>
                    <div class="hint">
                        No venues have been set up yet.
                        <?php if (is_office_staff()): ?>
                            <a href="<?= url('admin/venues.php') ?>">Add one now</a>.
                        <?php else: ?>
                            Ask the office to add one.
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="form-row">
            <label for="description">Description</label>
            <textarea id="description" name="description"><?= e(field('description', $activity)) ?></textarea>
        </div>
    </section>

    <section class="card">
        <div class="card-head"><h2>Schedule</h2></div>

        <div class="form-grid form-grid-2">
            <div class="form-row">
                <label for="start_at">Starts <span class="req">*</span></label>
                <input type="datetime-local" id="start_at" name="start_at"
                       value="<?= e(field('start_at', $activity, true)) ?>" required>
            </div>
            <div class="form-row">
                <label for="end_at">Ends <span class="req">*</span></label>
                <input type="datetime-local" id="end_at" name="end_at"
                       value="<?= e(field('end_at', $activity, true)) ?>" required>
            </div>
            <div class="form-row">
                <label for="registration_opens_at">Registration opens</label>
                <input type="datetime-local" id="registration_opens_at" name="registration_opens_at"
                       value="<?= e(field('registration_opens_at', $activity, true)) ?>">
            </div>
            <div class="form-row">
                <label for="registration_closes_at">Registration closes</label>
                <input type="datetime-local" id="registration_closes_at" name="registration_closes_at"
                       value="<?= e(field('registration_closes_at', $activity, true)) ?>">
            </div>
        </div>
    </section>

    <section class="card">
        <div class="card-head"><h2>Participation</h2></div>

        <div class="form-grid form-grid-2">
            <div class="form-row">
                <label for="max_participants">Maximum participants</label>
                <input type="number" id="max_participants" name="max_participants" min="1"
                       value="<?= e(field('max_participants', $activity)) ?>">
                <div class="hint">Leave blank for unlimited.</div>
            </div>
            <div class="form-row">
                <label for="status">Status <span class="req">*</span></label>
                <select id="status" name="status" required>
                    <?php
                    $currentStatus = field('status', $activity) ?: 'draft';
                    foreach ($statuses as $statusOption): ?>
                        <option value="<?= e($statusOption) ?>" <?= $currentStatus === $statusOption ? 'selected' : '' ?>>
                            <?= e(ucfirst($statusOption)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="hint">Drafts are hidden from students.</div>
            </div>
        </div>

        <div class="form-row">
            <label for="eligibility">Who may join</label>
            <textarea id="eligibility" name="eligibility"
                      placeholder="e.g. Open to all bona fide students, 1st to 4th year."><?= e(field('eligibility', $activity)) ?></textarea>
        </div>

        <div class="form-row">
            <label for="instructions">How to participate</label>
            <textarea id="instructions" name="instructions"><?= e(field('instructions', $activity)) ?></textarea>
        </div>
    </section>

    <div class="btn-row">
        <button type="submit" class="btn btn-primary">
            <?= $isEdit ? 'Save changes' : 'Create activity' ?>
        </button>
        <a class="btn btn-outline"
           href="<?= $isEdit ? url('activities/view.php?id=' . $activityId) : url('activities/index.php') ?>">
            Cancel
        </a>
    </div>
</form>

<?php if ($isEdit && has_role('admin')): ?>
    <?php
    $registrationCount = (int) fetch_value(
        'SELECT COUNT(*) FROM registrations WHERE activity_id = ?', [$activityId]
    );
    ?>
    <section class="card" style="margin-top:1.5rem;">
        <div class="card-head"><h2>Delete this activity</h2></div>
        <p style="font-size:.9rem;margin-top:0;">
            Deleting removes the activity permanently, together with its
            <strong><?= $registrationCount ?></strong> registration<?= $registrationCount === 1 ? '' : 's' ?>,
            its requirements, and every document students submitted for it.
            To take an activity down without losing the records, set its status
            to <em>Cancelled</em> instead.
        </p>
        <form method="post"
              onsubmit="return confirm('Delete this activity and all <?= $registrationCount ?> registration(s) permanently? This cannot be undone.');">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <button type="submit" class="btn btn-danger">Delete permanently</button>
        </form>
    </section>
<?php endif; ?>

<?php
require __DIR__ . '/../../includes/layout/footer.php';
clear_old_input();
