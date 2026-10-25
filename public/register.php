<?php
/**
 * CASMS — Student and guest self-registration (FR-1.2, FR-1.3)
 *
 * Students use the default form; ?as=guest is the form for people outside the
 * student body (parents, outside coaches). New accounts are created with status 'pending' and cannot sign in until the
 * office activates them.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

if (is_logged_in() && current_user() !== null) {
    redirect('index.php');
}

$courses    = fetch_all('SELECT course_id, code, name, department FROM courses WHERE is_active = 1 ORDER BY department, name');
$yearLevels = fetch_all('SELECT year_level_id, label FROM year_levels ORDER BY sort_order');

$errors = [];
$as     = (is_post() ? post('as') : get('as')) === 'guest' ? 'guest' : 'student';

// Set by either handler below when an account was created; the notify,
// flash and redirect tail is shared.
$signedUp = null;

if (is_post() && $as === 'student') {
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
    } elseif (!in_array($courseId, array_map(static fn(array $c): string => (string) $c['course_id'], $courses), true)) {
        $errors[] = 'Choose a course from the list.';
    } elseif (!in_array($yearLevelId, array_map(static fn(array $y): string => (string) $y['year_level_id'], $yearLevels), true)) {
        $errors[] = 'Choose a year level from the list.';
    }
    if (($passwordProblem = password_error($password)) !== null) {
        $errors[] = $passwordProblem;
    }
    if ($password !== $passwordAgain) {
        $errors[] = 'The two passwords do not match.';
    }
    $errors = array_merge($errors, length_errors([
        'Student number' => [$studentNumber, 30],
        'First name'     => [$firstName, 80],
        'Middle name'    => [$middleName, 80],
        'Last name'      => [$lastName, 80],
        'Email address'  => [$email, 150],
        'Contact number' => [$contactNumber, 30],
        'Section'        => [$section, 20],
    ]));

    // Uniqueness — the database also enforces this, but a clear message beats
    // a constraint violation. One message whichever is taken: it reads better
    // than two stacked lines, and does not reveal which detail is on file.
    if ($errors === []) {
        $taken = fetch_value(
            'SELECT 1 FROM users WHERE email = ? OR student_number = ? LIMIT 1',
            [$email, $studentNumber]
        );
        if ($taken) {
            $errors[] = 'An account with this email address or student number already exists. '
                      . 'Sign in instead, or reset your password if you have forgotten it.';
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

        $signedUp = [
            'New student registration',
            $firstName . ' ' . $lastName . ' (' . $studentNumber . ') is awaiting account approval.',
        ];
    }
}

if (is_post() && $as === 'guest') {
    csrf_verify();

    $firstName      = post('first_name');
    $middleName     = post('middle_name');
    $lastName       = post('last_name');
    $email          = strtolower(post('email'));
    $contactNumber  = post('contact_number');
    $connectionNote = post('connection_note');
    $password       = (string) ($_POST['password'] ?? '');
    $passwordAgain  = (string) ($_POST['password_confirm'] ?? '');

    if ($firstName === '' || $lastName === '') {
        $errors[] = 'First name and last name are required.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'A valid email address is required.';
    }
    if ($contactNumber === '') {
        $errors[] = 'Contact number is required.';
    }
    if ($connectionNote === '') {
        $errors[] = 'Tell the office how you are connected to the unit\'s activities.';
    } elseif (mb_strlen($connectionNote) > 500) {
        $errors[] = 'Your connection to the unit must be 500 characters or fewer.';
    }
    if (($passwordProblem = password_error($password)) !== null) {
        $errors[] = $passwordProblem;
    }
    if ($password !== $passwordAgain) {
        $errors[] = 'The two passwords do not match.';
    }
    $errors = array_merge($errors, length_errors([
        'First name'     => [$firstName, 80],
        'Middle name'    => [$middleName, 80],
        'Last name'      => [$lastName, 80],
        'Email address'  => [$email, 150],
        'Contact number' => [$contactNumber, 30],
    ]));

    if ($errors === [] && fetch_value('SELECT 1 FROM users WHERE email = ? LIMIT 1', [$email])) {
        $errors[] = 'An account with this email address already exists. '
                  . 'Sign in instead, or reset your password if you have forgotten it.';
    }

    if ($errors === []) {
        query(
            'INSERT INTO users
                 (role_id, email, password_hash, first_name, middle_name,
                  last_name, contact_number, connection_note, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                fetch_value("SELECT role_id FROM roles WHERE name = 'guest'"),
                $email,
                password_hash($password, PASSWORD_DEFAULT),
                $firstName,
                $middleName !== '' ? $middleName : null,
                $lastName,
                $contactNumber,
                $connectionNote,
                'pending',
            ]
        );

        $newUserId = (int) db()->lastInsertId();
        audit_log('create', 'user', $newUserId, 'Guest self-registered: ' . $email);

        $signedUp = [
            'New guest sign-up',
            $firstName . ' ' . $lastName . ' is awaiting account approval as a guest.',
        ];
    }
}

if ($signedUp !== null) {
    // Let the office know an account is waiting.
    $officeIds = fetch_all(
        "SELECT u.user_id FROM users u JOIN roles r ON r.role_id = u.role_id
          WHERE r.name IN ('staff','admin') AND u.status = 'active'"
    );
    notify_many(
        array_map(static fn(array $r): int => (int) $r['user_id'], $officeIds),
        'system',
        $signedUp[0],
        $signedUp[1],
        url('admin/users.php?status=pending')
    );

    clear_old_input();
    flash('success', 'Your account was created and is now awaiting approval by the office. You will be able to sign in once it is activated.');
    redirect('login.php');
}

if (is_post()) {
    remember_input($_POST);
}
$pageTitle = 'Create an account';
$authIntro = $as === 'guest'
    ? 'For parents, coaches and others who follow the unit\'s activities. The office reviews new accounts before you can sign in.'
    : 'The office reviews new accounts before you can sign in.';
$portalWide = true;
require __DIR__ . '/../includes/layout/portal-header.php';
?>

<nav class="tabs tabs-signup" aria-label="Who is signing up">
    <a class="tab" href="<?= url('register.php') ?>"<?= $as === 'student' ? ' aria-current="page"' : '' ?>>BukSU student</a>
    <a class="tab" href="<?= url('register.php?as=guest') ?>"<?= $as === 'guest' ? ' aria-current="page"' : '' ?>>Not a BukSU student</a>
</nav>

<?php if ($errors !== []): ?>
    <div class="alert alert-error" role="alert">
        <?php foreach ($errors as $error): ?>
            <div><?= e($error) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php // Four short steps, one at a time (app.js), so the form fits on one screen.
      // Without JavaScript all four show in order and the form works the same. ?>
<form method="post" novalidate data-steps>
    <?= csrf_field() ?>

    <p class="steps-status" data-steps-status hidden aria-live="polite"></p>

<?php if ($as === 'guest'): ?>
    <input type="hidden" name="as" value="guest">

    <div class="form-section" data-step>
        <h3 tabindex="-1">About you</h3>
        <div class="form-grid form-grid-2">
            <div class="form-row">
                <label for="first_name">First name <span class="req">*</span></label>
                <input type="text" id="first_name" name="first_name" maxlength="80"
                       value="<?= e(old('first_name')) ?>" autocomplete="given-name" required>
            </div>
            <div class="form-row">
                <label for="last_name">Last name <span class="req">*</span></label>
                <input type="text" id="last_name" name="last_name" maxlength="80"
                       value="<?= e(old('last_name')) ?>" autocomplete="family-name" required>
            </div>
            <div class="form-row">
                <label for="middle_name">Middle name <span class="optional">(optional)</span></label>
                <input type="text" id="middle_name" name="middle_name" maxlength="80"
                       value="<?= e(old('middle_name')) ?>" autocomplete="additional-name">
            </div>
            <div class="form-row">
                <label for="email">Email address <span class="req">*</span></label>
                <input type="email" id="email" name="email" maxlength="150"
                       value="<?= e(old('email')) ?>" autocomplete="username" required>
            </div>
            <div class="form-row">
                <label for="contact_number">Contact number <span class="req">*</span></label>
                <input type="tel" id="contact_number" name="contact_number" maxlength="30"
                       value="<?= e(old('contact_number')) ?>" autocomplete="tel" required>
            </div>
        </div>
    </div>

    <div class="form-section" data-step>
        <h3 tabindex="-1">Your connection</h3>
        <div class="form-row">
            <label for="connection_note">How are you connected to the unit's activities? <span class="req">*</span></label>
            <textarea id="connection_note" name="connection_note" rows="4" maxlength="500" required
                      aria-describedby="connection_note-hint"><?= e(old('connection_note')) ?></textarea>
            <p class="hint" id="connection_note-hint">For example: parent of a student in the dance troupe, or coach of a visiting team. The office reads this before activating your account.</p>
        </div>
    </div>
<?php else: ?>
    <div class="form-section" data-step>
        <h3 tabindex="-1">Your name</h3>
        <div class="form-grid form-grid-2">
            <div class="form-row">
                <label for="first_name">First name <span class="req">*</span></label>
                <input type="text" id="first_name" name="first_name" maxlength="80"
                       value="<?= e(old('first_name')) ?>" autocomplete="given-name" required>
            </div>
            <div class="form-row">
                <label for="last_name">Last name <span class="req">*</span></label>
                <input type="text" id="last_name" name="last_name" maxlength="80"
                       value="<?= e(old('last_name')) ?>" autocomplete="family-name" required>
            </div>
            <div class="form-row">
                <label for="middle_name">Middle name <span class="optional">(optional)</span></label>
                <input type="text" id="middle_name" name="middle_name" maxlength="80"
                       value="<?= e(old('middle_name')) ?>" autocomplete="additional-name">
            </div>
        </div>
    </div>

    <div class="form-section" data-step>
        <h3 tabindex="-1">Credentials</h3>
        <div class="form-grid form-grid-2">
            <div class="form-row">
                <label for="student_number">Student number <span class="req">*</span></label>
                <input type="text" id="student_number" name="student_number" maxlength="30"
                       value="<?= e(old('student_number')) ?>" required>
            </div>
            <div class="form-row">
                <label for="email">Email address <span class="req">*</span></label>
                <input type="email" id="email" name="email" maxlength="150"
                       value="<?= e(old('email')) ?>" autocomplete="username" required>
            </div>
            <div class="form-row">
                <label for="contact_number">Contact number <span class="optional">(optional)</span></label>
                <input type="tel" id="contact_number" name="contact_number" maxlength="30"
                       value="<?= e(old('contact_number')) ?>" autocomplete="tel">
            </div>
        </div>
    </div>

    <div class="form-section" data-step>
        <h3 tabindex="-1">Your program</h3>
        <div class="form-grid form-grid-3">
            <div class="form-row">
                <label for="course_id">Course <span class="req">*</span></label>
                <select id="course_id" name="course_id" required>
                    <option value="">Select your course</option>
                    <?= course_options($courses, old('course_id')) ?>
                </select>
            </div>
            <div class="form-row">
                <label for="year_level_id">Year level <span class="req">*</span></label>
                <select id="year_level_id" name="year_level_id" required>
                    <option value="">Select year level</option>
                    <?php foreach ($yearLevels as $level): ?>
                        <option value="<?= (int) $level['year_level_id'] ?>"
                            <?= old('year_level_id') === (string) $level['year_level_id'] ? 'selected' : '' ?>>
                            <?= e($level['label']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row">
                <label for="section">Section <span class="optional">(optional)</span></label>
                <input type="text" id="section" name="section" maxlength="20" value="<?= e(old('section')) ?>">
            </div>
        </div>
    </div>
<?php endif; ?>

    <div class="form-section" data-step>
        <h3 tabindex="-1">Password</h3>
        <div class="form-grid form-grid-2">
            <div class="form-row">
                <label for="password">Password <span class="req">*</span></label>
                <div class="password-field">
                    <input type="password" id="password" name="password"
                           autocomplete="new-password" required aria-describedby="password-rules">
                    <?php // Shown by app.js; without JavaScript the field simply stays masked. ?>
                    <button type="button" class="password-toggle" data-password-toggle="password"
                            aria-controls="password" aria-pressed="false" aria-label="Show password" hidden><?= icon('eye', 'icon icon-show') ?><?= icon('eye-off', 'icon icon-hide') ?></button>
                </div>
                <?php $passwordRulesFor = 'password'; require __DIR__ . '/../includes/layout/password-rules.php'; ?>
            </div>
            <div class="form-row">
                <label for="password_confirm">Confirm password <span class="req">*</span></label>
                <div class="password-field">
                    <input type="password" id="password_confirm" name="password_confirm"
                           autocomplete="new-password" required data-password-match="password">
                    <button type="button" class="password-toggle" data-password-toggle="password_confirm"
                            aria-controls="password_confirm" aria-pressed="false" aria-label="Show password" hidden><?= icon('eye', 'icon icon-show') ?><?= icon('eye-off', 'icon icon-hide') ?></button>
                </div>
            </div>
        </div>
    </div>

    <div class="steps-nav" data-steps-nav hidden>
        <button type="button" class="btn btn-outline" data-steps-back>Back</button>
        <button type="button" class="btn btn-primary" data-steps-next>Continue</button>
    </div>

    <button type="submit" class="btn btn-primary btn-block portal-submit" data-loading="Creating account…">Create account</button>
</form>

<div class="portal-secondary">
    <p>Already registered? <a href="<?= url('login.php') ?>">Sign in</a></p>
</div>

<?php require __DIR__ . '/../includes/layout/portal-footer.php'; ?>
<?php clear_old_input(); ?>
