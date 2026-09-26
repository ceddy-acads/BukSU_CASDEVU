<?php
/**
 * CASMS — Sign in (FR-1.1)
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

// Already signed in — nothing to do here.
if (is_logged_in() && current_user() !== null) {
    redirect('index.php');
}

$errors = [];

if (is_post()) {
    csrf_verify();

    $email    = strtolower(post('email'));
    $password = (string) ($_POST['password'] ?? '');

    if ($email === '')    { $errors[] = 'Email address is required.'; }
    if ($password === '') { $errors[] = 'Password is required.'; }

    if ($errors === []) {
        $result = login_user($email, $password);
        if ($result['ok']) {
            flash('success', 'Welcome back.');
            redirect('index.php');
        }
        $errors[] = $result['error'];
    }

    remember_input($_POST);
}

$pageTitle = 'Sign in';
$authIntro = 'Use the email address and password for your ' . APP_NAME . ' account.';

// Tie each message to the field it is about. Only the two empty-field
// messages belong to one field; everything else (wrong credentials, lockout,
// account status) is about the sign-in as a whole and stays in the summary.
$emailError    = in_array('Email address is required.', $errors, true) ? 'Email address is required.' : '';
$passwordError = in_array('Password is required.', $errors, true) ? 'Password is required.' : '';
$formErrors    = array_values(array_diff($errors, [$emailError, $passwordError]));
$badCredentials = in_array('Incorrect email or password.', $formErrors, true);

// aria-describedby for each input: its own message, then the summary.
$emailDescribedBy    = trim(($emailError !== '' ? 'email-error ' : '') . ($formErrors !== [] ? 'login-error' : ''));
$passwordDescribedBy = trim(($passwordError !== '' ? 'password-error ' : '') . ($formErrors !== [] ? 'login-error' : ''));

require __DIR__ . '/../includes/layout/portal-header.php';
?>

<?php if ($formErrors !== []): ?>
    <div class="alert alert-error" id="login-error" role="alert">
        <?php foreach ($formErrors as $error): ?>
            <div><?= e($error) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<form method="post" class="portal-form" novalidate>
    <?= csrf_field() ?>

    <div class="form-row<?= $emailError !== '' ? ' has-error' : '' ?>">
        <label for="email">Email address</label>
        <input type="email" id="email" name="email" value="<?= e(old('email')) ?>"
               autocomplete="username" inputmode="email" spellcheck="false" required autofocus
               <?= $emailError !== '' || $badCredentials ? 'aria-invalid="true"' : '' ?>
               <?= $emailDescribedBy !== '' ? 'aria-describedby="' . $emailDescribedBy . '"' : '' ?>>
        <?php if ($emailError !== ''): ?>
            <p class="field-error" id="email-error"><?= e($emailError) ?></p>
        <?php endif; ?>
    </div>

    <div class="form-row<?= $passwordError !== '' ? ' has-error' : '' ?>">
        <label for="password">Password</label>
        <div class="password-field">
            <input type="password" id="password" name="password"
                   autocomplete="current-password" required
                   <?= $passwordError !== '' || $badCredentials ? 'aria-invalid="true"' : '' ?>
                   <?= $passwordDescribedBy !== '' ? 'aria-describedby="' . $passwordDescribedBy . '"' : '' ?>>
            <?php // Shown by app.js; without JavaScript the field simply stays masked. ?>
            <button type="button" class="password-toggle" data-password-toggle="password"
                    aria-controls="password" aria-pressed="false" hidden>Show</button>
        </div>
        <?php if ($passwordError !== ''): ?>
            <p class="field-error" id="password-error"><?= e($passwordError) ?></p>
        <?php endif; ?>
    </div>

    <button type="submit" class="btn btn-primary btn-block portal-submit" data-loading="Signing in…">Sign in</button>
</form>

<?php // Secondary actions after the main one, so Tab goes email, password, Sign in. ?>
<div class="portal-secondary">
    <a href="<?= url('forgot-password.php') ?>">Forgot password?</a>
    <p>New student? <a href="<?= url('register.php') ?>">Create an account</a> to register for activities.</p>
</div>

<?php require __DIR__ . '/../includes/layout/portal-footer.php'; ?>
<?php clear_old_input(); ?>
