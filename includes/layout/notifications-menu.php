<?php
/**
 * CASDevU — Notifications pop-up in the top bar (every signed-in page).
 *
 * A <details> disclosure like the account menu, so it opens without
 * JavaScript; app.js adds Esc and click-outside, and marks everything read
 * when it opens (as visiting the old notifications page did). "Mark all as
 * read" is a plain form, so it works without JavaScript too.
 *
 * Expects from header.php: $user, $unreadCount.
 * ?notifications=open on any page renders it already open; the old
 * notifications.php address redirects there.
 */

declare(strict_types=1);

$recentNotifications = recent_notifications((int) $user['user_id'], 15);
$notificationsOpen   = get('notifications') === 'open';
?>
<details class="notif-menu" data-menu data-notifications<?= $notificationsOpen ? ' open' : '' ?>>
    <summary class="icon-btn notif-trigger"
             aria-label="Notifications<?= $unreadCount > 0 ? ', ' . $unreadCount . ' unread' : '' ?>">
        <?= icon('bell') ?>
        <?php if ($unreadCount > 0): ?>
            <span class="nav-count" aria-hidden="true"><?= $unreadCount > 99 ? '99+' : $unreadCount ?></span>
        <?php endif; ?>
    </summary>

    <div class="notif-panel">
        <div class="notif-head">
            <h2>Notifications</h2>
            <?php if ($unreadCount > 0): ?>
                <form method="post" action="<?= url('notifications.php') ?>" data-notifications-read>
                    <?= csrf_field() ?>
                    <button type="submit" class="notif-mark">Mark all as read</button>
                </form>
            <?php endif; ?>
        </div>

        <?php if ($recentNotifications === []): ?>
            <p class="notif-empty">
                <strong>No notifications yet</strong>
                Registration decisions, document reviews, and deadline reminders will appear here.
            </p>
        <?php else: ?>
            <ul class="notif-list">
                <?php foreach ($recentNotifications as $notification): ?>
                    <?php [$typeIcon, $typeLabel] = notification_type_meta((string) $notification['type']); ?>
                    <li class="notice notif-item<?= $notification['is_read'] ? '' : ' is-new' ?>">
                        <span class="notice-icon notice-<?= e((string) $notification['type']) ?>"><?= icon($typeIcon) ?></span>
                        <div class="notice-body">
                            <p class="notice-type">
                                <?= e($typeLabel) ?>
                                <?php if (!$notification['is_read']): ?>
                                    <span class="badge badge-info">New</span>
                                <?php endif; ?>
                                <span class="notif-time"><?= e(notification_time((string) $notification['created_at'])) ?></span>
                            </p>
                            <h3><?= e($notification['title']) ?></h3>
                            <p class="notif-message"><?= e(strip_reminder_marker((string) $notification['message'])) ?></p>
                            <?php if ($notification['link_url']): ?>
                                <a class="notif-link" href="<?= e($notification['link_url']) ?>">View details</a>
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</details>
