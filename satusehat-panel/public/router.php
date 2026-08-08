<?php
/**
 * Static file router for PHP built-in DEV server only.
 * NOT for production — use Apache/Nginx with .htaccess (see README).
 */

// ── Dev cache busting: never let the browser hold a stale copy ──
// (PHP -S + browser caches caused an old app.js to keep running during
//  development; nuke caching for the shell and static assets.)
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// ── Path normalization: reject traversal before touching the filesystem ──
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = rawurldecode($path);

// Sub-folder support: strip the auto-detected base path so static/API
// checks operate on the docroot-relative path (production .htaccess
// already works relative to the alias; this makes `php -S` behave the
// same when running the panel inside a sub-directory).
require_once __DIR__ . '/../config/base_path.php';
$basePath = panel_base_path();

// Under `php -S` with a router, SCRIPT_NAME is the request path (not the
// index), so panel_base_path() can't see the sub-folder. Infer it from
// the router's own location relative to DOCUMENT_ROOT instead.
if ($basePath === '' && !empty($_SERVER['DOCUMENT_ROOT'])) {
    $routerPath = realpath(__DIR__);
    $docRoot    = realpath($_SERVER['DOCUMENT_ROOT']);
    if ($routerPath !== false && $docRoot !== false && str_starts_with($routerPath, $docRoot)) {
        $rel = substr($routerPath, strlen($docRoot));
        $rel = str_replace('\\', '/', $rel);
        if ($rel !== '' && $rel !== '/') {
            $basePath = '/' . trim($rel, '/');
        }
    }
}

if ($basePath !== '' && $basePath !== '/' && str_starts_with($path, $basePath)) {
    $path = substr($path, strlen($basePath));
    if ($path === '' || $path === false) {
        $path = '/';
    }
}

// Reject any '..' segment (path traversal guard)
if (preg_match('#(?:^|/)\.\.(?:/|$)#', $path)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Forbidden';
    return true;
}

// Reject dotfiles / sensitive names
if (preg_match('#(?:^|/)(\.env|\.git|config|src|storage)(?:/|$)#i', $path)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Forbidden';
    return true;
}

// Front-controller alias (mirrors production): /index.php?r=/api/...
// The SPA fetches APIs via index.php?r= in every deployment mode;
// rewrite REQUEST_URI to the ?r= value so the public/auth checks and
// router dispatch behave exactly like the drop-in root index.php.
if ($path === '/index.php' && isset($_GET['r']) && is_string($_GET['r'])) {
    $apiPath = '/' . ltrim($_GET['r'], '/');
    if ($apiPath !== '' && str_starts_with($apiPath, '/api/')) {
        $query = (string) ($_SERVER['QUERY_STRING'] ?? '');
        $_SERVER['REQUEST_URI'] = $apiPath . ($query !== '' ? '?' . $query : '');
        require __DIR__ . '/index.php';
        return true;
    }
}

// Serve existing static files directly (css/js/images only reachable here)
$file = __DIR__ . $path;
if ($path !== '/' && is_file($file)) {
    // Only serve whitelisted static extensions from public/
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    if (in_array($ext, ['css', 'js', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'ico', 'woff', 'woff2', 'ttf'], true)) {
        // Serve through the router so the no-store header applies
        // (otherwise PHP's built-in server caches the file content and
        //  the browser can keep running a stale app.js after an edit)
        $mime = [
            'css' => 'text/css', 'js' => 'application/javascript',
            'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif', 'svg' => 'image/svg+xml', 'ico' => 'image/x-icon',
            'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf',
        ][$ext] ?? 'application/octet-stream';
        header('Content-Type: ' . $mime);
        readfile($file);
        return true;
    }
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Forbidden';
    return true;
}

// API routes go to index.php
if (str_starts_with($path, '/api/')) {
    require __DIR__ . '/index.php';
    return true;
}

// Everything else serves the SPA shell (through shell.php so the
// sub-folder base path is injected into {{BASE_PATH}})
require __DIR__ . '/shell.php';
return true;
