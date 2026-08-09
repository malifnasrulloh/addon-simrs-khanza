<?php
/**
 * SATUSEHAT Admin Panel - Front Controller
 *
 * All requests route through this file.
 */

declare(strict_types=1);

// Absolute paths (independent of CLI project)
define('PANEL_BASE', dirname(__DIR__));
define('PANEL_SRC', PANEL_BASE . '/src');

// Sub-folder base path — '' at docroot, '/satusehat-panel' inside a sub-folder.
require_once PANEL_BASE . '/config/base_path.php';
define('PANEL_BASE_PATH', panel_base_path());

// ── Autoloader ─────────────────────────────────────────────────────
// Prefer composer (dev/CI: vendor/autoload.php); fall back to the legacy
// loader so drop-in deployments without vendor/ keep working unchanged.
if (is_file(PANEL_BASE . '/vendor/autoload.php')) {
    require_once PANEL_BASE . '/vendor/autoload.php';
} else {
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
    require_once PANEL_SRC . '/Util/SatuSehatConfig.php';
    require_once PANEL_SRC . '/Util/DateTimeUtil.php';
    require_once PANEL_SRC . '/Util/NumberUtil.php';
}

use SatusehatPanel\Core\Router;
use SatusehatPanel\Core\Auth;
use SatusehatPanel\Core\ErrorHandler;

ErrorHandler::register();

// ── Auth: session start + protect all /api/* except auth endpoints ──
Auth::start();

// Determine incoming request path (supports clean URLs and ?r=/api/... parameters)
if (isset($_GET['r']) && is_string($_GET['r']) && $_GET['r'] !== '') {
    $reqPath = '/' . ltrim($_GET['r'], '/');
    $qPos = strpos($reqPath, '?');
    if ($qPos !== false) {
        $queryString = substr($reqPath, $qPos + 1);
        parse_str($queryString, $extraGet);
        foreach ($extraGet as $k => $v) {
            if (!isset($_GET[$k])) {
                $_GET[$k] = $v;
            }
        }
    }
} else {
    $reqPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    if (PANEL_BASE_PATH !== '' && str_starts_with($reqPath, PANEL_BASE_PATH)) {
        $reqPath = substr($reqPath, strlen(PANEL_BASE_PATH));
    }
}
if (str_ends_with($reqPath, '/index.php') || $reqPath === '/index.php') {
    $reqPath = '/';
}

$cleanPath = $reqPath;
$qPos = strpos($cleanPath, '?');
if ($qPos !== false) {
    $cleanPath = substr($cleanPath, 0, $qPos);
}

// Serve SPA index.html for non-API requests (e.g. /, /index.php, /index.html)
if (!str_starts_with($cleanPath, '/api/')) {
    $htmlFile = PANEL_BASE . '/public/index.html';
    if (is_file($htmlFile)) {
        header('Content-Type: text/html; charset=utf-8');
        $content = file_get_contents($htmlFile);
        echo str_replace('{{BASE_PATH}}', PANEL_BASE_PATH, $content);
        exit;
    }
}

$publicPaths = ['/api/auth/login', '/api/auth/status'];
$isPublic = in_array($cleanPath, $publicPaths, true);
if (!$isPublic && str_starts_with($cleanPath, '/api/')) {
    if (!Auth::check()) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'Unauthorized', 'auth_required' => true]);
        exit;
    }
}

// ── Routes ──────────────────────────────────────────────────────────
$router = new Router();
if (PANEL_BASE_PATH !== '') {
    $router->setBasePath(PANEL_BASE_PATH);
}
$router->setRequestUri($reqPath);

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

// API: patient detail with available resources
$router->add('GET', '/api/patients/{noRawat:any}', function (array $params) {
    return SatusehatPanel\Controller\PatientController::detail($params['noRawat']);
});

// API: audit log
$router->add('GET', '/api/audit/{id}', function (array $params) {
    return SatusehatPanel\Controller\AuditController::detail((int) $params['id']);
});
$router->add('GET', '/api/audit/stats', function () {
    return SatusehatPanel\Controller\AuditController::stats();
});
$router->add('GET', '/api/audit/export', function () {
    return SatusehatPanel\Controller\AuditController::export();
});
$router->add('GET', '/api/audit', function () {
    return SatusehatPanel\Controller\AuditController::list();
});

// API: Satu Sehat credential settings
$router->add('GET', '/api/settings', function () {
    return SatusehatPanel\Controller\SettingsController::get();
});
$router->add('POST', '/api/settings', function () {
    return SatusehatPanel\Controller\SettingsController::save();
});

$router->dispatch();
