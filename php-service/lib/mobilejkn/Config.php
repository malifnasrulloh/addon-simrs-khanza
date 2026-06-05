<?php

/**
 * Config - Environment configuration loader for Mobile JKN Sync Service.
 *
 * Loads .env, validates required keys, provides typed accessors.
 * Credentials fall back to APLICARE_* if MOBILEJKN_* are empty (shared BPJS creds).
 *
 * @author  malifnasrulloh (converted from Java by Antigravity)
 */

declare(strict_types=1);

class MobileJknConfig
{
    private array $env;

    // ─── Credential fields (resolved with fallback) ────────────────────────
    public readonly string $consId;
    public readonly string $secretKey;
    public readonly string $userKey;
    public readonly string $baseUrl;

    // ─── Runtime tuning ────────────────────────────────────────────────────
    public readonly int    $batchSize;
    public readonly int    $lookbackDays;
    public readonly bool   $includeNonJkn;
    public readonly bool   $skipFarmasiNoResep;
    public readonly bool   $deferRobotInfer;
    public readonly array  $robotRanges;

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

    // ─── Indonesian day-of-week map (0=Sunday) ─────────────────────────────
    private const HARI = ['AKHAD', 'SENIN', 'SELASA', 'RABU', 'KAMIS', 'JUMAT', 'SABTU'];

    /**
     * @param string $envPath Absolute path to .env file
     * @throws RuntimeException if .env missing or required keys absent
     */
    public function __construct(string $envPath)
    {
        if (!file_exists($envPath)) {
            throw new \RuntimeException(".env file not found at: {$envPath}");
        }

        $this->env = self::parseEnvFile($envPath);

        // ── Timezone (set early so date() calls below are correct) ──────
        $this->timezone = $this->get('TIMEZONE', 'Asia/Jakarta');
        date_default_timezone_set($this->timezone);

        // ── Database ────────────────────────────────────────────────────
        $this->dbHost = $this->require('DB_HOST');
        $this->dbPort = (int) $this->get('DB_PORT', '3306');
        $this->dbName = $this->require('DB_NAME');
        $this->dbUser = $this->require('DB_USER');
        $this->dbPass = $this->get('DB_PASS', '');

        // ── BPJS credentials (MOBILEJKN_* → APLICARE_* fallback) ────────
        $this->consId    = $this->getWithFallback('MOBILEJKN_CONS_ID',    'APLICARE_CONS_ID');
        $this->secretKey = $this->getWithFallback('MOBILEJKN_SECRET_KEY', 'APLICARE_SECRET_KEY');
        $this->userKey   = $this->getWithFallback('MOBILEJKN_USER_KEY',   'APLICARE_USER_KEY');
        $this->baseUrl   = rtrim($this->require('MOBILEJKN_BASE_URL'), '/');

        if (empty($this->consId) || empty($this->secretKey)) {
            throw new \RuntimeException(
                'BPJS credentials missing. Set MOBILEJKN_CONS_ID/SECRET_KEY or APLICARE_CONS_ID/SECRET_KEY in .env'
            );
        }

        // ── Runtime tuning ──────────────────────────────────────────────
        $this->batchSize          = max(1, (int) $this->get('MOBILEJKN_BATCH_SIZE', '4'));
        $this->lookbackDays       = max(1, (int) $this->get('MOBILEJKN_LOOKBACK_DAYS', '6'));
        $this->includeNonJkn      = filter_var($this->get('MOBILEJKN_INCLUDE_NON_JKN', 'true'), FILTER_VALIDATE_BOOLEAN);
        $this->skipFarmasiNoResep = filter_var($this->get('MOBILEJKN_SKIP_FARMASI_NO_RESEP', 'false'), FILTER_VALIDATE_BOOLEAN);
        $this->deferRobotInfer    = filter_var($this->get('MOBILEJKN_DEFER_ROBOT_INFER', 'true'), FILTER_VALIDATE_BOOLEAN);
        $this->robotRanges        = [
            '4' => self::parseRange($this->get('ROBOT_RANGE_4', '35,58'), [35, 58]),
            '5' => self::parseRange($this->get('ROBOT_RANGE_5', '3,10'), [3, 10]),
            '6' => self::parseRange($this->get('ROBOT_RANGE_6', '6,15'), [6, 15]),
            '7' => self::parseRange($this->get('ROBOT_RANGE_7', '8,15'), [8, 15]),
        ];

        // ── Logging ─────────────────────────────────────────────────────
        $this->logDir           = $this->get('LOG_DIR', 'logs');
        $this->logLevel         = strtoupper($this->get('LOG_LEVEL', 'INFO'));
        $this->logRetentionDays = (int) $this->get('LOG_RETENTION_DAYS', '30');
    }

    /**
     * Get the Indonesian day name for today (e.g. "SENIN").
     */
    public function todayHari(): string
    {
        return self::HARI[(int) date('w')];
    }

    /**
     * Get today's date in Y-m-d format.
     */
    public function todayDate(): string
    {
        return date('Y-m-d');
    }

    /**
     * Get the lookback start date (today minus lookbackDays).
     */
    public function lookbackDate(): string
    {
        return date('Y-m-d', strtotime("-{$this->lookbackDays} days"));
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Internal helpers
    // ═══════════════════════════════════════════════════════════════════════

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

    /**
     * Try primary key, fall back to secondary key.
     */
    private function getWithFallback(string $primary, string $fallback): string
    {
        $val = $this->env[$primary] ?? '';
        return $val !== '' ? $val : ($this->env[$fallback] ?? '');
    }

    /**
     * Parse a .env file into an associative array.
     * Supports comments (#), empty lines, and quoted values.
     */
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

    /**
     * Parse a comma-separated range string (min,max) into a 2-element array [min, max].
     */
    private static function parseRange(string $val, array $default): array
    {
        $parts = explode(',', $val);
        if (count($parts) === 2) {
            $min = (int) trim($parts[0]);
            $max = (int) trim($parts[1]);
            if ($min > 0 && $max >= $min) {
                return [$min, $max];
            }
        }
        return $default;
    }
}
