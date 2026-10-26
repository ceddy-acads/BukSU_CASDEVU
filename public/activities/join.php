<?php
/** Standalone fallback for the activity registration dialog. */
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_role(['student']);

$activityId = $activityId ?? get_id('activity_id');
$activity = $activityId ? fetch_one('SELECT * FROM activities WHERE activity_id = ?', [$activityId]) : null;
if ($activity === null) {
    abort_page(404, 'Activity not found.');
}
$joinCheck = can_register($activity, current_user());
if (!$joinCheck['ok']) {
    flash('error', $joinCheck['reason']);
    redirect('activities/view.php?id=' . $activityId);
}
$pageTitle = 'Register: ' . $activity['title'];
require __DIR__ . '/../../includes/layout/header.php';
?>
<div class="page-head"><div>
    <a class="crumb" href="<?= e(url('activities/view.php?id=' . $activityId)) ?>">&larr; Back to activity</a>
    <h1>Register for this activity</h1>
</div></div>
<section class="card join-page" aria-label="Registration requirements">
    <?php require __DIR__ . '/../../includes/layout/join-form.php'; ?>
</section>
<?php require __DIR__ . '/../../includes/layout/footer.php'; ?>
