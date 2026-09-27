<?php
/**
 * CASDevU — Sign-in portal layout (login page only).
 *
 * The frame (campus photo, logo, "Culture. Arts. Sports.") comes from
 * portal_open_html() in includes/portal.php, shared with the full-page status
 * screens. Registration and the password-reset pages keep the plain auth
 * layout (auth-header.php).
 *
 * Pages set before including:
 *   $pageTitle   heading and <title>
 *   $authIntro   optional one-line subtitle under the heading
 */

declare(strict_types=1);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#10284d">
    <title><?= e($pageTitle) ?> · <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= asset('css/style.css') ?>">
    <script src="<?= asset('js/app.js') ?>" defer></script>
</head>
<body class="portal-body">
<?= portal_open_html() ?>
            <header class="portal-panel-head">
                <h1><?= e($pageTitle) ?></h1>
                <?php if (!empty($authIntro)): ?>
                    <p><?= e($authIntro) ?></p>
                <?php endif; ?>
            </header>

            <?php foreach (take_flashes() as $flashMessage): ?>
                <div class="alert alert-<?= e($flashMessage['type']) ?>" role="<?= $flashMessage['type'] === 'error' ? 'alert' : 'status' ?>"><?= e($flashMessage['message']) ?></div>
            <?php endforeach; ?>
