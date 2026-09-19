<?php
/**
 * CASMS — CSRF protection (NFR-3)
 *
 * Every state-changing form carries a token; every POST handler verifies it
 * before touching the database.
 */

declare(strict_types=1);

/** Return the session's CSRF token, generating one on first use. */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Hidden input to drop inside every <form method="post">. */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/**
 * Verify the submitted token. Rejects the request outright on mismatch —
 * a failed check means the request did not originate from our form.
 */
function csrf_verify(): void
{
    $submitted = (string) ($_POST['csrf_token'] ?? '');

    // hash_equals: constant-time comparison, not vulnerable to timing analysis.
    if ($submitted === '' || !hash_equals($_SESSION['csrf_token'] ?? '', $submitted)) {
        http_response_code(419);
        exit('419 — Your session expired or the request could not be verified. Please go back and try again.');
    }
}
