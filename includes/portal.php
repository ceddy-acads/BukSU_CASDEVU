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
 * The photo is a BukSU event, public/assets/img/event-cheerdance.webp
 * (Intramurals 2023, a placeholder until the office supplies its own), and the logo is
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
 * BukSU event photos the sign-in and create-account screens fade between,
 * in order, each with the focus point that keeps its people in view.
 * Placeholders until the office supplies its own (public/assets/img).
 */
const PORTAL_PHOTOS = [
    ['img/event-cheerdance.webp', 'center 55%'],   // Intramurals 2023 cheer dance
    ['img/event-stage.webp',      'center 68%'],   // Folkloric Dance Troupe on stage
    ['img/event-stadium.webp',    'center 52%'],   // SCUAA 2023 team at the stadium
    ['img/buksu-campus.jpg',      'center 50%'],   // the campus the work is for
];

/**
 * The stacked photos behind the brand field. Only the first loads with the
 * page; app.js loads the rest afterwards and fades between them. Without
 * JavaScript the first photo simply stays.
 *
 * @param array<int, array{0: string, 1: string}> $slides URL and focus point
 */
function portal_slides_html(array $slides): string
{
    $html = '<div class="portal-slides" aria-hidden="true" data-portal-slides>';
    foreach ($slides as $index => [$url, $focus]) {
        $html .= $index === 0
            ? '<span class="portal-slide is-active" style="--slide-photo: url(\'' . portal_escape($url) . '\'); --slide-focus: ' . portal_escape($focus) . '"></span>'
            : '<span class="portal-slide" style="--slide-focus: ' . portal_escape($focus) . '" data-photo="' . portal_escape($url) . '"></span>';
    }
    return $html . '</div>';
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
    // Sign-in and create account fade between event photos; a status screen
    // keeps one still photo, so nothing moves behind an error message.
    $slides = [];
    if (!$centred) {
        foreach (PORTAL_PHOTOS as [$path, $focus]) {
            $url = portal_asset_url($path);
            if ($url !== null) {
                $slides[] = [$url, $focus];
            }
        }
    }
    $photo      = $slides === [] ? portal_asset_url(PORTAL_PHOTOS[0][0]) : null;
    $logo       = portal_asset_url('img/buksu-logo-white.png');
    $appName    = defined('APP_NAME') ? APP_NAME : 'CASDevU';
    $university = defined('UNIVERSITY') ? UNIVERSITY : 'Bukidnon State University';

    $classes = 'portal' . ($centred ? ' portal-status' : '') . ($wide ? ' portal-wide' : '')
             . ($photo ? ' has-photo' : '') . ($slides !== [] ? ' has-slides' : '');
    $open    = '<div class="' . $classes . '"'
             . ($photo ? ' style="--portal-photo: url(\'' . portal_escape($photo) . '\')"' : '') . '>';

    // The university name is written beside the logo, so the logo is decorative.
    $logoHtml = $logo
        ? '<img class="portal-logo" src="' . portal_escape($logo) . '" alt="" width="126" height="128">'
        : '';

    return $open . '
    <header class="portal-brand">
        ' . ($slides !== [] ? portal_slides_html($slides) : '') . '
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
        <p class="portal-legal">' . portal_escape($office) . ', ' . portal_escape($university) . ' <button type="button" class="portal-slides-toggle" data-portal-slides-toggle aria-pressed="false" hidden>Pause background photos</button></p>
    </main>
</div>
';
}
