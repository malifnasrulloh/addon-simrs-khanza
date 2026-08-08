<?php

namespace SatusehatPanel\Core;

/**
 * Simple session-based authentication for the admin panel.
 *
 * Uses a shared secret from .env (PANEL_AUTH_PASSWORD). Session cookie
 * is HttpOnly + SameSite=Lax; session id is regenerated on login.
 */
class Auth
{
    public const SESSION_KEY = 'panel_authed';

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_set_cookie_params([
                'httponly' => true,
                'samesite' => 'Lax',
                'secure'   => !empty($_SERVER['HTTPS']),
            ]);
            session_start();
        }
    }

    public static function check(): bool
    {
        self::start();
        return !empty($_SESSION[self::SESSION_KEY]);
    }

    /**
     * Attempt login.
     *
     * 1. If .env has PANEL_AUTH_PASSWORD set -> verify against it (existing
     *    deployments / dev override). The username is ignored in that mode.
     * 2. Otherwise fall back to the Khanza login (plug-and-play): authenticate
     *    against the SIMRS `sik` DB `admin` table using the Khanza AES pattern
     *    (AES_DECRYPT(usere,'nur'), AES_DECRYPT(passworde,'windi')) — the same
     *    mechanism dashboard_eksekutif/mapping_satu_sehat use.
     */
    public static function attempt(string $password, string $username = ''): bool
    {
        $expected = Config::env('PANEL_AUTH_PASSWORD', '');

        if ($expected !== '') {
            if (hash_equals($expected, $password)) {
                self::start();
                session_regenerate_id(true);
                $_SESSION[self::SESSION_KEY] = true;
                $_SESSION['nama_user'] = $username !== '' ? $username : 'admin';
                return true;
            }
            return false;
        }

        // No .env password -> Khanza login (plug-and-play mode)
        try {
            $db = Database::getMysql();
            $stmt = $db->prepare("
                SELECT
                    AES_DECRYPT(usere, 'nur')       AS usere,
                    AES_DECRYPT(passworde, 'windi') AS passworde
                FROM admin
                WHERE AES_DECRYPT(usere, 'nur') = ?
                LIMIT 1
            ");
            $stmt->execute([$username]);
            $row = $stmt->fetch();
            if (!$row || $row['passworde'] !== $password) {
                return false;
            }
        } catch (\Throwable $e) {
            return false; // DB unavailable — login fails closed
        }

        self::start();
        session_regenerate_id(true);
        $_SESSION[self::SESSION_KEY] = true;
        $_SESSION['nama_user'] = $username;
        return true;
    }

    public static function logout(): void
    {
        self::start();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    public static function csrfToken(): string
    {
        self::start();
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function validateCsrf(): bool
    {
        self::start();
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['_csrf'] ?? '';
        $sessionToken = $_SESSION['csrf_token'] ?? '';

        if ($token === '' || $sessionToken === '') {
            return false;
        }
        return hash_equals($sessionToken, $token);
    }

    /**
     * Require auth for API routes. Returns 401 JSON if not authed.
     */
    public static function requireAuth(): bool
    {
        if (self::check()) {
            return true;
        }
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'Unauthorized', 'auth_required' => true]);
        return false;
    }
}
