<?php
/**
 * CASMS — Audit trail viewer (FR-9.3)
 *
 * Administrators only. Entries were being written since Week 1 but there was
 * no way to read them; this closes that half of the requirement.
 */

declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';

require_role(['admin']);

// ------------------------------------------------------------------ Filters
$actionFilter = get('action_type');
$entityFilter = get('entity');
$userFilter   = get('user');
$fromDate     = get('from');
$toDate       = get('to');
$keyword      = get('q');

$conditions = [];
$params     = [];

// Whitelisted against what the log actually contains, so the value can never
// be anything other than one of these strings.
$knownActions = array_column(
    fetch_all('SELECT DISTINCT action FROM audit_logs ORDER BY action'), 'action'
);
$knownEntities = array_column(
    fetch_all('SELECT DISTINCT entity_type FROM audit_logs ORDER BY entity_type'), 'entity_type'
);

if (in_array($actionFilter, $knownActions, true)) {
    $conditions[] = 'a.action = ?';
    $params[]     = $actionFilter;
}
if (in_array($entityFilter, $knownEntities, true)) {
    $conditions[] = 'a.entity_type = ?';
    $params[]     = $entityFilter;
}
if ($userFilter !== '' && ctype_digit($userFilter)) {
    $conditions[] = 'a.user_id = ?';
    $params[]     = (int) $userFilter;
}
if ($fromDate !== '' && strtotime($fromDate) !== false) {
    $conditions[] = 'a.created_at >= ?';
    $params[]     = date('Y-m-d 00:00:00', strtotime($fromDate));
}
if ($toDate !== '' && strtotime($toDate) !== false) {
    $conditions[] = 'a.created_at <= ?';
    $params[]     = date('Y-m-d 23:59:59', strtotime($toDate));
}
if ($keyword !== '') {
    $conditions[] = '(a.description LIKE ? OR a.ip_address LIKE ?)';
    $like         = '%' . $keyword . '%';
    array_push($params, $like, $like);
}

$where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);

$page   = max(1, (int) (get('page') ?: 1));
$total  = (int) fetch_value("SELECT COUNT(*) FROM audit_logs a$where", $params);
$pages  = max(1, (int) ceil($total / PER_PAGE));
$page   = min($page, $pages);
$offset = ($page - 1) * PER_PAGE;

$entries = fetch_all(
    "SELECT a.*, u.first_name, u.last_name, r.name AS role_name
       FROM audit_logs a
       LEFT JOIN users u ON u.user_id = a.user_id
       LEFT JOIN roles r ON r.role_id = u.role_id
       $where
      ORDER BY a.created_at DESC, a.log_id DESC
      LIMIT " . PER_PAGE . " OFFSET $offset",
    $params
);

$actors = fetch_all(
    'SELECT DISTINCT u.user_id, u.first_name, u.last_name
       FROM audit_logs a JOIN users u ON u.user_id = a.user_id
      ORDER BY u.last_name, u.first_name'
);

// Headline figures for the period on screen.
$failedLogins = (int) fetch_value(
    "SELECT COUNT(*) FROM audit_logs
      WHERE action = 'login_failed' AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)"
);
$last24h = (int) fetch_value(
    'SELECT COUNT(*) FROM audit_logs WHERE created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)'
);

/** Preserve the active filters when building a link. */
function audit_link(array $overrides = []): string
{
    $query = array_filter(array_merge([
        'action_type' => get('action_type'), 'entity' => get('entity'),
        'user' => get('user'), 'from' => get('from'),
        'to' => get('to'), 'q' => get('q'),
    ], $overrides), static fn($v): bool => $v !== '' && $v !== null);

    return url('admin/audit.php' . ($query === [] ? '' : '?' . http_build_query($query)));
}

/** Colour an action so destructive ones stand out in a long list. */
function audit_action_badge(string $action): string
{
    $class = match ($action) {
        'create'                  => 'badge-success',
        'update'                  => 'badge-info',
        'delete', 'reject'        => 'badge-danger',
        'approve'                 => 'badge-success',
        'login'                   => 'badge-muted',
        'login_failed'            => 'badge-danger',
        'logout'                  => 'badge-muted',
        'view'                    => 'badge-muted',
        default                   => 'badge-muted',
    };

    return '<span class="badge ' . $class . '">'
         . e(ucwords(str_replace('_', ' ', $action))) . '</span>';
}

$pageTitle = 'Audit trail';
require __DIR__ . '/../../includes/layout/header.php';
?>

<div class="page-head">
    <div>
        <h1>Audit trail</h1>
        <p><?= number_format($total) ?> matching entr<?= $total === 1 ? 'y' : 'ies' ?></p>
    </div>
    <a class="btn btn-outline" href="<?= url('admin/users.php') ?>">User accounts</a>
</div>

<?php if ($failedLogins > 0): ?>
    <div class="alert alert-warning">
        <strong><?= $failedLogins ?></strong> failed sign-in attempt<?= $failedLogins === 1 ? '' : 's' ?>
        in the last 7 days.
        <a href="<?= e(audit_link(['action_type' => 'login_failed'])) ?>">Review them</a>
    </div>
<?php endif; ?>

<div class="grid grid-3" style="margin-bottom:1.5rem;">
    <div class="stat">
        <div class="stat-value"><?= number_format((int) fetch_value('SELECT COUNT(*) FROM audit_logs')) ?></div>
        <div class="stat-label">Entries recorded</div>
    </div>
    <div class="stat">
        <div class="stat-value"><?= number_format($last24h) ?></div>
        <div class="stat-label">In the last 24 hours</div>
    </div>
    <div class="stat <?= $failedLogins > 0 ? 'stat-danger' : '' ?>">
        <div class="stat-value"><?= number_format($failedLogins) ?></div>
        <div class="stat-label">Failed sign-ins, 7 days</div>
    </div>
</div>

<form method="get" class="filter-bar">
    <div class="form-row">
        <label for="q">Search</label>
        <input type="search" id="q" name="q" value="<?= e($keyword) ?>" placeholder="Description or IP">
    </div>
    <div class="form-row">
        <label for="action_type">Action</label>
        <select id="action_type" name="action_type">
            <option value="">All actions</option>
            <?php foreach ($knownActions as $option): ?>
                <option value="<?= e($option) ?>" <?= $actionFilter === $option ? 'selected' : '' ?>>
                    <?= e(ucwords(str_replace('_', ' ', $option))) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-row">
        <label for="entity">Record type</label>
        <select id="entity" name="entity">
            <option value="">All types</option>
            <?php foreach ($knownEntities as $option): ?>
                <option value="<?= e($option) ?>" <?= $entityFilter === $option ? 'selected' : '' ?>>
                    <?= e(ucwords(str_replace('_', ' ', $option))) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-row">
        <label for="user">Performed by</label>
        <select id="user" name="user">
            <option value="">Anyone</option>
            <?php foreach ($actors as $actor): ?>
                <option value="<?= (int) $actor['user_id'] ?>"
                    <?= $userFilter === (string) $actor['user_id'] ? 'selected' : '' ?>>
                    <?= e(full_name($actor, true)) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-row">
        <label for="from">From</label>
        <input type="date" id="from" name="from" value="<?= e($fromDate) ?>">
    </div>
    <div class="form-row">
        <label for="to">To</label>
        <input type="date" id="to" name="to" value="<?= e($toDate) ?>">
    </div>
    <div class="form-row" style="flex:0 0 auto;">
        <button type="submit" class="btn btn-primary">Filter</button>
    </div>
    <?php if ($conditions !== []): ?>
        <div class="form-row" style="flex:0 0 auto;">
            <a class="btn btn-outline" href="<?= url('admin/audit.php') ?>">Clear</a>
        </div>
    <?php endif; ?>
</form>

<?php if ($entries === []): ?>
    <div class="empty">
        <strong>No entries match</strong>
        Try widening the date range or clearing the filters.
    </div>
<?php else: ?>
    <div class="card">
        <div class="table-wrap">
            <table class="data">
                <thead>
                    <tr>
                        <th>When</th><th>Who</th><th>Action</th>
                        <th>Record</th><th>Description</th><th>From</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($entries as $entry): ?>
                    <tr>
                        <td style="white-space:nowrap;">
                            <?= e(format_datetime($entry['created_at'])) ?>
                        </td>
                        <td>
                            <?php if ($entry['first_name']): ?>
                                <strong><?= e(full_name($entry, true)) ?></strong>
                                <div class="hint"><?= e(ucfirst((string) $entry['role_name'])) ?></div>
                            <?php else: ?>
                                <span class="hint">System / signed out</span>
                            <?php endif; ?>
                        </td>
                        <td><?= audit_action_badge((string) $entry['action']) ?></td>
                        <td>
                            <?= e(ucwords(str_replace('_', ' ', (string) $entry['entity_type']))) ?>
                            <?php if ($entry['entity_id'] !== null): ?>
                                <div class="hint">#<?= (int) $entry['entity_id'] ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?= e($entry['description'] ?? '—') ?></td>
                        <td class="hint"><?= e($entry['ip_address'] ?? '—') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($pages > 1): ?>
        <nav class="pagination">
            <?php if ($page > 1): ?>
                <a href="<?= e(audit_link(['page' => $page - 1])) ?>">&laquo; Previous</a>
            <?php endif; ?>
            <?php for ($p = max(1, $page - 2); $p <= min($pages, $page + 2); $p++): ?>
                <?php if ($p === $page): ?>
                    <span class="current"><?= $p ?></span>
                <?php else: ?>
                    <a href="<?= e(audit_link(['page' => $p])) ?>"><?= $p ?></a>
                <?php endif; ?>
            <?php endfor; ?>
            <?php if ($page < $pages): ?>
                <a href="<?= e(audit_link(['page' => $page + 1])) ?>">Next &raquo;</a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/layout/footer.php'; ?>
