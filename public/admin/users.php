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

$addErrors = [];
$form      = ['first_name' => '', 'last_name' => '', 'email' => '', 'contact_number' => '', 'connection_note' => ''];

// --------------------------------------------------------------- Actions
if (is_post()) {
    csrf_verify();

    $action       = post('action');
    $targetUserId = (int) post('user_id');

    // Creating an account has no target user, so it is handled first.
    if ($action === 'add_guest') {
        if (!$isAdmin) {
            abort_page(403, 'Only an administrator may create an account.');
        }
        $form = [
            'first_name'      => post('first_name'),
            'last_name'       => post('last_name'),
            'email'           => strtolower(post('email')),
            'contact_number'  => post('contact_number'),
            'connection_note' => post('connection_note'),
        ];
        if ($form['first_name'] === '' || $form['last_name'] === '') {
            $addErrors[] = 'First name and last name are required.';
        }
        if (!filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
            $addErrors[] = 'A valid email address is required.';
        }
        if ($form['contact_number'] === '') {
            $addErrors[] = 'Contact number is required.';
        }
        if ($form['connection_note'] === '') {
            $addErrors[] = 'Tell the office how the guest is connected to the unit\'s activities.';
        } elseif (mb_strlen($form['connection_note']) > 500) {
            $addErrors[] = 'The connection to the unit must be 500 characters or fewer.';
        }
        $addErrors = array_merge($addErrors, length_errors([
            'First name'     => [$form['first_name'], 80],
            'Last name'      => [$form['last_name'], 80],
            'Email address'  => [$form['email'], 150],
            'Contact number' => [$form['contact_number'], 30],
        ]));
        if ($addErrors === [] && fetch_value('SELECT 1 FROM users WHERE email = ? LIMIT 1', [$form['email']])) {
            $addErrors[] = 'An account with this email address already exists.';
        }
        if ($addErrors === []) {
            $temp = temporary_password();
            query(
                'INSERT INTO users
                     (role_id, email, password_hash, first_name, last_name,
                      contact_number, connection_note, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    fetch_value("SELECT role_id FROM roles WHERE name = 'guest'"),
                    $form['email'],
                    password_hash($temp, PASSWORD_DEFAULT),
                    $form['first_name'],
                    $form['last_name'],
                    $form['contact_number'],
                    $form['connection_note'],
                    'active',
                ]
            );
            audit_log('create', 'user', (int) db()->lastInsertId(),
                      'Administrator created guest account: ' . $form['email']);
            flash('success', 'Guest account created for ' . $form['first_name'] . ' ' . $form['last_name']
                . '. Temporary password: ' . $temp . ' — copy it now, it will not be shown again.', $temp);
            redirect('admin/users.php');
        }
    }
}

if (is_post() && post('action') !== 'add_guest') {
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
            $isGuestTarget = $target['role_name'] === 'guest';
            notify($targetUserId, 'system', 'Your account is now active',
                   $isGuestTarget ? 'You can now sign in and follow the unit\'s schedule.'
                                  : 'You can now sign in and register for activities.', url('login.php'));
            // A pending student cannot open the in-app notice until they can
            // sign in, so this one is always emailed as well (to mail.log
            // while email is not set up).
            $activated = fetch_one('SELECT email, first_name FROM users WHERE user_id = ?', [$targetUserId]);
            if ($activated !== null) {
                send_mail(
                    (string) $activated['email'],
                    'Your ' . APP_NAME . ' account is active',
                    'Hi ' . $activated['first_name'] . ',' . PHP_EOL . PHP_EOL
                    . 'The office has activated your ' . APP_NAME . ' account. '
                    . ($isGuestTarget
                        ? 'You can now sign in and follow the unit\'s schedule:'
                        : 'You can now sign in and register for culture, arts, and sports activities:')
                    . PHP_EOL . PHP_EOL
                    . '  ' . absolute_url(url('login.php')) . PHP_EOL . PHP_EOL
                    . office_name() . PHP_EOL . university_name()
                );
            }
            flash('success', full_name($target) . ' can now sign in.');
            break;

        case 'deactivate':
            query("UPDATE users SET status = 'inactive' WHERE user_id = ?", [$targetUserId]);
            audit_log('update', 'user', $targetUserId, 'Deactivated account');
            cancel_open_student_requests($targetUserId, null, 'Your account was deactivated.');
            delete_id_photos($targetUserId);
            flash('success', full_name($target) . ' has been deactivated.');
            break;

        case 'decline':
            // A sign-up the office will not approve (a duplicate, a typo, not
            // a student). Marked inactive rather than deleted, so it leaves
            // the approval queue but can still be activated if declined by
            // mistake.
            if ($target['status'] !== 'pending') {
                flash('error', 'Only an account awaiting approval can be declined.');
                break;
            }
            query("UPDATE users SET status = 'inactive' WHERE user_id = ?", [$targetUserId]);
            audit_log('update', 'user', $targetUserId, 'Declined sign-up');
            delete_id_photos($targetUserId);
            $declined = fetch_one('SELECT email, first_name FROM users WHERE user_id = ?', [$targetUserId]);
            if ($declined !== null) {
                send_mail(
                    (string) $declined['email'],
                    'Your ' . APP_NAME . ' sign-up was not approved',
                    'Hi ' . $declined['first_name'] . ',' . PHP_EOL . PHP_EOL
                    . 'The office could not approve your ' . APP_NAME . ' account. If you think this is a '
                    . 'mistake, please contact the office' . office_contact_hint() . '.' . PHP_EOL . PHP_EOL
                    . office_name() . PHP_EOL . university_name()
                );
            }
            flash('success', 'Sign-up declined for ' . full_name($target) . '. It is no longer awaiting approval.');
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
            $newRole   = fetch_value('SELECT name FROM roles WHERE role_id = ?', [$newRoleId]);
            if (!$newRole) {
                flash('error', 'That role does not exist.');
                redirect('admin/users.php');
            }
            if ($target['role_name'] === 'guest' || $newRole === 'guest') {
                flash('error', 'Guest accounts keep their role. If someone signed up on the wrong path, decline the account and ask them to sign up again.');
                break;
            }
            if ($newRole === $target['role_name']) {
                flash('success', full_name($target) . ' is already ' . ucfirst((string) $newRole) . '. Nothing changed.');
                break;
            }
            query('UPDATE users SET role_id = ? WHERE user_id = ?', [$newRoleId, $targetUserId]);
            audit_log('update', 'user', $targetUserId,
                      'Changed role from ' . $target['role_name'] . ' to ' . $newRole);
            flash('success', full_name($target) . ' is now ' . ucfirst((string) $newRole) . '.');
            break;

        case 'reset_password':
            if (!$isAdmin) {
                http_response_code(403);
                abort_page(403, 'Only an administrator may reset a password.');
            }
            // Shown once to the administrator, who relays it to the person.
            $temp = temporary_password();
            query('UPDATE users SET password_hash = ? WHERE user_id = ?',
                  [password_hash($temp, PASSWORD_DEFAULT), $targetUserId]);
            audit_log('update', 'user', $targetUserId, 'Administrator reset the password');
            notify($targetUserId, 'system', 'Your password was reset',
                   'An administrator reset your password. Sign in with the temporary password from the office, '
                   . 'then change it under My profile.', url('profile.php'));
            flash('success', 'Temporary password for ' . full_name($target) . ': ' . $temp
                . ' — copy it now, it will not be shown again.', $temp);
            break;

        default:
            flash('error', 'Unknown action.');
    }

    redirect('admin/users.php?' . http_build_query(array_filter([
        'status' => get('status'),
        'role'   => get('role'),
        'q'      => get('q'),
        'sort'   => get('sort'),
        'dir'    => get('dir'),
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

// Sort: accounts awaiting approval first, then by name, unless the viewer
// sorts by name, which then orders purely by the displayed "Last, First".
// The ORDER BY comes from this fixed map, never from the request.
[$sortKey, $sortDir] = sort_param(['name'], '');
$orderBy = $sortKey === 'name'
    ? "u.last_name $sortDir, u.first_name $sortDir, u.user_id"
    : "FIELD(u.status, 'pending') DESC, u.last_name, u.first_name, u.user_id";
$sortFilters = ['status' => get('status'), 'role' => get('role'), 'q' => get('q')];

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
      ORDER BY $orderBy
      LIMIT " . PER_PAGE . " OFFSET $offset",
    $params
);

$roles        = fetch_all('SELECT role_id, name FROM roles ORDER BY role_id');

$pageTitle = 'Accounts';
require __DIR__ . '/../../includes/layout/header.php';
?>

<?php $isFiltered = $statusFilter !== '' || $roleFilter !== '' || $keyword !== ''; ?>

<div class="page-head">
    <div>
        <h1>Accounts</h1>
        <p data-live-region="summary"><?= $total ?> account<?= $total === 1 ? '' : 's' ?><?= $isFiltered ? ' match these filters' : '' ?>. Approve new sign-ups and manage who can sign in.</p>
    </div>
    <?php if ($isAdmin): ?>
        <a class="btn btn-outline" href="<?= url('admin/users.php?add=guest') ?>">Add a guest</a>
    <?php endif; ?>
</div>

<?php if ($isAdmin && (get('add') === 'guest' || $addErrors !== [])): ?>
<div class="card">
    <h2>Add a guest</h2>
    <?php if ($addErrors !== []): ?>
        <div class="alert alert-error" role="alert">
            <?php foreach ($addErrors as $error): ?>
                <div><?= e($error) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <form method="post" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_guest">
        <div class="form-grid form-grid-2">
            <div class="form-row">
                <label for="first_name">First name <span class="req">*</span></label>
                <input type="text" id="first_name" name="first_name" maxlength="80" value="<?= e($form['first_name']) ?>" required>
            </div>
            <div class="form-row">
                <label for="last_name">Last name <span class="req">*</span></label>
                <input type="text" id="last_name" name="last_name" maxlength="80" value="<?= e($form['last_name']) ?>" required>
            </div>
            <div class="form-row">
                <label for="email">Email address <span class="req">*</span></label>
                <input type="email" id="email" name="email" maxlength="150" value="<?= e($form['email']) ?>" required>
            </div>
            <div class="form-row">
                <label for="contact_number">Contact number <span class="req">*</span></label>
                <input type="tel" id="contact_number" name="contact_number" maxlength="30" value="<?= e($form['contact_number']) ?>" required>
            </div>
        </div>
        <div class="form-row">
            <label for="connection_note">How is this person connected to the unit's activities? <span class="req">*</span></label>
            <textarea id="connection_note" name="connection_note" rows="4" maxlength="500" required><?= e($form['connection_note']) ?></textarea>
        </div>
        <div class="btn-row">
            <button type="submit" class="btn btn-primary" data-loading="Creating…">Create guest account</button>
            <a class="btn btn-outline" href="<?= url('admin/users.php') ?>">Cancel</a>
        </div>
    </form>
</div>
<?php endif; ?>

<div data-live-region="notice">
<?php // Accounts awaiting approval now open in the notification pop-up: includes/attention.php. ?>
</div>

<form method="get" class="filter-bar" data-live-search>
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
    <?php if ($sortKey !== ''): ?>
        <input type="hidden" name="sort" value="<?= e($sortKey) ?>">
        <input type="hidden" name="dir" value="<?= e($sortDir) ?>">
    <?php endif; ?>
    <div class="form-row filter-actions" data-live-region="actions">
        <button type="submit" class="btn btn-primary">Filter</button>
        <?php if ($isFiltered): ?>
            <a class="btn btn-outline" href="<?= url('admin/users.php') ?>">Clear</a>
        <?php endif; ?>
    </div>
</form>

<div data-live-region="results">

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
            <?php // Five columns so the table fits beside the sidebar without scrolling
                  // sideways: email sits under the name, the student number with the
                  // course, and each row's actions stack. ?>
            <table class="data table-stack users-table">
                <thead>
                    <tr>
                        <?= sort_th('Name', 'name', $sortKey, $sortDir, 'admin/users.php', $sortFilters) ?>
                        <th>Details</th><th>Role</th><th>Status</th><th class="actions">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($users as $row): ?>
                    <tr>
                        <td data-label="Name">
                            <strong><?= e(full_name($row, true)) ?></strong>
                            <?php // A long address may break after the @ or before a dot, never mid-word. ?>
                            <div class="hint user-email"><?= str_replace(['@', '.'], ['@<wbr>', '<wbr>.'], e($row['email'])) ?></div>
                        </td>
                        <td data-label="Details">
                            <?php if ($row['role_name'] === 'guest'): ?>
                                Guest
                                <div class="hint preline"><?= e((string) $row['connection_note']) ?></div>
                            <?php elseif ($row['student_number'] || $row['course_code']): ?>
                                <?= $row['student_number'] ? '<span class="nowrap">' . e($row['student_number']) . '</span>' : '<span class="muted">No student number</span>' ?>
                                <div class="hint">
                                    <?= e(implode(', ', array_filter([$row['course_code'], $row['year_level_label']]))) ?>
                                </div>
                            <?php else: ?>
                                <span class="muted">Not a student</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Role">
                            <?php if ($isAdmin && (int) $row['user_id'] !== current_user_id() && $row['role_name'] !== 'guest'): ?>
                                <?php // Choose, then Change: a role is a permission grant, so it never
                                      // applies just because the dropdown moved (an arrow key on a
                                      // closed select fires 'change' in some browsers). app.js asks
                                      // for confirmation naming both roles. ?>
                                <form method="post" class="inline-form role-form" data-role-change
                                      data-person="<?= e(full_name($row)) ?>"
                                      data-current-role="<?= e(ucfirst($row['role_name'])) ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="role">
                                    <input type="hidden" name="user_id" value="<?= (int) $row['user_id'] ?>">
                                    <select name="role_id" class="input-auto input-sm"
                                            aria-label="Role for <?= e(full_name($row)) ?>">
                                        <?php foreach ($roles as $role): ?>
                                            <?php if ($role['name'] === 'guest') { continue; } ?>
                                            <option value="<?= (int) $role['role_id'] ?>"
                                                <?= (int) $row['role_id'] === (int) $role['role_id'] ? 'selected' : '' ?>>
                                                <?= e(ucfirst($role['name'])) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" class="btn btn-outline btn-sm"
                                            aria-label="Change role for <?= e(full_name($row)) ?>">Change</button>
                                </form>
                            <?php else: ?>
                                <?= e(ucfirst($row['role_name'])) ?>
                            <?php endif; ?>
                        </td>
                        <td data-label="Status"><?= status_badge($row['status'], 'account') ?></td>
                        <td data-label="Actions" class="actions">
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
                                        <?php if ($row['status'] === 'pending'): ?>
                                            <form method="post" class="inline-form"
                                                  onsubmit="return confirm('Decline this sign-up? It leaves the approval queue and cannot sign in. You can still activate it later.');">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="decline">
                                                <input type="hidden" name="user_id" value="<?= (int) $row['user_id'] ?>">
                                                <button type="submit" class="btn btn-outline btn-sm"
                                                        aria-label="Decline sign-up for <?= e(full_name($row)) ?>">Decline</button>
                                            </form>
                                        <?php endif; ?>
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
                                    <?php if ($isAdmin): ?>
                                        <form method="post" class="inline-form"
                                              onsubmit="return confirm('Reset this account\u2019s password? A temporary password will be shown once.');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="reset_password">
                                            <input type="hidden" name="user_id" value="<?= (int) $row['user_id'] ?>">
                                            <button type="submit" class="btn btn-outline btn-sm"
                                                    aria-label="Reset password for <?= e(full_name($row)) ?>">Reset password</button>
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
                        'status' => $statusFilter, 'role' => $roleFilter, 'q' => $keyword,
                        'sort' => $sortKey, 'dir' => $sortKey !== '' ? $sortDir : '', 'page' => $p,
                    ])))) ?>"><?= $p ?></a>
                <?php endif; ?>
            <?php endfor; ?>
        </nav>
    <?php endif; ?>
<?php endif; ?>

</div>

<?php require __DIR__ . '/../../includes/layout/footer.php'; ?>
