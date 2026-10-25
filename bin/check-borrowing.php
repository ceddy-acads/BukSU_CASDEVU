<?php
/**
 * CASMS — Self-check for student equipment borrowing.
 *
 * Command line only:  C:\xampp\php\php.exe bin\check-borrowing.php
 *
 * Every check runs inside one transaction that is always rolled back, so the
 * database is left as it was. Prints PASS/FAIL per check; exits 1 on any FAIL.
 * Needs the demo data (andrea, the coordinator, activities 15/16/17).
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('403 — This script runs from the command line only.');
}

require_once __DIR__ . '/../includes/bootstrap.php';

$failures = 0;
$tempFiles = [];

function check(string $name, bool $ok, string $why = ''): void
{
    global $failures;
    if (!$ok) {
        $failures++;
    }
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $why === '' ? '' : ': ' . $why) . PHP_EOL;
}

/**
 * Sign in as the user with this email, the way auth.php reads the session.
 * Limit: current_user() keeps a static cache that cannot be reset, so after
 * the first call to it the script keeps seeing that user. current_role(),
 * current_user_id() and has_role() follow act_as() correctly.
 *
 * @return array<string, mixed>
 */
function act_as(string $email): array
{
    $user = fetch_one(
        'SELECT u.*, r.name AS role_name FROM users u JOIN roles r ON r.role_id = u.role_id WHERE u.email = ?',
        [$email]
    );
    if ($user === null) {
        throw new RuntimeException('No such user: ' . $email);
    }
    $_SESSION['user_id']       = (int) $user['user_id'];
    $_SESSION['role']          = $user['role_name'];
    $_SESSION['last_activity'] = time();
    return $user;
}

db()->beginTransaction();
try {
    $andrea      = act_as('andrea.santos@student.buksu.edu.ph');
    $coordinator = act_as('bautista.coach@buksu.edu.ph');
    $staff       = act_as('rosales.office@buksu.edu.ph');
    // Coordinator runs activities 15 and 17, not 16.

    check('db columns', (bool) fetch_value("SHOW COLUMNS FROM users LIKE 'id_front_path'"));

    $aid   = (int) $andrea['user_id'];
    $other = fetch_one(
        "SELECT u.* FROM users u JOIN roles r ON r.role_id = u.role_id
         WHERE r.name = 'student' AND u.user_id <> ? ORDER BY u.user_id LIMIT 1",
        [$aid]
    );
    $itemId = (int) fetch_value('SELECT item_id FROM inventory_items ORDER BY item_id LIMIT 1');

    /** Insert a student request for Andrea with one line of quantity 2; returns its id. */
    $mk = function (int $activityId, string $status, int $studentRequest = 1) use ($aid, $itemId): int {
        query(
            'INSERT INTO reservations (activity_id, requested_by, purpose, needed_from, needed_until, status, student_request)
             VALUES (?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 2 DAY), ?, ?)',
            [$activityId, $aid, 'check', $status, $studentRequest]
        );
        $id = (int) db()->lastInsertId();
        query('INSERT INTO reservation_items (reservation_id, item_id, quantity) VALUES (?, ?, 2)', [$id, $itemId]);
        return $id;
    };
    $loan = function (int $resId, int $qty) use ($aid, $itemId, $staff): int {
        query(
            'INSERT INTO borrowings (reservation_id, item_id, borrower_id, quantity, released_by, released_at, expected_return_at)
             VALUES (?, ?, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 1 DAY))',
            [$resId, $itemId, $aid, $qty, (int) $staff['user_id']]
        );
        return (int) db()->lastInsertId();
    };

    // --- ID photo visibility ---
    $r16 = $mk(16, 'pending');
    act_as($coordinator['email']);
    check('coord cannot see ID: pending request is for activity 16', can_view_id_photos($aid) === false);
    query('UPDATE reservations SET activity_id = 15 WHERE reservation_id = ?', [$r16]);
    check('coord sees ID: pending request for activity 15', can_view_id_photos($aid) === true);
    query("UPDATE reservations SET status = 'approved' WHERE reservation_id = ?", [$r16]);
    check('coord cannot see ID once approved', can_view_id_photos($aid) === false);
    act_as($staff['email']);
    check('staff sees ID: approved, nothing collected', can_view_id_photos($aid) === true);
    $b = $loan($r16, 2);
    check('collected quantity is 2', reservation_collected_quantity($r16) === 2);
    check('staff sees ID: open loan', can_view_id_photos($aid) === true);
    query('UPDATE borrowings SET returned_at = NOW() WHERE borrowing_id = ?', [$b]);
    check('staff cannot see ID after return', can_view_id_photos($aid) === false);
    act_as($other['email']);
    check('other student cannot see ID', can_view_id_photos($aid) === false);
    act_as($andrea['email']);
    check('student sees own ID', can_view_id_photos($aid) === true);

    // --- helpers ---
    check('has_id_photos false when empty', has_id_photos(['id_front_path' => 'a', 'id_back_path' => '']) === false);
    check('has_id_photos true when both set', has_id_photos(['id_front_path' => 'a', 'id_back_path' => 'b']) === true);
    check('no open borrowing after return', student_has_open_borrowing($aid) === false);
    $rp = $mk(15, 'pending');
    check('pending request counts as open borrowing', student_has_open_borrowing($aid) === true);
    query("UPDATE reservations SET status = 'cancelled' WHERE reservation_id = ?", [$rp]);
    $enrolled = student_borrow_activities($aid);
    check('student_borrow_activities lists activity 15', in_array(15, array_map('intval', array_column($enrolled, 'activity_id')), true));

    // --- cancel / decide ---
    $rc = $mk(15, 'approved');
    act_as($andrea['email']);
    $resRow = fetch_one('SELECT * FROM reservations WHERE reservation_id = ?', [$rc]);
    check('requester may cancel approved uncollected', can_cancel_reservation($resRow) === true);
    $loan($rc, 1);
    check('cannot cancel once one borrowing exists', can_cancel_reservation($resRow) === false);
    act_as($coordinator['email']);
    check('coord may decide student request for own activity', can_decide_reservation($resRow) === true);
    $rn = $mk(15, 'pending', 0);
    $rnRow = fetch_one('SELECT * FROM reservations WHERE reservation_id = ?', [$rn]);
    check('coord cannot decide non-student request', can_decide_reservation($rnRow) === false);
    $r16Row = fetch_one('SELECT * FROM reservations WHERE reservation_id = ?', [$mk(16, 'pending')]);
    check('coord cannot decide request for unassigned activity', can_decide_reservation($r16Row) === false);
    act_as($staff['email']);
    check('office may decide any', can_decide_reservation($rnRow) === true);

    // --- partly released then returned stays open; pending is outside the office circle ---
    // Only the partial-collection rule can make this true: no open loan, no other request.
    query('UPDATE borrowings SET returned_at = NOW() WHERE borrower_id = ? AND returned_at IS NULL', [$aid]);
    query("UPDATE reservations SET status = 'cancelled' WHERE requested_by = ? AND status IN ('pending','approved')", [$aid]);
    check('baseline: nothing open', student_has_open_borrowing($aid) === false);
    $rpart = $mk(15, 'approved');
    $bp = $loan($rpart, 1);
    query('UPDATE borrowings SET returned_at = NOW() WHERE borrowing_id = ?', [$bp]);
    check('approved, partly released and returned: still open', student_has_open_borrowing($aid) === true);
    query("UPDATE reservations SET status = 'fulfilled' WHERE reservation_id = ?", [$rpart]);
    check('fulfilled: no longer open', student_has_open_borrowing($aid) === false);
    query('UPDATE borrowings SET returned_at = NOW() WHERE borrower_id = ? AND returned_at IS NULL', [$aid]);
    query("UPDATE reservations SET status = 'cancelled' WHERE requested_by = ?", [$aid]);
    $mk(15, 'pending');
    act_as($staff['email']);
    check('staff cannot see ID while only request is pending', can_view_id_photos($aid) === false);

    // --- bulk cancel ---
    query("UPDATE reservations SET status = 'cancelled' WHERE requested_by = ?", [$aid]);
    $p = $mk(15, 'pending');
    $a = $mk(15, 'approved');
    $f = $mk(15, 'fulfilled');
    check('cancel_open_student_requests returns 2', cancel_open_student_requests($aid, 15, 'x') === 2);
    check('fulfilled left alone', fetch_value('SELECT status FROM reservations WHERE reservation_id = ?', [$f]) === 'fulfilled');
    check('pending and approved now cancelled', fetch_value("SELECT COUNT(*) FROM reservations WHERE reservation_id IN (?, ?) AND status = 'cancelled'", [$p, $a]) == 2);

    // --- withdrawing a registration cancels the student's open requests ---
    query("UPDATE reservations SET status = 'cancelled' WHERE requested_by = ?", [$aid]);
    $rw = $mk(15, 'pending');
    $regId = (int) fetch_value('SELECT registration_id FROM registrations WHERE activity_id = 15 AND user_id = ?', [$aid]);
    query("UPDATE registrations SET status = 'pending' WHERE registration_id = ?", [$regId]);
    $notesBefore = (int) fetch_value('SELECT COUNT(*) FROM notifications WHERE user_id = ?', [$aid]);
    act_as($andrea['email']);
    withdraw_registration($regId);
    check('withdraw sets registration withdrawn', fetch_value('SELECT status FROM registrations WHERE registration_id = ?', [$regId]) === 'withdrawn');
    check('withdraw cancels the pending request', fetch_value('SELECT status FROM reservations WHERE reservation_id = ?', [$rw]) === 'cancelled');
    check('withdraw notifies with the withdrawn reason', (int) fetch_value(
        'SELECT COUNT(*) FROM notifications WHERE user_id = ? AND message = ?',
        [$aid, 'Your registration was withdrawn, so your equipment request was cancelled.']) >= 1
        && (int) fetch_value('SELECT COUNT(*) FROM notifications WHERE user_id = ?', [$aid]) === $notesBefore + 1);

    // --- replacing an ID photo removes the old file ---
    $idDir = UPLOAD_PATH . '/id-photos';
    is_dir($idDir) || mkdir($idDir, 0775, true);
    $tempFiles = [$idDir . '/check-old.jpg', $idDir . '/check-new.jpg'];
    file_put_contents($tempFiles[0], 'old');
    file_put_contents($tempFiles[1], 'new');
    query('UPDATE users SET id_front_path = ? WHERE user_id = ?', ['id-photos/check-old.jpg', $aid]);
    replace_id_photo($aid, 'front', 'id-photos/check-new.jpg');
    check('replace_id_photo removes the old file', !is_file($tempFiles[0]));
    check('replace_id_photo keeps the new file', is_file($tempFiles[1]));
    check('replace_id_photo saves the new path', fetch_value('SELECT id_front_path FROM users WHERE user_id = ?', [$aid]) === 'id-photos/check-new.jpg');
    try {
        replace_id_photo($aid, 'side', 'id-photos/check-new.jpg');
        check('replace_id_photo rejects a bad side', false, 'no exception');
    } catch (InvalidArgumentException $e) {
        check('replace_id_photo rejects a bad side', true);
    }
} catch (Throwable $e) {
    check('script ran', false, $e->getMessage());
} finally {
    foreach ($tempFiles as $file) {
        is_file($file) && unlink($file);
    }
    if (db()->inTransaction()) {
        db()->rollBack();
    }
}

exit($failures > 0 ? 1 : 0);
