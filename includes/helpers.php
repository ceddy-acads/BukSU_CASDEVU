<?php
/**
 * CASMS — Shared helpers: output escaping, URLs, flash messages, formatting.
 */

declare(strict_types=1);

// =====================================================================
// Output escaping (NFR-2)
// =====================================================================

/**
 * Escape a value for safe HTML output. Every echoed variable goes through this.
 */
function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Escape and echo in one call — for use inside templates. */
function out(?string $value): void
{
    echo e($value);
}

// =====================================================================
// URLs and redirects
// =====================================================================

/** Build an application URL from a path relative to the web root. */
function url(string $path = ''): string
{
    return BASE_URL . '/' . ltrim($path, '/');
}

/** Redirect and stop. Always followed by exit, never by more output. */
function redirect(string $path): never
{
    header('Location: ' . url($path));
    exit;
}

/** True when the current request is a form submission. */
function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/** Read a trimmed POST field. */
function post(string $key, string $default = ''): string
{
    return trim((string) ($_POST[$key] ?? $default));
}

/** Read a trimmed GET field. */
function get(string $key, string $default = ''): string
{
    return trim((string) ($_GET[$key] ?? $default));
}

/** Read a positive integer from GET, or null when absent/invalid. */
function get_id(string $key): ?int
{
    $value = filter_input(INPUT_GET, $key, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);
    return $value === false || $value === null ? null : $value;
}

// =====================================================================
// Flash messages — one-request notices surviving a redirect
// =====================================================================

/** @param 'success'|'error'|'info'|'warning' $type */
function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

/**
 * Return all pending flash messages and clear them.
 *
 * @return array<int, array{type: string, message: string}>
 */
function take_flashes(): array
{
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flashes;
}

// =====================================================================
// Form value retention — keeps input on screen after a validation failure
// =====================================================================

/** @param array<string, mixed> $values */
function remember_input(array $values): void
{
    unset($values['password'], $values['password_confirm'], $values['csrf_token']);
    $_SESSION['old_input'] = $values;
}

/** Recall a previously submitted value for re-display. */
function old(string $key, string $default = ''): string
{
    return (string) ($_SESSION['old_input'][$key] ?? $default);
}

function clear_old_input(): void
{
    unset($_SESSION['old_input']);
}

// =====================================================================
// Formatting
// =====================================================================

/** '05 Oct 2026, 2:00 PM' */
function format_datetime(?string $datetime): string
{
    if (!$datetime) {
        return '—';
    }
    return date('d M Y, g:i A', strtotime($datetime));
}

/** '05 Oct 2026' */
function format_date(?string $datetime): string
{
    if (!$datetime) {
        return '—';
    }
    return date('d M Y', strtotime($datetime));
}

/**
 * Render an activity/registration/item status as a coloured badge.
 * Falls back to a neutral badge for any status not listed.
 */
function status_badge(string $status): string
{
    $classes = [
        // Activities
        'draft'     => 'badge-muted',   'upcoming'  => 'badge-info',
        'ongoing'   => 'badge-success', 'completed' => 'badge-muted',
        'cancelled' => 'badge-danger',  'closed'    => 'badge-warning',
        // Registrations and submissions
        'pending'   => 'badge-warning', 'approved'  => 'badge-success',
        'rejected'  => 'badge-danger',  'withdrawn' => 'badge-muted',
        'verified'  => 'badge-success',
        // Accounts
        'active'    => 'badge-success', 'inactive'  => 'badge-muted',
        'suspended' => 'badge-danger',
    ];

    $class = $classes[$status] ?? 'badge-muted';
    $label = ucwords(str_replace('_', ' ', $status));

    return '<span class="badge ' . $class . '">' . e($label) . '</span>';
}

/**
 * Build a URL-safe slug, guaranteed unique within a table column.
 */
function make_slug(string $title, string $table = 'activities', string $column = 'slug'): string
{
    $base = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $title) ?? '', '-'));
    $base = $base !== '' ? $base : 'item';

    // Table and column are developer-supplied constants, never user input.
    $slug    = $base;
    $counter = 1;
    while (fetch_value("SELECT 1 FROM `$table` WHERE `$column` = ?", [$slug])) {
        $slug = $base . '-' . (++$counter);
    }

    return $slug;
}

/** Full name for display: 'Dela Cruz, Juan' or 'Juan Dela Cruz'. */
function full_name(array $user, bool $lastNameFirst = false): string
{
    return $lastNameFirst
        ? $user['last_name'] . ', ' . $user['first_name']
        : $user['first_name'] . ' ' . $user['last_name'];
}
