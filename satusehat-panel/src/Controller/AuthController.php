<?php

namespace SatusehatPanel\Controller;

use SatusehatPanel\Core\Auth;

class AuthController
{
    /**
     * POST /api/auth/login — body: { password: "...", username?: "..." }
     * username is required in plug-and-play (Khanza) mode; ignored when a
     * panel .env password is configured.
     */
    public static function login(): array
    {
        $input = json_decode(file_get_contents('php://input'), true);
        $password = is_array($input) ? (string) ($input['password'] ?? '') : '';
        $username = is_array($input) ? (string) ($input['username'] ?? '') : '';

        if ($password === '') {
            return ['success' => false, 'error' => 'Password required'];
        }

        // Rate limit gate (T24): 5 failures / 15 min per IP+username.
        $identifier = ($_SERVER['REMOTE_ADDR'] ?? 'cli') . '|' . $username;
        if (Auth::loginBlocked($identifier)) {
            http_response_code(429);
            header('Retry-After: 900');
            return ['success' => false, 'error' => 'Terlalu banyak percobaan login. Coba lagi dalam 15 menit.'];
        }

        if (Auth::attempt($password, $username)) {
            return [
                'success' => true,
                'user' => $_SESSION['nama_user'] ?? 'admin',
                'csrf_token' => Auth::csrfToken(),
            ];
        }
        return ['success' => false, 'error' => 'Username atau password salah'];
    }

    /**
     * POST /api/auth/logout
     */
    public static function logout(): array
    {
        Auth::logout();
        return ['success' => true];
    }

    /**
     * GET /api/auth/status
     */
    public static function status(): array
    {
        $authed = Auth::check();
        return [
            'success' => true,
            'authed' => $authed,
            'user' => $authed ? ($_SESSION['nama_user'] ?? 'admin') : null,
            'csrf_token' => $authed ? Auth::csrfToken() : null,
        ];
    }
}
