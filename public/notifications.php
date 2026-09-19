<?php
/**
 * CASMS — Notification inbox (FR-3.3, FR-3.4)
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

require_login();

$userId = (int) current_user_id();

// Read the list before marking it read, so unread items can still be styled.
$notifications = fetch_all(
    'SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 50',
    [$userId]
);

mark_notifications_read($userId);

$pageTitle = 'Notifications';
require __DIR__ . '/../includes/layout/header.php';
?>

<div class="page-head">
    <div>
        <h1>Notifications</h1>
        <p>Updates about activities, registrations, and requirements.</p>
    </div>
</div>

<?php if ($notifications === []): ?>
    <div class="empty">
        <strong>Nothing here yet</strong>
        You will be notified about new activities, approvals, and deadlines.
    </div>
<?php else: ?>
    <div class="card">
        <?php foreach ($notifications as $notification): ?>
            <article style="padding:.85rem 0;border-bottom:1px solid var(--line);
                            <?= $notification['is_read'] ? '' : 'border-left:3px solid var(--gold);padding-left:.75rem;' ?>">
                <div style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
                    <strong><?= e($notification['title']) ?></strong>
                    <span class="hint"><?= e(format_datetime($notification['created_at'])) ?></span>
                </div>
                <p style="margin:.25rem 0 0;font-size:.9rem;"><?= e($notification['message']) ?></p>
                <?php if ($notification['link_url']): ?>
                    <a class="btn btn-outline btn-sm" style="margin-top:.5rem;"
                       href="<?= e($notification['link_url']) ?>">Open</a>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/layout/footer.php'; ?>
