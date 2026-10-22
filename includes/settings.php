<?php
/**
 * CASMS — Office settings and personal preferences (FR-1.6)
 *
 * Two key/value stores with one shape:
 *   settings          the office's value, the same for everyone
 *   user_preferences  one person's override
 *
 * A preference that has no row means "the default", so a new switch needs no
 * backfill: the code that reads it names the default.
 */

declare(strict_types=1);

/** One office-wide setting, or $default when the row is missing or empty. */
function office_setting(string $key, ?string $default = null): ?string
{
    // Cached for the request: a page that saves a setting redirects straight
    // after, so a stale read is never rendered.
    static $values = [];

    if (!array_key_exists($key, $values)) {
        $values[$key] = fetch_value('SELECT setting_value FROM settings WHERE setting_key = ?', [$key]);
    }

    $value = $values[$key];

    return $value === null || trim((string) $value) === '' ? $default : (string) $value;
}

/**
 * Write office settings. The caller validates; this stores.
 *
 * @param array<string, string> $settings
 */
function save_office_settings(array $settings): void
{
    foreach ($settings as $key => $value) {
        query(
            'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
            [$key, (string) $value]
        );
    }
}

/**
 * Every stored preference for one person.
 *
 * @param  bool $refresh  re-read after this request wrote a preference
 * @return array<string, string>
 */
function user_preferences(int $userId, bool $refresh = false): array
{
    static $cache = [];

    if ($refresh || !isset($cache[$userId])) {
        $cache[$userId] = [];
        $rows = fetch_all('SELECT pref_key, pref_value FROM user_preferences WHERE user_id = ?', [$userId]);
        foreach ($rows as $row) {
            $cache[$userId][(string) $row['pref_key']] = (string) $row['pref_value'];
        }
    }

    return $cache[$userId];
}

/** One preference, or $default when the person has not set it. */
function user_preference(int $userId, string $key, ?string $default = null): ?string
{
    return user_preferences($userId)[$key] ?? $default;
}

/** A switch: true only when the person turned it on (or the default is on). */
function user_preference_on(int $userId, string $key, bool $default = false): bool
{
    return user_preference($userId, $key, $default ? '1' : '0') === '1';
}

/**
 * Store preferences for one person. A null value deletes the row, which puts
 * the switch back to its default.
 *
 * @param array<string, string|null> $values
 */
function save_user_preferences(int $userId, array $values): void
{
    foreach ($values as $key => $value) {
        if ($value === null) {
            query('DELETE FROM user_preferences WHERE user_id = ? AND pref_key = ?', [$userId, (string) $key]);
            continue;
        }

        query(
            'INSERT INTO user_preferences (user_id, pref_key, pref_value) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE pref_value = VALUES(pref_value)',
            [$userId, (string) $key, (string) $value]
        );
    }

    user_preferences($userId, true);
}

/** The office's name as printed on every message it sends. */
function office_name(): string
{
    return (string) office_setting('office_name', OFFICE_NAME);
}

function university_name(): string
{
    return (string) office_setting('university_name', UNIVERSITY);
}

/** Upload ceiling in bytes: the setting, clamped, with the constant as fallback. */
function max_upload_bytes(): int
{
    $megabytes = (int) office_setting('max_upload_size_mb', '5');

    return max(1, min(50, $megabytes)) * 1024 * 1024;
}
