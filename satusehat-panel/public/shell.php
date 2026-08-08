<?php
/**
 * SPA shell renderer — serves index.html with the sub-folder base path
 * injected into the {{BASE_PATH}} placeholder (boot-time PANEL_BASE).
 *
 * Used by BOTH execution paths so the shell carries the correct prefix:
 *   - dev:  router.php falls back here for the SPA shell
 *   - prod: .htaccess routes non-API, non-static requests to this file
 *
 * Returns the rendered HTML. Intentionally lightweight:
 * it only substitutes the base-path token and passes everything else
 * through untouched (keeps the shell a pure static template).
 */

require_once __DIR__ . '/../config/base_path.php';

$html = file_get_contents(__DIR__ . '/index.html');
if ($html === false) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Shell not found';
    return true;
}

$base = panel_base_path();
$html = str_replace('{{BASE_PATH}}', $base, $html);

// Add a <base href> so any absolute-looking asset URLs (icon, etc.)
// resolve under the sub-folder too. Inserted right after <head>.
if ($base !== '') {
    $baseTag = '<base href="' . htmlspecialchars(rtrim($base, '/') . '/', ENT_QUOTES, 'UTF-8') . '">';
    $html = preg_replace('#<head>#', '<head>' . $baseTag, $html, 1);
}

header('Content-Type: text/html; charset=utf-8');
echo $html;
return true;