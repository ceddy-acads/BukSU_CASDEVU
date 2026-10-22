<?php
/**
 * CASMS — Settings (FR-1.6)
 *
 * One page, sections by role: what reaches the person by email, how their
 * activity list opens, and — for an administrator — the office details that
 * print on every message the system sends.
 *
 * Identity, photo and password live on profile.php; this page links there
 * rather than copying it, so the two can never disagree.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

require_login();

$user      = current_user();
$userId    = (int) $user['user_id'];
$isStudent = current_role() === 'student';
$isAdmin   = has_role('admin');
$errors    = [];

if (is_post()) {
    csrf_verify();
    $action = post('action');

    // ------------------------------------------------ What reaches me by email
    if ($action === 'notifications') {
        // Students are the only people the system emails reminders to;
        // reviewers are the only people it emails about work waiting on them.
        $key = $isStudent ? 'email_reminders' : 'email_review_alerts';
        save_user_preferences($userId, [$key => post($key) === '1' ? '1' : '0']);

        audit_log('update', 'user', $userId, 'Changed own notification settings');
        flash('success', 'Notification settings saved.');
        redirect('settings.php');
    }

    // ------------------------------------------------- How my list opens
    if ($action === 'list') {
        save_user_preferences($userId, [
            'hide_past_activities' => post('hide_past_activities') === '1' ? '1' : '0',
        ]);

        audit_log('update', 'user', $userId, 'Changed own activity list setting');
        flash('success', 'Activity list setting saved.');
        redirect('settings.php');
    }

    // -------------------------------------------------- Office (admin only)
    if ($action === 'office') {
        if (!$isAdmin) {
            http_response_code(403);
            abort_page(403, 'Only an administrator may change the office settings.');
        }

        $officeName = trim(post('office_name'));
        $university = trim(post('university_name'));
        $megabytes  = post('max_upload_size_mb');

        if ($officeName === '' || mb_strlen($officeName) > 120) {
            $errors[] = 'Office name is required, up to 120 characters.';
        }
        if ($university === '' || mb_strlen($university) > 120) {
            $errors[] = 'University name is required, up to 120 characters.';
        }
        if (!ctype_digit($megabytes) || (int) $megabytes < 1 || (int) $megabytes > 50) {
            $errors[] = 'Upload limit must be a whole number of megabytes between 1 and 50.';
        }

        if ($errors === []) {
            save_office_settings([
                'office_name'        => $officeName,
                'university_name'    => $university,
                'max_upload_size_mb' => (string) (int) $megabytes,
            ]);

            audit_log('update', 'setting', null,
                      'Office details set to "' . $officeName . '", "' . $university . '", '
                      . (int) $megabytes . ' MB uploads');
            flash('success', 'Office settings saved.');
            redirect('settings.php');
        }
    }
}

$emailAlertsOn = $isStudent
    ? user_preference_on($userId, 'email_reminders', true)
    : user_preference_on($userId, 'email_review_alerts', true);
$hidePast      = user_preference_on($userId, 'hide_past_activities');

$pageTitle = 'Settings';
require __DIR__ . '/../includes/layout/header.php';
?>

<div class="page-head">
    <div>
        <h1>Settings</h1>
        <p>What reaches you by email, and how your activity list opens<?= $isAdmin ? ' — and the details the office prints on its messages' : '' ?>.</p>
    </div>
</div>

<?php if ($errors !== []): ?>
    <div class="alert alert-error" role="alert">
        <?php foreach ($errors as $error): ?>
            <div><?= e($error) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php // ---------------------------------------------------- Notifications ?>
<section class="card">
    <div class="card-head"><h2>Notifications</h2></div>
    <p class="card-intro">Decisions on your own records always appear in the bell. Email is optional.</p>

    <form method="post" action="<?= url('settings.php') ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="notifications">

        <div class="form-row">
            <label class="check">
                <input type="checkbox" name="<?= $isStudent ? 'email_reminders' : 'email_review_alerts' ?>" value="1"
                    <?= $emailAlertsOn ? 'checked' : '' ?>>
                <span><?= $isStudent
                    ? 'Email me when a requirement deadline is near'
                    : 'Email me when a registration or document is waiting for review' ?></span>
            </label>
            <div class="hint"><?= $isStudent
                ? 'A few days before the deadline and again on the day. The bell keeps showing them either way.'
                : 'Sent when a student registers for one of your activities or uploads a document. Office staff and administrators also get the office-wide queue.' ?></div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary" data-loading="Saving…">Save</button>
        </div>
    </form>
</section>

<?php // --------------------------------------------------- Activity list ?>
<section class="card">
    <div class="card-head"><h2>Activity list</h2></div>
    <p class="card-intro">The Activities page lists what is coming and what has already finished.</p>

    <form method="post" action="<?= url('settings.php') ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="list">

        <div class="form-row">
            <label class="check">
                <input type="checkbox" name="hide_past_activities" value="1" <?= $hidePast ? 'checked' : '' ?>>
                <span>Hide activities that have already ended</span>
            </label>
            <div class="hint">Filtering by status shows those activities anyway, so a report or a closed event is still reachable.</div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary" data-loading="Saving…">Save</button>
        </div>
    </form>
</section>

<?php if ($isAdmin): ?>
    <?php // ------------------------------------------------------- Office ?>
    <section class="card">
        <div class="card-head"><h2>Office</h2></div>
        <p class="card-intro">Printed at the foot of every message the system sends, and the ceiling on requirement documents.</p>

        <form method="post" action="<?= url('settings.php') ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="office">

            <div class="form-grid form-grid-2">
                <div class="form-row">
                    <label for="office_name">Office name <span class="req">*</span></label>
                    <input type="text" id="office_name" name="office_name" required maxlength="120"
                           value="<?= e(post('office_name', (string) office_setting('office_name', OFFICE_NAME))) ?>">
                </div>
                <div class="form-row">
                    <label for="university_name">University name <span class="req">*</span></label>
                    <input type="text" id="university_name" name="university_name" required maxlength="120"
                           value="<?= e(post('university_name', (string) office_setting('university_name', UNIVERSITY))) ?>">
                </div>
            </div>

            <div class="form-row">
                <label for="max_upload_size_mb">Largest requirement file</label>
                <input type="number" id="max_upload_size_mb" name="max_upload_size_mb" min="1" max="50" required
                       value="<?= e(post('max_upload_size_mb', (string) (int) (max_upload_bytes() / 1024 / 1024))) ?>">
                <div class="hint">In megabytes, 1&ndash;50. Students see this limit on the upload page.</div>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary" data-loading="Saving…">Save</button>
                <a class="btn btn-outline" href="<?= url('admin/reminders.php') ?>">Reminder timing</a>
            </div>
        </form>
    </section>
<?php endif; ?>

<?php // -------------------------------------------------------- Account ?>
<section class="card">
    <div class="card-head"><h2>Account</h2></div>
    <p class="card-intro">Your name, photo and password are kept on your profile, so both pages agree.</p>

    <dl class="profile-fields">
        <div class="profile-wide"><dt>Email address</dt><dd><?= str_replace('@', '@<wbr>', e($user['email'])) ?></dd></div>
        <div><dt>Role</dt><dd><?= e(ucfirst((string) $user['role_name'])) ?></dd></div>
        <div><dt>Account status</dt><dd><?= status_badge((string) $user['status'], 'account') ?></dd></div>
    </dl>

    <div class="form-actions">
        <a class="btn btn-outline" href="<?= url('profile.php') ?>">My profile</a>
        <a class="btn btn-outline" href="<?= url('profile.php?edit=password#password') ?>">Change password</a>
    </div>
</section>

<?php require __DIR__ . '/../includes/layout/footer.php'; ?>
