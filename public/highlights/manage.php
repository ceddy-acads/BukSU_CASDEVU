<?php
/**
 * CASDevU — Post, replace or delete an activity's highlight report
 *
 * Without activity_id: the activities this person may manage, with the state of
 * each one's report. With it: the upload form. The office passes for every
 * activity; a coordinator only for the ones assigned to them.
 */

declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';

require_role(['staff', 'admin', 'coordinator']);

$activityId = get_id('activity_id');
$maxMb      = (int) floor(highlight_max_bytes() / 1048576);

// ------------------------------------------------------------------ Activity list
if ($activityId === null) {
    $sql = 'SELECT a.activity_id, a.title, a.start_at, a.status, h.title AS report_title
              FROM activities a
              LEFT JOIN highlight_reports h ON h.activity_id = a.activity_id';
    $params = [];
    if (!is_office_staff()) {
        $sql .= ' JOIN activity_coordinators ac ON ac.activity_id = a.activity_id AND ac.user_id = ?';
        $params[] = current_user_id();
    }
    $activities = fetch_all($sql . ' ORDER BY a.start_at DESC, a.activity_id DESC', $params);

    $pageTitle = 'Highlight reports';
    require __DIR__ . '/../../includes/layout/header.php';
    ?>
    <div class="page-head">
        <div>
            <h1>Highlight reports</h1>
            <p>Choose an activity to post, replace or delete its report. Anyone can read a posted report on the Highlights page.</p>
        </div>
    </div>

    <section class="card<?= $activities !== [] ? ' card-flush' : '' ?>">
        <?php if ($activities === []): ?>
            <div class="empty">
                <strong>No activities to manage yet</strong>
                <?= is_office_staff()
                    ? 'Reports are posted per activity. Create an activity first.'
                    : 'Reports are posted for the activities you are assigned to. Ask the office to assign you.' ?>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data table-stack">
                    <thead><tr><th>Activity</th><th>Date</th><th>Status</th><th>Report</th><th class="actions"><span class="sr-only">Actions</span></th></tr></thead>
                    <tbody>
                    <?php foreach ($activities as $row): ?>
                        <?php $visible = highlight_visible_status((string) $row['status']); ?>
                        <tr>
                            <td data-label="Activity"><a href="<?= url('activities/view.php?id=' . (int) $row['activity_id']) ?>"><?= e($row['title']) ?></a></td>
                            <td data-label="Date"><span class="nowrap"><?= e(format_date($row['start_at'])) ?></span></td>
                            <td data-label="Status"><?= status_badge((string) $row['status'], 'activity') ?></td>
                            <td data-label="Report">
                                <div>
                                    <?= $row['report_title'] !== null ? e($row['report_title']) : '<span class="muted">No report</span>' ?>
                                    <?php if (!$visible): ?>
                                        <div class="hint">Hidden while <?= e((string) $row['status']) ?></div>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="actions">
                                <a class="btn btn-outline btn-sm" href="<?= url('highlights/manage.php?activity_id=' . (int) $row['activity_id']) ?>"><?= $row['report_title'] !== null ? 'Replace' : 'Upload' ?><span class="sr-only"> report for <?= e($row['title']) ?></span></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
    <?php
    require __DIR__ . '/../../includes/layout/footer.php';
    return;
}

// ------------------------------------------------------------------ One activity
require_activity_access($activityId);

$activity = fetch_one('SELECT activity_id, title, status FROM activities WHERE activity_id = ?', [$activityId]);
if ($activity === null) {
    http_response_code(404);
    abort_page(404, 'Activity not found.');
}

$errors  = [];
$formUrl = 'highlights/manage.php?activity_id=' . $activityId;   // kept in the action so an oversized POST still knows the activity

if (is_post()) {
    // PHP drops the whole body when it is over post_max_size, token included.
    if ($_POST === [] && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > highlight_server_limit_bytes()) {
        $errors[] = 'This file is larger than ' . $maxMb . ' MB.';
    } else {
        csrf_verify();

        if (post('action') === 'upload') {
            $result = save_highlight($activityId, post('title'), $_FILES['pdf'] ?? [], current_user_id());
            if ($result['ok']) {
                $replaced = !empty($result['replaced']);
                audit_log($replaced ? 'update' : 'create', 'highlight_report', $activityId,
                          ($replaced ? 'Replaced' : 'Posted') . ' highlight report: ' . $activity['title']);
                flash('success', ($replaced ? 'Replaced.' : 'Posted.') . ' Anyone can now read it on the Highlights page.');
                redirect('highlights/view.php?activity_id=' . $activityId);
            }
            $errors[] = $result['error'] ?? 'The report could not be saved.';
        } elseif (post('action') === 'delete') {
            if (remove_highlight($activityId)) {
                audit_log('delete', 'highlight_report', $activityId, 'Deleted highlight report: ' . $activity['title']);
                flash('success', 'The report was deleted.');
            } else {
                flash('error', 'There was no report to delete.');
            }
            redirect($formUrl);
        }
    }
}

$report  = highlight_for_activity($activityId);
$visible = highlight_visible_status((string) $activity['status']);

$pageTitle = 'Highlight report: ' . $activity['title'];
require __DIR__ . '/../../includes/layout/header.php';
?>

<div class="page-head">
    <div>
        <a class="crumb" href="<?= url('activities/view.php?id=' . $activityId) ?>">&larr; Back to activity</a>
        <h1>Highlight report</h1>
        <p><?= e($activity['title']) ?> &middot; <?= status_badge((string) $activity['status'], 'activity') ?></p>
    </div>
</div>

<?php if ($errors !== []): ?>
    <div class="alert alert-error" role="alert">
        <?php foreach ($errors as $error): ?><div><?= e($error) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="split">
    <section class="card">
        <div class="card-head"><h2><?= $report !== null ? 'Replace the report' : 'Post a report' ?></h2></div>

        <?php if (!$visible): ?>
            <div class="alert alert-warning alert-flush">
                A report can be posted only while the activity is upcoming, ongoing, completed or closed.
                This one is <?= e((string) $activity['status']) ?>. Change its status first.
            </div>
        <?php else: ?>
            <form method="post" action="<?= url($formUrl) ?>" enctype="multipart/form-data" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="upload">

                <div class="form-row">
                    <label for="title">Title <span class="req">*</span></label>
                    <input type="text" id="title" name="title" required maxlength="200"
                           value="<?= e(post('title', $report !== null ? (string) $report['title'] : $activity['title'] . ' Highlights')) ?>">
                </div>

                <div class="form-row">
                    <label for="pdf">PDF file <span class="req">*</span></label>
                    <input type="file" id="pdf" name="pdf" accept="application/pdf,.pdf" required>
                    <p class="hint">PDF only, up to <?= $maxMb ?> MB. The first page becomes the cover.</p>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary" data-loading="Uploading…">
                        <?= $report !== null ? 'Replace report' : 'Post report' ?>
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </section>

    <section class="card">
        <div class="card-head"><h2>Current report</h2></div>
        <?php if ($report === null): ?>
            <div class="empty">
                <strong>No report yet</strong>
                Post a PDF and it shows on the Highlights page at once.
            </div>
        <?php else: ?>
            <dl class="detail-list">
                <div><dt>Title</dt><dd><?= e($report['title']) ?></dd></div>
                <div><dt>File</dt><dd><?= e(format_filesize((int) $report['file_size'])) ?></dd></div>
                <div><dt>Last updated</dt><dd><?= e(format_datetime($report['updated_at'])) ?></dd></div>
                <?php if (!$visible): ?>
                    <div><dt>Shown</dt><dd>Hidden while the activity is <?= e((string) $activity['status']) ?></dd></div>
                <?php endif; ?>
            </dl>
            <div class="btn-row">
                <?php if ($visible): ?>
                    <a class="btn btn-outline" href="<?= url('highlights/view.php?activity_id=' . $activityId) ?>">Open</a>
                <?php endif; ?>
                <form method="post" action="<?= url($formUrl) ?>" class="inline-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <button type="submit" class="btn btn-outline" data-loading="Deleting…">Delete report</button>
                </form>
            </div>
        <?php endif; ?>
    </section>
</div>

<?php require __DIR__ . '/../../includes/layout/footer.php'; ?>
