<?php
/**
 * CASDevU — The sign-in portal frame: campus photo, BukSU logo, name,
 * "Culture. Arts. Sports." and the column that holds the panel.
 *
 * Shared by the login page (layout/portal-header.php, portal-footer.php) and
 * the full-page status screens (errors.php: expired form, access denied, not
 * found, unexpected error). Self-contained, like errors.php: it needs only
 * the config constants and BASE_URL, so a status page can use it even when a
 * failure happens before the helpers are loaded.
 *
 * The campus photo is public/assets/img/buksu-campus.jpg and the logo is
 * public/assets/img/buksu-logo-white.png, a white version of the university's
 * supplied mark. Either one is simply left out when its file is missing.
 */

declare(strict_types=1);

function portal_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** URL of a file under public/assets, stamped with its version, or null if it is missing. */
function portal_asset_url(string $path): ?string
{
    $file = dirname(__DIR__) . '/public/assets/' . $path;
    if (!is_file($file)) {
        return null;
    }
    return (defined('BASE_URL') ? BASE_URL : '') . '/assets/' . $path . '?v=' . filemtime($file);
}

/**
 * Opens the portal up to the inside of the panel; the caller fills the panel
 * and then closes with portal_close_html(). $centred is for status screens:
 * the card sits in the middle of the page under the logo (see .portal-status).
 * $wide is for long forms such as registration: the card gets more of the
 * width (see .portal-wide).
 */
function portal_open_html(bool $centred = false, bool $wide = false): string
{
    $photo      = portal_asset_url('img/buksu-campus.jpg');
    $logo       = portal_asset_url('img/buksu-logo-white.png');
    $appName    = defined('APP_NAME') ? APP_NAME : 'CASDevU';
    $university = defined('UNIVERSITY') ? UNIVERSITY : 'Bukidnon State University';

    $classes = 'portal' . ($centred ? ' portal-status' : '') . ($wide ? ' portal-wide' : '') . ($photo ? ' has-photo' : '');
    $open    = '<div class="' . $classes . '"'
             . ($photo ? ' style="--portal-photo: url(\'' . portal_escape($photo) . '\')"' : '') . '>';

    // The university name is written beside the logo, so the logo is decorative.
    $logoHtml = $logo
        ? '<img class="portal-logo" src="' . portal_escape($logo) . '" alt="" width="126" height="128">'
        : '';

    return $open . '
    <header class="portal-brand">
        <div class="portal-brand-inner">
            <p class="portal-mark">
                ' . $logoHtml . '
                <span class="portal-mark-text">
                    <span class="brand-mark">' . portal_escape($appName) . '</span>
                    <span class="portal-mark-sub">' . portal_escape($university) . '</span>
                </span>
            </p>
            <p class="portal-headline">
                <span>Culture.</span>
                <span>Arts.</span>
                <span>Sports.</span>
            </p>
            <p class="portal-lede">
                Register for activities, submit requirements, and keep up with announcements.
                Office staff and coordinators run activities, inventory, and reports from here.
            </p>
        </div>
    </header>

    <main class="portal-main" id="main">
        <div class="portal-panel">
';
}

/** Closes the panel, adds the office line, and closes the portal. */
function portal_close_html(): string
{
    $office     = defined('OFFICE_NAME') ? OFFICE_NAME : 'Culture, Arts, and Sports Development Unit';
    $university = defined('UNIVERSITY') ? UNIVERSITY : 'Bukidnon State University';

    return '
        </div>
        <p class="portal-legal">' . portal_escape($office) . ', ' . portal_escape($university) . '</p>
    </main>
</div>
';
}
