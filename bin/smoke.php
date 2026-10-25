<?php
/**
 * CASDevU — Page smoke test (command line only)
 *
 * Replaces the manual "sign in as three roles and click every page" pass with
 * one command. It signs in over HTTP, requests every page under public/ as
 * each role, and fails on anything a browser pass would have caught:
 *
 *   - a 5xx response, or the designed "Something went wrong" page
 *   - a notice, warning or fatal error (all of which the error handler in
 *     includes/errors.php promotes to an exception, so they surface as 500s)
 *   - a new line in storage/logs/php-error.log during the request
 *   - a page that a role should be able to open returning 403, or a page that
 *     should be forbidden returning 200 (docs/03-role-matrix.md)
 *   - the session dropping back to the sign-in page mid-run
 *   - a public page that a signed-out visitor cannot open, or a page that
 *     should send them to sign in, and the highlight PDF's Range answers
 *
 * It only issues GET requests, so it never creates, edits or deletes data.
 *
 *   C:\xampp\php\php.exe bin\smoke.php
 *   C:\xampp\php\php.exe bin\smoke.php --only=activities --verbose
 *   C:\xampp\php\php.exe bin\smoke.php --admin=me@buksu.edu.ph:my-password
 *
 * Options
 *   --base=URL          Site URL to test (default: http://localhost/<folder>/public)
 *   --only=TEXT         Only request pages whose path contains TEXT
 *   --admin=EMAIL:PASS  Override an account; also --staff=, --coordinator=, --student=
 *   --slow=MS           Report a request slower than this (default 1500)
 *   --timeout=SECONDS   Per-request timeout (default 20)
 *   --verbose           Print every request, not only failures
 *   --help              Show this text
 *
 * Exits 0 when everything passes, 1 when anything fails.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// config.php derives BASE_URL from the web server; give it the same head start
// bin/check-setup.php uses.
$_SERVER['DOCUMENT_ROOT'] = $_SERVER['DOCUMENT_ROOT'] ?? '';
require __DIR__ . '/../includes/config.php';

// =====================================================================
// Options
// =====================================================================

$defaults = [
    'base'        => 'http://localhost/' . rawurlencode(basename(BASE_PATH)) . '/public',
    'only'        => '',
    'slow'        => 1500,
    'timeout'     => 20,
    'verbose'     => false,
    'admin'       => 'admin@buksu.edu.ph:admin123',
    'staff'       => 'rosales.office@buksu.edu.ph:demo1234',
    'coordinator' => 'bautista.coach@buksu.edu.ph:demo1234',
    'student'     => 'andrea.santos@student.buksu.edu.ph:demo1234',
];

$options = $defaults;
$roles   = ['admin', 'staff', 'coordinator', 'student'];

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--help' || $arg === '-h') {
        echo <<<HELP
        CASDevU page smoke test

          C:\\xampp\\php\\php.exe bin\\smoke.php
          C:\\xampp\\php\\php.exe bin\\smoke.php --only=activities --verbose

        Options
          --base=URL          Site URL to test (default http://localhost/<folder>/public)
          --only=TEXT         Only request pages whose path contains TEXT
          --admin=EMAIL:PASS  Override an account; also --staff=, --coordinator=, --student=
          --slow=MS           Report a request slower than this (default 1500)
          --timeout=SECONDS   Per-request timeout (default 20)
          --verbose           Print every request, not only failures
          --help              Show this text

        HELP;
        exit(0);
    }
    if ($arg === '--verbose' || $arg === '-v') {
        $options['verbose'] = true;
        continue;
    }
    if (!preg_match('/^--([a-z]+)=(.*)$/', $arg, $m)) {
        fwrite(STDERR, "Unknown option: $arg (try --help)\n");
        exit(2);
    }
    [, $name, $value] = $m;
    if (!array_key_exists($name, $defaults)) {
        fwrite(STDERR, "Unknown option: --$name (try --help)\n");
        exit(2);
    }
    $options[$name] = in_array($name, ['slow', 'timeout'], true) ? (int) $value : $value;
    if ($name === 'verbose') {
        $options['verbose'] = true;
    }
}

// A base URL is only ever stripped of a trailing slash; the default already
// points at public/. Passing --base=http://host (public/ as document root)
// stays untouched.
$options['base'] = rtrim($options['base'], '/');

// =====================================================================
// Output helpers
// =====================================================================

$checks = 0;
$failed = 0;
$warned = 0;
$timings = [];

function ok(string $message): void
{
    echo "  [ OK ]  $message\n";
}

function warn(string $message): void
{
    global $warned;
    $warned++;
    echo "  [warn]  $message\n";
}

function fail(string $message): void
{
    global $failed;
    $failed++;
    echo "  [FAIL]  $message\n";
}

function section(string $title): void
{
    echo "\n$title\n";
}

// =====================================================================
// Preflight
// =====================================================================

echo "\nCASDevU smoke test\n==================";
echo "\nBase     " . $options['base'] . "\n";

if (!extension_loaded('curl')) {
    fwrite(STDERR, "\nThe curl extension is not enabled, so this test cannot sign in.\n"
        . "Fix: in C:\\xampp\\php\\php.ini remove the \";\" before \"extension=curl\", then restart.\n\n");
    exit(1);
}

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET),
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    fwrite(STDERR, "\nCannot reach the database: " . $e->getMessage() . "\n"
        . "Fix: start MySQL in the XAMPP Control Panel, then run bin\\check-setup.php.\n\n");
    exit(1);
}

/** First column of the first row, or null. */
function db_value(PDO $pdo, string $sql, array $params = []): mixed
{
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    $value = $statement->fetchColumn();
    return $value === false ? null : $value;
}

$accountCount = (int) db_value($pdo, 'SELECT COUNT(*) FROM users');
if ($accountCount === 0) {
    fwrite(STDERR, "\nThe database is empty. Import database/schema.sql and database/demo-data.sql first.\n\n");
    exit(1);
}
echo 'Database ' . DB_NAME . " ($accountCount accounts)\n";

// The ids the detail pages need, taken from whatever data is installed.
$ids = [
    'activity' => (int) (db_value($pdo,
        "SELECT activity_id FROM activities WHERE status IN ('upcoming', 'ongoing') ORDER BY activity_id LIMIT 1")
        ?? db_value($pdo, 'SELECT activity_id FROM activities ORDER BY activity_id LIMIT 1')),
    'item'     => (int) db_value($pdo, 'SELECT item_id FROM inventory_items ORDER BY item_id LIMIT 1'),
];
try {
    $ids['highlight'] = (int) db_value($pdo,
        "SELECT h.activity_id FROM highlight_reports h JOIN activities a ON a.activity_id = h.activity_id
          WHERE a.status IN ('upcoming','ongoing','completed','closed') ORDER BY h.activity_id LIMIT 1");
} catch (PDOException $e) {
    fwrite(STDERR, "
The highlight_reports table is missing. Run: mysql -u root casms < database/update-highlight-reports.sql

");
    exit(1);
}
$ids['assigned'] = (int) (db_value($pdo,
    'SELECT ac.activity_id FROM activity_coordinators ac
       JOIN users u ON u.user_id = ac.user_id
      WHERE u.email = ? LIMIT 1',
    [account_email($options['coordinator'])]) ?? $ids['activity']);

$studentEmail      = account_email($options['student']);
$studentUserId     = (int) db_value($pdo, 'SELECT user_id FROM users WHERE email = ?', [$studentEmail]);
$ids['student']    = $studentUserId;
$ids['student_activity'] = (int) (db_value($pdo,
    "SELECT r.activity_id FROM registrations r
      WHERE r.user_id = ? AND r.status IN ('pending', 'approved', 'completed')
      ORDER BY r.activity_id LIMIT 1",
    [$studentUserId]) ?? $ids['activity']);
$ids['own_submission'] = (int) db_value($pdo,
    'SELECT rs.submission_id FROM requirement_submissions rs
       JOIN registrations r ON r.registration_id = rs.registration_id
      WHERE r.user_id = ? ORDER BY rs.submission_id LIMIT 1',
    [$studentUserId]);
$ids['coord_participant'] = (int) (db_value($pdo,
    'SELECT r.user_id FROM registrations r
       JOIN activity_coordinators ac ON ac.activity_id = r.activity_id
       JOIN users u                  ON u.user_id = ac.user_id
      WHERE u.email = ? LIMIT 1',
    [account_email($options['coordinator'])]) ?? $studentUserId);

echo 'Dataset  ' . db_value($pdo, 'SELECT COUNT(*) FROM activities') . ' activities, '
    . db_value($pdo, 'SELECT COUNT(*) FROM inventory_items') . ' items, '
    . db_value($pdo, 'SELECT COUNT(*) FROM registrations') . " registrations\n";

// =====================================================================
// Stylesheet integrity
//
// A comment closed without being opened turns the rest of the line into a
// declaration, and the parser then swallows the next declaration with it.
// That is exactly how --s1 disappeared: `gap: var(--s1) var(--s4)` became
// invalid, every legend gap fell back to zero, and the swatches sat hard
// against their labels. Nothing reported an error, so the overlap kept
// coming back on every page that used the token.
// =====================================================================

section('Stylesheet');
$cssFiles = glob(BASE_PATH . '/public/assets/css/*.css') ?: [];
if ($cssFiles === []) {
    warn('No stylesheet found under public/assets/css.');
}

// Tokens set from PHP inside style="" attributes are legitimately absent from
// the stylesheet, so a definition anywhere in the app counts.
$inlineTokens = [];
foreach (['public', 'includes'] as $directory) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(BASE_PATH . '/' . $directory, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if ($file->getExtension() === 'php') {
            if (preg_match_all('/(--[a-z0-9-]+)\s*:/i', (string) file_get_contents($file->getPathname()), $m)) {
                foreach ($m[1] as $token) {
                    $inlineTokens[$token] = true;
                }
            }
        }
    }
}

foreach ($cssFiles as $cssFile) {
    $css  = (string) file_get_contents($cssFile);
    $name = basename($cssFile);
    $lineAt = static fn (int $offset): int => substr_count(substr($css, 0, $offset), "\n") + 1;
    $problems = [];

    // 1. Comment markers must pair up.
    $openAt = null;
    preg_match_all('~/\*|\*/~', $css, $markers, PREG_OFFSET_CAPTURE);
    foreach ($markers[0] as [$marker, $offset]) {
        if ($marker === '/*') {
            if ($openAt === null) {
                $openAt = $offset;                      // remember where it opened
            }
        } elseif ($openAt === null) {
            $problems[] = 'line ' . $lineAt($offset) . ': "*/" closes a comment that was never opened — '
                . 'the parser will swallow the next declaration';
        } else {
            $openAt = null;
        }
    }
    if ($openAt !== null) {
        $problems[] = 'line ' . $lineAt($openAt) . ': comment opened and never closed';
    }

    // 2. Every custom property needs a value.
    preg_match_all('/(--[a-z0-9-]+)\s*:\s*([^;{}]*)[;}]/i', $css, $definitions, PREG_SET_ORDER);
    $defined = $inlineTokens;
    foreach ($definitions as $definition) {
        $defined[$definition[1]] = true;
        if (trim($definition[2]) === '') {
            $problems[] = 'custom property ' . $definition[1] . ' is defined with no value';
        }
    }

    // 3. Every var() must name a property that is defined somewhere. A
    //    fallback (var(--x, ...)) makes an undefined token harmless.
    preg_match_all('/var\(\s*(--[a-z0-9-]+)\s*(,|\))/i', $css, $uses, PREG_SET_ORDER);
    foreach ($uses as $use) {
        if ($use[2] === ',') {
            continue;
        }
        if (!isset($defined[$use[1]])) {
            $problems[] = 'var(' . $use[1] . ') is used but never defined';
        }
    }

    $checks++;
    if ($problems === []) {
        ok($name . ': comments balanced, ' . count($defined) . ' tokens, all var() references resolve');
    } else {
        foreach ($problems as $problem) {
            $checks++;
            fail($name . ': ' . $problem);
        }
    }
}

// =====================================================================
// HTTP
// =====================================================================

/**
 * One request. Returns status, Location header, body and elapsed seconds.
 *
 * @return array{status: int, location: string, headers: string, body: string, seconds: float, error: string}
 */
function http_request(string $url, string $jar, ?array $post = null, int $timeout = 20, array $headers = []): array
{
    $handle = curl_init($url);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_USERAGENT      => 'CASDevU smoke test',
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    if ($post !== null) {
        curl_setopt($handle, CURLOPT_POST, true);
        curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($post));
    }

    $started  = microtime(true);
    $raw      = curl_exec($handle);
    $seconds  = microtime(true) - $started;
    $status   = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $headerAt = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    $error    = curl_error($handle);
    curl_close($handle);

    $head     = is_string($raw) ? substr($raw, 0, $headerAt) : '';
    $body     = is_string($raw) ? substr($raw, $headerAt) : '';
    $location = '';
    if (preg_match('/^Location:\s*(.+)$/im', $head, $m)) {
        $location = trim($m[1]);
    }

    return ['status' => $status, 'location' => $location, 'headers' => $head, 'body' => $body, 'seconds' => $seconds, 'error' => $error];
}

/** "email:password" → email. */
function account_email(string $pair): string
{
    return explode(':', $pair, 2)[0];
}

/** "email:password" → [email, password]. */
function account_pair(string $pair): array
{
    $parts = explode(':', $pair, 2);
    return [$parts[0], $parts[1] ?? ''];
}

/**
 * Sign in and return the cookie jar holding the session.
 * Returns null and prints the reason on failure.
 */
function sign_in(string $base, string $role, string $credentials, int $timeout): ?string
{
    [$email, $password] = account_pair($credentials);
    $jar = tempnam(sys_get_temp_dir(), 'casdevu-smoke-');

    $page = http_request($base . '/login.php', $jar, null, $timeout);
    if ($page['status'] !== 200 || !preg_match('/name="csrf_token"\s+value="([^"]+)"/', $page['body'], $m)) {
        fail("$role: could not load the sign-in form (HTTP {$page['status']}).");
        return null;
    }

    $result = http_request($base . '/login.php', $jar, [
        'csrf_token' => $m[1],
        'email'      => $email,
        'password'   => $password,
    ], $timeout);

    if ($result['status'] !== 302 || !str_contains($result['location'], 'index.php')) {
        $because = $result['error'] !== ''
            ? $result['error']
            : 'HTTP ' . $result['status'] . ' — ' . (trim(strip_tags($result['body'])) !== ''
                ? substr(trim(preg_replace('/\s+/', ' ', strip_tags($result['body']))), 0, 160)
                : 'no redirect to the dashboard');
        fail("$role: could not sign in as $email."
            . "\n          $because"
            . "\n          Fix: check the account exists and the password is right, or pass --$role=email:password");
        return null;
    }

    return $jar;
}

// =====================================================================
// The page list
//
// allow:   roles that should get a successful response
// query:   fixed query string added to every request
// params:  query values; values are keys of $ids, or 'own_user'
// tolerate: extra statuses that count as a pass (302 = page redirects)
// optional: 404 is acceptable (detail pages whose demo record may not exist)
// soft:    roles that may also get 403 without failing (documented limits)
// binary:  response is a file, so skip the text checks
// =====================================================================

$pages = [
    // Every signed-in role
    ['path' => 'index.php',                       'allow' => ['admin', 'staff', 'coordinator', 'student']],
    ['path' => 'profile.php',                     'allow' => ['admin', 'staff', 'coordinator', 'student']],
    ['path' => 'settings.php',                    'allow' => ['admin', 'staff', 'coordinator', 'student']],
    ['path' => 'notifications.php',               'allow' => ['admin', 'staff', 'coordinator', 'student'],
     'tolerate' => [302]],   // opens the bell panel on the dashboard instead
    ['path' => 'profile-photo.php',               'allow' => ['admin', 'staff', 'coordinator', 'student'],
     'params' => ['id' => 'own_user'], 'optional' => true, 'binary' => true],

    ['path' => 'review.php',                      'allow' => ['admin', 'staff', 'coordinator']],

    ['path' => 'activities/index.php',            'allow' => ['admin', 'staff', 'coordinator', 'student']],
    ['path' => 'activities/view.php',             'allow' => ['admin', 'staff', 'coordinator', 'student'],
     'params' => ['id' => 'activity'], 'optional' => true],
    ['path' => 'activities/calendar.php',         'allow' => ['admin', 'staff', 'coordinator', 'student']],
    ['path' => 'activities/manage.php',           'allow' => ['admin', 'staff', 'coordinator'],
     'params' => ['id' => 'assigned'], 'optional' => true],
    ['path' => 'activities/coordinators.php',     'allow' => ['admin', 'staff'],
     'params' => ['activity_id' => 'assigned'], 'optional' => true],
    ['path' => 'activities/eligibility.php',      'allow' => ['admin', 'staff', 'coordinator'],
     'params' => ['activity_id' => 'assigned'], 'optional' => true],

    ['path' => 'participation/my-activities.php', 'allow' => ['student']],
    ['path' => 'participation/register.php',      'allow' => ['student'], 'tolerate' => [302]],
    ['path' => 'participation/participants.php',  'allow' => ['admin', 'staff', 'coordinator'],
     'params' => ['activity_id' => 'assigned'], 'optional' => true],

    ['path' => 'requirements/mine.php',           'allow' => ['student']],
    ['path' => 'requirements/submit.php',         'allow' => ['student'],
     'params' => ['activity_id' => 'student_activity'], 'optional' => true, 'tolerate' => [302]],
    ['path' => 'requirements/manage.php',         'allow' => ['admin', 'staff', 'coordinator'],
     'params' => ['activity_id' => 'assigned'], 'optional' => true],
    ['path' => 'requirements/verify.php',         'allow' => ['admin', 'staff', 'coordinator'],
     'params' => ['activity_id' => 'assigned'], 'optional' => true],
    ['path' => 'requirements/download.php',       'allow' => ['admin', 'staff', 'coordinator', 'student'],
     'params' => ['submission_id' => 'own_submission'], 'optional' => true,
     'soft' => ['coordinator'], 'binary' => true],

    ['path' => 'inventory/index.php',             'allow' => ['admin', 'staff', 'coordinator', 'student']],
    ['path' => 'inventory/reserve.php',           'allow' => ['admin', 'staff', 'coordinator']],
    ['path' => 'inventory/reservations.php',      'allow' => ['admin', 'staff', 'coordinator', 'student']],
    ['path' => 'inventory/borrow.php',            'allow' => ['student']],
    ['path' => 'inventory/borrowings.php',        'allow' => ['admin', 'staff']],
    ['path' => 'inventory/manage.php',            'allow' => ['admin', 'staff']],
    ['path' => 'inventory/photo.php',             'allow' => ['admin', 'staff', 'coordinator', 'student'],
     'params' => ['id' => 'item'], 'optional' => true, 'binary' => true],

    ['path' => 'id-photo.php',                    'allow' => ['student'], 'query' => 'side=front',
     'params' => ['user' => 'own_user'], 'optional' => true, 'binary' => true],

    ['path' => 'announcements/index.php',         'allow' => ['admin', 'staff', 'coordinator', 'student']],
    ['path' => 'announcements/manage.php',        'allow' => ['admin', 'staff', 'coordinator']],

    ['path' => 'reports/index.php',               'allow' => ['admin', 'staff', 'coordinator']],
    ['path' => 'reports/participation.php',       'allow' => ['admin', 'staff', 'coordinator']],
    ['path' => 'reports/inventory.php',           'allow' => ['admin', 'staff']],
    ['path' => 'reports/students.php',            'allow' => ['admin', 'staff', 'coordinator'],
     'params' => ['student' => 'coord_participant'], 'optional' => true, 'soft' => ['coordinator']],

    ['path' => 'admin/users.php',                 'allow' => ['admin', 'staff']],
    ['path' => 'admin/venues.php',                'allow' => ['admin', 'staff']],
    ['path' => 'admin/categories.php',            'allow' => ['admin']],
    ['path' => 'admin/audit.php',                 'allow' => ['admin']],
    ['path' => 'admin/backup.php',                'allow' => ['admin']],
    ['path' => 'admin/reminders.php',             'allow' => ['admin', 'staff']],

    // Public pages. A signed-in visitor is redirected away from some of them.
    ['path' => 'login.php',                       'allow' => ['admin', 'staff', 'coordinator', 'student'],
     'tolerate' => [302]],
    ['path' => 'register.php',                    'allow' => ['admin', 'staff', 'coordinator', 'student'],
     'tolerate' => [302]],
    ['path' => 'forgot-password.php',             'allow' => ['admin', 'staff', 'coordinator', 'student'],
     'tolerate' => [302]],   // a signed-in visitor is sent to their profile
    ['path' => 'reset-password.php',              'allow' => ['admin', 'staff', 'coordinator', 'student'],
     'optional' => true],

    // Highlight reports: public cover grid
    ['path' => 'highlights/index.php',            'allow' => ['admin', 'staff', 'coordinator', 'student'],
     'guest' => 'allow'],

    // Highlight reports: upload page
    ['path' => 'highlights/manage.php',           'allow' => ['admin', 'staff', 'coordinator'],
     'guest' => 'login'],
    ['path' => 'highlights/manage.php',           'allow' => ['admin', 'staff', 'coordinator'],
     'params' => ['activity_id' => 'assigned'], 'optional' => true],

    // Highlight reports: public PDF
    ['path' => 'highlights/file.php',             'allow' => ['admin', 'staff', 'coordinator', 'student'],
     'params' => ['activity_id' => 'highlight'], 'optional' => true, 'binary' => true, 'guest' => 'allow'],

    // Highlight reports: public flipbook viewer
    ['path' => 'highlights/view.php',             'allow' => ['admin', 'staff', 'coordinator', 'student'],
     'params' => ['activity_id' => 'highlight'], 'optional' => true, 'guest' => 'allow'],
];

if ($options['only'] !== '') {
    $pages = array_values(array_filter(
        $pages,
        static fn(array $page): bool => stripos($page['path'], $options['only']) !== false
    ));
    if ($pages === []) {
        fwrite(STDERR, "No page path contains \"{$options['only']}\".\n");
        exit(2);
    }
}

// =====================================================================
// Error log tracking
// =====================================================================

$logFile = STORAGE_PATH . '/logs/php-error.log';
clearstatcache(true, $logFile);
$logPosition = is_file($logFile) ? (int) filesize($logFile) : 0;

/** Lines appended to the error log since the last call. */
function log_since(string $file, int &$position): array
{
    clearstatcache(true, $file);
    if (!is_file($file)) {
        return [];
    }
    $size = (int) filesize($file);
    if ($size < $position) {
        $position = 0;          // the log was rotated or truncated
    }
    if ($size === $position) {
        return [];
    }
    $handle = fopen($file, 'rb');
    if ($handle === false) {
        return [];
    }
    fseek($handle, $position);
    $new = (string) stream_get_contents($handle);
    fclose($handle);
    $position = $size;

    return array_values(array_filter(
        array_map('trim', preg_split('/\R/', $new) ?: []),
        static fn(string $line): bool => $line !== ''
    ));
}

/** Text that should never appear in a page that rendered correctly. */
function body_error(string $body): ?string
{
    foreach ([
        'Something went wrong',
        'The system could not complete that request',
        'Fatal error',
        'Uncaught ',
        'Warning: ',
        'Notice: ',
        'Deprecated: ',
        'Parse error',
    ] as $marker) {
        if (str_contains($body, $marker)) {
            return $marker;
        }
    }
    return null;
}

// =====================================================================
// Sign in, one session per role
// =====================================================================

section('Signing in');
$jars = [];
foreach ($roles as $role) {
    $jar = sign_in($options['base'], $role, $options[$role], $options['timeout']);
    if ($jar === null) {
        continue;
    }
    $jars[$role] = $jar;
    ok(str_pad($role, 12) . account_email($options[$role]));
}

if ($jars === []) {
    echo "\nNo session could be opened, so nothing was tested.\n\n";
    exit(1);
}

// =====================================================================
// Requests
// =====================================================================

section('Pages');

$ownUserIds = [];
foreach (array_keys($jars) as $role) {
    $ownUserIds[$role] = (int) db_value($pdo, 'SELECT user_id FROM users WHERE email = ?', [account_email($options[$role])]);
}

foreach ($pages as $page) {
    foreach ($jars as $role => $jar) {
        // Query values are resolved per role, because "own user id" differs.
        $pairs = isset($page['query']) ? [$page['query']] : [];
        foreach ($page['params'] ?? [] as $name => $idKey) {
            $id = $idKey === 'own_user' ? ($ownUserIds[$role] ?? 0) : ($ids[$idKey] ?? 0);
            if ($id > 0) {
                $pairs[] = $name . '=' . $id;
            }
        }
        $query = $pairs === [] ? '' : '?' . implode('&', $pairs);

        $url = $options['base'] . '/' . $page['path'] . $query;
        $result = http_request($url, $jar, null, $options['timeout']);
        $newLog = log_since($logFile, $logPosition);

        $checks++;
        $expected = in_array($role, $page['allow'], true);
        $tolerate = $page['tolerate'] ?? [];
        $soft     = $page['soft'] ?? [];
        $label    = $page['path'] . $query . ' as ' . $role;
        $timings[] = ['label' => $label, 'seconds' => $result['seconds']];

        $problem = null;
        if ($result['error'] !== '') {
            $problem = 'request failed: ' . $result['error'];
        } elseif ($result['status'] >= 500) {
            $problem = 'HTTP ' . $result['status'] . ' (server error)';
        } elseif ($newLog !== []) {
            $problem = 'wrote to the error log: ' . $newLog[0];
        } elseif ($result['status'] !== 302 && empty($page['binary']) && ($marker = body_error($result['body'])) !== null) {
            $problem = 'page contains "' . $marker . '"';
        } elseif ($result['status'] === 302) {
            if (str_contains($result['location'], 'login.php')) {
                $problem = 'session lost — redirected to sign-in';
            } elseif ($expected && !in_array(302, $tolerate, true)) {
                $problem = 'redirected to ' . $result['location'] . ' (expected a page)';
            }
        } elseif ($expected) {
            if ($result['status'] === 200) {
                // pass
            } elseif ($result['status'] === 403 && in_array($role, $soft, true)) {
                // pass: documented limit, e.g. a coordinator outside their activities
            } elseif (($result['status'] === 403 || $result['status'] === 404)
                && !empty($page['optional']) && $result['status'] !== 403) {
                // pass: the demo record this detail page needs is not installed
            } elseif ($result['status'] === 403) {
                $problem = 'HTTP 403 — this role should be able to open it';
            } elseif ($result['status'] === 404) {
                $problem = 'HTTP 404 — expected the page to exist';
            } else {
                $problem = 'HTTP ' . $result['status'] . ' (expected 200)';
            }
        } else {
            if ($result['status'] === 200) {
                $problem = 'HTTP 200 — this role should be refused (403)';
            } elseif (!in_array($result['status'], [403, 404], true)) {
                $problem = 'HTTP ' . $result['status'] . ' (expected 403)';
            }
        }

        if ($problem === null) {
            if ($options['verbose']) {
                ok(sprintf('%-72s %4d  %5.0fms', $label, $result['status'], $result['seconds'] * 1000));
            }
            if ($result['seconds'] * 1000 > $options['slow']) {
                warn(sprintf('%s took %.0fms', $label, $result['seconds'] * 1000));
            }
        } else {
            fail($label . ': ' . $problem);
        }
    }
}

// A student who is not the owner must not get Andrea's ID photo (404 whether
// or not one is on file, so this passes trivially until a photo is uploaded).
section('ID photo privacy');
$otherStudent = 'bryan.reyes@student.buksu.edu.ph:demo1234';
// Check the account first: sign_in() reports a failure itself.
$otherJar = db_value($pdo, 'SELECT 1 FROM users WHERE email = ?', [account_email($otherStudent)])
    ? sign_in($options['base'], 'student', $otherStudent, $options['timeout']) : null;
if ($otherJar === null) {
    echo "  [skip]  second student account not available
";
} else {
    $checks++;
    $r = http_request($options['base'] . '/id-photo.php?side=front&user=' . $studentUserId, $otherJar, null, $options['timeout']);
    $r['status'] === 404
        ? ok('id-photo.php as another student: 404')
        : fail('id-photo.php as another student: HTTP ' . $r['status'] . ' (expected 404)');

    // Profile photos are served to their owner and the office only.
    $photoOwner = db_value($pdo, 'SELECT user_id FROM users WHERE photo_path IS NOT NULL AND email <> ? LIMIT 1',
        [account_email($otherStudent)]);
    if ($photoOwner === null) {
        echo "  [skip]  no other user has a profile photo\n";
    } else {
        $checks++;
        $r = http_request($options['base'] . '/profile-photo.php?id=' . (int) $photoOwner, $otherJar, null, $options['timeout']);
        $r['status'] === 404
            ? ok("profile-photo.php for someone else's photo, as a student: 404")
            : fail("profile-photo.php for someone else's photo, as a student: HTTP " . $r['status'] . ' (expected 404)');
    }
}

// =====================================================================
// Signed out
//
// guest 'allow': a visitor with no session gets the page (404 only when optional
// and its record is not installed).
// guest 'login': a visitor is sent to the sign-in page.
// =====================================================================

section('Signed out');
$guestJar = tempnam(sys_get_temp_dir(), 'smoke-guest');
foreach ($pages as $page) {
    if (!isset($page['guest'])) {
        continue;
    }
    $pairs = [];
    foreach ($page['params'] ?? [] as $name => $idKey) {
        if (($ids[$idKey] ?? 0) > 0) {
            $pairs[] = $name . '=' . $ids[$idKey];
        }
    }
    $query  = $pairs === [] ? '' : '?' . implode('&', $pairs);
    $label  = $page['path'] . $query . ' signed out';
    $result = http_request($options['base'] . '/' . $page['path'] . $query, $guestJar, null, $options['timeout']);
    $newLog = log_since($logFile, $logPosition);
    $checks++;

    $problem = null;
    if ($result['error'] !== '') {
        $problem = 'request failed: ' . $result['error'];
    } elseif ($result['status'] >= 500) {
        $problem = 'HTTP ' . $result['status'] . ' (server error)';
    } elseif ($newLog !== []) {
        $problem = 'wrote to the error log: ' . $newLog[0];
    } elseif ($result['status'] !== 302 && empty($page['binary']) && ($marker = body_error($result['body'])) !== null) {
        $problem = 'page contains "' . $marker . '"';
    } elseif ($page['guest'] === 'login') {
        if ($result['status'] !== 302 || !str_contains($result['location'], 'login.php')) {
            $problem = 'HTTP ' . $result['status'] . ' (expected a redirect to sign-in)';
        }
    } elseif ($result['status'] === 404 && !empty($page['optional']) && count($pairs) < count($page['params'] ?? [])) {
        // pass: no record installed (a public page whose record exists must answer 200)
    } elseif ($result['status'] !== 200) {
        $problem = 'HTTP ' . $result['status'] . ' (expected 200 without signing in)';
    }

    if ($problem === null) {
        if ($options['verbose']) {
            ok(sprintf('%-72s %4d', $label, $result['status']));
        }
    } else {
        fail($label . ': ' . $problem);
    }
}

if ($ids['highlight'] > 0 && stripos('highlights/file.php', $options['only']) !== false) {
    section('Range requests');
    $url  = $options['base'] . '/highlights/file.php?activity_id=' . $ids['highlight'];
    $size = (int) db_value($pdo, 'SELECT file_size FROM highlight_reports WHERE activity_id = ?', [$ids['highlight']]);
    $cases = [
        ['bytes=0-99',      206, '/^Content-Range:\s*bytes 0-99\/' . $size . '\r?$/im', 100],
        ['bytes=999999999-', 416, '/^Content-Range:\s*bytes \*\/' . $size . '\r?$/im', null],
        [null,              200, '/^Accept-Ranges:\s*bytes\r?$/im', $size],
    ];
    foreach ($cases as [$range, $status, $headerPattern, $length]) {
        $result = http_request($url, $guestJar, null, $options['timeout'], $range === null ? [] : ['Range: ' . $range]);
        log_since($logFile, $logPosition);
        $checks++;
        $label = 'highlights/file.php ' . ($range ?? 'no Range header');
        if ($result['status'] !== $status) {
            fail($label . ': HTTP ' . $result['status'] . ' (expected ' . $status . ')');
        } elseif (!preg_match($headerPattern, $result['headers'])) {
            fail($label . ': expected header ' . $headerPattern);
        } elseif ($length !== null && strlen($result['body']) !== $length) {
            fail($label . ': body is ' . strlen($result['body']) . ' bytes (expected ' . $length . ')');
        } else {
            ok($label . ' answered ' . $status);
        }
    }
}
@unlink($guestJar);

// =====================================================================
// Summary
// =====================================================================

$passRate = $checks === 0 ? 0 : (int) round((($checks - $failed) / $checks) * 100);
echo "\nSummary\n";
echo "  $checks requests, " . ($checks - $failed) . " passed ($passRate%), $failed failed, $warned warnings\n";

usort($timings, static fn(array $a, array $b): int => $b['seconds'] <=> $a['seconds']);
$slowest = array_slice($timings, 0, 5);
if ($slowest !== []) {
    echo "\nSlowest requests\n";
    foreach ($slowest as $index => $timing) {
        echo sprintf("  %d. %6.0fms  %s\n", $index + 1, $timing['seconds'] * 1000, $timing['label']);
    }
}

echo "\n" . ($failed === 0
    ? "All pages loaded cleanly. Role permissions match docs/03-role-matrix.md.\n\n"
    : "$failed request" . ($failed === 1 ? '' : 's') . " failed. Fix the first one, then run this again.\n\n");

foreach (array_keys($jars) as $role) {
    @unlink($jars[$role]);
}

exit($failed === 0 ? 0 : 1);
