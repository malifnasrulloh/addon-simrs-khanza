<?php

/**
 * Config - Environment configuration loader for Satu Sehat Service.
 *
 * @author malifnasrulloh (converted from Java by Antigravity)
 */

declare(strict_types=1);

class SatuSehatConfig
{
    private array $env;

    // ─── Satu Sehat Credentials ─────────────────────────────────────────────
    public readonly string $orgId;
    public readonly string $clientId;
    public readonly string $secretKey;
    public readonly string $authUrl;
    public readonly string $baseUrl;
    public readonly int    $tokenTimeout;
    public readonly int    $delayMs;
    public readonly int    $lookbackDays;
    public readonly string $dateFrom;
    public readonly string $dateTo;

    // ─── Database ──────────────────────────────────────────────────────────
    public readonly string $dbHost;
    public readonly int    $dbPort;
    public readonly string $dbName;
    public readonly string $dbUser;
    public readonly string $dbPass;

    // ─── Logging ───────────────────────────────────────────────────────────
    public readonly string $logDir;
    public readonly string $logLevel;
    public readonly int    $logRetentionDays;
    public readonly string $timezone;
    public readonly string $jwtSecret;

    // ─── Webhook Credentials ─────────────────────────────────────────────────
    public readonly string $webhookUser;
    public readonly string $webhookPassword;

    // ─── PACS / Orthanc & DICOM Converter ────────────────────────────────────────
    public readonly string $orthancUrl;
    public readonly int    $orthancPort;
    public readonly string $orthancUser;
    public readonly string $orthancPass;
    public readonly string $dicomConverterUrl;
    public readonly int    $dicomConverterPort;
    public readonly string $dicomRouterAe;
    public readonly string $simrsWebappsUrl;

    public function __construct(string $envPath)
    {
        if (!file_exists($envPath)) {
            throw new \RuntimeException(".env file not found at: {$envPath}");
        }

        $this->env = self::parseEnvFile($envPath);

        $this->timezone = $this->get('TIMEZONE', 'Asia/Jakarta');
        date_default_timezone_set($this->timezone);

        $this->dbHost = $this->require('DB_HOST');
        $this->dbPort = (int) $this->get('DB_PORT', '3306');
        $this->dbName = $this->require('DB_NAME');
        $this->dbUser = $this->require('DB_USER');
        $this->dbPass = $this->get('DB_PASS', '');

        $this->webhookUser     = $this->get('WEBHOOK_USER', 'user_webhook_rs');
        $this->webhookPassword = $this->get('WEBHOOK_PASSWORD', 'password_webhook_rs');

        $this->orthancUrl         = $this->get('ORTHANC_URL', 'http://localhost');
        $this->orthancPort        = (int) $this->get('ORTHANC_PORT', '8042');
        $this->orthancUser        = $this->get('ORTHANC_USER', 'admin');
        $this->orthancPass        = $this->get('ORTHANC_PASS', 'password');
        $this->dicomConverterUrl  = $this->get('DICOM_CONVERTER_URL', 'http://localhost');
        $this->dicomConverterPort = (int) $this->get('DICOM_CONVERTER_PORT', '8080');
        $this->dicomRouterAe      = $this->get('DICOM_ROUTER_AE', 'DCMROUTER');
        $this->simrsWebappsUrl    = rtrim($this->get('SIMRS_WEBAPPS_URL', 'http://localhost/webapps'), '/');

        $this->orgId        = $this->require('SATUSEHAT_ORG_ID');

        $this->clientId     = $this->require('SATUSEHAT_CLIENT_ID');

        $this->secretKey    = $this->require('SATUSEHAT_SECRET_KEY');
        $this->authUrl      = rtrim($this->require('SATUSEHAT_AUTH_URL'), '/');
        $this->baseUrl      = rtrim($this->require('SATUSEHAT_BASE_URL'), '/');
        
        $this->tokenTimeout = (int) $this->get('SATUSEHAT_TOKEN_TIMEOUT', '3000');
        $this->delayMs      = (int) $this->get('SATUSEHAT_DELAY_MS', '500');
        $this->lookbackDays = (int) $this->get('SATUSEHAT_LOOKBACK_DAYS', '0');
        $this->dateFrom     = $this->get('SATUSEHAT_DATE_FROM', date('Y-m-d'));
        $this->dateTo       = $this->get('SATUSEHAT_DATE_TO', date('Y-m-d'));

        $logDir = $this->get('LOG_DIR', 'logs');
        if (!str_starts_with($logDir, '/')) {
            $logDir = (defined('BASE_DIR') ? BASE_DIR : dirname(__DIR__, 2)) . '/' . $logDir;
        }
        $this->logDir           = $logDir;
        $this->logLevel         = strtoupper($this->get('LOG_LEVEL', 'INFO'));
        $this->logRetentionDays = (int) $this->get('LOG_RETENTION_DAYS', '30');
        $this->jwtSecret        = $this->get('JWT_SECRET', 'simrs-khanza-secret-super-secure-key');
    }

    private function get(string $key, string $default = ''): string
    {
        return $this->env[$key] ?? $default;
    }

    private function require(string $key): string
    {
        $val = $this->env[$key] ?? '';
        if ($val === '' && !in_array($key, ['DB_PASS'], true)) {
            throw new \RuntimeException("Required environment variable '{$key}' is missing or empty in .env");
        }
        return $val;
    }

    public static function parseEnvFile(string $path): array
    {
        $vars = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) continue;
            if (!str_contains($line, '=')) continue;

            [$key, $value] = explode('=', $line, 2);
            $key   = trim($key);
            $value = trim($value);
            if (preg_match('/^(["\'])(.*)\\1$/', $value, $m)) {
                $value = $m[2];
            }
            $vars[$key] = $value;
        }
        return $vars;
    }
}
