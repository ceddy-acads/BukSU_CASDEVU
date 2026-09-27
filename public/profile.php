<?php
/**
 * CASMS — Profile and password management (FR-1.5)
 *
 * Read-only by default: identity on the left, contact and account on the
 * right. ?edit=details swaps the left panel for the edit form and
 * ?edit=password the right one for the password form; both are plain links.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

require_login();

$user       = current_user();
$courses    = fetch_all('SELECT course_id, code, name FROM courses WHERE is_active = 1 ORDER BY code');
$yearLevels = fetch_all('SELECT year_level_id, label FROM year_levels ORDER BY sort_order');

$errors = [];

if (is_post()) {
    csrf_verify();
    $action = post('action');

    // --------------------------------------------------------- Profile photo
    if ($action === 'photo') {
        $stored = store_profile_photo($_FILES['photo'] ?? []);
        if (!$stored['ok']) {
            $errors[] = $stored['error'];
        } else {
            query('UPDATE users SET photo_path = ? WHERE user_id = ?', [$stored['path'], $user['user_id']]);
            delete_upload($user['photo_path']);   // the one it replaces, if any
            audit_log('update', 'user', (int) $user['user_id'], 'Changed own profile photo');
            flash('success', 'Your profile photo has been updated.');
            redirect('profile.php');
        }
    }

    if ($action === 'photo_remove' && !empty($user['photo_path'])) {
        query('UPDATE users SET photo_path = NULL WHERE user_id = ?', [$user['user_id']]);
        delete_upload($user['photo_path']);
        audit_log('update', 'user', (int) $user['user_id'], 'Removed own profile photo');
        flash('success', 'Your profile photo has been removed.');
        redirect('profile.php');
    }

    // ------------------------------------------------------ Update details
    if ($action === 'details') {
        $firstName     = post('first_name');
        $middleName    = post('middle_name');
        $lastName      = post('last_name');
        $contactNumber = post('contact_number');
        $courseId      = post('course_id');
        $yearLevelId   = post('year_level_id');
        $section       = post('section');

        if ($firstName === '' || $lastName === '') {
            $errors[] = 'First name and last name are required.';
        }

        if ($errors === []) {
            query(
                'UPDATE users
                    SET first_name = ?, middle_name = ?, last_name = ?, contact_number = ?,
                        course_id = ?, year_level_id = ?, section = ?
                  WHERE user_id = ?',
                [
                    $firstName,
                    $middleName !== '' ? $middleName : null,
                    $lastName,
                    $contactNumber !== '' ? $contactNumber : null,
                    $courseId !== '' ? (int) $courseId : null,
                    $yearLevelId !== '' ? (int) $yearLevelId : null,
                    $section !== '' ? $section : null,
                    $user['user_id'],
                ]
            );

            audit_log('update', 'user', (int) $user['user_id'], 'Updated own profile');
            flash('success', 'Your profile has been updated.');
            redirect('profile.php');
        }
    }

    // ----------------------------------------------------- Change password
    if ($action === 'password') {
        $currentPassword = (string) ($_POST['current_password'] ?? '');
        $newPassword     = (string) ($_POST['new_password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

        if (!password_verify($currentPassword, $user['password_hash'])) {
            $errors[] = 'Your current password is incorrect.';
        }
        if (strlen($newPassword) < 8) {
            $errors[] = 'The new password must be at least 8 characters long.';
        }
        if ($newPassword !== $confirmPassword) {
            $errors[] = 'The two new passwords do not match.';
        }

        if ($errors === []) {
            query(
                'UPDATE users SET password_hash = ? WHERE user_id = ?',
                [password_hash($newPassword, PASSWORD_DEFAULT), $user['user_id']]
            );

            audit_log('update', 'user', (int) $user['user_id'], 'Changed own password');
            flash('success', 'Your password has been changed.');
            redirect('profile.php');
        }
    }
}

// Which part is being edited: none (the read-only profile), the details,
// or the password. A failed submit reopens the form it came from.
$mode = get('edit');
if (is_post() && $errors !== []) {
    $mode = post('action');
}
$mode = in_array($mode, ['details', 'password'], true) ? $mode : 'view';

$isStudent = current_role() === 'student';
$initials  = strtoupper(mb_substr((string) $user['first_name'], 0, 1) . mb_substr((string) $user['last_name'], 0, 1));
$photoUrl  = user_photo_url($user);

/** A profile value, or a muted "Not set". */
$show = static fn (?string $value): string =>
    $value !== null && trim($value) !== '' ? e($value) : '<span class="muted">Not set</span>';

$pageTitle = 'My profile';
require __DIR__ . '/../includes/layout/header.php';
?>

<div class="page-head">
    <div>
        <h1>My profile</h1>
        <p>Keep your details current so the office can reach you.</p>
    </div>
</div>

<?php if ($errors !== []): ?>
    <div class="alert alert-error" role="alert">
        <?php foreach ($errors as $error): ?>
            <div><?= e($error) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<section class="card profile-sheet" aria-labelledby="profile-name">
    <div class="profile-grid">

        <?php // ---------------------------------------------------- Identity ?>
        <div class="profile-panel">
            <div class="profile-id">
                <span class="profile-avatar" data-photo-preview>
                    <?php if ($photoUrl): ?>
                        <img src="<?= e($photoUrl) ?>" alt="Your profile photo" width="64" height="64">
                    <?php else: ?>
                        <span aria-hidden="true"><?= e($initials) ?></span>
                    <?php endif; ?>
                </span>
                <div>
                    <h2 id="profile-name"><?= e(full_name($user)) ?></h2>
                    <p class="profile-role">
                        <?= e(ucfirst((string) $user['role_name'])) ?>
                        <?= status_badge($user['status'], 'account') ?>
                    </p>

                    <?php // Choosing a file uploads it straight away (app.js crops and resizes it
                          // first); without JavaScript the Upload button sends it. ?>
                    <div class="profile-photo-actions">
                        <form method="post" action="<?= url('profile.php') ?>" enctype="multipart/form-data" data-photo-form>
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="photo">
                            <label class="btn btn-outline btn-sm profile-photo-pick">
                                <?= $photoUrl ? 'Change photo' : 'Add photo' ?>
                                <input type="file" name="photo" accept="image/jpeg,image/png" class="sr-only" data-photo-input
                                       aria-describedby="photo-hint">
                            </label>
                            <button type="submit" class="btn btn-primary btn-sm" data-photo-submit data-loading="Uploading…">Upload</button>
                        </form>
                        <?php if ($photoUrl): ?>
                            <form method="post" action="<?= url('profile.php') ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="photo_remove">
                                <button type="submit" class="btn btn-ghost btn-sm">Remove</button>
                            </form>
                        <?php endif; ?>
                    </div>
                    <p class="profile-photo-hint" id="photo-hint">JPG or PNG, shown cropped to a circle.</p>
                </div>
            </div>

            <?php if ($mode === 'details'): ?>
                <form method="post" action="<?= url('profile.php') ?>" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="details">

                    <div class="form-grid form-grid-2">
                        <div class="form-row">
                            <label for="first_name">First name <span class="req">*</span></label>
                            <input type="text" id="first_name" name="first_name"
                                   value="<?= e(post('first_name', (string) $user['first_name'])) ?>" required autocomplete="given-name">
                        </div>
                        <div class="form-row">
                            <label for="last_name">Last name <span class="req">*</span></label>
                            <input type="text" id="last_name" name="last_name"
                                   value="<?= e(post('last_name', (string) $user['last_name'])) ?>" required autocomplete="family-name">
                        </div>
                        <div class="form-row">
                            <label for="middle_name">Middle name <span class="optional">(optional)</span></label>
                            <input type="text" id="middle_name" name="middle_name"
                                   value="<?= e(post('middle_name', (string) $user['middle_name'])) ?>" autocomplete="additional-name">
                        </div>
                        <div class="form-row">
                            <label for="contact_number">Contact number <span class="optional">(optional)</span></label>
                            <input type="tel" id="contact_number" name="contact_number"
                                   value="<?= e(post('contact_number', (string) $user['contact_number'])) ?>" autocomplete="tel">
                        </div>
                    </div>

                    <?php if ($isStudent): ?>
                        <div class="form-grid form-grid-2">
                            <div class="form-row">
                                <label for="course_id">Course</label>
                                <select id="course_id" name="course_id">
                                    <option value="">Not set</option>
                                    <?php foreach ($courses as $course): ?>
                                        <option value="<?= (int) $course['course_id'] ?>"
                                            <?= (int) $user['course_id'] === (int) $course['course_id'] ? 'selected' : '' ?>>
                                            <?= e($course['code']) ?>: <?= e($course['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-row">
                                <label for="year_level_id">Year level</label>
                                <select id="year_level_id" name="year_level_id">
                                    <option value="">Not set</option>
                                    <?php foreach ($yearLevels as $level): ?>
                                        <option value="<?= (int) $level['year_level_id'] ?>"
                                            <?= (int) $user['year_level_id'] === (int) $level['year_level_id'] ? 'selected' : '' ?>>
                                            <?= e($level['label']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="form-row">
                            <label for="section">Section</label>
                            <input type="text" id="section" name="section" value="<?= e(post('section', (string) $user['section'])) ?>">
                        </div>
                    <?php endif; ?>

                    <div class="profile-actions">
                        <a class="btn btn-outline" href="<?= url('profile.php') ?>">Cancel</a>
                        <button type="submit" class="btn btn-primary">Save changes</button>
                    </div>
                </form>
            <?php else: ?>
                <dl class="profile-fields">
                    <div><dt>First name</dt><dd><?= $show($user['first_name']) ?></dd></div>
                    <div><dt>Last name</dt><dd><?= $show($user['last_name']) ?></dd></div>
                    <div><dt>Middle name</dt><dd><?= $show($user['middle_name']) ?></dd></div>
                    <?php if ($isStudent): ?>
                        <div><dt>Student number</dt><dd><?= $show($user['student_number']) ?></dd></div>
                        <div class="profile-wide"><dt>Course</dt><dd><?= $user['course_code']
                            ? e($user['course_code'] . ': ' . $user['course_name'])
                            : '<span class="muted">Not set</span>' ?></dd></div>
                        <div><dt>Year level</dt><dd><?= $show($user['year_level_label']) ?></dd></div>
                        <div><dt>Section</dt><dd><?= $show($user['section']) ?></dd></div>
                    <?php else: ?>
                        <div><dt>Role</dt><dd><?= e(ucfirst((string) $user['role_name'])) ?></dd></div>
                    <?php endif; ?>
                </dl>
            <?php endif; ?>
        </div>

        <?php // --------------------------------------- Contact and account ?>
        <div class="profile-panel">
            <?php if ($mode === 'password'): ?>
                <h2 class="profile-panel-title" id="password">Change password</h2>
                <p class="profile-panel-sub">Use at least 8 characters.</p>
                <form method="post" action="<?= url('profile.php') ?>" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="password">
                    <?php // Unnamed, so never submitted: lets password managers pair the new password with this account. ?>
                    <input type="email" autocomplete="username" value="<?= e($user['email']) ?>" hidden aria-hidden="true" tabindex="-1">

                    <div class="form-row">
                        <label for="current_password">Current password <span class="req">*</span></label>
                        <input type="password" id="current_password" name="current_password"
                               autocomplete="current-password" required>
                    </div>
                    <div class="form-row">
                        <label for="new_password">New password <span class="req">*</span></label>
                        <input type="password" id="new_password" name="new_password"
                               autocomplete="new-password" required>
                    </div>
                    <div class="form-row">
                        <label for="confirm_password">Confirm new password <span class="req">*</span></label>
                        <input type="password" id="confirm_password" name="confirm_password"
                               autocomplete="new-password" required>
                    </div>

                    <div class="profile-actions">
                        <a class="btn btn-outline" href="<?= url('profile.php') ?>">Cancel</a>
                        <button type="submit" class="btn btn-primary">Change password</button>
                    </div>
                </form>
            <?php else: ?>
                <h2 class="profile-panel-title">Contact and account</h2>
                <p class="profile-panel-sub">How the office reaches you, and your sign-in details.</p>
                <dl class="profile-fields">
                    <?php // A long address may break after the @ rather than mid-word. ?>
                    <div class="profile-wide"><dt>Email address</dt><dd><?= str_replace('@', '@<wbr>', e($user['email'])) ?></dd></div>
                    <div><dt>Contact number</dt><dd><?= $show($user['contact_number']) ?></dd></div>
                    <div><dt>Account status</dt><dd><?= e(status_meta((string) $user['status'], 'account')[1]) ?></dd></div>
                    <div><dt>Last sign-in</dt><dd><?= e(format_datetime($user['last_login_at'])) ?></dd></div>
                    <div><dt>Member since</dt><dd><?= e(format_date($user['created_at'])) ?></dd></div>
                </dl>
                <p class="profile-note">To change your registered email, contact the office.</p>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($mode === 'view'): ?>
        <div class="profile-actions">
            <a class="btn btn-outline" href="<?= url('profile.php?edit=password#password') ?>">Change password</a>
            <a class="btn btn-primary" href="<?= url('profile.php?edit=details') ?>">Edit profile</a>
        </div>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/../includes/layout/footer.php'; ?>
