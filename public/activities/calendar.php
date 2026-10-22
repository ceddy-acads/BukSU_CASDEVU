<?php
/**
 * CASMS — Monthly activity calendar (FR-2.6)
 *
 * A month grid beside an agenda. The agenda opens on today (or the chosen
 * day) and runs forward through the month, grouped by day, so "what is
 * next" is answered first; earlier days fold away below. On a phone the
 * grid becomes a compact month of tappable days with category dots and the
 * agenda is the main content. Categories are filters as well as a key.
 *
 * Status is always written, never only coloured: cancelled activities stay
 * in place, struck through and labelled; drafts (visible to the office and
 * coordinators only) are outlined and labelled; a student sees their own
 * registration status.
 *
 * No JavaScript library: month, day, and category travel in the query
 * string, so every view works without scripting, can be bookmarked, and
 * prints cleanly.
 */

declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';

require_login();

// ------------------------------------------------------------------ Month
// Validated to a real year/month, so a crafted value cannot reach strtotime
// as something unexpected.
$year  = (int) (get('year')  ?: date('Y'));
$month = (int) (get('month') ?: date('n'));

if ($year < 2000 || $year > 2100) { $year  = (int) date('Y'); }
if ($month < 1   || $month > 12)  { $month = (int) date('n'); }

$firstDay    = sprintf('%04d-%02d-01', $year, $month);
$daysInMonth = (int) date('t', strtotime($firstDay));
$lastDay     = sprintf('%04d-%02d-%02d', $year, $month, $daysInMonth);
$monthName   = date('F', strtotime($firstDay));
$monthLabel  = date('F Y', strtotime($firstDay));

$previous = date('Y-n', strtotime($firstDay . ' -1 month'));
$next     = date('Y-n', strtotime($firstDay . ' +1 month'));
[$prevYear, $prevMonth] = array_map('intval', explode('-', $previous));
[$nextYear, $nextMonth] = array_map('intval', explode('-', $next));

$today       = date('Y-m-d');
$isThisMonth = date('Y-n') === $year . '-' . $month;

// The agenda opens on the chosen day; in the current month, on today.
$selectedDay = (int) get('day');
if ($selectedDay < 1 || $selectedDay > $daysInMonth) {
    $selectedDay = $isThisMonth ? (int) date('j') : 0;
}

// ----------------------------------------------------------------- Filters
$categories = fetch_all('SELECT category_id, name, color_hex FROM activity_categories WHERE is_active = 1 ORDER BY name');
$categoryIn = get('category');
$categoryIn = $categoryIn !== '' && ctype_digit($categoryIn) ? $categoryIn : '';

$isStudent = current_role() === 'student';

$conditions = [];
$params     = [];
// Students never see drafts, matching the activity list.
if (!is_office_staff() && current_role() !== 'coordinator') {
    $conditions[] = "a.status <> 'draft'";
}
if ($categoryIn !== '') {
    $conditions[] = 'a.category_id = ?';
    $params[]     = (int) $categoryIn;
}

// A student also sees where they stand on each activity.
$mineJoin   = $isStudent ? 'LEFT JOIN registrations mr ON mr.activity_id = a.activity_id AND mr.user_id = ?' : '';
$mineSelect = $isStudent ? 'mr.status AS my_status' : 'NULL AS my_status';
$mineParams = $isStudent ? [(int) current_user_id()] : [];

$activities = fetch_all(
    "SELECT a.activity_id, a.title, a.start_at, a.end_at, a.status,
            c.name AS category, c.color_hex, v.name AS venue, $mineSelect
       FROM activities a
       JOIN activity_categories c ON c.category_id = a.category_id
       LEFT JOIN venues v         ON v.venue_id    = a.venue_id
       $mineJoin
      WHERE a.start_at <= ? AND a.end_at >= ?"
       . ($conditions === [] ? '' : ' AND ' . implode(' AND ', $conditions)) . "
      ORDER BY a.start_at, a.title",
    array_merge($mineParams, [$lastDay . ' 23:59:59', $firstDay . ' 00:00:00'], $params)
);

// An empty month points at the next activity rather than a dead end.
$nextActivity = null;
if ($activities === []) {
    $nextActivity = fetch_one(
        'SELECT a.activity_id, a.title, a.start_at FROM activities a
          WHERE a.start_at > ?'
          . ($conditions === [] ? '' : ' AND ' . implode(' AND ', $conditions)) . '
          ORDER BY a.start_at LIMIT 1',
        array_merge([$lastDay . ' 23:59:59'], $params)
    );
}

// Spread each activity across every day it runs, so a three-day event shows
// on all three days, labelled "Day 2 of 3" after the first.
$byDay = [];
foreach ($activities as $activity) {
    $startDate = strtotime(date('Y-m-d', strtotime((string) $activity['start_at'])));
    $endDate   = strtotime(date('Y-m-d', strtotime((string) $activity['end_at'])));
    $spanDays  = (int) round(($endDate - $startDate) / 86400) + 1;

    $from = max(strtotime($firstDay), $startDate);
    $to   = min(strtotime($lastDay), $endDate);
    for ($ts = $from; $ts <= $to; $ts = strtotime('+1 day', $ts)) {
        $byDay[(int) date('j', $ts)][] = $activity + [
            'day_number' => (int) round(($ts - $startDate) / 86400) + 1,
            'span_days'  => $spanDays,
        ];
    }
}

// ------------------------------------------------------------------- Grid
// Whole weeks from Sunday to Saturday, with the neighbouring months' days
// shown faintly instead of as blank boxes.
$leading = (int) date('w', strtotime($firstDay));
$cells   = [];
for ($i = $leading; $i > 0; $i--) {
    $cells[] = ['date' => date('Y-m-d', strtotime($firstDay . " -$i day")), 'in_month' => false];
}
for ($d = 1; $d <= $daysInMonth; $d++) {
    $cells[] = ['date' => sprintf('%04d-%02d-%02d', $year, $month, $d), 'in_month' => true];
}
for ($i = 1; count($cells) % 7 !== 0; $i++) {
    $cells[] = ['date' => date('Y-m-d', strtotime($lastDay . " +$i day")), 'in_month' => false];
}
$weeks = array_chunk($cells, 7);

/** Chips shown in a grid cell before the rest fold into "+N more". */
const CALENDAR_CHIPS_PER_DAY = 3;

// ------------------------------------------------------------------ Agenda
// The chosen day first (even when empty), then the rest of the month ahead,
// then the days before it, folded away.
$focusDate  = $selectedDay > 0 ? sprintf('%04d-%02d-%02d', $year, $month, $selectedDay) : null;
$laterDays  = [];
$earlierDays = [];
foreach ($byDay as $d => $events) {
    if ($selectedDay === 0 || $d > $selectedDay) {
        $laterDays[$d] = $events;
    } elseif ($d < $selectedDay) {
        $earlierDays[$d] = $events;
    }
}
ksort($laterDays);
ksort($earlierDays);

/** Keep the category filter when moving between months or choosing a day. */
function calendar_link(int $year, int $month, int $day = 0, ?string $category = null): string
{
    $query = array_filter([
        'year' => $year, 'month' => $month, 'day' => $day ?: null,
        'category' => $category ?? get('category'),
    ], static fn($v): bool => $v !== '' && $v !== null);

    return url('activities/calendar.php?' . http_build_query($query));
}

/** '8:00 AM – 5:00 PM', '8:00 AM', or 'Day 2 of 3' on a later day of a long activity. */
function calendar_when(array $event): string
{
    if ($event['span_days'] > 1) {
        return 'Day ' . $event['day_number'] . ' of ' . $event['span_days'];
    }
    $start = strtotime((string) $event['start_at']);
    $end   = strtotime((string) $event['end_at']);
    return date('g:i A', $start) . ($end > $start ? ' – ' . date('g:i A', $end) : '');
}

/** The written status a chip or agenda row must carry, or '' for an ordinary activity. */
function calendar_status_word(array $event): string
{
    return match ($event['status']) {
        'cancelled' => 'Cancelled',
        'draft'     => 'Draft',
        default     => '',
    };
}

/** One agenda row: time, title, venue, category in words, and status. */
function calendar_agenda_item(array $event): string
{
    $status = (string) $event['status'];
    $mine   = (string) ($event['my_status'] ?? '');
    $html   = '<li class="cal-item' . ($status === 'cancelled' ? ' is-cancelled' : '') . ($status === 'draft' ? ' is-draft' : '')
            . '" style="--cat: ' . e($event['color_hex'] ?: 'var(--navy)') . '">'
            . '<span class="cal-item-time">' . e(calendar_when($event)) . '</span>'
            . '<div class="cal-item-body">'
            . '<a class="cal-item-title" href="' . e(url('activities/view.php?id=' . (int) $event['activity_id'])) . '">'
            . e($event['title']) . '</a>'
            . ($event['venue'] ? '<p class="cal-item-meta">' . e($event['venue']) . '</p>' : '')
            . '<p class="cal-item-tags"><span class="cal-cat"><span class="cal-dot" aria-hidden="true"></span>'
            . e($event['category']) . '</span>';
    if (!in_array($status, ['upcoming'], true)) {
        $html .= status_badge($status, 'activity');
    }
    if ($mine !== '' && $mine !== 'withdrawn') {
        $html .= '<span class="cal-mine">You: ' . e(status_meta($mine, 'registration')[1]) . '</span>';
    }
    return $html . '</p></div></li>';
}

$pageTitle = 'Calendar: ' . $monthLabel;
require __DIR__ . '/../../includes/layout/header.php';
?>

<div class="page-head photo-band"<?= photo_band_style('cheerdance') ?>>
    <div>
        <h1><?= e($monthLabel) ?></h1>
        <p><?= count($activities) ?> activit<?= count($activities) === 1 ? 'y' : 'ies' ?> this month<?= $categoryIn !== '' ? ' in this category' : '' ?></p>
    </div>
    <nav class="cal-stepper" aria-label="Change month">
        <a class="cal-step" href="<?= e(calendar_link($prevYear, $prevMonth)) ?>"
           aria-label="Previous month, <?= e(date('F Y', strtotime($previous . '-01'))) ?>"><?= icon('chevron-right', 'icon flip') ?></a>
        <a class="cal-step cal-step-today" href="<?= e(calendar_link((int) date('Y'), (int) date('n'))) ?>"<?= $isThisMonth ? ' aria-current="date"' : '' ?>>Today</a>
        <a class="cal-step" href="<?= e(calendar_link($nextYear, $nextMonth)) ?>"
           aria-label="Next month, <?= e(date('F Y', strtotime($next . '-01'))) ?>"><?= icon('chevron-right') ?></a>
    </nav>
</div>

<?php // The key is the filter: each category is a link, the active one filled. ?>
<nav class="cal-filter" aria-label="Show one category">
    <a class="cal-filter-chip" href="<?= e(calendar_link($year, $month, 0, '')) ?>"<?= $categoryIn === '' ? ' aria-current="true"' : '' ?>>All categories</a>
    <?php foreach ($categories as $category): ?>
        <?php $isActive = $categoryIn === (string) $category['category_id']; ?>
        <a class="cal-filter-chip" style="--cat: <?= e($category['color_hex'] ?: 'var(--navy)') ?>"
           href="<?= e(calendar_link($year, $month, 0, $isActive ? '' : (string) $category['category_id'])) ?>"<?= $isActive ? ' aria-current="true"' : '' ?>>
            <span class="cal-dot" aria-hidden="true"></span><?= e($category['name']) ?>
        </a>
    <?php endforeach; ?>
</nav>

<div class="cal-layout">
    <section class="card cal-card" aria-label="<?= e($monthLabel) ?>">
        <table class="calendar">
            <thead>
                <tr>
                    <?php foreach (['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'] as $dayName): ?>
                        <th scope="col"><abbr title="<?= $dayName ?>"><?= substr($dayName, 0, 3) ?></abbr></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($weeks as $week): ?>
                <tr>
                    <?php foreach ($week as $index => $cell): ?>
                        <?php
                        $ts         = strtotime($cell['date']);
                        $dayNumber  = (int) date('j', $ts);
                        $events     = $cell['in_month'] ? ($byDay[$dayNumber] ?? []) : [];
                        $isToday    = $cell['date'] === $today;
                        $isSelected = $cell['in_month'] && $dayNumber === $selectedDay;
                        $classes    = array_filter([
                            $cell['in_month'] ? '' : 'cal-outside',
                            $index === 0 || $index === 6 ? 'cal-weekend' : '',
                            $cell['in_month'] && $cell['date'] < $today ? 'cal-past' : '',
                            $isToday ? 'cal-today' : '',
                            $isSelected ? 'cal-selected' : '',
                        ]);
                        ?>
                        <td<?= $classes !== [] ? ' class="' . implode(' ', $classes) . '"' : '' ?>>
                            <?php if (!$cell['in_month']): ?>
                                <span class="cal-daynum" aria-hidden="true"><?= $dayNumber ?></span>
                            <?php else: ?>
                                <div class="cal-day-head">
                                    <?php // Every day can be chosen; the agenda then opens on it. ?>
                                    <a class="cal-daynum" href="<?= e(calendar_link($year, $month, $dayNumber)) ?>"
                                       aria-label="<?= e(date('l j F', $ts)) ?><?= $isToday ? ', today' : '' ?>, <?= count($events) === 0 ? 'no activities' : count($events) . ' activit' . (count($events) === 1 ? 'y' : 'ies') ?>"
                                       <?= $isSelected ? 'aria-current="date"' : '' ?>><?= $dayNumber ?></a>
                                    <?php if ($isToday): ?><span class="cal-today-word" aria-hidden="true">Today</span><?php endif; ?>
                                </div>

                                <?php if ($events !== []): ?>
                                    <?php // Phone: up to three category dots stand in for the chips. ?>
                                    <span class="cal-dots" aria-hidden="true">
                                        <?php foreach (array_slice($events, 0, 3) as $event): ?>
                                            <span class="cal-dot" style="--cat: <?= e($event['color_hex'] ?: 'var(--navy)') ?>"></span>
                                        <?php endforeach; ?>
                                    </span>

                                    <?php foreach (array_slice($events, 0, CALENDAR_CHIPS_PER_DAY) as $event): ?>
                                        <?php $word = calendar_status_word($event); ?>
                                        <a class="cal-chip<?= $event['status'] === 'cancelled' ? ' is-cancelled' : '' ?><?= $event['status'] === 'draft' ? ' is-draft' : '' ?>"
                                           style="--cat: <?= e($event['color_hex'] ?: 'var(--navy)') ?>"
                                           href="<?= url('activities/view.php?id=' . (int) $event['activity_id']) ?>"
                                           aria-label="<?= e($word !== '' ? $word . ': ' : '') ?><?= e($event['title']) ?>, <?= e($event['category']) ?>, <?= e(date('j F', $ts)) ?>, <?= e(calendar_when($event)) ?><?= $event['venue'] ? ', ' . e($event['venue']) : '' ?>">
                                            <span class="cal-chip-meta"><?= e($word !== '' ? $word : calendar_when($event)) ?></span>
                                            <span class="cal-chip-title"><?= e($event['title']) ?></span>
                                        </a>
                                    <?php endforeach; ?>

                                    <?php if (count($events) > CALENDAR_CHIPS_PER_DAY): ?>
                                        <a class="cal-more" href="<?= e(calendar_link($year, $month, $dayNumber)) ?>">
                                            +<?= count($events) - CALENDAR_CHIPS_PER_DAY ?> more
                                        </a>
                                    <?php endif; ?>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <?php // ----------------------------------------------------------- Agenda ?>
    <aside class="card cal-panel" aria-labelledby="cal-panel-title">
        <?php if ($focusDate !== null): ?>
            <?php $focusEvents = $byDay[$selectedDay] ?? []; ?>
            <h2 id="cal-panel-title" class="cal-panel-title">
                <?= $focusDate === $today ? 'Today' : e(date('l', strtotime($focusDate))) ?>
                <span><?= e(date($focusDate === $today ? 'l, j F' : 'j F', strtotime($focusDate))) ?></span>
            </h2>
            <?php if ($focusEvents === []): ?>
                <p class="cal-panel-note">Nothing scheduled on this day.</p>
            <?php else: ?>
                <ul class="cal-items"><?php foreach ($focusEvents as $event) { echo calendar_agenda_item($event); } ?></ul>
            <?php endif; ?>
        <?php else: ?>
            <h2 id="cal-panel-title" class="cal-panel-title"><?= e($monthName) ?> <span><?= count($activities) ?> activit<?= count($activities) === 1 ? 'y' : 'ies' ?></span></h2>
        <?php endif; ?>

        <?php if ($activities === []): ?>
            <div class="cal-panel-empty">
                <strong>No activities in <?= e($monthName) ?><?= $categoryIn !== '' ? ' in this category' : '' ?>.</strong>
                <?php if ($nextActivity !== null): ?>
                    Next: <a href="<?= url('activities/view.php?id=' . (int) $nextActivity['activity_id']) ?>"><?= e($nextActivity['title']) ?></a>,
                    <?= e(date('D j M', strtotime((string) $nextActivity['start_at']))) ?>.
                <?php endif; ?>
                <?php if ($categoryIn !== ''): ?>
                    <a href="<?= e(calendar_link($year, $month, 0, '')) ?>">Show all categories</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($laterDays !== []): ?>
            <?php if ($focusDate !== null): ?>
                <h3 class="cal-section-title">Later in <?= e($monthName) ?></h3>
            <?php endif; ?>
            <?php foreach ($laterDays as $d => $events): ?>
                <?php $date = sprintf('%04d-%02d-%02d', $year, $month, $d); ?>
                <section class="cal-day-group" aria-label="<?= e(date('l j F', strtotime($date))) ?>">
                    <h4 class="cal-day-title"><a href="<?= e(calendar_link($year, $month, $d)) ?>"><?= e(date('D j M', strtotime($date))) ?></a></h4>
                    <ul class="cal-items"><?php foreach ($events as $event) { echo calendar_agenda_item($event); } ?></ul>
                </section>
            <?php endforeach; ?>
        <?php elseif ($focusDate !== null && $activities !== []): ?>
            <p class="cal-panel-note">Nothing else later in <?= e($monthName) ?>.</p>
        <?php endif; ?>

        <?php if ($earlierDays !== []): ?>
            <details class="cal-earlier">
                <summary>Earlier in <?= e($monthName) ?> (<?= array_sum(array_map('count', $earlierDays)) ?>)</summary>
                <?php foreach ($earlierDays as $d => $events): ?>
                    <?php $date = sprintf('%04d-%02d-%02d', $year, $month, $d); ?>
                    <section class="cal-day-group" aria-label="<?= e(date('l j F', strtotime($date))) ?>">
                        <h4 class="cal-day-title"><a href="<?= e(calendar_link($year, $month, $d)) ?>"><?= e(date('D j M', strtotime($date))) ?></a></h4>
                        <ul class="cal-items"><?php foreach ($events as $event) { echo calendar_agenda_item($event); } ?></ul>
                    </section>
                <?php endforeach; ?>
            </details>
        <?php endif; ?>
    </aside>
</div>

<?php require __DIR__ . '/../../includes/layout/footer.php'; ?>
