<?php
/**
 * CASDevU — Interface icons.
 *
 * One small hand-drawn set on a 24px grid, 1.75 stroke, round caps and
 * joins, so every icon in the product shares the same weight. Icons are
 * decorative next to a visible label, so they are hidden from screen
 * readers; an icon used alone must get an aria-label on its control.
 */

declare(strict_types=1);

/** Inline SVG for a named icon, or an empty string for an unknown name. */
function icon(string $name, string $class = 'icon'): string
{
    static $paths = [
        // Overview
        'dashboard'     => '<rect x="3.5" y="3.5" width="7" height="8" rx="1.5"/><rect x="13.5" y="3.5" width="7" height="5" rx="1.5"/><rect x="13.5" y="11.5" width="7" height="9" rx="1.5"/><rect x="3.5" y="14.5" width="7" height="6" rx="1.5"/>',
        // A flag on a pole: an event people sign up for
        'activities'    => '<path d="M5 21V4"/><path d="M5 4h11l-2 4 2 4H5"/>',
        'calendar'      => '<rect x="3.5" y="5" width="17" height="15.5" rx="2"/><path d="M3.5 10h17M8 3v4M16 3v4"/>',
        'announcements' => '<path d="M4 10v4a1 1 0 0 0 1 1h2l5 4V5L7 9H5a1 1 0 0 0-1 1Z"/><path d="M16 9a4 4 0 0 1 0 6M18.5 6.5a7.5 7.5 0 0 1 0 11"/>',
        // Clipboard with a tick: the things I signed up for
        'my-activities' => '<rect x="5" y="4.5" width="14" height="16" rx="2"/><path d="M9 4.5V3.5h6v1"/><path d="m9 13 2 2 4-4"/>',
        // Document with a tick: required paperwork
        'requirements'  => '<path d="M14 3.5H7a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8.5Z"/><path d="M14 3.5v5h5"/><path d="m9 14.5 2 2 4-4"/>',
        // Clothes hanger: costumes and equipment
        'equipment'     => '<path d="M12 8.5V8a2 2 0 1 0-2-2"/><path d="M12 8.5 3.5 15a1 1 0 0 0 .6 1.8h15.8a1 1 0 0 0 .6-1.8Z"/>',
        'inventory'     => '<path d="m3.5 7.5 8.5-4 8.5 4v9l-8.5 4-8.5-4Z"/><path d="m3.5 7.5 8.5 4 8.5-4M12 11.5v9"/>',
        // Tray with a tick: things waiting on a decision
        'review'        => '<path d="M3.5 13.5 6 5h12l2.5 8.5v5a2 2 0 0 1-2 2h-13a2 2 0 0 1-2-2Z"/><path d="M3.5 13.5H8l1.5 2.5h5l1.5-2.5h4.5"/><path d="m9.5 9 2 2 3-3"/>',
        'reports'       => '<path d="M4 20.5h16"/><path d="M7 17v-6M12 17V6M17 17v-9"/>',
        'users'         => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 4.6a3.5 3.5 0 0 1 0 6.8M18.5 20a6.5 6.5 0 0 0-2.8-5.3"/>',
        'venues'        => '<path d="M12 21s-7-6.2-7-11.5a7 7 0 0 1 14 0C19 14.8 12 21 12 21Z"/><circle cx="12" cy="9.5" r="2.5"/>',
        'reminders'     => '<circle cx="12" cy="13" r="7.5"/><path d="M12 9v4l2.5 2M4.5 4.5l2.5-2M19.5 4.5 17 2.5"/>',
        'categories'    => '<path d="M3.5 12.2V4.5a1 1 0 0 1 1-1h7.7l8.3 8.3a1.5 1.5 0 0 1 0 2.1l-6.1 6.1a1.5 1.5 0 0 1-2.1 0Z"/><circle cx="8" cy="8" r="1.5"/>',
        'audit'         => '<path d="M12 3 4.5 6v5.5c0 4.5 3.2 8.2 7.5 9.5 4.3-1.3 7.5-5 7.5-9.5V6Z"/><path d="M9 12.5h6M9 9.5h6M9 15.5h3"/>',
        'backup'        => '<ellipse cx="12" cy="6" rx="7.5" ry="2.5"/><path d="M4.5 6v6c0 1.4 3.4 2.5 7.5 2.5s7.5-1.1 7.5-2.5V6"/><path d="M4.5 12v6c0 1.4 3.4 2.5 7.5 2.5s7.5-1.1 7.5-2.5v-6"/>',
        // Shell controls
        'bell'          => '<path d="M6 16V11a6 6 0 0 1 12 0v5l1.5 2h-15Z"/><path d="M10 20.5a2 2 0 0 0 4 0"/>',
        'menu'          => '<path d="M4 7h16M4 12h16M4 17h16"/>',
        'close'         => '<path d="M6 6l12 12M18 6 6 18"/>',
        'chevron-down'  => '<path d="m6 9 6 6 6-6"/>',
        'chevron-right' => '<path d="m9 6 6 6-6 6"/>',
        'user'          => '<circle cx="12" cy="8" r="4"/><path d="M4 20.5a8 8 0 0 1 16 0"/>',
        'sign-out'      => '<path d="M15 4.5h3a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2h-3"/><path d="M10 16.5 5.5 12 10 7.5M5.5 12h10"/>',
        'sort'          => '<path d="m8 10 4-4 4 4M8 14l4 4 4-4"/>',
    ];

    // Sidebar sections that share a drawing.
    $name = ['my-requirements' => 'requirements'][$name] ?? $name;

    if (!isset($paths[$name])) {
        return '';
    }

    return '<svg class="' . htmlspecialchars($class, ENT_QUOTES) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
         . ' stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
         . $paths[$name] . '</svg>';
}
