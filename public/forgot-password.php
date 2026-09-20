<?php
/**
 * CASMS — Request a password reset (FR-1.7)
 *
 * The response never reveals whether an address is registered: a stranger
 * must not be able to use this page to discover who holds an account.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

if (is_logged_in() && current_user() !== null) {
    redirect('profile.php');
}

$errors = [];
$sent   = false;

if (is_post()) {
    csrf_verify();

    $email = strtolower(post('email'));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }

    if ($errors === []) {
        $user = fetch_one(
            "SELECT user_id, email, first_name, status FROM users WHERE email = ?",
            [$email]
        );

        // Only an active account gets a link, but the page says the same
        // thing either way.
        if ($user !== null && $user['status'] === 'active') {
            // Cap requests per account so this cannot be used to flood an inbox.
            $recent = (int) fetch_value(
                "SELECT COUNT(*) FROM auth_tokens
                  WHERE user_id = ? AND type = 'password_reset'
                    AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)",
                [$user['user_id']]
            );

            if ($recent < PASSWORD_RESET_MAX_PER_HOUR) {
                // Any earlier link for this account stops working now.
                query(
                    "UPDATE auth_tokens SET used_at = NOW()
                      WHERE user_id = ? AND type = 'password_reset' AND used_at IS NULL",
                    [$user['user_id']]
                );

                // The raw token goes in the email; only its hash is stored, so
                // a leaked database row cannot be replayed as a reset link.
                $rawToken = bin2hex(random_bytes(32));

                query(
                    "INSERT INTO auth_tokens (user_id, type, token_hash, expires_at)
                     VALUES (?, 'password_reset', ?, DATE_ADD(NOW(), INTERVAL ? MINUTE))",
                    [$user['user_id'], hash('sha256', $rawToken), PASSWORD_RESET_TTL_MINUTES]
                );

                $link = absolute_url(url('reset-password.php?token=' . $rawToken));

                send_mail(
                    (string) $user['email'],
                    'Reset your CASMS password',
                    'Hi ' . $user['first_name'] . ',' . PHP_EOL . PHP_EOL
                    . 'Someone asked to reset the password for your account at the '
                    . OFFICE_NAME . '.' . PHP_EOL . PHP_EOL
                    . 'Open this link to choose a new password:' . PHP_EOL . PHP_EOL
                    . '  ' . $link . PHP_EOL . PHP_EOL
                    . 'The link works once and expires in '
                    . PASSWORD_RESET_TTL_MINUTES . ' minutes.' . PHP_EOL . PHP_EOL
                    . 'If this was not you, ignore this message — your password '
                    . 'stays as it is.' . PHP_EOL . PHP_EOL
                    . '— ' . OFFICE_NAME . PHP_EOL . UNIVERSITY
                );

                audit_log('request', 'password_reset', (int) $user['user_id'],
                          'Password reset requested for ' . $email);
            } else {
                audit_log('request', 'password_reset', (int) $user['user_id'],
                          'Password reset throttled for ' . $email);
            }
        } else {
            // Logged so the office can spot probing, but the page stays silent.
            audit_log('request', 'password_reset', null,
                      'Password reset requested for unknown or inactive address: ' . $email);
        }

        $sent = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset your password — <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= url('assets/css/style.css') ?>">
</head>
<body>
<div class="auth-wrap">
    <div class="auth-card">
        <div class="auth-head">
            <h1>Reset your password</h1>
            <p><?= e(OFFICE_NAME) ?> &middot; <?= e(UNIVERSITY) ?></p>
        </div>

        <?php if ($errors !== []): ?>
            <div class="alert alert-error">
                <?php foreach ($errors as $error): ?><div><?= e($error) ?></div><?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($sent): ?>
            <div class="alert alert-success">
                If that email address belongs to an active account, a reset link
                is on its way. It works once and expires in
                <?= PASSWORD_RESET_TTL_MINUTES ?> minutes.
            </div>
            <p class="hint">
                Nothing arrived? Check that you typed the address you registered
                with, or contact the office.
            </p>
            <a class="btn btn-primary btn-block" href="<?= url('login.php') ?>">Back to sign in</a>
        <?php else: ?>
            <p style="font-size:.9rem;margin-top:0;">
                Enter the email address you registered with and we will send you
                a link to choose a new password.
            </p>

            <form method="post" novalidate>
                <?= csrf_field() ?>
                <div class="form-row">
                    <label for="email">Email address <span class="req">*</span></label>
                    <input type="email" id="email" name="email" required autofocus
                           value="<?= e(post('email')) ?>" autocomplete="username">
                </div>
                <button type="submit" class="btn btn-primary btn-block">Send reset link</button>
            </form>

            <div class="auth-foot">
                Remembered it? <a href="<?= url('login.php') ?>">Sign in</a>
            </div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
