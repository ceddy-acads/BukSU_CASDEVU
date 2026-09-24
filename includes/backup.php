<?php
/**
 * CASMS — Database backup and restore (FR-9.4)
 *
 * Backups are written under storage/backups/, outside the web root, because
 * a dump contains every password hash and every uploaded document's path.
 * They are reachable only through the authenticated download route.
 */

declare(strict_types=1);

/** Backup filenames are generated here, so this pattern is the whole alphabet. */
const BACKUP_FILENAME_PATTERN = '/^casms-backup-\d{8}-\d{6}\.sql$/';

function backup_directory(): string
{
    if (!is_dir(BACKUP_PATH) && !mkdir(BACKUP_PATH, 0775, true) && !is_dir(BACKUP_PATH)) {
        throw new RuntimeException('Could not create the backup directory.');
    }
    return BACKUP_PATH;
}

/** Full path to mysqldump / mysql, quoted for the shell. */
function mysql_tool(string $tool): string
{
    return MYSQL_BIN_PATH . '/' . $tool . '.exe';
}

function mysqldump_available(): bool
{
    return is_file(mysql_tool('mysqldump'));
}

function mysql_client_available(): bool
{
    return is_file(mysql_tool('mysql'));
}

/**
 * Validate a filename that came from a request and return its full path.
 * Returns null for anything that is not a backup this system produced.
 */
function backup_path_for(string $filename): ?string
{
    // Pattern first: this alone rules out traversal, since '/' and '.' cannot
    // appear in a matching name.
    if (!preg_match(BACKUP_FILENAME_PATTERN, $filename)) {
        return null;
    }

    $candidate = realpath(BACKUP_PATH . '/' . $filename);
    $root      = realpath(BACKUP_PATH);

    if ($candidate === false || $root === false) {
        return null;
    }

    // Belt and braces: confirm the resolved path is still inside the folder.
    if (!str_starts_with(str_replace('\\', '/', $candidate), str_replace('\\', '/', $root) . '/')) {
        return null;
    }

    return is_file($candidate) ? $candidate : null;
}

/**
 * List stored backups, newest first.
 *
 * @return array<int, array{filename: string, size: int, created_at: int}>
 */
function list_backups(): array
{
    $directory = backup_directory();
    $backups   = [];

    foreach ((array) scandir($directory) as $entry) {
        if (!is_string($entry) || !preg_match(BACKUP_FILENAME_PATTERN, $entry)) {
            continue;
        }
        $path = $directory . '/' . $entry;
        $backups[] = [
            'filename'   => $entry,
            'size'       => (int) filesize($path),
            'created_at' => (int) filemtime($path),
        ];
    }

    usort($backups, static fn(array $a, array $b): int => $b['created_at'] <=> $a['created_at']);

    return $backups;
}

/**
 * Run mysqldump into a new file.
 *
 * @return array{ok: bool, filename?: string, size?: int, error?: string}
 */
function create_backup(): array
{
    if (!mysqldump_available()) {
        return ['ok' => false, 'error' => 'mysqldump was not found at ' . MYSQL_BIN_PATH
            . '. Set MYSQL_BIN_PATH in includes/config.php.'];
    }

    try {
        $directory = backup_directory();
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }

    $filename = 'casms-backup-' . date('Ymd-His') . '.sql';
    $target   = $directory . '/' . $filename;

    // The password is passed through a defaults file rather than the command
    // line, where it would be visible to anyone listing running processes.
    $optionsFile = $directory . '/.my.cnf.tmp';
    $ini = "[client]\nuser=" . DB_USER . "\n"
         . (DB_PASS !== '' ? 'password=' . DB_PASS . "\n" : '')
         . 'host=' . DB_HOST . "\n"
         . 'port=' . DB_PORT . "\n";

    if (file_put_contents($optionsFile, $ini, LOCK_EX) === false) {
        return ['ok' => false, 'error' => 'Could not prepare the database credentials file.'];
    }
    @chmod($optionsFile, 0600);

    // Every argument is escaped; none of it comes from user input.
    $command = escapeshellarg(mysql_tool('mysqldump'))
        . ' --defaults-extra-file=' . escapeshellarg($optionsFile)
        . ' --single-transaction --routines --events --triggers'
        . ' --add-drop-table --default-character-set=utf8mb4'
        . ' ' . escapeshellarg(DB_NAME)
        . ' --result-file=' . escapeshellarg($target)
        . ' 2>&1';

    exec($command, $output, $exitCode);

    @unlink($optionsFile);

    if ($exitCode !== 0 || !is_file($target) || filesize($target) === 0) {
        @unlink($target);
        error_log('[CASMS] mysqldump failed (' . $exitCode . '): ' . implode(' ', $output));
        return ['ok' => false, 'error' => 'The backup could not be created. '
            . 'Check storage/logs/php-error.log for the detail.'];
    }

    return ['ok' => true, 'filename' => $filename, 'size' => (int) filesize($target)];
}

/**
 * Replace the current database with the contents of a stored backup.
 *
 * @return array{ok: bool, error?: string}
 */
function restore_backup(string $filename): array
{
    if (!mysql_client_available()) {
        return ['ok' => false, 'error' => 'The mysql client was not found at ' . MYSQL_BIN_PATH . '.'];
    }

    $path = backup_path_for($filename);
    if ($path === null) {
        return ['ok' => false, 'error' => 'That backup file does not exist.'];
    }

    $directory   = backup_directory();
    $optionsFile = $directory . '/.my.cnf.tmp';
    $ini = "[client]\nuser=" . DB_USER . "\n"
         . (DB_PASS !== '' ? 'password=' . DB_PASS . "\n" : '')
         . 'host=' . DB_HOST . "\n"
         . 'port=' . DB_PORT . "\n";

    if (file_put_contents($optionsFile, $ini, LOCK_EX) === false) {
        return ['ok' => false, 'error' => 'Could not prepare the database credentials file.'];
    }
    @chmod($optionsFile, 0600);

    $command = escapeshellarg(mysql_tool('mysql'))
        . ' --defaults-extra-file=' . escapeshellarg($optionsFile)
        . ' --default-character-set=utf8mb4'
        . ' ' . escapeshellarg(DB_NAME)
        . ' < ' . escapeshellarg($path)
        . ' 2>&1';

    exec($command, $output, $exitCode);

    @unlink($optionsFile);

    if ($exitCode !== 0) {
        error_log('[CASMS] Restore failed (' . $exitCode . '): ' . implode(' ', $output));
        return ['ok' => false, 'error' => 'The restore failed. The database may be in a '
            . 'partial state. Check storage/logs/php-error.log.'];
    }

    return ['ok' => true];
}

/** Delete one stored backup. */
function delete_backup(string $filename): bool
{
    $path = backup_path_for($filename);
    return $path !== null && @unlink($path);
}

/** '2.4 MB' — reuses the uploads helper so sizes read the same everywhere. */
function backup_size_label(int $bytes): string
{
    return format_filesize($bytes);
}
