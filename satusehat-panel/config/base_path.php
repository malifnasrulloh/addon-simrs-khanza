<?php
/**
 * Sub-folder base-path auto-detection.
 *
 * The panel works deployed at the web root (DocumentRoot -> public/)
 * OR inside a sub-folder (e.g. 192.168.1.10/satusehat-panel/).
 * Returns just the sub-folder prefix ('' at root, '/satusehat-panel'
 * in a sub-folder) computed from the SCRIPT_NAME — the same trick
 * Sistem-Informasi-Operasi uses for its sub-directory deployment.
 *
 * root:            SCRIPT_NAME = /index.php          -> ''
 * subdir:          SCRIPT_NAME = /satusehat-panel/index.php -> /satusehat-panel
 */
function panel_base_path(): string
{
    // Manual override — check .env first, then process env. Use only if
    // auto-detection needs fixing behind a proxy/rewrite.
    $override = '';
    $envFile = __DIR__ . '/../.env';
    $envLines = is_file($envFile) ? @file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : false;
    if (is_array($envLines)) {
        foreach ($envLines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
            [$k, $v] = explode('=', $line, 2);
            if (trim($k) === 'APP_BASE_PATH') { $override = trim($v); break; }
        }
    }
    if ($override === '') {
        $override = (string) (getenv('APP_BASE_PATH') ?: '');
    }
    if ($override !== '') {
        return '/' . trim($override, '/');
    }

    $script = $_SERVER['SCRIPT_NAME'] ?? '/index.php';

    // Only trust dirname(SCRIPT_NAME) when SCRIPT_NAME points at a real
    // script entry (index.php) — i.e. an Apache/Alias sub-folder like
    // /satusehat-panel/index.php. Under `php -S` the SCRIPT_NAME is the
    // REQUEST path (/js/app.js, /api/auth/login), which would give bogus
    // bases (/js, /api/auth) — reject those.
    $scriptName = basename($script);
    if (!in_array($scriptName, ['index.php', 'router.php', 'shell.php', 'index.html'], true)) {
        return '';
    }

    $dir = dirname($script);
    if ($dir === '.' || $dir === '/' || $dir === '\\' || $dir === '') {
        return '';
    }
    return '/' . trim($dir, '/');
}