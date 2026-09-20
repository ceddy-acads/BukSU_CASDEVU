<?php
/**
 * CASMS — Page header and navigation.
 *
 * Pages set $pageTitle before including this file.
 * Navigation is role-aware (FR-1.4) but is presentation only — the real
 * enforcement is require_role() on each page.
 */

declare(strict_types=1);

$user         = current_user();
$role         = current_role();
$unreadCount  = $user ? unread_notification_count((int) $user['user_id']) : 0;
$currentFile  = basename($_SERVER['SCRIPT_NAME']);
$currentDir   = basename(dirname($_SERVER['SCRIPT_NAME']));

/** Mark a nav item active when its section is the one being viewed. */
function nav_active(string $section, string $currentDir, string $currentFile): string
{
    $isActive = $section === $currentDir
        || ($section === 'dashboard' && $currentFile === 'index.php' && $currentDir === 'public');
    return $isActive ? ' class="active"' : '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($pageTitle) ? e($pageTitle) . ' — ' : '' ?><?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= url('assets/css/style.css') ?>">
</head>
<body>

<header class="topbar">
    <div class="topbar-inner">
        <a class="brand" href="<?= url('index.php') ?>">
            <span class="brand-mark"><?= e(APP_NAME) ?></span>
            <span class="brand-sub"><?= e(UNIVERSITY) ?></span>
        </a>

        <?php if ($user): ?>
            <nav class="topnav">
                <a href="<?= url('index.php') ?>"<?= nav_active('dashboard', $currentDir, $currentFile) ?>>Dashboard</a>
                <a href="<?= url('activities/index.php') ?>"<?= nav_active('activities', $currentDir, $currentFile) ?>>Activities</a>
                <a href="<?= url('announcements/index.php') ?>"<?= nav_active('announcements', $currentDir, $currentFile) ?>>Announcements</a>

                <?php if ($role === 'student'): ?>
                    <a href="<?= url('participation/my-activities.php') ?>"<?= nav_active('participation', $currentDir, $currentFile) ?>>My Activities</a>
                <?php endif; ?>

                <?php if (has_role('staff', 'admin', 'coordinator')): ?>
                    <a href="<?= url('inventory/index.php') ?>"<?= nav_active('inventory', $currentDir, $currentFile) ?>>Inventory</a>
                <?php endif; ?>

                <?php if (has_role('staff', 'admin')): ?>
                    <a href="<?= url('admin/users.php') ?>"<?= $currentFile === 'users.php' ? ' class="active"' : '' ?>>
                        <?= has_role('admin') ? 'Users' : 'Accounts' ?>
                    </a>
                    <a href="<?= url('admin/venues.php') ?>"<?= $currentFile === 'venues.php' ? ' class="active"' : '' ?>>Venues</a>
                <?php endif; ?>
            </nav>

            <div class="topbar-right">
                <a class="bell" href="<?= url('notifications.php') ?>" title="Notifications">
                    &#9993;
                    <?php if ($unreadCount > 0): ?>
                        <span class="bell-count"><?= $unreadCount > 99 ? '99+' : $unreadCount ?></span>
                    <?php endif; ?>
                </a>

                <div class="user-chip">
                    <a href="<?= url('profile.php') ?>">
                        <strong><?= e(full_name($user)) ?></strong>
                        <small><?= e(ucfirst((string) $role)) ?></small>
                    </a>
                </div>

                <a class="btn btn-ghost btn-sm" href="<?= url('logout.php') ?>">Sign out</a>
            </div>
        <?php endif; ?>
    </div>
</header>

<main class="page">
<?php foreach (take_flashes() as $flashMessage): ?>
    <div class="alert alert-<?= e($flashMessage['type']) ?>"><?= e($flashMessage['message']) ?></div>
<?php endforeach; ?>
