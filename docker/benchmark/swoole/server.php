<?php
declare(strict_types=1);

/*
 * Swoole HTTP server bootstrap — EXPERIMENTAL.
 *
 * Like FrankenPHP worker mode, this keeps the PHP process alive across
 * requests, so the framework's index.php (which defines CSP_NONCE as a
 * constant once per process) cannot be reused as-is. This bootstrap skips
 * the CSP header for the same reason documented in
 * docker/benchmark/frankenphp-worker/worker.php.
 *
 * Known limitation: Swoole's HTTP server does not populate php://input, so
 * Request::json() (used by POST /api/echo) will read an empty body. Only
 * GET /api/health (no body, no session) is validated for this runtime — see
 * documentation/tutorials/benchmark.md.
 */

if (PHP_VERSION_ID < 80200) {
    fwrite(STDERR, 'PHP 8.2 atau lebih baru diperlukan. Versi aktif: ' . PHP_VERSION . PHP_EOL);
    exit(1);
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
// plain variable assignment in app/Config/Routes.php. require_once only
// runs that assignment once per process; $routes is static config (not
// per-request), so it is safe to capture here (before Swoole forks workers)
// and re-inject it into run.php's scope on every request.
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

$server = new Swoole\Http\Server('0.0.0.0', 80);
$server->set([
    'worker_num' => (int) (getenv('SWOOLE_WORKERS') ?: 4),
]);

$server->on('request', static function (Swoole\Http\Request $request, Swoole\Http\Response $response) use ($bootRoutes, $bootDbConfig): void {
    $routes = $bootRoutes;
    global $dbconfig;
    $dbconfig = $bootDbConfig;
    header_remove();

    $_GET = $request->get ?? [];
    $_POST = $request->post ?? [];
    $_COOKIE = $request->cookie ?? [];

    $_SERVER = [];
    foreach ($request->server ?? [] as $key => $value) {
        $_SERVER[strtoupper((string) $key)] = $value;
    }
    foreach ($request->header ?? [] as $key => $value) {
        $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', (string) $key))] = $value;
    }

    ob_start();
    if (!headers_sent()) {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Cross-Origin-Opener-Policy: same-origin');
        header('Cross-Origin-Resource-Policy: same-origin');
        header('X-Permitted-Cross-Domain-Policies: none');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    }

    // Plain require: only the dispatch (new \System\App(...)) at the bottom
    // of run.php needs to run fresh; the require_once calls inside it load
    // classes/config once and are safe to reuse across requests.
    require ROOTPATH . 'system/run.php';

    $body = ob_get_clean();

    foreach (headers_list() as $headerLine) {
        $parts = explode(':', $headerLine, 2);
        $name = trim($parts[0]);
        $value = trim($parts[1] ?? '');
        if ($name !== '') {
            $response->header($name, $value);
        }
    }

    $response->status(http_response_code() ?: 200);
    $response->end($body === false ? '' : $body);
});

$server->start();
