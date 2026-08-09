<?php

declare(strict_types=1);

namespace SatusehatPanel\Core;

/**
 * Global error handling contract:
 *  - exceptions become clean JSON (never stack traces / __DIR__ leaks)
 *  - APP_DEBUG=true keeps the trace locally for diagnosis
 *  - PHP warnings/notices are logged, not rendered
 */
final class ErrorHandler
{
    private static bool $registered = false;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;

        error_reporting(E_ALL);
        ini_set('display_errors', '0');

        set_exception_handler(static function (\Throwable $e): void {
            error_log('[PANEL] Uncaught: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: application/json; charset=utf-8');
            }
            $body = [
                'success' => false,
                'error' => 'Internal server error',
            ];
            if (Config::get('app.debug', false)) {
                $body['debug'] = [
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 15),
                ];
            }
            echo json_encode($body);
            exit(1);
        });

        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false; // suppressed with @
            }
            $map = [
                E_ERROR => 'ERROR', E_WARNING => 'WARNING', E_PARSE => 'PARSE',
                E_NOTICE => 'NOTICE', E_USER_ERROR => 'ERROR', E_USER_WARNING => 'WARNING',
                E_USER_NOTICE => 'NOTICE', E_DEPRECATED => 'DEPRECATED',
            ];
            $level = $map[$severity] ?? 'ERROR';
            error_log("[PANEL][{$level}] {$message} @ {$file}:{$line}");
            return true;
        });
    }
}