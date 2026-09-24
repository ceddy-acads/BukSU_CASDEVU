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

    $target = fetch_one(
        'SELECT u.user_id, u.first_name, u.last_name, u.status, r.name AS role_name
           FROM users u JOIN roles r ON r.role_id = u.role_id
          WHERE u.user_id = ?',
        [$targetUserId]
    );
    if ($target === null) {
        flash('error', 'That user account no longer exists.');
        redirect('admin/users.php');
    }

    // Guard against an administrator locking themselves out.
    if ($targetUserId === current_user_id() && $action !== 'role') {
        flash('error', 'You cannot change the status of your own account.');
        redirect('admin/users.php');
    }

    // Only an administrator may act on another administrator's account.
    // Without this, staff could disable the accounts that supervise them.
    if ($target['role_name'] === 'admin' && !$isAdmin) {
        http_response_code(403);
        abort_page(403, 'Only an administrator may change another administrator\'s account.');
    }

    // Never leave the system without a way in: refuse to remove the last
    // active administrator, whether by status change or by role change.
    if ($target['role_name'] === 'admin'
        && in_array($action, ['deactivate', 'suspend', 'role'], true)) {
        $activeAdmins = (int) fetch_value(
            "SELECT COUNT(*) FROM users u JOIN roles r ON r.role_id = u.role_id
              WHERE r.name = 'admin' AND u.status = 'active'"
        );

        // For a role change, only block when the new role is not admin.
        $stillAdminAfterwards = $action === 'role'
            && (string) fetch_value('SELECT name FROM roles WHERE role_id = ?', [(int) post('role_id')]) === 'admin';

        if ($activeAdmins <= 1 && $target['status'] === 'active' && !$stillAdminAfterwards) {
            flash('error', 'This is the last active administrator. '
                . 'Promote another account to administrator before changing this one.');
            redirect('admin/users.php');
        }
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
                abort_page(403, 'Only an administrator may change a user role.');
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

<?php $isFiltered = $statusFilter !== '' || $roleFilter !== '' || $keyword !== ''; ?>

<div class="page-head">
    <div>
        <h1>User accounts</h1>
        <p><?= $total ?> account<?= $total === 1 ? '' : 's' ?><?= $isFiltered ? ' match these filters' : '' ?>. Approve new sign-ups and manage who can sign in.</p>
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
    <div class="form-row filter-actions">
        <button type="submit" class="btn btn-primary">Filter</button>
        <?php if ($isFiltered): ?>
            <a class="btn btn-outline" href="<?= url('admin/users.php') ?>">Clear</a>
        <?php endif; ?>
    </div>
</form>

<?php if ($users === []): ?>
    <?php if ($statusFilter === 'pending' && $roleFilter === '' && $keyword === ''): ?>
        <div class="empty">
            <strong>No accounts awaiting approval</strong>
            Every sign-up has been reviewed. New registrations will appear here.
            <div class="btn-row"><a class="btn btn-outline" href="<?= url('admin/users.php') ?>">Show all accounts</a></div>
        </div>
    <?php elseif ($isFiltered): ?>
        <div class="empty">
            <strong>No accounts match these filters</strong>
            Try a different search term, status, or role.
            <div class="btn-row"><a class="btn btn-outline" href="<?= url('admin/users.php') ?>">Clear filters</a></div>
        </div>
    <?php else: ?>
        <div class="empty"><strong>No user accounts yet</strong> Accounts appear here once people register.</div>
    <?php endif; ?>
<?php else: ?>
    <div class="card card-flush">
        <div class="table-wrap">
            <table class="data">
                <thead>
                    <tr>
                        <th>Name</th><th>Email</th><th>Student no.</th>
                        <th>Course / Year</th><th>Role</th><th>Status</th><th class="actions">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($users as $row): ?>
                    <tr>
                        <td><strong><?= e(full_name($row, true)) ?></strong></td>
                        <td><?= e($row['email']) ?></td>
                        <td><?= $row['student_number'] ? e($row['student_number']) : '<span class="muted">None</span>' ?></td>
                        <td>
                            <?= $row['course_code'] ? e($row['course_code']) : '<span class="muted">None</span>' ?>
                            <?= $row['year_level_label'] ? '<div class="hint">' . e($row['year_level_label']) . '</div>' : '' ?>
                        </td>
                        <td>
                            <?php if ($isAdmin && (int) $row['user_id'] !== current_user_id()): ?>
                                <form method="post" class="inline-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="role">
                                    <input type="hidden" name="user_id" value="<?= (int) $row['user_id'] ?>">
                                    <select name="role_id" onchange="this.form.submit()" class="input-auto input-sm"
                                            aria-label="Role for <?= e(full_name($row)) ?>">
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
                        <td><?= status_badge($row['status'], 'account') ?></td>
                        <td class="actions">
                            <?php if ((int) $row['user_id'] === current_user_id()): ?>
                                <span class="muted small">You</span>
                            <?php else: ?>
                                <div class="btn-row">
                                    <?php if ($row['status'] !== 'active'): ?>
                                        <form method="post" class="inline-form">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="activate">
                                            <input type="hidden" name="user_id" value="<?= (int) $row['user_id'] ?>">
                                            <button type="submit" class="btn btn-primary btn-sm"
                                                    aria-label="Activate <?= e(full_name($row)) ?>">Activate</button>
                                        </form>
                                    <?php else: ?>
                                        <form method="post" class="inline-form"
                                              onsubmit="return confirm('Deactivate this account? The user will not be able to sign in.');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="deactivate">
                                            <input type="hidden" name="user_id" value="<?= (int) $row['user_id'] ?>">
                                            <button type="submit" class="btn btn-outline btn-sm"
                                                    aria-label="Deactivate <?= e(full_name($row)) ?>">Deactivate</button>
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
        <nav class="pagination" aria-label="Pagination">
            <?php for ($p = 1; $p <= $pages; $p++): ?>
                <?php if ($p === $page): ?>
                    <span class="current" aria-current="page"><?= $p ?></span>
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
