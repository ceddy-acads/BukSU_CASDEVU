<?php
/**
 * CASMS — Database connection
 *
 * Exposes a single shared PDO instance. Every query in the system goes through
 * this connection using prepared statements (NFR-1).
 */

declare(strict_types=1);

/**
 * Returns the shared PDO connection, creating it on first call.
 */
function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
    );

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            // Throw on error rather than failing silently.
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            // Plain associative arrays; no duplicate numeric keys.
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Use real prepared statements, not PDO's client-side emulation.
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        // Never leak credentials or SQL to the browser (NFR + security checklist).
        error_log('[CASMS] Database connection failed: ' . $e->getMessage());
        http_response_code(500);
        exit(APP_DEBUG
            ? 'Database connection failed: ' . htmlspecialchars($e->getMessage())
            : 'The system is temporarily unavailable. Please try again later.');
    }

    return $pdo;
}

/**
 * Convenience: prepare, execute, return the statement.
 *
 * @param array<int|string, mixed> $params
 */
function query(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

/**
 * Fetch a single row, or null when there is no match.
 *
 * @param  array<int|string, mixed> $params
 * @return array<string, mixed>|null
 */
function fetch_one(string $sql, array $params = []): ?array
{
    $row = query($sql, $params)->fetch();
    return $row === false ? null : $row;
}

/**
 * Fetch every matching row.
 *
 * @param  array<int|string, mixed> $params
 * @return array<int, array<string, mixed>>
 */
function fetch_all(string $sql, array $params = []): array
{
    return query($sql, $params)->fetchAll();
}

/**
 * Fetch the first column of the first row — for COUNT(*) and similar.
 *
 * @param array<int|string, mixed> $params
 */
function fetch_value(string $sql, array $params = []): mixed
{
    $value = query($sql, $params)->fetchColumn();
    return $value === false ? null : $value;
}
