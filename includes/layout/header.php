<?php
/**
 * CASMS — Page header and navigation.
 *
 * Pages set $pageTitle before including this file.
 * Navigation is role-aware (FR-1.4) but is presentation only — the real
 * enforcement is require_role() on each page.
 *
 * Layout: a navy sidebar on wide screens; below 960px the same sidebar is a
 * drawer opened from a compact app bar (assets/js/app.js).
 */

declare(strict_types=1);

$user         = current_user();
$role         = current_role();
$unreadCount  = $user ? unread_notification_count((int) $user['user_id']) : 0;
$currentFile  = basename($_SERVER['SCRIPT_NAME']);
$currentDir   = basename(dirname($_SERVER['SCRIPT_NAME']));

/**
 * Work out which sidebar entry owns the page being viewed, so pages deeper
 * in a section (an activity's participants, a requirement review) keep
 * their parent highlighted.
 */
$navSection = match (true) {
    $currentDir === 'public' && $currentFile === 'index.php'  => 'dashboard',
    $currentFile === 'notifications.php'                       => 'notifications',
    $currentFile === 'calendar.php'                            => 'calendar',
    $currentDir === 'activities'                               => 'activities',
    $currentDir === 'announcements'                            => 'announcements',
    $currentDir === 'inventory'                                => 'inventory',
    $currentDir === 'reports'                                  => 'reports',
    $currentDir === 'admin'                                    => pathinfo($currentFile, PATHINFO_FILENAME),
    ($currentDir === 'participation' || $currentDir === 'requirements') && $role === 'student' => 'my-activities',
    $currentDir === 'participation' || $currentDir === 'requirements' => 'activities',
    default                                                    => '',
};

/** Render one sidebar link, marked as the current page when it owns the view. */
function nav_link(string $section, string $href, string $label, string $navSection, int $count = 0): string
{
    $current = $section === $navSection ? ' aria-current="page"' : '';
    $badge   = $count > 0
        ? '<span class="nav-count" aria-label="' . $count . ' unread">' . ($count > 99 ? '99+' : $count) . '</span>'
        : '';
    return '<a class="nav-link" href="' . e(url($href)) . '"' . $current . '><span>' . e($label) . '</span>' . $badge . '</a>';
}

$initials = $user
    ? strtoupper(mb_substr((string) $user['first_name'], 0, 1) . mb_substr((string) $user['last_name'], 0, 1))
    : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#10284d">
    <title><?= isset($pageTitle) ? e($pageTitle) . ' · ' : '' ?><?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= asset('css/style.css') ?>">
    <script src="<?= asset('js/app.js') ?>" defer></script>
</head>
<body>
<a class="skip-link" href="#main">Skip to content</a>

<div class="app">
<?php if ($user): ?>
    <header class="appbar">
        <button type="button" class="icon-btn" data-nav-open aria-controls="sidebar" aria-expanded="false">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
            <span>Menu</span>
        </button>
        <a class="brand" href="<?= url('index.php') ?>"><span class="brand-mark"><?= e(APP_NAME) ?></span></a>
        <a class="icon-btn" href="<?= url('notifications.php') ?>"
           aria-label="Notifications<?= $unreadCount > 0 ? ', ' . $unreadCount . ' unread' : '' ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 16V11a6 6 0 0 1 12 0v5l1.5 2h-15L6 16Z"/><path d="M10 20.5a2 2 0 0 0 4 0"/></svg>
            <?php if ($unreadCount > 0): ?>
                <span class="nav-count" aria-hidden="true"><?= $unreadCount > 99 ? '99+' : $unreadCount ?></span>
            <?php endif; ?>
        </a>
    </header>

    <div class="nav-backdrop" data-nav-close></div>

    <aside class="sidebar" id="sidebar" aria-label="Main navigation">
        <div class="sidebar-brand">
            <a class="brand" href="<?= url('index.php') ?>">
                <span class="brand-mark"><?= e(APP_NAME) ?></span>
                <span class="brand-sub"><?= e(UNIVERSITY) ?></span>
            </a>
            <button type="button" class="icon-btn nav-close" data-nav-close aria-label="Close menu">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>
            </button>
        </div>

        <nav class="sidebar-nav">
            <div class="nav-group">
                <?= nav_link('dashboard', 'index.php', 'Dashboard', $navSection) ?>
                <?php if ($role === 'student'): ?>
                    <?= nav_link('my-activities', 'participation/my-activities.php', 'My activities', $navSection) ?>
                <?php endif; ?>
                <?= nav_link('activities', 'activities/index.php', 'Activities', $navSection) ?>
                <?= nav_link('calendar', 'activities/calendar.php', 'Calendar', $navSection) ?>
                <?= nav_link('announcements', 'announcements/index.php', 'Announcements', $navSection) ?>
                <?php if ($role === 'student'): ?>
                    <?= nav_link('inventory', 'inventory/index.php', 'Costumes & equipment', $navSection) ?>
                <?php endif; ?>
                <?= nav_link('notifications', 'notifications.php', 'Notifications', $navSection, $unreadCount) ?>
            </div>

            <?php if (has_role('staff', 'admin', 'coordinator')): ?>
                <div class="nav-group">
                    <p class="nav-group-title">Operations</p>
                    <?= nav_link('inventory', 'inventory/index.php', 'Inventory', $navSection) ?>
                    <?= nav_link('reports', 'reports/index.php', 'Reports', $navSection) ?>
                </div>
            <?php endif; ?>

            <?php if (has_role('staff', 'admin')): ?>
                <div class="nav-group">
                    <p class="nav-group-title">Administration</p>
                    <?= nav_link('users', 'admin/users.php', has_role('admin') ? 'Users' : 'Accounts', $navSection) ?>
                    <?= nav_link('venues', 'admin/venues.php', 'Venues', $navSection) ?>
                    <?= nav_link('reminders', 'admin/reminders.php', 'Reminders', $navSection) ?>
                    <?php if (has_role('admin')): ?>
                        <?= nav_link('categories', 'admin/categories.php', 'Categories', $navSection) ?>
                        <?= nav_link('audit', 'admin/audit.php', 'Audit log', $navSection) ?>
                        <?= nav_link('backup', 'admin/backup.php', 'Backup', $navSection) ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </nav>

        <div class="sidebar-foot">
            <a class="account" href="<?= url('profile.php') ?>"<?= $currentFile === 'profile.php' ? ' aria-current="page"' : '' ?>>
                <span class="account-initials" aria-hidden="true"><?= e($initials) ?></span>
                <span class="account-text">
                    <span class="account-name"><?= e(full_name($user)) ?></span>
                    <span class="account-role"><?= e(ucfirst((string) $role)) ?></span>
                </span>
            </a>
            <a class="signout" href="<?= url('logout.php') ?>">Sign out</a>
        </div>
    </aside>
<?php endif; ?>

<div class="main">
<main class="page" id="main" tabindex="-1">
<?php foreach (take_flashes() as $flashMessage): ?>
    <div class="alert alert-<?= e($flashMessage['type']) ?>" role="<?= $flashMessage['type'] === 'error' ? 'alert' : 'status' ?>"><?= e($flashMessage['message']) ?></div>
<?php endforeach; ?>
