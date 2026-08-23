<?php
declare(strict_types=1);

/*
 * FrankenPHP worker-mode bootstrap — EXPERIMENTAL.
 *
 * The framework's index.php was written for one-process-per-request SAPIs:
 * it defines CSP_NONCE as a PHP constant once per request. A constant can
 * only be defined once per OS process, so in a long-lived worker it would
 * stay frozen after the first request. Rather than ship a header that lies
 * about the nonce, this worker deliberately skips the CSP header and only
 * re-runs the actual route dispatch (system/run.php) per request.
 *
 * Consequence: only used safely with stateless JSON API routes (this
 * benchmark only exercises GET /api/health). HTML views that rely on the
 * CSP nonce for inline <script>/<style> are NOT validated under worker mode.
 * See documentation/tutorials/benchmark.md for the full caveat.
 */

if (PHP_VERSION_ID < 80200) {
    http_response_code(500);
    exit('PHP 8.2 atau lebih baru diperlukan. Versi aktif: ' . PHP_VERSION);
}

define('ROOTPATH', __DIR__ . DIRECTORY_SEPARATOR);
define('BASEPATH', ROOTPATH);
// Built-in views (e.g. app/Views/errors/error_404.php) reference CSP_NONCE
// directly; leaving it undefined fatals the worker on any 404. Since this
// bootstrap deliberately does not send a CSP header (see file docblock), a
// fixed empty value is safe: no header means no nonce to match anyway.
define('CSP_NONCE', '');

require_once ROOTPATH . 'system/Config/Config.php';
require_once ROOTPATH . 'app/Config/Config.php';
require_once ROOTPATH . 'app/Config/Constants.php';
require_once ROOTPATH . 'system/Core/Route.php';
require_once ROOTPATH . 'system/Core/RouteGroup.php';
require_once ROOTPATH . 'system/Core/Middleware.php';
require_once ROOTPATH . 'system/Core/Request.php';
require_once ROOTPATH . 'system/Core/Response.php';

// system/run.php reads $routes from its own top-level scope, populated by a
// plain (non-namespaced-array) variable assignment in app/Config/Routes.php.
// require_once only runs that assignment on the very first request in this
// process; $routes itself is static config (not per-request), so it is safe
// to capture once here and re-inject it into run.php's scope every request.
require_once ROOTPATH . 'app/Config/Routes.php';
$bootRoutes = $routes;

// system/Database.php reads $dbconfig via `global $dbconfig;` inside its
// constructor — that always binds to the real $GLOBALS, not whatever local
// scope happened to `require` this file. Since the request handler below is
// a closure, a plain `$dbconfig = ...;` assignment inside it would only be
// a closure-local variable and Database would never see it (unlike $routes
// above, which run.php reads as a plain local, not via `global`). Capture
// dbconfig once here and explicitly repopulate $GLOBALS every request.
require_once ROOTPATH . 'app/Config/Database.php';
$bootDbConfig = $dbconfig;

ignore_user_abort(true);

$handler = static function () use ($bootRoutes, $bootDbConfig): void {
    $routes = $bootRoutes;
    global $dbconfig;
    $dbconfig = $bootDbConfig;

    // FrankenPHP resets $_SERVER/$_GET/$_POST/php://input for the current
    // request before invoking this closure.
    if (!headers_sent()) {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Cross-Origin-Opener-Policy: same-origin');
        header('Cross-Origin-Resource-Policy: same-origin');
        header('X-Permitted-Cross-Domain-Policies: none');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }

    // Plain require (not require_once): the file's own top-level
    // require_once calls only load classes/config once, but the
    // `new \System\App(...)` dispatch at the bottom of run.php must execute
    // fresh for every request.
    require ROOTPATH . 'system/run.php';
};

$maxRequests = (int) (getenv('FRANKENPHP_MAX_REQUESTS') ?: 0);
for ($count = 0; $maxRequests <= 0 || $count < $maxRequests; $count++) {
    $keepRunning = \frankenphp_handle_request($handler);
    gc_collect_cycles();
    if (!$keepRunning) {
        break;
    }
}
