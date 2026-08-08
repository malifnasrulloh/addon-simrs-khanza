<?php
/**
 * SATUSEHAT Admin Panel — drop-in entry point.
 *
 * Paste the satusehat-panel/ folder into your Khanza webroot and open
 * http://ip/satusehat-panel/ — no server config needed. This file is a
 * real PHP script, so any web server that already runs PHP (Apache,
 * Nginx+FPM, PHP built-in) executes it directly.
 *
 * Routing (no rewrites):
 *   /satusehat-panel/                 -> serves the SPA shell (index.html)
 *   /satusehat-panel/index.php?r=...  -> API (front controller)
 *   /satusehat-panel/api/...          -> API (front controller, for servers
 *                                        that pass the path through)
 *   /satusehat-panel/js|css|public/.. -> static (served by the web server)
 */

declare(strict_types=1);

define('PANEL_BASE', __DIR__);
define('PANEL_SRC', PANEL_BASE . '/src');
define('PANEL_PUBLIC', PANEL_BASE . '/public');

// ── Simple PSR-4 autoloader (namespaced panel classes) ─────────────
spl_autoload_register(function (string $class): void {
    if (!str_starts_with($class, 'SatusehatPanel\\')) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen('SatusehatPanel\\')));
    $file = PANEL_SRC . '/' . $relative . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

// ── Adopted SATUSEHAT logic (global namespace, kept as-is) ─────────
require_once PANEL_SRC . '/Util/SatuSehatClient.php';
require_once PANEL_SRC . '/Util/PayloadBuilder.php';
require_once PANEL_SRC . '/Util/AllergyDictionary.php';
require_once PANEL_SRC . '/Util/ObservationTTVDictionary.php';
require_once PANEL_SRC . '/Util/EpisodeOfCareType.php';
require_once PANEL_SRC . '/Util/Logger.php';
require_once PANEL_SRC . '/Util/CredentialLocator.php';

use SatusehatPanel\Core\Router;
use SatusehatPanel\Core\Auth;

// ── Determine the API path (from ?r= or the REQUEST_URI suffix) ────
$apiPath = '';
if (isset($_GET['r']) && is_string($_GET['r'])) {
    $apiPath = '/' . ltrim($_GET['r'], '/');
} else {
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    // Strip the folder prefix + leading /api so we get /api/... as-is
    if (preg_match('#/(?:api/.*)$#', $uri, $m)) {
        $apiPath = '/' . ltrim($m[1], '/');
    }
}

// ── Serve the SPA shell for non-API requests ──────────────────────
if ($apiPath === '' || !str_starts_with($apiPath, '/api/')) {
    $shell = file_get_contents(PANEL_PUBLIC . '/index.html');
    if ($shell === false) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Shell not found';
        return;
    }
    // Drop-in mode: BASE = the folder path (e.g. /satusehat-panel) so the
    // JS fetches API via /satusehat-panel/index.php?r=/api/... and assets
    // resolve relative to public/. Compute it from this script's own URL.
    $selfPath = str_replace('\\', '/', dirname((string) parse_url($_SERVER['SCRIPT_NAME'] ?? '/index.php', PHP_URL_PATH)));
    $base = ($selfPath === '.' || $selfPath === '/') ? '' : rtrim($selfPath, '/');
    $shell = str_replace('{{BASE_PATH}}', $base, $shell);
    $shell = str_replace('href="css/', 'href="public/css/', $shell);
    $shell = str_replace('src="js/', 'src="public/js/', $shell);
    header('Content-Type: text/html; charset=utf-8');
    echo $shell;
    return;
}

// ── API: session + auth gate (public: login/status) ───────────────
Auth::start();

$publicPaths = ['/api/auth/login', '/api/auth/status'];
$isPublic = in_array($apiPath, $publicPaths, true);
if (!$isPublic && !Auth::check()) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'Unauthorized', 'auth_required' => true]);
    return;
}

// ── Routes ──────────────────────────────────────────────────────────
$router = new Router();
// In drop-in mode the request path arriving at this script is the
// ?r= value (or the /api suffix), already root-relative — no base strip.
$router->setRequestUri($apiPath);

// Auth endpoints
$router->add('POST', '/api/auth/login', function () {
    return SatusehatPanel\Controller\AuthController::login();
});
$router->add('POST', '/api/auth/logout', function () {
    return SatusehatPanel\Controller\AuthController::logout();
});
$router->add('GET', '/api/auth/status', function () {
    return SatusehatPanel\Controller\AuthController::status();
});

// API: patient list
$router->add('GET', '/api/patients', function () {
    return SatusehatPanel\Controller\PatientController::list();
});

// API: resource payload preview (MOST SPECIFIC first)
$router->add('GET', '/api/patients/{noRawat:any}/resources/{resource}', function (array $params) {
    return SatusehatPanel\Controller\ResourceController::preview($params['noRawat'], $params['resource']);
});

// API: send selected resources via Bundle
$router->add('POST', '/api/patients/{noRawat:any}/send', function (array $params) {
    return SatusehatPanel\Controller\SendController::sendBundle($params['noRawat']);
});

// API: patient detail
$router->add('GET', '/api/patients/{noRawat:any}', function (array $params) {
    return SatusehatPanel\Controller\PatientController::detail($params['noRawat']);
});

// API: audit log
$router->add('GET', '/api/audit', function () {
    return SatusehatPanel\Controller\AuditController::list();
});

// API: settings
$router->add('GET', '/api/settings', function () {
    return SatusehatPanel\Controller\SettingsController::get();
});
$router->add('POST', '/api/settings', function () {
    return SatusehatPanel\Controller\SettingsController::save();
});

$router->dispatch();
