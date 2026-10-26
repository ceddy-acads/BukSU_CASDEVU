<?php
/**
 * CASMS — Request a password reset (FR-1.7)
 *
 * The account holder gets a 6-digit PIN by email (no link) and types it on
 * reset-password.php. The response never reveals whether an address is
 * registered: a stranger must not be able to use this page to discover who
 * holds an account.
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

        // Only an active account gets a PIN, but the page says the same
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
                // Any earlier PIN for this account stops working now.
                query(
                    "UPDATE auth_tokens SET used_at = NOW()
                      WHERE user_id = ? AND type = 'password_reset' AND used_at IS NULL",
                    [$user['user_id']]
                );

                // The PIN goes in the email; only a salted hash is stored, so a
                // leaked database row does not give the PIN away.
                $pin = str_pad((string) random_int(0, 999999), 6, "0", STR_PAD_LEFT);

                query(
                    "INSERT INTO auth_tokens (user_id, type, token_hash, expires_at)
                     VALUES (?, 'password_reset', ?, DATE_ADD(NOW(), INTERVAL ? MINUTE))",
                    [$user['user_id'], password_hash($pin, PASSWORD_DEFAULT), PASSWORD_RESET_TTL_MINUTES]
                );

                send_mail(
                    (string) $user['email'],
                    'Reset your ' . APP_NAME . ' password',
                    'Hi ' . $user['first_name'] . ',' . PHP_EOL . PHP_EOL
                    . 'Someone asked to reset the password for your ' . APP_NAME . ' account at the '
                    . office_name() . ', ' . university_name() . '.' . PHP_EOL . PHP_EOL
                    . 'Your PIN to reset your password:' . PHP_EOL . PHP_EOL
                    . '  ' . $pin . PHP_EOL . PHP_EOL
                    . 'Type it on the Reset password page, where you asked for it. It works once '
                    . 'and expires in ' . PASSWORD_RESET_TTL_MINUTES . ' minutes. Do not share it: '
                    . 'the office will never ask for it.' . PHP_EOL . PHP_EOL
                    . 'If this was not you, ignore this message. Your password '
                    . 'stays as it is.' . PHP_EOL . PHP_EOL
                    . office_name() . PHP_EOL . university_name()
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

        // Straight on to the PIN form, with the address filled in. Set for any
        // address, so the next page gives nothing away either.
        if (email_delivers()) {
            $_SESSION['reset_email'] = $email;
            redirect('reset-password.php');
        }
    }
}
$pageTitle = 'Reset your password';
require __DIR__ . '/../includes/layout/auth-header.php';
?>

<?php if ($errors !== []): ?>
    <div class="alert alert-error" role="alert">
        <?php foreach ($errors as $error): ?><div><?= e($error) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($sent && !email_delivers()): ?>
    <?php // Email is not set up: say so, rather than promise a message that never comes. ?>
    <div class="alert alert-info" role="status">
        Email is not switched on for this system yet, so a reset PIN cannot
        reach your inbox.
    </div>
    <p class="muted">
        Ask the office<?= e(office_contact_hint()) ?> to reset your password.
        They will give you a temporary one to sign in with, which you can then
        change under My profile.
    </p>
<?php else: ?>
    <p class="muted">
        Enter the email address you registered with and we will send you
        a 6-digit PIN to choose a new password.
    </p>

    <form method="post" novalidate>
        <?= csrf_field() ?>
        <div class="form-row">
            <label for="email">Email address</label>
            <input type="email" id="email" name="email" required autofocus
                   value="<?= e(post('email')) ?>" autocomplete="username">
        </div>
        <button type="submit" class="btn btn-primary btn-block">Send PIN</button>
    </form>

    <div class="auth-foot">
        <p>Already have a PIN? <a href="<?= url('reset-password.php') ?>">Enter it</a></p>
        <p>Remembered it? <a href="<?= url('login.php') ?>">Sign in</a></p>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/layout/auth-footer.php'; ?>
