<?php
/**
 * CASMS — Backup and restore (FR-9.4)
 *
 * Administrators only. A dump contains every password hash, so the files live
 * outside the web root and are served only through this page.
 */

declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';

require_role(['admin']);

if (is_post()) {
    csrf_verify();

    $action   = post('action');
    $filename = post('filename');

    // ---------------------------------------------------------------- Create
    if ($action === 'create') {
        $result = create_backup();

        if (!$result['ok']) {
            flash('error', $result['error']);
        } else {
            audit_log('create', 'backup', null,
                      'Created backup ' . $result['filename']
                      . ' (' . format_filesize($result['size']) . ')');
            flash('success', 'Backup created: ' . $result['filename']
                . ' (' . format_filesize($result['size']) . ').');
        }

        redirect('admin/backup.php');
    }

    // ---------------------------------------------------------------- Delete
    if ($action === 'delete') {
        if (backup_path_for($filename) === null) {
            flash('error', 'That backup file does not exist.');
            redirect('admin/backup.php');
        }

        if (delete_backup($filename)) {
            audit_log('delete', 'backup', null, 'Deleted backup ' . $filename);
            flash('success', 'Backup deleted.');
        } else {
            flash('error', 'The backup could not be deleted.');
        }

        redirect('admin/backup.php');
    }

    // --------------------------------------------------------------- Restore
    if ($action === 'restore') {
        // Typing the filename is the confirmation: a stray click cannot
        // replace the live database.
        if (post('confirm_filename') !== $filename) {
            flash('error', 'The filename you typed did not match. Nothing was restored.');
            redirect('admin/backup.php');
        }

        if (backup_path_for($filename) === null) {
            flash('error', 'That backup file does not exist.');
            redirect('admin/backup.php');
        }

        // Take a safety copy first, so a bad restore is itself recoverable.
        $safety = create_backup();

        audit_log('restore', 'backup', null,
                  'Restoring from ' . $filename
                  . ($safety['ok'] ? ' (safety copy: ' . $safety['filename'] . ')' : ' (safety copy failed)'));

        $result = restore_backup($filename);

        if (!$result['ok']) {
            flash('error', $result['error']);
            redirect('admin/backup.php');
        }

        // The restored data may not contain the current session's account.
        logout_user();
        start_session();
        flash('success', 'Database restored from ' . $filename . '.'
            . ($safety['ok'] ? ' A safety copy of the previous state was saved as ' . $safety['filename'] . '.' : '')
            . ' Please sign in again.');
        redirect('login.php');
    }
}

// -------------------------------------------------------------- Download
if (get('download') !== '') {
    $filename = get('download');
    $path     = backup_path_for($filename);

    if ($path === null) {
        http_response_code(404);
        exit('404 — Backup not found.');
    }

    audit_log('view', 'backup', null, 'Downloaded backup ' . $filename);

    header('Content-Type: application/sql');
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    readfile($path);
    exit;
}

$backups   = list_backups();
$totalSize = array_sum(array_column($backups, 'size'));
$toolsOk   = mysqldump_available() && mysql_client_available();

$pageTitle = 'Backup & restore';
require __DIR__ . '/../../includes/layout/header.php';
?>

<div class="page-head">
    <div>
        <h1>Backup &amp; restore</h1>
        <p><?= count($backups) ?> backup<?= count($backups) === 1 ? '' : 's' ?> stored
           <?= $backups !== [] ? '&middot; ' . e(format_filesize($totalSize)) . ' total' : '' ?></p>
    </div>
    <a class="btn btn-outline" href="<?= url('admin/audit.php') ?>">Audit trail</a>
</div>

<?php if (!$toolsOk): ?>
    <div class="alert alert-error">
        <strong>Database tools not found.</strong>
        <code>mysqldump.exe</code> and <code>mysql.exe</code> were expected in
        <code><?= e(MYSQL_BIN_PATH) ?></code>. Update <code>MYSQL_BIN_PATH</code>
        in <code>includes/config.php</code> to match your XAMPP installation.
    </div>
<?php endif; ?>

<div class="alert alert-warning">
    <strong>Backup files contain everything</strong> — including password
    hashes. They are stored outside the web root and can only be downloaded
    from this page. Keep downloaded copies somewhere safe.
</div>

<section class="card">
    <div class="card-head"><h2>Create a backup</h2></div>
    <p style="font-size:.9rem;margin-top:0;">
        Writes a complete SQL dump of the <code><?= e(DB_NAME) ?></code> database
        to <code>storage/backups/</code>. Safe to run at any time; it does not
        lock the system.
    </p>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <button type="submit" class="btn btn-gold" <?= $toolsOk ? '' : 'disabled' ?>>
            Create backup now
        </button>
    </form>
</section>

<section class="card">
    <div class="card-head"><h2>Stored backups</h2></div>

    <?php if ($backups === []): ?>
        <div class="empty">
            <strong>No backups yet</strong>
            Create one before making any large change to the data.
        </div>
    <?php else: ?>
        <?php if (count($backups) > BACKUP_KEEP): ?>
            <div class="alert alert-info">
                There are more than <?= BACKUP_KEEP ?> backups stored. Consider
                deleting the oldest to save space.
            </div>
        <?php endif; ?>

        <div class="table-wrap">
            <table class="data">
                <thead><tr><th>Backup</th><th>Created</th><th>Size</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach ($backups as $index => $backup): ?>
                    <tr>
                        <td>
                            <strong><?= e($backup['filename']) ?></strong>
                            <?php if ($index === 0): ?>
                                <span class="badge badge-success">Newest</span>
                            <?php endif; ?>
                        </td>
                        <td style="white-space:nowrap;">
                            <?= e(date('d M Y, g:i A', $backup['created_at'])) ?>
                        </td>
                        <td><?= e(format_filesize($backup['size'])) ?></td>
                        <td class="actions">
                            <div class="btn-row">
                                <a class="btn btn-outline btn-sm"
                                   href="<?= url('admin/backup.php?download=' . rawurlencode($backup['filename'])) ?>">
                                    Download
                                </a>

                                <button type="button" class="btn btn-outline btn-sm"
                                        onclick="document.getElementById('restore-<?= $index ?>').hidden = false; this.hidden = true;">
                                    Restore
                                </button>

                                <form method="post" style="display:inline;"
                                      onsubmit="return confirm('Delete this backup file permanently?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="filename" value="<?= e($backup['filename']) ?>">
                                    <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                                </form>
                            </div>

                            <form method="post" id="restore-<?= $index ?>" hidden style="margin-top:.5rem;"
                                  onsubmit="return confirm('This REPLACES the entire live database with this backup. Continue?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="restore">
                                <input type="hidden" name="filename" value="<?= e($backup['filename']) ?>">
                                <div class="alert alert-error" style="margin:0 0 .4rem;font-size:.8rem;">
                                    This replaces <strong>all</strong> current data.
                                    Type the filename to confirm.
                                </div>
                                <input type="text" name="confirm_filename" required
                                       placeholder="<?= e($backup['filename']) ?>"
                                       style="font-size:.8rem;margin-bottom:.35rem;">
                                <button type="submit" class="btn btn-danger btn-sm" <?= $toolsOk ? '' : 'disabled' ?>>
                                    Restore this backup
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <p class="hint" style="margin-bottom:0;">
            A restore takes a safety copy of the current data first, then signs
            you out — the restored data may not contain your account.
        </p>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/../../includes/layout/footer.php'; ?>
