<?php
/**
 * CASMS — Student self-registration (FR-1.2, FR-1.3)
 *
 * New accounts are created with status 'pending' and cannot sign in until the
 * office activates them.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

if (is_logged_in() && current_user() !== null) {
    redirect('index.php');
}

$courses    = fetch_all('SELECT course_id, code, name FROM courses WHERE is_active = 1 ORDER BY code');
$yearLevels = fetch_all('SELECT year_level_id, label FROM year_levels ORDER BY sort_order');

$errors = [];

if (is_post()) {
    csrf_verify();

    $studentNumber = post('student_number');
    $firstName     = post('first_name');
    $middleName    = post('middle_name');
    $lastName      = post('last_name');
    $email         = strtolower(post('email'));
    $contactNumber = post('contact_number');
    $courseId      = post('course_id');
    $yearLevelId   = post('year_level_id');
    $section       = post('section');
    $password      = (string) ($_POST['password'] ?? '');
    $passwordAgain = (string) ($_POST['password_confirm'] ?? '');

    // ------------------------------------------------------------ Validation
    if ($studentNumber === '') {
        $errors[] = 'Student number is required.';
    }
    if ($firstName === '' || $lastName === '') {
        $errors[] = 'First name and last name are required.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'A valid email address is required.';
    }
    if ($courseId === '' || $yearLevelId === '') {
        $errors[] = 'Course and year level are required.';
    }
    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters long.';
    }
    if ($password !== $passwordAgain) {
        $errors[] = 'The two passwords do not match.';
    }

    // Uniqueness — the database also enforces this, but a clear message beats
    // a constraint violation.
    if ($errors === []) {
        if (fetch_value('SELECT 1 FROM users WHERE email = ?', [$email])) {
            $errors[] = 'That email address is already registered.';
        }
        if (fetch_value('SELECT 1 FROM users WHERE student_number = ?', [$studentNumber])) {
            $errors[] = 'That student number is already registered.';
        }
    }

    // ---------------------------------------------------------------- Insert
    if ($errors === []) {
        $studentRoleId = fetch_value("SELECT role_id FROM roles WHERE name = 'student'");

        query(
            'INSERT INTO users
                 (role_id, email, password_hash, student_number, first_name, middle_name,
                  last_name, contact_number, course_id, year_level_id, section, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $studentRoleId,
                $email,
                password_hash($password, PASSWORD_DEFAULT),
                $studentNumber,
                $firstName,
                $middleName !== '' ? $middleName : null,
                $lastName,
                $contactNumber !== '' ? $contactNumber : null,
                (int) $courseId,
                (int) $yearLevelId,
                $section !== '' ? $section : null,
                'pending',
            ]
        );

        $newUserId = (int) db()->lastInsertId();
        audit_log('create', 'user', $newUserId, 'Student self-registered: ' . $email);

        // Let the office know an account is waiting.
        $officeIds = fetch_all(
            "SELECT u.user_id FROM users u JOIN roles r ON r.role_id = u.role_id
              WHERE r.name IN ('staff','admin') AND u.status = 'active'"
        );
        notify_many(
            array_map(static fn(array $r): int => (int) $r['user_id'], $officeIds),
            'system',
            'New student registration',
            $firstName . ' ' . $lastName . ' (' . $studentNumber . ') is awaiting account approval.',
            url('admin/users.php?status=pending')
        );

        clear_old_input();
        flash('success', 'Your account was created and is now awaiting approval by the office. You will be able to sign in once it is activated.');
        redirect('login.php');
    }

    remember_input($_POST);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student registration — <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= url('assets/css/style.css') ?>">
</head>
<body>
<div class="auth-wrap">
    <div class="auth-card wide">
        <div class="auth-head">
            <h1>Student registration</h1>
            <p><?= e(OFFICE_NAME) ?> &middot; <?= e(UNIVERSITY) ?></p>
        </div>

        <?php if ($errors !== []): ?>
            <div class="alert alert-error">
                <?php foreach ($errors as $error): ?>
                    <div><?= e($error) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="alert alert-info">
            New accounts must be approved by the office before you can sign in.
        </div>

        <form method="post" novalidate>
            <?= csrf_field() ?>

            <div class="form-grid form-grid-2">
                <div class="form-row">
                    <label for="student_number">Student number <span class="req">*</span></label>
                    <input type="text" id="student_number" name="student_number"
                           value="<?= e(old('student_number')) ?>" required>
                </div>
                <div class="form-row">
                    <label for="email">Email address <span class="req">*</span></label>
                    <input type="email" id="email" name="email"
                           value="<?= e(old('email')) ?>" required>
                </div>

                <div class="form-row">
                    <label for="first_name">First name <span class="req">*</span></label>
                    <input type="text" id="first_name" name="first_name"
                           value="<?= e(old('first_name')) ?>" required>
                </div>
                <div class="form-row">
                    <label for="last_name">Last name <span class="req">*</span></label>
                    <input type="text" id="last_name" name="last_name"
                           value="<?= e(old('last_name')) ?>" required>
                </div>

                <div class="form-row">
                    <label for="middle_name">Middle name</label>
                    <input type="text" id="middle_name" name="middle_name"
                           value="<?= e(old('middle_name')) ?>">
                </div>
                <div class="form-row">
                    <label for="contact_number">Contact number</label>
                    <input type="text" id="contact_number" name="contact_number"
                           value="<?= e(old('contact_number')) ?>">
                </div>

                <div class="form-row">
                    <label for="course_id">Course <span class="req">*</span></label>
                    <select id="course_id" name="course_id" required>
                        <option value="">— Select course —</option>
                        <?php foreach ($courses as $course): ?>
                            <option value="<?= (int) $course['course_id'] ?>"
                                <?= old('course_id') === (string) $course['course_id'] ? 'selected' : '' ?>>
                                <?= e($course['code']) ?> — <?= e($course['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row">
                    <label for="year_level_id">Year level <span class="req">*</span></label>
                    <select id="year_level_id" name="year_level_id" required>
                        <option value="">— Select year level —</option>
                        <?php foreach ($yearLevels as $level): ?>
                            <option value="<?= (int) $level['year_level_id'] ?>"
                                <?= old('year_level_id') === (string) $level['year_level_id'] ? 'selected' : '' ?>>
                                <?= e($level['label']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-row">
                    <label for="section">Section</label>
                    <input type="text" id="section" name="section" value="<?= e(old('section')) ?>">
                </div>
                <div class="form-row"></div>

                <div class="form-row">
                    <label for="password">Password <span class="req">*</span></label>
                    <input type="password" id="password" name="password"
                           autocomplete="new-password" required>
                    <div class="hint">At least 8 characters.</div>
                </div>
                <div class="form-row">
                    <label for="password_confirm">Confirm password <span class="req">*</span></label>
                    <input type="password" id="password_confirm" name="password_confirm"
                           autocomplete="new-password" required>
                </div>
            </div>

            <button type="submit" class="btn btn-primary btn-block">Create account</button>
        </form>

        <div class="auth-foot">
            Already registered? <a href="<?= url('login.php') ?>">Sign in</a>
        </div>
    </div>
</div>
</body>
</html>
<?php clear_old_input(); ?>
