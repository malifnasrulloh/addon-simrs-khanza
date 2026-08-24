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

    public const MAX_LOGIN_ATTEMPTS = 5;
    public const LOGIN_WINDOW_SECONDS = 900;

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            if (!headers_sent()) {
                // Proxy-aware Secure flag: trust X-Forwarded-Proto only when the
                // operator opted in (PANEL_TRUST_PROXY=true) — avoids silently
                // downgrading cookie security on non-TLS setups.
                $isHttps = !empty($_SERVER['HTTPS'])
                    || (Config::env('PANEL_TRUST_PROXY', 'false') === 'true'
                        && ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
                session_set_cookie_params([
                    'httponly' => true,
                    'samesite' => 'Lax',
                    'secure'   => $isHttps,
                ]);
                @session_start();
            } else {
                @session_start();
            }
        }
    }

    public static function check(): bool
    {
        self::start();
        if (empty($_SESSION[self::SESSION_KEY])) {
            return false;
        }
        // Session idle timeout (T24): PANEL_SESSION_TTL seconds, default 30 min.
        $ttl = max(60, (int) Config::env('PANEL_SESSION_TTL', '1800'));
        $last = (int) ($_SESSION['last_activity'] ?? 0);
        if ($last > 0 && (time() - $last) > $ttl) {
            self::logout();
            return false;
        }
        $_SESSION['last_activity'] = time();
        return true;
    }

    /**
     * True when this identifier (IP + username) is inside its lockout window.
     */
    public static function loginBlocked(string $identifier): bool
    {
        try {
            $db = Database::getSqlite();
            $hash = hash('sha256', $identifier);
            $stmt = $db->prepare("
                SELECT attempts FROM login_attempts
                WHERE identifier_hash = ? AND window_start > ?
                LIMIT 1
            ");
            $stmt->execute([$hash, time() - self::LOGIN_WINDOW_SECONDS]);
            return ((int) $stmt->fetchColumn()) >= self::MAX_LOGIN_ATTEMPTS;
        } catch (\Throwable $e) {
            return false; // store unavailable → fail open on blocking, auth itself still gates
        }
    }

    public static function recordLoginFailure(string $identifier): void
    {
        try {
            $db = Database::getSqlite();
            $hash = hash('sha256', $identifier);
            $stmt = $db->prepare("
                INSERT INTO login_attempts (identifier_hash, window_start, attempts)
                VALUES (?, ?, 1)
                ON CONFLICT(identifier_hash)
                DO UPDATE SET attempts = CASE
                    WHEN window_start > ? THEN attempts + 1
                    ELSE 1 END,
                    window_start = CASE WHEN window_start > ? THEN window_start ELSE ? END
            ");
            $stmt->execute([$hash, time(), time() - self::LOGIN_WINDOW_SECONDS, time() - self::LOGIN_WINDOW_SECONDS, time()]);
        } catch (\Throwable $e) {
            error_log('[PANEL] recordLoginFailure: ' . $e->getMessage());
        }
    }

    public static function recordLoginSuccess(string $identifier): void
    {
        try {
            $db = Database::getSqlite();
            $stmt = $db->prepare("DELETE FROM login_attempts WHERE identifier_hash = ?");
            $stmt->execute([hash('sha256', $identifier)]);
        } catch (\Throwable $e) {
            // non-fatal
        }
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
        $identifier = ($_SERVER['REMOTE_ADDR'] ?? 'cli') . '|' . $username;
        if (self::loginBlocked($identifier)) {
            return false;
        }

        $expected = Config::env('PANEL_AUTH_PASSWORD', '');

        $ok = false;
        if ($expected !== '') {
            $ok = hash_equals($expected, $password);
            if ($ok) {
                self::start();
                session_regenerate_id(true);
                $_SESSION[self::SESSION_KEY] = true;
                $_SESSION['nama_user'] = $username !== '' ? $username : 'admin';
            }
        } else {
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
                if ($row && is_string($row['passworde']) && hash_equals($row['passworde'], $password)) {
                    $ok = true;
                    self::start();
                    session_regenerate_id(true);
                    $_SESSION[self::SESSION_KEY] = true;
                    $_SESSION['nama_user'] = $username;
                }
            } catch (\Throwable $e) {
                $ok = false; // DB unavailable — login fails closed
            }
        }

        if ($ok) {
            $_SESSION['last_activity'] = time();
            self::recordLoginSuccess($identifier);
        } else {
            self::recordLoginFailure($identifier);
        }
        return $ok;
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
