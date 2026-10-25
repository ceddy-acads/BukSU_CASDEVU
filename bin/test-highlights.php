<?php
/**
 * CASDevU — Highlight report self-check (command line only)
 *
 * Asserts the pure rules in includes/highlights.php: byte ranges, ini sizes,
 * the visible-status rule and the upload ceiling.
 *
 *   C:\xampp\php\php.exe bin\test-highlights.php
 *
 * Exits 0 when every check passes, 1 otherwise.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/bootstrap.php';

$failed = 0;

function check(bool $ok, string $label): void
{
    global $failed;
    echo ($ok ? '[ OK ] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) {
        $failed++;
    }
}

check(parse_byte_range(null, 1000) === null,              'no header serves whole file');
check(parse_byte_range('bytes=0-99', 1000) === [0, 99],   'first 100 bytes');
check(parse_byte_range('bytes=500-', 1000) === [500, 999],'open-ended range');
check(parse_byte_range('bytes=-500', 1000) === [500, 999],'suffix range');
check(parse_byte_range('bytes=-5000', 1000) === [0, 999], 'suffix longer than file');
check(parse_byte_range('bytes=900-5000', 1000) === [900, 999], 'end clamped to size');
check(parse_byte_range('bytes=1000-', 1000) === false,    'start past end is 416');
check(parse_byte_range('bytes=50-10', 1000) === null,     'reversed range ignored');
check(parse_byte_range('bytes=0-1,5-9', 1000) === null,   'multi-range serves whole file');
check(parse_byte_range('items=0-9', 1000) === null,       'other unit ignored');
check(php_ini_bytes('40M') === 41943040,                  '40M');
check(php_ini_bytes('512K') === 524288,                   '512K');
check(php_ini_bytes('1G') === 1073741824,                 '1G');
check(php_ini_bytes('0') === PHP_INT_MAX,                 '0 means no limit');
check(highlight_visible_status('completed') && !highlight_visible_status('draft') && !highlight_visible_status('cancelled'), 'status rule');
check(highlight_status_sql() === "a.status IN ('upcoming','ongoing','completed','closed')", 'status SQL');
check(highlight_max_bytes() <= highlight_server_limit_bytes(), 'limit never above server');

exit($failed > 0 ? 1 : 0);
