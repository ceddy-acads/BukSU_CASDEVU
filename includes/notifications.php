<?php
/**
 * CASMS — In-app notifications (FR-3.3, FR-3.4)
 *
 * Week 1 provides the plumbing; Week 2–3 features call notify() when a
 * registration is approved, a requirement is rejected, and so on.
 */

declare(strict_types=1);

/**
 * Send one notification to one user.
 *
 * @param 'activity'|'announcement'|'registration'|'requirement'|'reservation'|'deadline'|'system' $type
 */
function notify(int $userId, string $type, string $title, string $message, ?string $linkUrl = null): void
{
    query(
        'INSERT INTO notifications (user_id, type, title, message, link_url)
         VALUES (?, ?, ?, ?, ?)',
        [$userId, $type, $title, $message, $linkUrl]
    );
}

/**
 * Send the same notification to many users — e.g. announcing a new activity.
 *
 * @param array<int, int> $userIds
 */
function notify_many(array $userIds, string $type, string $title, string $message, ?string $linkUrl = null): void
{
    if ($userIds === []) {
        return;
    }

    $sql    = 'INSERT INTO notifications (user_id, type, title, message, link_url) VALUES ';
    $rows   = [];
    $params = [];
    foreach ($userIds as $userId) {
        $rows[]   = '(?, ?, ?, ?, ?)';
        $params[] = $userId;
        $params[] = $type;
        $params[] = $title;
        $params[] = $message;
        $params[] = $linkUrl;
    }

    query($sql . implode(', ', $rows), $params);
}

/** Every active student — the audience for a new activity announcement. */
function all_active_student_ids(): array
{
    $rows = fetch_all(
        "SELECT u.user_id FROM users u
           JOIN roles r ON r.role_id = u.role_id
          WHERE r.name = 'student' AND u.status = 'active'"
    );
    return array_map(static fn(array $row): int => (int) $row['user_id'], $rows);
}

function unread_notification_count(int $userId): int
{
    return (int) fetch_value(
        'SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0',
        [$userId]
    );
}

/** @return array<int, array<string, mixed>> */
function recent_notifications(int $userId, int $limit = 10): array
{
    // $limit is cast to int, never interpolated from raw input.
    $limit = max(1, min(100, $limit));
    return fetch_all(
        "SELECT * FROM notifications WHERE user_id = ?
          ORDER BY created_at DESC LIMIT $limit",
        [$userId]
    );
}

/**
 * Icon and label for each notification type, so an announcement, a
 * registration decision and a deadline read differently at a glance.
 *
 * @return array{0: string, 1: string} [icon name, label]
 */
function notification_type_meta(string $type): array
{
    return match ($type) {
        'activity'     => ['activities',    'Activity'],
        'announcement' => ['announcements', 'Announcement'],
        'registration' => ['my-activities', 'Registration'],
        'requirement'  => ['requirements',  'Requirement'],
        'reservation'  => ['inventory',     'Reservation'],
        'deadline'     => ['reminders',     'Deadline'],
        'system'       => ['bell',          'Account'],
        default        => ['bell',          'Update'],
    };
}

/** '2:15 PM' for today, '26 Sep, 2:15 PM' this year, '26 Sep 2025' before. */
function notification_time(string $createdAt): string
{
    $time = strtotime($createdAt);
    if (date('Y-m-d', $time) === date('Y-m-d')) {
        return date('g:i A', $time);
    }
    return date('Y', $time) === date('Y') ? date('j M, g:i A', $time) : date('j M Y', $time);
}

function mark_notifications_read(int $userId): void
{
    query(
        'UPDATE notifications SET is_read = 1, read_at = NOW()
          WHERE user_id = ? AND is_read = 0',
        [$userId]
    );
}
