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

/**
 * URL for a file under public/assets, stamped with its modification time so
 * browsers pick up a changed stylesheet or script instead of a cached copy.
 */
function asset(string $path): string
{
    $file = dirname(__DIR__) . '/public/assets/' . ltrim($path, '/');
    $version = is_file($file) ? '?v=' . filemtime($file) : '';
    return url('assets/' . ltrim($path, '/')) . $version;
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
    // A crafted field[]=x arrives as an array; treat it as absent rather
    // than letting the string conversion raise an error.
    $value = $_POST[$key] ?? $default;
    return is_scalar($value) ? trim((string) $value) : $default;
}

/** Read a trimmed GET field. */
function get(string $key, string $default = ''): string
{
    // A crafted ?key[]=x arrives as an array; treat it as absent rather
    // than letting the string conversion raise an error.
    $value = $_GET[$key] ?? $default;
    return is_scalar($value) ? trim((string) $value) : $default;
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

/**
 * Server-side sort for paginated tables: the requested column, checked
 * against the allowed list, and its direction. Anything else gives the
 * default, so no request value ever reaches SQL.
 *
 * @param  array<int, string> $allowed
 * @return array{0: string, 1: string} [column key, 'asc' | 'desc']
 */
function sort_param(array $allowed, string $default = ''): array
{
    $key = get('sort');
    $key = in_array($key, $allowed, true) ? $key : $default;
    $dir = get('dir') === 'desc' ? 'desc' : 'asc';
    return [$key, $dir];
}

/**
 * A sortable column heading for a server-sorted table. Clicking it sorts by
 * that column A to Z, clicking again Z to A; filters are kept and the list
 * returns to page 1. The arrow and aria-sort match the in-browser sortable
 * tables, so both kinds look and read the same.
 *
 * @param array<string, string> $filters the list's current filter query
 */
function sort_th(string $label, string $key, string $currentKey, string $currentDir, string $path, array $filters, string $class = ''): string
{
    $active = $key === $currentKey;
    $next   = $active && $currentDir === 'asc' ? 'desc' : 'asc';
    $query  = array_filter($filters, static fn ($v): bool => $v !== '' && $v !== null);
    $query['sort'] = $key;
    $query['dir']  = $next;
    $aria   = $active ? ($currentDir === 'asc' ? 'ascending' : 'descending') : 'none';
    $hint   = $active ? ', sorted ' . ($currentDir === 'asc' ? 'A to Z' : 'Z to A') : '';

    return '<th' . ($class !== '' ? ' class="' . e($class) . '"' : '') . ' aria-sort="' . $aria . '">'
         . '<a class="sort-btn" href="' . e(url($path . '?' . http_build_query($query))) . '"'
         . ' aria-label="' . e($label . $hint . '. Sort ' . ($next === 'asc' ? 'A to Z' : 'Z to A')) . '">'
         . e($label)
         . '<svg class="sort-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
         . '<path class="sort-up" d="m8 10 4-4 4 4"/><path class="sort-down" d="m8 14 4 4 4-4"/></svg></a></th>';
}

/**
 * The Month / List switch shared by the calendar and the activity list.
 * Each view is its own page, so the options are real links; the current one
 * carries aria-current. A category filter carries across the switch.
 */
function activity_view_switch(string $active): string
{
    $category = get('category');
    $keep     = $category !== '' && ctype_digit($category) ? '?category=' . $category : '';
    $views    = ['month' => ['Month', 'activities/calendar.php'], 'list' => ['List', 'activities/index.php']];

    $html = '<nav class="segmented" aria-label="Activity view">';
    foreach ($views as $key => [$label, $path]) {
        $html .= '<a class="segmented-option" href="' . e(url($path) . $keep) . '"'
               . ($key === $active ? ' aria-current="page"' : '') . '>' . e($label) . '</a>';
    }
    return $html . '</nav>';
}

/**
 * Stat tile tone: the colour class only when there is something to count.
 * A zero is not an alarm, so it stays neutral.
 */
function stat_tone(int|float|string|null $value, string $class): string
{
    return (float) $value > 0 ? $class : '';
}

/** '05 Oct 2026, 2:00 PM' */
function format_datetime(?string $datetime): string
{
    if (!$datetime) {
        return 'Not set';
    }
    return date('d M Y, g:i A', strtotime($datetime));
}

/** '05 Oct 2026' */
function format_date(?string $datetime): string
{
    if (!$datetime) {
        return 'Not set';
    }
    return date('d M Y', strtotime($datetime));
}

/**
 * Render an activity/registration/item status as a coloured badge.
 * Falls back to a neutral badge for any status not listed.
 */
function status_badge(string $status, string $domain = ''): string
{
    [$tone, $label] = status_meta($status, $domain);
    return '<span class="badge badge-' . $tone . '">' . e($label) . '</span>';
}

/**
 * The one status system. Every stored status maps to a tone and a label,
 * per domain, because the same word means different things: a "pending"
 * registration waits for the office, a "pending" document is under review,
 * a "cancelled" activity is bad news but a cancelled reservation is routine.
 *
 * Tones:  success = done / good to go     (approved, verified, available)
 *         warning = waiting on a decision (pending review, reserved)
 *         danger  = a problem             (rejected, damaged, past due)
 *         info    = in progress / scheduled (upcoming, under review, borrowed)
 *         muted   = inactive or closed    (draft, withdrawn, fulfilled)
 *
 * Tones repeat across statuses, so the label always carries the meaning.
 * Domains mirror the database enums; the pseudo-statuses under 'submission'
 * ('missing', 'past_due') describe a requirement with no upload yet.
 *
 * @return array{0: string, 1: string} [tone, label]
 */
function status_meta(string $status, string $domain = ''): array
{
    static $map = [
        'activity' => [
            'draft'     => ['muted',   'Draft'],
            'upcoming'  => ['info',    'Upcoming'],
            'ongoing'   => ['success', 'Happening now'],
            'completed' => ['success', 'Completed'],
            'cancelled' => ['danger',  'Cancelled'],
            'closed'    => ['muted',   'Registration closed'],
        ],
        'registration' => [
            'pending'   => ['warning', 'Pending review'],
            'approved'  => ['success', 'Approved'],
            'rejected'  => ['danger',  'Rejected'],
            'withdrawn' => ['muted',   'Withdrawn'],
            'completed' => ['success', 'Completed'],
        ],
        'submission' => [
            'missing'   => ['warning', 'Not submitted'],
            'past_due'  => ['danger',  'Past due'],
            'pending'   => ['info',    'Under review'],
            'verified'  => ['success', 'Verified'],
            'rejected'  => ['danger',  'Rejected'],
        ],
        'reservation' => [
            'pending'   => ['warning', 'Pending review'],
            'approved'  => ['success', 'Approved'],
            'rejected'  => ['danger',  'Rejected'],
            'cancelled' => ['muted',   'Cancelled'],
            'fulfilled' => ['muted',   'Fulfilled'],
        ],
        'inventory' => [
            'available'         => ['success', 'Available'],
            'reserved'          => ['warning', 'Reserved'],
            'borrowed'          => ['info',    'Borrowed'],
            'damaged'           => ['danger',  'Damaged'],
            'under_maintenance' => ['muted',   'Under maintenance'],
            'unavailable'       => ['muted',   'Unavailable'],
        ],
        'account' => [
            'pending'   => ['warning', 'Awaiting approval'],
            'active'    => ['success', 'Active'],
            'inactive'  => ['muted',   'Inactive'],
            'suspended' => ['danger',  'Suspended'],
        ],
        'announcement' => [
            'draft'     => ['muted',   'Draft'],
            'published' => ['success', 'Published'],
            'archived'  => ['muted',   'Archived'],
        ],
    ];

    if (isset($map[$domain][$status])) {
        return $map[$domain][$status];
    }
    // No domain given: first domain that knows the word.
    foreach ($map as $statuses) {
        if (isset($statuses[$status])) {
            return $statuses[$status];
        }
    }
    return ['muted', ucwords(str_replace('_', ' ', $status))];
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
