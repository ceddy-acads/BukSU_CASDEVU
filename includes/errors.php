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

    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
       . '<title>Something went wrong — CASMS</title>'
       . '<link rel="stylesheet" href="' . $baseUrl . '/assets/css/style.css"></head><body>'
       . '<div class="auth-wrap"><div class="auth-card">'
       . '<div class="auth-head"><h1>Something went wrong</h1>'
       . '<p>The system could not complete that request.</p></div>';

    if (defined('APP_DEBUG') && APP_DEBUG) {
        echo '<div class="alert alert-error" style="white-space:pre-wrap;word-break:break-word;">'
           . $safeDetail . '</div>'
           . '<p class="hint">This detail is shown because the system is running '
           . 'in development mode.</p>';
    } else {
        echo '<div class="alert alert-error">Please try again. If it keeps happening, '
           . 'report this reference to the office: <strong>' . $safeReference . '</strong></div>';
    }

    echo '<a class="btn btn-primary btn-block" href="' . $baseUrl . '/index.php">Back to the dashboard</a>'
       . '</div></div></body></html>';
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
