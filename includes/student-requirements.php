<?php
/** Shared student requirement queries and persistence. Callers own transactions and file cleanup. */
declare(strict_types=1);

function student_requirement_items(int $activityId, int $registrationId = 0, bool $lock = false): array
{
    return fetch_all(
        'SELECT ar.*, rs.submission_id, rs.file_path, rs.original_name, rs.file_size,
                rs.status AS submission_status, rs.reject_reason, rs.submitted_at, rs.note
           FROM activity_requirements ar
           LEFT JOIN requirement_submissions rs
                  ON rs.requirement_id = ar.requirement_id AND rs.registration_id = ?
          WHERE ar.activity_id = ? ORDER BY ar.sort_order, ar.requirement_id' . ($lock ? ' FOR UPDATE' : ''),
        [$registrationId, $activityId]
    );
}

/** Return null on success; both the join form and later replacements use this validation. */
function student_requirement_error(array $item, ?array $file, bool $acknowledged, string $note): ?string
{
    if ($item['submission_status'] === 'verified') {
        return 'This requirement has already been verified and cannot be replaced.';
    }
    if (mb_strlen($note) > 500) {
        return 'Keep the note to 500 characters or fewer.';
    }
    if ((int) $item['needs_file'] === 1) {
        if ($file === null || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return 'Choose a file for this requirement.';
        }
    } elseif (!$acknowledged) {
        return 'Confirm that you have read this requirement.';
    }
    return null;
}

/** Save metadata only. The caller deletes replaced files after commit, never before it. */
function save_student_requirement(int $registrationId, array $item, ?array $stored, string $note): int
{
    $values = [$stored['path'] ?? null, $stored['original'] ?? null,
        $stored['mime'] ?? null, $stored['size'] ?? null, $note !== '' ? $note : null];
    if ($item['submission_id'] !== null) {
        query(
            "UPDATE requirement_submissions
                SET file_path = ?, original_name = ?, mime_type = ?, file_size = ?, note = ?,
                    status = 'pending', verified_by = NULL, verified_at = NULL,
                    reject_reason = NULL, submitted_at = NOW()
              WHERE submission_id = ?",
            [...$values, (int) $item['submission_id']]
        );
        return (int) $item['submission_id'];
    }
    query(
        'INSERT INTO requirement_submissions
            (file_path, original_name, mime_type, file_size, note, registration_id, requirement_id)
         VALUES (?, ?, ?, ?, ?, ?, ?)',
        [...$values, $registrationId, (int) $item['requirement_id']]
    );
    return (int) db()->lastInsertId();
}

/** PHP drops the entire POST body when post_max_size is exceeded. */
function student_upload_body_too_large(): bool
{
    $limit = php_ini_bytes((string) ini_get('post_max_size'));
    return $limit > 0 && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > $limit;
}

/** Normalize only the documented form fields; never echo arbitrary request values. */
function student_join_values(): array
{
    $values = [];
    foreach (['team_name', 'remarks'] as $key) {
        $values[$key] = is_string($_POST[$key] ?? null) ? trim($_POST[$key]) : '';
    }
    foreach (['notes', 'acknowledgements'] as $key) {
        $values[$key] = [];
        foreach (is_array($_POST[$key] ?? null) ? $_POST[$key] : [] as $id => $value) {
            if (ctype_digit((string) $id) && is_string($value)) {
                $values[$key][(int) $id] = trim($value);
            }
        }
    }
    return $values;
}

/** Register and submit together. A failure leaves neither DB rows nor newly stored files. */
function register_student_with_requirements(int $activityId, array $student, array $values): array
{
    $storedFiles = [];
    $replacedFiles = [];
    $errors = [];
    $saved = [];
    try {
        db()->beginTransaction();
        // Serialize repeat applications for this activity, then re-read all rules.
        $activity = fetch_one('SELECT * FROM activities WHERE activity_id = ? FOR UPDATE', [$activityId]);
        $check = $activity === null ? ['ok' => false, 'reason' => 'This activity no longer exists.']
                                   : can_register($activity, $student);
        if (!$check['ok']) {
            $errors['form'] = $check['reason'];
        }
        if (mb_strlen($values['team_name']) > 120 || mb_strlen($values['remarks']) > 500) {
            $errors['form'] = 'Keep the team name to 120 characters and remarks to 500 characters.';
        }
        if ((int) post('file_count') > (int) ini_get('max_file_uploads')) {
            $errors['form'] = 'Too many files in one submission. Remove optional files or contact the office for help.';
        }
        $previous = fetch_one('SELECT * FROM registrations WHERE activity_id = ? AND user_id = ? FOR UPDATE',
            [$activityId, $student['user_id']]);
        $items = student_requirement_items($activityId, (int) ($previous['registration_id'] ?? 0), true);
        $prepared = [];
        if ($errors === []) {
            foreach ($items as $item) {
                $id = (int) $item['requirement_id'];
                if ($item['submission_status'] === 'verified') {
                    continue;
                }
                $file = null;
                if (isset($_FILES['documents']['error'][$id]) && is_scalar($_FILES['documents']['error'][$id])) {
                    $file = [];
                    foreach (['name', 'type', 'tmp_name', 'error', 'size'] as $part) {
                        $value = $_FILES['documents'][$part][$id] ?? null;
                        $file[$part] = is_scalar($value) ? $value : '';
                    }
                }
                $hasFile = $file !== null && (int) $file['error'] !== UPLOAD_ERR_NO_FILE;
                $ack = ($values['acknowledgements'][$id] ?? '') === '1';
                if (!(int) $item['is_mandatory'] && !$hasFile && !$ack) {
                    continue;
                }
                $note = $values['notes'][$id] ?? '';
                $error = student_requirement_error($item, $file, $ack, $note);
                if ($error !== null) {
                    $errors[$id] = $item['name'] . ': ' . $error;
                    continue;
                }
                $stored = null;
                if ((int) $item['needs_file'] === 1) {
                    $stored = store_upload($file, 'requirements');
                    if (!$stored['ok']) {
                        $errors[$id] = $item['name'] . ': ' . $stored['error'];
                        continue;
                    }
                    $storedFiles[] = $stored['path'];
                }
                $prepared[] = [$item, $stored, $note];
            }
        }
        if ($errors !== []) {
            db()->rollBack();
            foreach ($storedFiles as $path) { delete_upload($path); }
            return ['ok' => false, 'errors' => $errors];
        }
        if ($previous !== null) {
            $registrationId = (int) $previous['registration_id'];
            query("UPDATE registrations SET status = 'pending', team_name = ?, remarks = ?,
                    reviewed_by = NULL, reviewed_at = NULL, review_remarks = NULL, attended = 0,
                    registered_at = NOW() WHERE registration_id = ?",
                [$values['team_name'] ?: null, $values['remarks'] ?: null, $registrationId]);
        } else {
            query('INSERT INTO registrations (activity_id, user_id, team_name, remarks) VALUES (?, ?, ?, ?)',
                [$activityId, $student['user_id'], $values['team_name'] ?: null, $values['remarks'] ?: null]);
            $registrationId = (int) db()->lastInsertId();
        }
        foreach ($prepared as [$item, $stored, $note]) {
            $saved[] = save_student_requirement($registrationId, $item, $stored, $note);
            if ($item['file_path']) { $replacedFiles[] = $item['file_path']; }
        }
        db()->commit();
    } catch (Throwable $error) {
        if (db()->inTransaction()) { db()->rollBack(); }
        foreach ($storedFiles as $path) { delete_upload($path); }
        error_log('[CASMS] Registration submission failed: ' . $error->getMessage());
        return ['ok' => false, 'errors' => ['form' => 'Your registration could not be saved. Please try again.']];
    }
    foreach ($replacedFiles as $path) { delete_upload($path); }
    audit_log('create', 'registration', $registrationId, 'Registered with requirements for: ' . $activity['title']);
    foreach ($saved as $submissionId) {
        audit_log('create', 'requirement_submission', $submissionId, 'Submitted with registration for: ' . $activity['title']);
    }
    return ['ok' => true, 'registration_id' => $registrationId, 'submitted' => count($saved)];
}
