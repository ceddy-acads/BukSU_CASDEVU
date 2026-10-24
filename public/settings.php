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
        $message = 'Email ' . (post($key) === '1' ? 'turned on.' : 'turned off.');
        if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch') {
            // A switch flipped with JavaScript: app.js shows the message, no reload.
            header('Content-Type: application/json');
            echo json_encode(['message' => $message]);
            return;
        }
        flash('success', $message);
        redirect('settings.php');
    }

    // ------------------------------------------------- How my list opens
    if ($action === 'list') {
        save_user_preferences($userId, [
            'hide_past_activities' => post('hide_past_activities') === '1' ? '1' : '0',
        ]);

        audit_log('update', 'user', $userId, 'Changed own activity list setting');
        $message = post('hide_past_activities') === '1'
            ? 'Ended activities are now hidden.'
            : 'Ended activities are now shown.';
        if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch') {
            header('Content-Type: application/json');
            echo json_encode(['message' => $message]);
            return;
        }
        flash('success', $message);
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

// Office details as summary rows. "Change" opens one in place (?edit=key);
// its form still posts all three, the other two as hidden fields, so the
// handler above validates the set exactly as before.
$officeFields = [
    'office_name'        => ['Office name', (string) office_setting('office_name', OFFICE_NAME)],
    'university_name'    => ['University name', (string) office_setting('university_name', UNIVERSITY)],
    'max_upload_size_mb' => ['Largest file', (string) (int) (max_upload_bytes() / 1024 / 1024)],
];
$editing = is_post() ? post('edit') : get('edit');
if (!$isAdmin || !isset($officeFields[$editing])) {
    $editing = '';
}
$emailKey = $isStudent ? 'email_reminders' : 'email_review_alerts';

$pageTitle = 'Settings';
require __DIR__ . '/../includes/layout/header.php';
?>

<div class="page-head">
    <div>
        <h1>Settings</h1>
        <p>What reaches you by email, how your activity list opens<?= $isAdmin ? ', and the details the office prints on its messages' : '' ?>.</p>
    </div>
</div>

<div class="settings">
<div class="settings-layout">

<nav class="settings-nav" aria-label="Settings sections">
    <a href="#notifications">Notifications</a>
    <a href="#activity-list">Activity list</a>
    <?php if ($isAdmin): ?><a href="#office">Office</a><?php endif; ?>
    <a href="#account">Account</a>
</nav>

<div class="settings-cards">

<?php // ---------------------------------------------------- Notifications ?>
<section class="set-card" id="notifications" aria-labelledby="notifications-title">
    <div class="set-card-head"><h2 id="notifications-title">Notifications</h2></div>
    <div class="set-card-body">
        <form class="set-row" method="post" action="<?= url('settings.php') ?>" data-autosave>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="notifications">
            <div class="set-text">
                <label class="set-label" for="<?= $emailKey ?>"><?= $isStudent ? 'Deadline emails' : 'Review emails' ?></label>
                <p class="set-desc"><?= $isStudent
                    ? 'An email a few days before a requirement deadline and again on the day. The bell shows them either way.'
                    : 'An email when a registration or document waits for you. The bell shows them either way.' ?></p>
            </div>
            <div class="set-control">
                <span class="set-switch-state" aria-hidden="true"><?= $emailAlertsOn ? 'On' : 'Off' ?></span>
                <input class="switch" type="checkbox" role="switch" id="<?= $emailKey ?>" name="<?= $emailKey ?>" value="1"
                    <?= $emailAlertsOn ? 'checked' : '' ?>>
                <button type="submit" class="btn btn-primary btn-sm" data-autosave-submit>Save</button>
            </div>
        </form>
    </div>
</section>

<?php // --------------------------------------------------- Activity list ?>
<section class="set-card" id="activity-list" aria-labelledby="activity-list-title">
    <div class="set-card-head"><h2 id="activity-list-title">Activity list</h2></div>
    <div class="set-card-body">
        <form class="set-row" method="post" action="<?= url('settings.php') ?>" data-autosave>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="list">
            <div class="set-text">
                <label class="set-label" for="hide_past_activities">Hide ended activities</label>
                <p class="set-desc">Filtering by status still shows them, so a report or a closed event stays reachable.</p>
            </div>
            <div class="set-control">
                <span class="set-switch-state" aria-hidden="true"><?= $hidePast ? 'On' : 'Off' ?></span>
                <input class="switch" type="checkbox" role="switch" id="hide_past_activities" name="hide_past_activities" value="1"
                    <?= $hidePast ? 'checked' : '' ?>>
                <button type="submit" class="btn btn-primary btn-sm" data-autosave-submit>Save</button>
            </div>
        </form>
    </div>
</section>

<?php if ($isAdmin): ?>
    <?php // ------------------------------------------------------- Office ?>
    <section class="set-card" id="office" aria-labelledby="office-title">
        <div class="set-card-head"><h2 id="office-title">Office</h2></div>
        <div class="set-card-body">
            <?php foreach ($officeFields as $key => [$label, $value]): ?>
                <?php if ($key === $editing): ?>
                    <form class="set-edit" method="post" action="<?= url('settings.php#office') ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="office">
                        <input type="hidden" name="edit" value="<?= e($key) ?>">
                        <?php foreach ($officeFields as $otherKey => [, $otherValue]): ?>
                            <?php if ($otherKey !== $key): ?>
                                <input type="hidden" name="<?= e($otherKey) ?>" value="<?= e(post($otherKey, $otherValue)) ?>">
                            <?php endif; ?>
                        <?php endforeach; ?>

                        <?php if ($errors !== []): ?>
                            <div class="alert alert-error" role="alert">
                                <?php foreach ($errors as $error): ?><div><?= e($error) ?></div><?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <div class="form-row">
                            <label for="<?= e($key) ?>"><?= e($label) ?><?= $key === 'max_upload_size_mb' ? ' (MB)' : '' ?></label>
                            <?php if ($key === 'max_upload_size_mb'): ?>
                                <input type="number" id="<?= e($key) ?>" name="<?= e($key) ?>" min="1" max="50" required autofocus
                                       value="<?= e(post($key, $value)) ?>">
                                <div class="hint">1&ndash;50. Students see this limit on the upload page.</div>
                            <?php else: ?>
                                <input type="text" id="<?= e($key) ?>" name="<?= e($key) ?>" required maxlength="120" autofocus
                                       value="<?= e(post($key, $value)) ?>">
                            <?php endif; ?>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary" data-loading="Saving…">Save</button>
                            <a class="btn btn-outline" href="<?= url('settings.php#office') ?>">Cancel</a>
                        </div>
                    </form>
                <?php else: ?>
                    <div class="set-row">
                        <div class="set-text">
                            <span class="set-label"><?= e($label) ?></span>
                            <p class="set-desc"><?= e($value) ?><?= $key === 'max_upload_size_mb' ? ' MB per requirement document' : '' ?></p>
                        </div>
                        <a class="btn btn-outline btn-sm" href="<?= url('settings.php?edit=' . $key . '#office') ?>">Change<span class="sr-only"> <?= e(strtolower($label)) ?></span></a>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
            <div class="set-row">
                <div class="set-text">
                    <span class="set-label">Deadline reminders</span>
                    <p class="set-desc">What is due soon, and sending reminders by hand.</p>
                </div>
                <a class="btn btn-outline btn-sm" href="<?= url('admin/reminders.php') ?>">Open<span class="sr-only"> reminders</span></a>
            </div>
        </div>
    </section>
<?php endif; ?>

<?php // -------------------------------------------------------- Account ?>
<section class="set-card" id="account" aria-labelledby="account-title">
    <div class="set-card-head"><h2 id="account-title">Account</h2></div>
    <div class="set-card-body">
        <div class="set-row is-value">
            <div class="set-text">
                <span class="set-label">Email address</span>
                <p class="set-desc">The address you sign in with.</p>
            </div>
            <span class="set-value"><?= str_replace('@', '@<wbr>', e($user['email'])) ?></span>
        </div>
        <div class="set-row is-value">
            <span class="set-label">Role</span>
            <span class="set-value"><?= e(ucfirst((string) $user['role_name'])) ?></span>
        </div>
        <div class="set-row is-value">
            <span class="set-label">Account status</span>
            <span class="set-value"><?= status_badge((string) $user['status'], 'account') ?></span>
        </div>
        <div class="set-row">
            <div class="set-text">
                <span class="set-label">Password</span>
                <p class="set-desc">Changed on your profile, where you confirm the current one first.</p>
            </div>
            <a class="btn btn-outline btn-sm" href="<?= url('profile.php?edit=password#password') ?>">Change password</a>
        </div>
        <div class="set-row">
            <div class="set-text">
                <span class="set-label">Name and photo</span>
                <p class="set-desc">Kept on your profile, so both pages agree.</p>
            </div>
            <a class="btn btn-outline btn-sm" href="<?= url('profile.php') ?>">Open profile</a>
        </div>
    </div>
</section>

</div>
</div>
</div>

<?php require __DIR__ . '/../includes/layout/footer.php'; ?>
