<?php
/**
 * CASMS — Choose a new password from a reset link (FR-1.7)
 *
 * The token is looked up by its SHA-256 hash, must be unused, unexpired, and
 * belong to an active account. It is consumed the moment the password changes.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

/**
 * Resolve a raw token to its account, or null when it is not usable.
 *
 * @return array<string, mixed>|null
 */
function resolve_reset_token(string $rawToken): ?array
{
    if ($rawToken === '' || !ctype_xdigit($rawToken)) {
        return null;
    }

    return fetch_one(
        "SELECT t.token_id, t.user_id, u.email, u.first_name, u.last_name
           FROM auth_tokens t
           JOIN users u ON u.user_id = t.user_id
          WHERE t.token_hash = ?
            AND t.type       = 'password_reset'
            AND t.used_at    IS NULL
            AND t.expires_at > NOW()
            AND u.status     = 'active'",
        [hash('sha256', $rawToken)]
    );
}

// The token arrives in the query string, then rides the form as a hidden field.
$rawToken = is_post() ? post('token') : get('token');
$target   = resolve_reset_token($rawToken);
$errors   = [];
$done     = false;

if (is_post() && $target !== null) {
    csrf_verify();

    $password = (string) ($_POST['password'] ?? '');
    $confirm  = (string) ($_POST['password_confirm'] ?? '');

    if (strlen($password) < 8) {
        $errors[] = 'The new password must be at least 8 characters long.';
    }
    if ($password !== $confirm) {
        $errors[] = 'The two passwords do not match.';
    }

    if ($errors === []) {
        db()->beginTransaction();
        try {
            query(
                'UPDATE users SET password_hash = ? WHERE user_id = ?',
                [password_hash($password, PASSWORD_DEFAULT), $target['user_id']]
            );

            // Consume this token, and retire any other outstanding one.
            query(
                "UPDATE auth_tokens SET used_at = NOW()
                  WHERE user_id = ? AND type = 'password_reset' AND used_at IS NULL",
                [$target['user_id']]
            );

            db()->commit();
        } catch (Throwable $e) {
            db()->rollBack();
            error_log('[CASMS] Password reset failed: ' . $e->getMessage());
            $errors[] = 'The password could not be changed. Please request a new link.';
        }

        if ($errors === []) {
            audit_log('update', 'user', (int) $target['user_id'],
                      'Password reset completed for ' . $target['email']);

            // Tell the account holder, so an unexpected change is noticed.
            send_mail(
                (string) $target['email'],
                'Your CASMS password was changed',
                'Hi ' . $target['first_name'] . ',' . PHP_EOL . PHP_EOL
                . 'The password for your account was changed on '
                . date('d M Y \a\t g:i A') . '.' . PHP_EOL . PHP_EOL
                . 'If this was not you, contact the ' . OFFICE_NAME
                . ' immediately.' . PHP_EOL . PHP_EOL
                . '— ' . OFFICE_NAME . PHP_EOL . UNIVERSITY
            );

            $done = true;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Choose a new password — <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= url('assets/css/style.css') ?>">
</head>
<body>
<div class="auth-wrap">
    <div class="auth-card">
        <div class="auth-head">
            <h1>Choose a new password</h1>
            <p><?= e(OFFICE_NAME) ?> &middot; <?= e(UNIVERSITY) ?></p>
        </div>

        <?php if ($done): ?>
            <div class="alert alert-success">
                Your password has been changed. You can sign in with it now.
            </div>
            <a class="btn btn-primary btn-block" href="<?= url('login.php') ?>">Go to sign in</a>

        <?php elseif ($target === null): ?>
            <div class="alert alert-error">
                This reset link is not valid. It may have expired, been used
                already, or been replaced by a newer one.
            </div>
            <a class="btn btn-primary btn-block" href="<?= url('forgot-password.php') ?>">
                Request a new link
            </a>
            <div class="auth-foot">
                <a href="<?= url('login.php') ?>">Back to sign in</a>
            </div>

        <?php else: ?>
            <?php if ($errors !== []): ?>
                <div class="alert alert-error">
                    <?php foreach ($errors as $error): ?><div><?= e($error) ?></div><?php endforeach; ?>
                </div>
            <?php endif; ?>

            <p style="font-size:.9rem;margin-top:0;">
                Setting a new password for <strong><?= e($target['email']) ?></strong>.
            </p>

            <form method="post" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="token" value="<?= e($rawToken) ?>">

                <div class="form-row">
                    <label for="password">New password <span class="req">*</span></label>
                    <input type="password" id="password" name="password" required autofocus
                           autocomplete="new-password">
                    <div class="hint">At least 8 characters.</div>
                </div>

                <div class="form-row">
                    <label for="password_confirm">Confirm new password <span class="req">*</span></label>
                    <input type="password" id="password_confirm" name="password_confirm" required
                           autocomplete="new-password">
                </div>

                <button type="submit" class="btn btn-primary btn-block">Change password</button>
            </form>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
