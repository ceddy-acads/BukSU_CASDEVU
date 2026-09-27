<?php
/**
 * CASMS — Monthly activity calendar (FR-2.6)
 *
 * A plain PHP month grid, coloured by category, with a panel beside it
 * listing the details of every activity shown in the grid. Choosing a day
 * number narrows the panel to that day (?day=N). No JavaScript library: the
 * month and day are chosen by query string, so the view works without
 * scripting, can be bookmarked, and prints cleanly.
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

// date('w'): 0 = Sunday. The grid starts on Sunday.
$leadingBlanks = (int) date('w', strtotime($firstDay));

$previous = date('Y-n', strtotime($firstDay . ' -1 month'));
$next     = date('Y-n', strtotime($firstDay . ' +1 month'));
[$prevYear, $prevMonth] = array_map('intval', explode('-', $previous));
[$nextYear, $nextMonth] = array_map('intval', explode('-', $next));

// A chosen day narrows the side panel; anything outside the month is ignored.
$selectedDay = (int) get('day');
if ($selectedDay < 1 || $selectedDay > $daysInMonth) { $selectedDay = 0; }

// ----------------------------------------------------------------- Filters
$categories   = fetch_all('SELECT category_id, name, color_hex FROM activity_categories WHERE is_active = 1 ORDER BY name');
$categoryIn   = get('category');

$conditions = [
    // Any activity overlapping the month, not only those starting in it.
    'a.start_at <= ?',
    'a.end_at   >= ?',
];
$params = [$lastDay . ' 23:59:59', $firstDay . ' 00:00:00'];

// Students never see drafts, matching the activity list.
if (!is_office_staff() && current_role() !== 'coordinator') {
    $conditions[] = "a.status <> 'draft'";
}
if ($categoryIn !== '' && ctype_digit($categoryIn)) {
    $conditions[] = 'a.category_id = ?';
    $params[]     = (int) $categoryIn;
}

$where = ' WHERE ' . implode(' AND ', $conditions);

$activities = fetch_all(
    "SELECT a.activity_id, a.title, a.start_at, a.end_at, a.status,
            c.name AS category, c.color_hex, v.name AS venue
       FROM activities a
       JOIN activity_categories c ON c.category_id = a.category_id
       LEFT JOIN venues v         ON v.venue_id    = a.venue_id
       $where
      ORDER BY a.start_at, a.title",
    $params
);

// Spread each activity across every day it runs, so a three-day event shows
// on all three days rather than only the first.
$byDay = [];
foreach ($activities as $activity) {
    $start = max(strtotime($firstDay), strtotime((string) $activity['start_at']));
    $end   = min(strtotime($lastDay . ' 23:59:59'), strtotime((string) $activity['end_at']));

    for ($ts = strtotime(date('Y-m-d', $start)); $ts <= $end; $ts = strtotime('+1 day', $ts)) {
        $key = (int) date('j', $ts);
        $byDay[$key][] = $activity + [
            'is_first' => date('Y-m-d', $ts) === date('Y-m-d', strtotime((string) $activity['start_at'])),
        ];
    }
}

// The panel lists what the grid shows: the whole month, or the chosen day.
$panelEvents = $selectedDay > 0 ? ($byDay[$selectedDay] ?? []) : $activities;

$today       = date('Y-m-d');
$isThisMonth = date('Y-n') === $year . '-' . $month;
$monthLabel  = date('F Y', strtotime($firstDay));

/** Keep the category filter when moving between months or choosing a day. */
function calendar_link(int $year, int $month, int $day = 0): string
{
    $query = array_filter([
        'year' => $year, 'month' => $month, 'day' => $day ?: null, 'category' => get('category'),
    ], static fn($v): bool => $v !== '' && $v !== null);

    return url('activities/calendar.php?' . http_build_query($query));
}

/**
 * 'Tue, 22 Sep · 8:00 AM – 5:00 PM' ('· 8:00 AM' alone when no end time is
 * set), or '22 Sep, 8:00 AM – 24 Sep, 5:00 PM' across days.
 */
function calendar_event_when(array $activity): string
{
    $start = strtotime((string) $activity['start_at']);
    $end   = strtotime((string) $activity['end_at']);

    if (date('Y-m-d', $start) === date('Y-m-d', $end)) {
        return date('D, j M', $start) . ' · ' . date('g:i A', $start)
            . ($end > $start ? ' – ' . date('g:i A', $end) : '');
    }
    return date('j M, g:i A', $start) . ' – ' . date('j M, g:i A', $end);
}

$pageTitle = 'Calendar: ' . $monthLabel;
require __DIR__ . '/../../includes/layout/header.php';
?>

<div class="page-head">
    <div>
        <h1><?= e($monthLabel) ?></h1>
        <p><?= count($activities) ?> activit<?= count($activities) === 1 ? 'y' : 'ies' ?> this month. Choose a day to see only its events.</p>
    </div>
    <div class="btn-row">
        <nav class="btn-group" aria-label="Change month">
            <a class="btn btn-outline" href="<?= e(calendar_link($prevYear, $prevMonth)) ?>"
               aria-label="Previous month, <?= e(date('F Y', strtotime($previous . '-01'))) ?>"><?= icon('chevron-right', 'icon flip') ?><span><?= e(date('M', strtotime($previous . '-01'))) ?></span></a>
            <a class="btn btn-outline" href="<?= e(calendar_link((int) date('Y'), (int) date('n'))) ?>"<?= $isThisMonth ? ' aria-current="date"' : '' ?>>Today</a>
            <a class="btn btn-outline" href="<?= e(calendar_link($nextYear, $nextMonth)) ?>"
               aria-label="Next month, <?= e(date('F Y', strtotime($next . '-01'))) ?>"><span><?= e(date('M', strtotime($next . '-01'))) ?></span><?= icon('chevron-right') ?></a>
        </nav>
    </div>
</div>

<form method="get" class="filter-bar filter-bar-compact">
    <input type="hidden" name="year"  value="<?= $year ?>">
    <input type="hidden" name="month" value="<?= $month ?>">
    <div class="form-row">
        <label for="category">Category</label>
        <select id="category" name="category" onchange="this.form.submit()">
            <option value="">All categories</option>
            <?php foreach ($categories as $category): ?>
                <option value="<?= (int) $category['category_id'] ?>"
                    <?= $categoryIn === (string) $category['category_id'] ? 'selected' : '' ?>>
                    <?= e($category['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-row filter-actions">
        <button type="submit" class="btn btn-primary">Show</button>
    </div>
</form>

<div class="cal-legend">
    <?php foreach ($categories as $category): ?>
        <span class="cal-legend-item">
            <span class="cal-swatch" style="background: <?= e($category['color_hex'] ?: '#10284d') ?>"></span>
            <?= e($category['name']) ?>
        </span>
    <?php endforeach; ?>
</div>

<div class="cal-layout">
    <section class="card cal-card" aria-label="Calendar for <?= e($monthLabel) ?>">
        <div class="cal-wrap">
            <table class="calendar">
                <thead>
                    <tr>
                        <?php foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $dayName): ?>
                            <th scope="col"><?= $dayName ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                <?php
                $cell = 0;
                $day  = 1;
                $totalCells = $leadingBlanks + $daysInMonth;
                $rows = (int) ceil($totalCells / 7);

                for ($row = 0; $row < $rows; $row++): ?>
                    <tr>
                        <?php for ($column = 0; $column < 7; $column++): ?>
                            <?php
                            $isBlank    = $cell < $leadingBlanks || $day > $daysInMonth;
                            $dateStr    = $isBlank ? null : sprintf('%04d-%02d-%02d', $year, $month, $day);
                            $isToday    = $dateStr === $today;
                            $isSelected = !$isBlank && $day === $selectedDay;
                            $events     = $isBlank ? [] : ($byDay[$day] ?? []);
                            ?>
                            <td class="<?= $isBlank ? 'cal-blank' : '' ?><?= $isToday ? ' cal-today' : '' ?><?= $isSelected ? ' cal-selected' : '' ?>">
                                <?php if (!$isBlank): ?>
                                    <?php if ($events !== []): ?>
                                        <?php // Days with events are links that narrow the panel to that day. ?>
                                        <a class="cal-daynum" href="<?= e(calendar_link($year, $month, $day)) ?>"
                                           aria-label="<?= e(date('l j F', strtotime($dateStr))) ?>, <?= count($events) ?> event<?= count($events) === 1 ? '' : 's' ?>. Show in the panel"
                                           <?= $isSelected ? 'aria-current="date"' : '' ?>><?= $day ?></a>
                                    <?php else: ?>
                                        <span class="cal-daynum"><?= $day ?></span>
                                    <?php endif; ?>

                                    <?php foreach ($events as $event): ?>
                                        <a class="cal-event"
                                           style="--cat: <?= e($event['color_hex'] ?: '#10284d') ?>"
                                           href="<?= url('activities/view.php?id=' . (int) $event['activity_id']) ?>"
                                           title="<?= e($event['title']) ?>, <?= e($event['category']) ?><?= $event['venue'] ? ' at ' . e($event['venue']) : '' ?>">
                                            <?php if ($event['is_first']): ?>
                                                <span class="cal-time"><?= e(date('g:i A', strtotime((string) $event['start_at']))) ?></span>
                                            <?php endif; ?>
                                            <?= e($event['title']) ?>
                                        </a>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </td>
                            <?php
                            if (!$isBlank) { $day++; }
                            $cell++;
                            ?>
                        <?php endfor; ?>
                    </tr>
                <?php endfor; ?>
                </tbody>
            </table>
        </div>
    </section>

    <?php // ---------------------------------------------- Event details panel ?>
    <aside class="card cal-panel" aria-labelledby="cal-panel-title">
        <div class="cal-panel-head">
            <h2 id="cal-panel-title">
                <?= $selectedDay > 0
                    ? e(date('l, j F', strtotime(sprintf('%04d-%02d-%02d', $year, $month, $selectedDay))))
                    : 'Events in ' . e(date('F', strtotime($firstDay))) ?>
            </h2>
            <p>
                <?= count($panelEvents) ?> event<?= count($panelEvents) === 1 ? '' : 's' ?>
                <?php if ($selectedDay > 0): ?>
                    &middot; <a href="<?= e(calendar_link($year, $month)) ?>">Show all of <?= e(date('F', strtotime($firstDay))) ?></a>
                <?php endif; ?>
            </p>
        </div>

        <?php if ($panelEvents === []): ?>
            <div class="cal-panel-empty">
                <strong>Nothing scheduled</strong>
                <?php if ($categoryIn !== ''): ?>
                    No activities in this category this month.
                    <a href="<?= e(url('activities/calendar.php?year=' . $year . '&month=' . $month)) ?>">Show all categories</a>
                <?php else: ?>
                    Use the month buttons to look at another month.
                <?php endif; ?>
            </div>
        <?php else: ?>
            <ul class="agenda cal-agenda">
                <?php foreach ($panelEvents as $event): ?>
                    <?php $start = strtotime((string) $event['start_at']); ?>
                    <li style="--cat: <?= e($event['color_hex'] ?: '#10284d') ?>">
                        <span class="agenda-date" aria-hidden="true">
                            <span class="mon"><?= e(date('M', $start)) ?></span>
                            <span class="day"><?= e(date('j', $start)) ?></span>
                        </span>
                        <div class="agenda-body">
                            <a href="<?= url('activities/view.php?id=' . (int) $event['activity_id']) ?>"><?= e($event['title']) ?></a>
                            <p class="meta"><?= e(calendar_event_when($event)) ?></p>
                            <?php if ($event['venue']): ?>
                                <p class="meta"><?= e($event['venue']) ?></p>
                            <?php endif; ?>
                            <p class="cal-agenda-tags">
                                <span class="cal-agenda-cat"><span class="cal-swatch" aria-hidden="true"></span><?= e($event['category']) ?></span>
                                <?= status_badge($event['status'], 'activity') ?>
                            </p>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </aside>
</div>

<?php require __DIR__ . '/../../includes/layout/footer.php'; ?>
