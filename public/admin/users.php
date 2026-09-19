<?php
/**
 * CASMS — User management and account activation (FR-1.3, FR-9.1)
 *
 * Staff may activate and deactivate accounts; only admin may change a role.
 */

declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';

require_role(['staff', 'admin']);

$isAdmin = has_role('admin');

// --------------------------------------------------------------- Actions
if (is_post()) {
    csrf_verify();

    $action       = post('action');
    $targetUserId = (int) post('user_id');

    $target = fetch_one('SELECT user_id, first_name, last_name, status FROM users WHERE user_id = ?', [$targetUserId]);
    if ($target === null) {
        flash('error', 'That user account no longer exists.');
        redirect('admin/users.php');
    }

    // Guard against an administrator locking themselves out.
    if ($targetUserId === current_user_id() && $action !== 'role') {
        flash('error', 'You cannot change the status of your own account.');
        redirect('admin/users.php');
    }

    switch ($action) {
        case 'activate':
            query("UPDATE users SET status = 'active' WHERE user_id = ?", [$targetUserId]);
            audit_log('approve', 'user', $targetUserId, 'Activated account');
            notify($targetUserId, 'system', 'Your account is now active',
                   'You can now sign in and register for activities.', url('login.php'));
            flash('success', full_name($target) . ' can now sign in.');
            break;

        case 'deactivate':
            query("UPDATE users SET status = 'inactive' WHERE user_id = ?", [$targetUserId]);
            audit_log('update', 'user', $targetUserId, 'Deactivated account');
            flash('success', full_name($target) . ' has been deactivated.');
            break;

        case 'suspend':
            query("UPDATE users SET status = 'suspended' WHERE user_id = ?", [$targetUserId]);
            audit_log('update', 'user', $targetUserId, 'Suspended account');
            flash('success', full_name($target) . ' has been suspended.');
            break;

        case 'role':
            if (!$isAdmin) {
                http_response_code(403);
                exit('403 — Only an administrator may change a user role.');
            }
            if ($targetUserId === current_user_id()) {
                flash('error', 'You cannot change your own role.');
                redirect('admin/users.php');
            }
            $newRoleId = (int) post('role_id');
            if (!fetch_value('SELECT 1 FROM roles WHERE role_id = ?', [$newRoleId])) {
                flash('error', 'That role does not exist.');
                redirect('admin/users.php');
            }
            query('UPDATE users SET role_id = ? WHERE user_id = ?', [$newRoleId, $targetUserId]);
            audit_log('update', 'user', $targetUserId, 'Changed role');
            flash('success', 'Role updated for ' . full_name($target) . '.');
            break;

        default:
            flash('error', 'Unknown action.');
    }

    redirect('admin/users.php?' . http_build_query(array_filter([
        'status' => get('status'),
        'role'   => get('role'),
        'q'      => get('q'),
    ])));
}

// --------------------------------------------------------------- Filters
$statusFilter = get('status');
$roleFilter   = get('role');
$keyword      = get('q');

$conditions = [];
$params     = [];

if (in_array($statusFilter, ['pending', 'active', 'inactive', 'suspended'], true)) {
    $conditions[] = 'u.status = ?';
    $params[]     = $statusFilter;
}
if ($roleFilter !== '' && ctype_digit($roleFilter)) {
    $conditions[] = 'u.role_id = ?';
    $params[]     = (int) $roleFilter;
}
if ($keyword !== '') {
    $conditions[] = '(u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ? OR u.student_number LIKE ?)';
    $like         = '%' . $keyword . '%';
    array_push($params, $like, $like, $like, $like);
}

$where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);

$page   = max(1, (int) (get('page') ?: 1));
$total  = (int) fetch_value("SELECT COUNT(*) FROM users u$where", $params);
$pages  = max(1, (int) ceil($total / PER_PAGE));
$page   = min($page, $pages);
$offset = ($page - 1) * PER_PAGE;

$users = fetch_all(
    "SELECT u.*, r.name AS role_name, c.code AS course_code, y.label AS year_level_label
       FROM users u
       JOIN roles r            ON r.role_id       = u.role_id
       LEFT JOIN courses c     ON c.course_id     = u.course_id
       LEFT JOIN year_levels y ON y.year_level_id = u.year_level_id
       $where
      ORDER BY FIELD(u.status, 'pending') DESC, u.last_name, u.first_name
      LIMIT " . PER_PAGE . " OFFSET $offset",
    $params
);

$roles        = fetch_all('SELECT role_id, name FROM roles ORDER BY role_id');
$pendingCount = (int) fetch_value("SELECT COUNT(*) FROM users WHERE status = 'pending'");

$pageTitle = 'User accounts';
require __DIR__ . '/../../includes/layout/header.php';
?>

<div class="page-head">
    <div>
        <h1>User accounts</h1>
        <p><?= $total ?> account<?= $total === 1 ? '' : 's' ?></p>
    </div>
</div>

<?php if ($pendingCount > 0 && $statusFilter !== 'pending'): ?>
    <div class="alert alert-warning">
        <strong><?= $pendingCount ?></strong> account<?= $pendingCount === 1 ? '' : 's' ?> awaiting approval.
        <a href="<?= url('admin/users.php?status=pending') ?>">Show only pending</a>
    </div>
<?php endif; ?>

<form method="get" class="filter-bar">
    <div class="form-row">
        <label for="q">Search</label>
        <input type="search" id="q" name="q" value="<?= e($keyword) ?>" placeholder="Name, email, student number">
    </div>
    <div class="form-row">
        <label for="status">Status</label>
        <select id="status" name="status">
            <option value="">All statuses</option>
            <?php foreach (['pending', 'active', 'inactive', 'suspended'] as $statusOption): ?>
                <option value="<?= e($statusOption) ?>" <?= $statusFilter === $statusOption ? 'selected' : '' ?>>
                    <?= e(ucfirst($statusOption)) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-row">
        <label for="role">Role</label>
        <select id="role" name="role">
            <option value="">All roles</option>
            <?php foreach ($roles as $role): ?>
                <option value="<?= (int) $role['role_id'] ?>"
                    <?= $roleFilter === (string) $role['role_id'] ? 'selected' : '' ?>>
                    <?= e(ucfirst($role['name'])) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-row" style="flex:0 0 auto;">
        <button type="submit" class="btn btn-primary">Filter</button>
    </div>
</form>

<?php if ($users === []): ?>
    <div class="empty"><strong>No accounts match</strong> Try different filters.</div>
<?php else: ?>
    <div class="card">
        <div class="table-wrap">
            <table class="data">
                <thead>
                    <tr>
                        <th>Name</th><th>Email</th><th>Student no.</th>
                        <th>Course / Year</th><th>Role</th><th>Status</th><th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($users as $row): ?>
                    <tr>
                        <td><strong><?= e(full_name($row, true)) ?></strong></td>
                        <td><?= e($row['email']) ?></td>
                        <td><?= e($row['student_number'] ?? '—') ?></td>
                        <td>
                            <?= e($row['course_code'] ?? '—') ?>
                            <?= $row['year_level_label'] ? '<div class="hint">' . e($row['year_level_label']) . '</div>' : '' ?>
                        </td>
                        <td>
                            <?php if ($isAdmin && (int) $row['user_id'] !== current_user_id()): ?>
                                <form method="post" style="display:inline;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="role">
                                    <input type="hidden" name="user_id" value="<?= (int) $row['user_id'] ?>">
                                    <select name="role_id" onchange="this.form.submit()" style="font-size:.8rem;padding:.2rem;">
                                        <?php foreach ($roles as $role): ?>
                                            <option value="<?= (int) $role['role_id'] ?>"
                                                <?= (int) $row['role_id'] === (int) $role['role_id'] ? 'selected' : '' ?>>
                                                <?= e(ucfirst($role['name'])) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </form>
                            <?php else: ?>
                                <?= e(ucfirst($row['role_name'])) ?>
                            <?php endif; ?>
                        </td>
                        <td><?= status_badge($row['status']) ?></td>
                        <td class="actions">
                            <?php if ((int) $row['user_id'] === current_user_id()): ?>
                                <span class="hint">You</span>
                            <?php else: ?>
                                <div class="btn-row">
                                    <?php if ($row['status'] !== 'active'): ?>
                                        <form method="post" style="display:inline;">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="activate">
                                            <input type="hidden" name="user_id" value="<?= (int) $row['user_id'] ?>">
                                            <button type="submit" class="btn btn-gold btn-sm">Activate</button>
                                        </form>
                                    <?php else: ?>
                                        <form method="post" style="display:inline;"
                                              onsubmit="return confirm('Deactivate this account? The user will not be able to sign in.');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="deactivate">
                                            <input type="hidden" name="user_id" value="<?= (int) $row['user_id'] ?>">
                                            <button type="submit" class="btn btn-outline btn-sm">Deactivate</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($pages > 1): ?>
        <nav class="pagination">
            <?php for ($p = 1; $p <= $pages; $p++): ?>
                <?php if ($p === $page): ?>
                    <span class="current"><?= $p ?></span>
                <?php else: ?>
                    <a href="<?= e(url('admin/users.php?' . http_build_query(array_filter([
                        'status' => $statusFilter, 'role' => $roleFilter, 'q' => $keyword, 'page' => $p,
                    ])))) ?>"><?= $p ?></a>
                <?php endif; ?>
            <?php endfor; ?>
        </nav>
    <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/layout/footer.php'; ?>
