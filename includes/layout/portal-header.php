<?php
/**
 * CASDevU — Sign-in portal layout (sign-in and registration).
 *
 * The frame (campus photo, logo, "Culture. Arts. Sports.") comes from
 * portal_open_html() in includes/portal.php, shared with the full-page status
 * screens. The password-reset pages keep the plain auth layout
 * (auth-header.php).
 *
 * Pages set before including:
 *   $pageTitle   heading and <title>
 *   $authIntro   optional one-line subtitle under the heading
 *   $portalWide  optional; true for a long form (registration)
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
    <link rel="icon" href="<?= asset('img/favicon-32.png') ?>" type="image/png" sizes="32x32">
    <link rel="apple-touch-icon" href="<?= asset('img/apple-touch-icon.png') ?>">
    <link rel="stylesheet" href="<?= asset('css/style.css') ?>">
    <script src="<?= asset('js/app.js') ?>" defer></script>
</head>
<body class="portal-body">
<?= portal_open_html(false, !empty($portalWide)) ?>
            <header class="portal-panel-head">
                <h1><?= e($pageTitle) ?></h1>
                <?php if (!empty($authIntro)): ?>
                    <p><?= e($authIntro) ?></p>
                <?php endif; ?>
            </header>

            <?php // Inside the glass panel a fixed toast would pin to the panel
                  // (backdrop-filter makes it the containing block) and cover the
                  // heading, so messages show in place under the heading instead. ?>
            <?php $toastsInline = true; require __DIR__ . '/toasts.php'; ?>
