<?php
/** Progressive live rendering over the existing, authorized PHP pages. */
declare(strict_types=1);

function live_route(): string
{
    $file = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $root = str_replace('\\', '/', __DIR__ . '/../public/');
    $root = str_replace('\\', '/', (string) realpath($root)) . '/';
    return str_starts_with($file, $root) ? substr($file, strlen($root)) : '';
}

function live_page_enabled(): bool
{
    return in_array(live_route(), [
        'index.php', 'review.php', 'activities/index.php', 'activities/calendar.php',
        'activities/view.php', 'activities/manage.php', 'announcements/index.php',
        'announcements/manage.php', 'participation/participants.php', 'participation/my-activities.php',
        'requirements/verify.php', 'requirements/manage.php', 'inventory/index.php',
        'inventory/reservations.php', 'inventory/borrowings.php', 'invitations/mine.php',
        'invitations/view.php', 'admin/users.php', 'admin/id-verification.php', 'profile.php', 'inventory/borrow.php',
    ], true);
}

function live_is_poll(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && ($_SERVER['HTTP_X_CASDEVU_LIVE'] ?? '') === 'poll';
}

function live_is_action(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_SERVER['HTTP_X_CASDEVU_LIVE'] ?? '') === 'action';
}

function live_json(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: private, no-store');
    echo json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function live_revision(array $row): string
{
    // Do not include password material or routine sign-in timestamps in account revisions.
    unset($row['password_hash'], $row['last_login_at']);
    ksort($row);
    return hash('sha256', json_encode($row, JSON_INVALID_UTF8_SUBSTITUTE));
}

/** Lock a legacy decision row until redirect; existing publication decisions have their own versions. */
function live_lock_revision(string $table, int $id, ?int $activityId = null): void
{
    $keys = ['registrations' => 'registration_id', 'requirement_submissions' => 'submission_id',
        'reservations' => 'reservation_id', 'users' => 'user_id'];
    if (!isset($keys[$table])) { throw new LogicException('Unsupported live record.'); }
    query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
    db()->beginTransaction();
    $GLOBALS['live_transaction'] = true;
    if ($activityId !== null) {
        fetch_one('SELECT activity_id FROM activities WHERE activity_id = ? FOR UPDATE', [$activityId]);
    }
    $row = fetch_one("SELECT * FROM $table WHERE {$keys[$table]} = ? FOR UPDATE", [$id]);
    $expected = post('_live_revision');
    if ((live_is_action() && $expected === '') || ($expected !== '' && ($row === null || !hash_equals(live_revision($row), $expected)))) {
        live_end_transaction(false);
        if (live_is_action()) {
            live_json(['ok' => false, 'conflict' => true, 'messages' => [['type' => 'error',
                'message' => 'This record changed while you were reviewing it. Review its latest status before deciding.']]], 409);
        }
        flash('error', 'This record changed while you were reviewing it. Review its latest status before deciding.');
        redirect(live_route() . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : ''));
    }
}

function live_end_transaction(bool $commit): void
{
    if (!empty($GLOBALS['live_transaction']) && db()->inTransaction()) {
        $commit ? db()->commit() : db()->rollBack();
    }
    $GLOBALS['live_transaction'] = false;
}

/** The output renderer adds revision tokens in batches, never a query per form. */
function live_annotate(DOMDocument $doc): void
{
    $xpath = new DOMXPath($doc);
    $targets = [
        'participation/participants.php' => ['registration_id', 'registrations'],
        'requirements/verify.php' => ['submission_id', 'requirement_submissions'],
        'inventory/reservations.php' => ['reservation_id', 'reservations'],
        'admin/users.php' => ['user_id', 'users'],
        'admin/id-verification.php' => ['user_id', 'users'],
        'invitations/view.php' => ['invitation_id', 'invitations'],
    ];
    $actions = ['approve', 'reject', 'verify', 'activate', 'decline', 'deactivate', 'cancel',
        'attend', 'attendance', 'complete', 'withdraw', 'retry_email', 'contacted'];
    $forms = [];
    $ids = [];
    foreach ($xpath->query('//form[translate(@method,"POST","post")="post"]') as $form) {
        $action = '';
        foreach ($form->getElementsByTagName('input') as $input) {
            if ($input->getAttribute('name') === 'action') { $action = $input->getAttribute('value'); }
        }
        $publication = false;
        foreach ($form->getElementsByTagName('button') as $button) {
            if ($button->getAttribute('name') === 'decision') { $publication = true; }
        }
        if (!in_array($action, $actions, true) && !$publication) { continue; }
        $form->setAttribute('data-live-action', '');
        if ($publication) {
            $identity = [];
            foreach ($form->getElementsByTagName('input') as $input) { $identity[$input->getAttribute('name')] = $input->getAttribute('value'); }
            $publicationKey = 'publication:' . ($identity['kind'] ?? '') . ':' . ($identity['id'] ?? '');
            $form->setAttribute('data-refresh-key', $publicationKey);
            $parent = $form->parentNode;
            if ($parent instanceof DOMElement && $parent->tagName === 'section') { $parent->setAttribute('data-refresh-key', 'review:' . $publicationKey); }
        }
        $target = $targets[live_route()] ?? null;
        if ($target !== null) {
            if (live_route() === 'invitations/view.php') {
                $input = $doc->createElement('input');
                $input->setAttribute('type', 'hidden'); $input->setAttribute('name', 'invitation_id'); $input->setAttribute('value', (string) get_id('id'));
                $form->appendChild($input);
            }
            foreach ($form->getElementsByTagName('input') as $input) {
                if ($input->getAttribute('name') === $target[0]) {
                    $id = (int) $input->getAttribute('value');
                    if ($id > 0) {
                        $forms[] = [$form, $id, $target[1], $action];
                        $ids[$id] = $id;
                    }
                    break;
                }
            }
        }
    }
    if ($ids !== []) {
        [$key, $table] = $targets[live_route()];
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $rows = fetch_all("SELECT * FROM $table WHERE $key IN ($marks)", array_values($ids));
        $versions = [];
        foreach ($rows as $row) { $versions[(int) $row[$key]] = live_revision($row); }
        foreach ($forms as [$form, $id, $table, $action]) {
            if (!isset($versions[$id])) { continue; }
            $input = $doc->createElement('input');
            $input->setAttribute('type', 'hidden');
            $input->setAttribute('name', '_live_revision');
            $input->setAttribute('value', $versions[$id]);
            $form->appendChild($input);
            $form->setAttribute('data-refresh-key', 'action:' . $table . ':' . $id . ':' . $action);
            $parent = $form->parentNode;
            while ($parent instanceof DOMElement && $parent->tagName !== 'main') {
                if ($parent->tagName === 'tr' || ($parent->tagName === 'section' && preg_match('/\bcard\b/', $parent->getAttribute('class')))) {
                    $parent->setAttribute('data-refresh-key', $table . ':' . $id);
                    break;
                }
                $parent = $parent->parentNode;
            }
        }
    }
    // Read-only list rows/cards also need stable identities when records are inserted or removed.
    foreach ($xpath->query('//main//tr[not(@data-refresh-key)] | //main//article[not(@data-refresh-key)]') as $node) {
        foreach ($node->getElementsByTagName('a') as $link) {
            $href = $link->getAttribute('href');
            if (preg_match('~/(activities/view|announcements/manage|invitations/view)\.php\?(?:[^#]*&)?id=(\d+)~', $href, $match)) {
                $node->setAttribute('data-refresh-key', $match[1] . ':' . $match[2]);
                break;
            }
        }
    }
}

/** Render snapshots only after the page's usual role, ownership and visibility gates. */
function live_output(string $html): string
{
    foreach (headers_list() as $header) {
        if (stripos($header, 'Content-Type:') === 0 && stripos($header, 'text/html') === false) { return $html; }
    }
    if (!str_contains($html, '<main') || !str_contains($html, 'id="main"') || http_response_code() >= 400) {
        live_end_transaction(false);
        return $html;
    }
    if (current_user_id() === null) { return $html; }
    $doc = new DOMDocument('1.0', 'UTF-8');
    $previous = libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    foreach (iterator_to_array($doc->childNodes) as $node) {
        if ($node->nodeType === XML_PI_NODE) { $doc->removeChild($node); }
    }
    live_annotate($doc);
    $xpath = new DOMXPath($doc);
    $regions = ['notifications' => $xpath->query('//details[@data-notifications]')->item(0),
        'review-link' => $xpath->query('//aside//a[contains(@href,"/review.php")]')->item(0)];
    if (live_page_enabled()) { $regions['page'] = $doc->getElementById('main'); }
    $payload = [];
    foreach ($regions as $name => $node) {
        if (!$node instanceof DOMElement) { continue; }
        $node->setAttribute('data-refresh-region', $name);
        $fragment = $doc->saveHTML($node);
        $payload[$name] = ['html' => $fragment, 'revision' => hash('sha256', $fragment)];
    }
    if (live_is_poll()) {
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: private, no-store');
        return json_encode(['ok' => true, 'role' => current_role(), 'regions' => $payload], JSON_HEX_TAG | JSON_INVALID_UTF8_SUBSTITUTE);
    }
    $body = $doc->getElementsByTagName('body')->item(0);
    if ($body instanceof DOMElement) { $body->setAttribute('data-live-role', (string) current_role()); }
    return $doc->saveHTML();
}
