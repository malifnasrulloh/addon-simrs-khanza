<?php
/**
 * Database configuration for SATUSEHAT Admin Panel
 */
return [
    'sqlite' => [
        'path' => __DIR__ . '/../storage/panel.db',
    ],
    'mysql' => [
        'host' => \SatusehatPanel\Core\Config::env('DB_HOST', 'localhost'),
        'port' => (int) \SatusehatPanel\Core\Config::env('DB_PORT', '3306'),
        'database' => \SatusehatPanel\Core\Config::env('DB_NAME', 'sik'),
        'username' => \SatusehatPanel\Core\Config::env('DB_USER', 'root'),
        'password' => \SatusehatPanel\Core\Config::env('DB_PASS', ''),
        'charset' => 'utf8mb4',
    ],
];