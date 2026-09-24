<?php
/**
 * CASMS — Profile and password management (FR-1.5)
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

$isStudent = current_role() === 'student';
$pageTitle = 'My profile';
require __DIR__ . '/../includes/layout/header.php';
?>

<div class="page-head">
    <div>
        <h1>My profile</h1>
        <p>Keep your contact details current so the office can reach you.</p>
    </div>
</div>

<?php if ($errors !== []): ?>
    <div class="alert alert-error">
        <?php foreach ($errors as $error): ?>
            <div><?= e($error) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="split">
    <section class="card">
        <div class="card-head"><h2>Personal details</h2></div>

        <form method="post" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="details">

            <div class="form-row">
                <label for="email_display">Email address</label>
                <input type="email" id="email_display" value="<?= e($user['email']) ?>" disabled>
                <div class="hint">Contact the office to change your registered email.</div>
            </div>

            <?php if ($user['student_number']): ?>
                <div class="form-row">
                    <label for="student_number_display">Student number</label>
                    <input type="text" id="student_number_display" value="<?= e($user['student_number']) ?>" disabled>
                </div>
            <?php endif; ?>

            <div class="form-grid form-grid-2">
                <div class="form-row">
                    <label for="first_name">First name <span class="req">*</span></label>
                    <input type="text" id="first_name" name="first_name"
                           value="<?= e($user['first_name']) ?>" required>
                </div>
                <div class="form-row">
                    <label for="last_name">Last name <span class="req">*</span></label>
                    <input type="text" id="last_name" name="last_name"
                           value="<?= e($user['last_name']) ?>" required>
                </div>
                <div class="form-row">
                    <label for="middle_name">Middle name <span class="optional">(optional)</span></label>
                    <input type="text" id="middle_name" name="middle_name"
                           value="<?= e($user['middle_name']) ?>">
                </div>
                <div class="form-row">
                    <label for="contact_number">Contact number <span class="optional">(optional)</span></label>
                    <input type="text" id="contact_number" name="contact_number"
                           value="<?= e($user['contact_number']) ?>">
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
                    <input type="text" id="section" name="section" value="<?= e($user['section']) ?>">
                </div>
            <?php endif; ?>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Save changes</button>
            </div>
        </form>
    </section>

    <div class="stack">
    <section class="card">
        <div class="card-head"><h2>Change password</h2></div>

        <form method="post" novalidate>
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
                <div class="hint">At least 8 characters.</div>
            </div>
            <div class="form-row">
                <label for="confirm_password">Confirm new password <span class="req">*</span></label>
                <input type="password" id="confirm_password" name="confirm_password"
                       autocomplete="new-password" required>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Change password</button>
            </div>
        </form>
    </section>

    <section class="card">
        <div class="card-head"><h2>Account</h2></div>
        <dl class="detail-list">
            <div><dt>Role</dt><dd><?= e(ucfirst((string) $user['role_name'])) ?></dd></div>
            <div><dt>Status</dt><dd><?= status_badge($user['status'], 'account') ?></dd></div>
            <div><dt>Last sign-in</dt><dd><?= e(format_datetime($user['last_login_at'])) ?></dd></div>
            <div><dt>Member since</dt><dd><?= e(format_date($user['created_at'])) ?></dd></div>
        </dl>
    </section>
    </div>
</div>

<?php require __DIR__ . '/../includes/layout/footer.php'; ?>
