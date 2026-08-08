<?php
/**
 * Application configuration
 */
use SatusehatPanel\Core\Config;

return [
    'app' => [
        'name' => 'SATUSEHAT Admin Panel',
        'version' => '1.0.0',
        'debug' => Config::env('APP_DEBUG', 'false') === 'true',
        'timezone' => Config::env('APP_TIMEZONE', 'Asia/Jakarta'),
        'auth_password' => Config::env('PANEL_AUTH_PASSWORD', ''),
    ],
    'satusehat' => [
        'client_id' => Config::env('SATUSEHAT_CLIENT_ID'),
        'client_secret' => Config::env('SATUSEHAT_SECRET_KEY'),
        'auth_url' => Config::env('SATUSEHAT_AUTH_URL', 'https://api-satusehat-dev.dto.kemkes.go.id/oauth2/v1'),
        'base_url' => Config::env('SATUSEHAT_BASE_URL', 'https://api-satusehat-dev.dto.kemkes.go.id/fhir-r4/v1'),
        'org_id' => Config::env('SATUSEHAT_ORG_ID'),
    ],
];