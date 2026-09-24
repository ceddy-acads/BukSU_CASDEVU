<?php
/**
 * CASMS — Global error and exception handling (NFR-4, security checklist)
 *
 * Any uncaught throwable is logged with its full detail and shown to the user
 * as a plain apology. Before this existed, an unexpected database error
 * printed its SQL and file paths straight to the browser.
 */

declare(strict_types=1);

/** Has an error page already been sent? Prevents a second one on shutdown. */
function error_page_sent(?bool $set = null): bool
{
    static $sent = false;
    if ($set !== null) {
        $sent = $set;
    }
    return $sent;
}

/**
 * Render the failure page. In development the detail is shown; in production
 * the user gets a reference code and the detail goes to the error log only.
 */
function render_error_page(string $detail, string $reference): void
{
    if (error_page_sent()) {
        return;
    }
    error_page_sent(true);

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=UTF-8');
    }

    $safeDetail    = htmlspecialchars($detail, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeReference = htmlspecialchars($reference, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $baseUrl       = defined('BASE_URL') ? BASE_URL : '';

    if (defined('APP_DEBUG') && APP_DEBUG) {
        $body = '<div class="alert alert-error preline" style="word-break:break-word;">' . $safeDetail . '</div>'
              . '<p class="hint">This detail is shown because the system is running in development mode.</p>';
    } else {
        $body = '<div class="alert alert-error">Please try again. If it keeps happening, '
              . 'report this reference to the office: <strong>' . $safeReference . '</strong></div>';
    }

    echo status_page_html(
        'Something went wrong',
        'The system could not complete that request.',
        $body,
        '<a class="btn btn-primary btn-block" href="' . $baseUrl . '/index.php">Back to the dashboard</a>'
    );
}

/**
 * The shared frame for full-page status screens (errors, access denied,
 * not found, expired session). Self-contained: it only needs BASE_URL, so
 * it still renders when a failure happens before the helpers are loaded.
 */
function status_page_html(string $title, string $lead, string $bodyHtml, string $actionsHtml): string
{
    $baseUrl   = defined('BASE_URL') ? BASE_URL : '';
    $appName   = defined('APP_NAME') ? APP_NAME : 'CASDevU';
    $tagline   = defined('APP_TAGLINE') ? APP_TAGLINE : '';
    $cssFile   = dirname(__DIR__) . '/public/assets/css/style.css';
    $cssUrl    = $baseUrl . '/assets/css/style.css' . (is_file($cssFile) ? '?v=' . filemtime($cssFile) : '');
    $safe      = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    return '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
         . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
         . '<meta name="theme-color" content="#10284d">'
         . '<title>' . $safe($title) . ' · ' . $safe($appName) . '</title>'
         . '<link rel="stylesheet" href="' . $safe($cssUrl) . '"></head><body>'
         . '<main class="auth-wrap"><div><div class="auth-card">'
         . '<header class="auth-head"><span class="brand-mark">' . $safe($appName) . '</span>'
         . '<span class="brand-sub">' . $safe($tagline) . '</span>'
         . '<h1>' . $safe($title) . '</h1><p>' . $safe($lead) . '</p></header>'
         . $bodyHtml
         . '<div class="form-actions">' . $actionsHtml . '</div>'
         . '</div></div></main></body></html>';
}

/**
 * Stop the request with a designed status page instead of bare text.
 * Used for access denied (403), not found (404), and expired forms (419).
 */
function abort_page(int $code, string $message): never
{
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: text/html; charset=UTF-8');
    }

    [$title, $lead] = match ($code) {
        403     => ['You do not have access', 'Your account is not allowed to open this page.'],
        404     => ['Not found', 'The page or record you asked for does not exist.'],
        419     => ['Your session expired', 'The form could not be verified, so nothing was saved.'],
        default => ['Something went wrong', 'The system could not complete that request.'],
    };

    $baseUrl  = defined('BASE_URL') ? BASE_URL : '';
    $signedIn = function_exists('is_logged_in') && is_logged_in();
    $body     = '<div class="alert alert-' . ($code === 404 ? 'info' : 'warning') . '">'
              . htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>';

    $actions  = $signedIn
        ? '<a class="btn btn-primary" href="' . $baseUrl . '/index.php">Back to the dashboard</a>'
        : '<a class="btn btn-primary" href="' . $baseUrl . '/login.php">Sign in</a>';
    if ($code === 419 || $code === 404) {
        $actions .= '<a class="btn btn-outline" href="javascript:history.back()">Go back</a>';
    }

    echo status_page_html($title, $lead, $body, $actions);
    exit;
}

/**
 * Install the handlers. Called once from bootstrap.php, before any work that
 * could fail.
 */
function register_error_handlers(): void
{
    // A short code the user can quote and we can grep for in the log.
    $reference = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));

    set_exception_handler(static function (Throwable $e) use ($reference): void {
        error_log(sprintf(
            '[CASMS][%s] Uncaught %s: %s in %s:%d%s%s',
            $reference, get_class($e), $e->getMessage(),
            $e->getFile(), $e->getLine(), PHP_EOL, $e->getTraceAsString()
        ));

        render_error_page(
            get_class($e) . ': ' . $e->getMessage()
            . "\n" . $e->getFile() . ':' . $e->getLine(),
            $reference
        );
        exit(1);
    });

    // Promote warnings and notices to exceptions so they surface in one place
    // rather than printing halfway down a half-rendered page.
    set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
        // Respect any @-suppression and the configured error_reporting level.
        if ((error_reporting() & $severity) === 0) {
            return false;
        }
        throw new ErrorException($message, 0, $severity, $file, $line);
    });

    // Fatal errors bypass both handlers above; catch them on the way out.
    register_shutdown_function(static function () use ($reference): void {
        $last = error_get_last();
        if ($last === null
            || !in_array($last['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            return;
        }

        error_log(sprintf(
            '[CASMS][%s] Fatal: %s in %s:%d',
            $reference, $last['message'], $last['file'], $last['line']
        ));

        render_error_page(
            $last['message'] . "\n" . $last['file'] . ':' . $last['line'],
            $reference
        );
    });
}
