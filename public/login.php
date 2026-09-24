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
$authIntro = 'Sign in with your university email address.';
require __DIR__ . '/../includes/layout/auth-header.php';
?>

<?php if ($errors !== []): ?>
    <div class="alert alert-error" role="alert">
        <?php foreach ($errors as $error): ?>
            <div><?= e($error) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<form method="post" novalidate>
    <?= csrf_field() ?>

    <div class="form-row">
        <label for="email">Email address</label>
        <input type="email" id="email" name="email" value="<?= e(old('email')) ?>"
               autocomplete="username" placeholder="name@student.buksu.edu.ph" required autofocus>
    </div>

    <div class="form-row">
        <label for="password">Password</label>
        <input type="password" id="password" name="password"
               autocomplete="current-password" required>
        <p class="hint"><a href="<?= url('forgot-password.php') ?>">Forgot your password?</a></p>
    </div>

    <button type="submit" class="btn btn-primary btn-block">Sign in</button>
</form>

<div class="auth-foot">
    <p>New student? <a href="<?= url('register.php') ?>">Create a student account</a></p>
</div>

<?php require __DIR__ . '/../includes/layout/auth-footer.php'; ?>
<?php clear_old_input(); ?>
