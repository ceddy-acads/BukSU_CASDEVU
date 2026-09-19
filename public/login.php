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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign in — <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= url('assets/css/style.css') ?>">
</head>
<body>
<div class="auth-wrap">
    <div class="auth-card">
        <div class="auth-head">
            <h1><?= e(APP_NAME) ?></h1>
            <p><?= e(APP_TAGLINE) ?><br><?= e(UNIVERSITY) ?></p>
        </div>

        <?php foreach (take_flashes() as $flashMessage): ?>
            <div class="alert alert-<?= e($flashMessage['type']) ?>"><?= e($flashMessage['message']) ?></div>
        <?php endforeach; ?>

        <?php if ($errors !== []): ?>
            <div class="alert alert-error">
                <?php foreach ($errors as $error): ?>
                    <div><?= e($error) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="post" novalidate>
            <?= csrf_field() ?>

            <div class="form-row">
                <label for="email">Email address <span class="req">*</span></label>
                <input type="email" id="email" name="email" value="<?= e(old('email')) ?>"
                       autocomplete="username" required autofocus>
            </div>

            <div class="form-row">
                <label for="password">Password <span class="req">*</span></label>
                <input type="password" id="password" name="password"
                       autocomplete="current-password" required>
            </div>

            <button type="submit" class="btn btn-primary btn-block">Sign in</button>
        </form>

        <div class="auth-foot">
            No account yet? <a href="<?= url('register.php') ?>">Register as a student</a>
        </div>
    </div>
</div>
</body>
</html>
<?php clear_old_input(); ?>
