<?php

namespace SatusehatPanel\Core;

class Config
{
    private static array $loaded = [];

    public static function get(string $key, mixed $default = null): mixed
    {
        if (empty(self::$loaded)) {
            self::$loaded = require __DIR__ . '/../../config/app.php';
        }

        // Dot notation: app.debug, satusehat.client_id
        $keys = explode('.', $key);
        $value = self::$loaded;

        foreach ($keys as $k) {
            if (!is_array($value) || !array_key_exists($k, $value)) {
                return $default;
            }
            $value = $value[$k];
        }

        return $value;
    }

    public static function env(string $key, string $default = ''): string
    {
        // Load .env file once
        static $env = null;
        if ($env === null) {
            $env = [];
            $path = __DIR__ . '/../../.env';
            if (file_exists($path)) {
                foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                    $line = trim($line);
                    if ($line === '' || str_starts_with($line, '#')) continue;
                    if (!str_contains($line, '=')) continue;
                    [$k, $v] = explode('=', $line, 2);
                    $env[trim($k)] = trim($v);
                }
            }
        }

        return $env[$key] ?? $default;
    }
}
