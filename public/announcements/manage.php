<?php
/**
 * CASMS — Publish and edit announcements (FR-3.1, FR-3.2, FR-3.3)
 *
 * Publishing notifies every active student once — republishing an already
 * published announcement does not notify again.
 */

declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';

require_role(['staff', 'admin', 'coordinator']);

$announcementId = get_id('id');
$isEdit         = $announcementId !== null;

$announcement = null;
if ($isEdit) {
    $announcement = fetch_one('SELECT * FROM announcements WHERE announcement_id = ?', [$announcementId]);
    if ($announcement === null) {
        http_response_code(404);
        exit('404 — Announcement not found.');
    }

    // A coordinator may only touch their own posts; the office may touch any.
    if (!is_office_staff() && (int) $announcement['posted_by'] !== current_user_id()) {
        http_response_code(403);
        exit('403 — You may only edit announcements you posted.');
    }
}

// Office staff may announce about anything; a coordinator only about the
// activities they are assigned to.
$activities = is_office_staff()
    ? fetch_all("SELECT activity_id, title FROM activities
                  WHERE status IN ('draft','upcoming','ongoing') ORDER BY start_at DESC")
    : fetch_all("SELECT a.activity_id, a.title FROM activities a
                   JOIN activity_coordinators ac ON ac.activity_id = a.activity_id
                  WHERE ac.user_id = ? AND a.status IN ('draft','upcoming','ongoing')
                  ORDER BY a.start_at DESC", [current_user_id()]);

/**
 * A coordinator must not attach an announcement to an activity they do not
 * coordinate. Checked server-side: the filtered dropdown above is presentation.
 */
function assert_may_announce_for(?int $activityId): void
{
    if ($activityId === null || is_office_staff()) {
        return;
    }
    $assigned = fetch_value(
        'SELECT 1 FROM activity_coordinators WHERE activity_id = ? AND user_id = ?',
        [$activityId, current_user_id()]
    );
    if (!$assigned) {
        http_response_code(403);
        exit('403 — You may only post announcements for activities you coordinate.');
    }
}

$errors = [];

if (is_post()) {
    csrf_verify();
    $action = post('action', 'save');

    // ---------------------------------------------------------------- Delete
    if ($action === 'delete' && $isEdit) {
        if (!has_role('admin')) {
            http_response_code(403);
            exit('403 — Only an administrator may delete an announcement.');
        }
        query('DELETE FROM announcements WHERE announcement_id = ?', [$announcementId]);
        audit_log('delete', 'announcement', $announcementId, 'Deleted: ' . $announcement['title']);
        flash('success', 'Announcement deleted.');
        redirect('announcements/index.php');
    }

    // ------------------------------------------------------------------ Save
    $title      = post('title');
    $body       = post('body');
    $activityIn = post('activity_id');
    $status     = post('status', 'draft');
    $isPinned   = post('is_pinned') === '1' ? 1 : 0;

    if ($title === '') { $errors[] = 'A title is required.'; }
    if ($body === '')  { $errors[] = 'The announcement body cannot be empty.'; }
    if (!in_array($status, ['draft', 'published', 'archived'], true)) {
        $errors[] = 'Please choose a valid status.';
    }

    if ($errors === []) {
        $activityId = $activityIn !== '' && ctype_digit($activityIn) ? (int) $activityIn : null;

        assert_may_announce_for($activityId);

        // Notify only on the transition into 'published'.
        $wasPublished  = $isEdit && $announcement['status'] === 'published';
        $becomingLive  = $status === 'published' && !$wasPublished;

        if ($isEdit) {
            query(
                'UPDATE announcements
                    SET title = ?, body = ?, activity_id = ?, status = ?, is_pinned = ?,
                        published_at = CASE WHEN ? = 1 THEN NOW() ELSE published_at END
                  WHERE announcement_id = ?',
                [$title, $body, $activityId, $status, $isPinned, $becomingLive ? 1 : 0, $announcementId]
            );
            $targetId = $announcementId;
            audit_log('update', 'announcement', $targetId, 'Updated: ' . $title);
        } else {
            query(
                'INSERT INTO announcements
                     (activity_id, title, body, is_pinned, status, published_at, posted_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [
                    $activityId, $title, $body, $isPinned, $status,
                    $status === 'published' ? date('Y-m-d H:i:s') : null,
                    current_user_id(),
                ]
            );
            $targetId = (int) db()->lastInsertId();
            audit_log('create', 'announcement', $targetId, 'Posted: ' . $title);
        }

        if ($becomingLive) {
            notify_many(
                all_active_student_ids(),
                'announcement',
                $title,
                mb_strimwidth(strip_tags($body), 0, 160, '…'),
                url('announcements/index.php')
            );
        }

        clear_old_input();
        flash('success', $status === 'published'
            ? 'Announcement published' . ($becomingLive ? ' and students have been notified.' : '.')
            : 'Announcement saved as a draft.');
        redirect('announcements/index.php');
    }

    remember_input($_POST);
}

/** Submitted value wins, then the stored row, then a default. */
function announcement_field(string $key, ?array $row, string $default = ''): string
{
    $remembered = old($key);
    if ($remembered !== '') {
        return $remembered;
    }
    $value = $row[$key] ?? '';
    return $value === '' || $value === null ? $default : (string) $value;
}

$pageTitle = $isEdit ? 'Edit announcement' : 'New announcement';
require __DIR__ . '/../../includes/layout/header.php';
?>

<div class="page-head">
    <div>
        <h1><?= $isEdit ? 'Edit announcement' : 'New announcement' ?></h1>
        <p>Published announcements notify every active student once.</p>
    </div>
    <a class="btn btn-outline" href="<?= url('announcements/index.php') ?>">Back</a>
</div>

<?php if ($errors !== []): ?>
    <div class="alert alert-error">
        <?php foreach ($errors as $error): ?><div><?= e($error) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<form method="post" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">

    <section class="card">
        <div class="form-row">
            <label for="title">Title <span class="req">*</span></label>
            <input type="text" id="title" name="title" required
                   value="<?= e(announcement_field('title', $announcement)) ?>">
        </div>

        <div class="form-row">
            <label for="body">Announcement <span class="req">*</span></label>
            <textarea id="body" name="body" required style="min-height:200px;"><?= e(announcement_field('body', $announcement)) ?></textarea>
        </div>

        <div class="form-grid form-grid-2">
            <div class="form-row">
                <label for="activity_id">Related activity</label>
                <select id="activity_id" name="activity_id">
                    <option value="">— Not related to an activity —</option>
                    <?php foreach ($activities as $activity): ?>
                        <option value="<?= (int) $activity['activity_id'] ?>"
                            <?= announcement_field('activity_id', $announcement) === (string) $activity['activity_id'] ? 'selected' : '' ?>>
                            <?= e($activity['title']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row">
                <label for="status">Status <span class="req">*</span></label>
                <select id="status" name="status" required>
                    <?php
                    $currentStatus = announcement_field('status', $announcement, 'draft');
                    foreach (['draft' => 'Draft — not visible to students',
                              'published' => 'Published — visible and notifies students',
                              'archived' => 'Archived'] as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= $currentStatus === $value ? 'selected' : '' ?>>
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="form-row">
            <label>
                <input type="checkbox" name="is_pinned" value="1" style="width:auto;"
                    <?= announcement_field('is_pinned', $announcement) === '1' ? 'checked' : '' ?>>
                Pin to the top of the list and the dashboard
            </label>
        </div>
    </section>

    <div class="btn-row">
        <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Save changes' : 'Save announcement' ?></button>
        <a class="btn btn-outline" href="<?= url('announcements/index.php') ?>">Cancel</a>
    </div>
</form>

<?php if ($isEdit && has_role('admin')): ?>
    <form method="post" style="margin-top:1rem;"
          onsubmit="return confirm('Delete this announcement permanently?');">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete">
        <button type="submit" class="btn btn-danger btn-sm">Delete this announcement</button>
    </form>
<?php endif; ?>

<?php
require __DIR__ . '/../../includes/layout/footer.php';
clear_old_input();
