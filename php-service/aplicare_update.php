#!/usr/bin/env php
<?php
/**
 * SIMRS Khanza - Aplicare Bed Availability Update Service
 * 
 * CLI-only service to push bed availability data to BPJS Aplicare API.
 * Designed to run as a cron job.
 *
 * Usage:
 *   php aplicare_update.php                  # Normal run
 *   php aplicare_update.php --dry-run        # Simulate without sending API requests
 *   php aplicare_update.php --verbose        # Extra debug output
 *   php aplicare_update.php --help           # Show help
 *
 * Cron example (every 5 minutes):
 *   * /5 * * * * cd /path/to/php-service && php aplicare_update.php >> /dev/null 2>&1
 *
 * @author  malifnasrulloh (converted from Java by Antigravity)
 * @version 2.0.0
 */

declare(strict_types=1);

// ─── Bootstrap ─────────────────────────────────────────────────────────────
define('SERVICE_NAME', 'KhanzaAplicareService');
define('SERVICE_VERSION', '2.0.0');
define('BASE_DIR', __DIR__);

// Prevent web execution
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

// ─── Parse CLI arguments ───────────────────────────────────────────────────
$options = getopt('', ['help', 'dry-run', 'verbose']);
if (isset($options['help'])) {
    $ver = SERVICE_VERSION;
    echo <<<HELP
    ╔══════════════════════════════════════════════════════════════╗
    ║  SIMRS Khanza - Aplicare Bed Availability Update Service    ║
    ║  Version $ver                                               ║
    ╚══════════════════════════════════════════════════════════════╝

    Usage:
      php aplicare_update.php [options]

    Options:
      --help       Show this help message
      --dry-run    Simulate run without sending API requests
      --verbose    Enable debug-level logging to terminal

    Environment:
      Copy .env.example to .env and fill in your credentials.

    Cron (every 5 minutes):
      */5 * * * * cd /path/to/php-service && php aplicare_update.php

    HELP;
    exit(0);
}

$isDryRun = isset($options['dry-run']);
$isVerbose = isset($options['verbose']);

// ─── Load environment ──────────────────────────────────────────────────────
$envFile = BASE_DIR . '/.env';
if (!file_exists($envFile)) {
    fwrite(STDERR, "[FATAL] .env file not found. Copy .env.example to .env and configure it.\n");
    exit(1);
}
$env = parseEnvFile($envFile);

// Timezone
date_default_timezone_set($env['TIMEZONE'] ?? 'Asia/Jakarta');

// ─── Logger setup ──────────────────────────────────────────────────────────
require_once BASE_DIR . '/lib/Logger.php';

$logLevel = $isVerbose ? 'DEBUG' : strtoupper($env['LOG_LEVEL'] ?? 'INFO');
$log = new Logger($env['LOG_DIR'] ?? 'logs', 'aplicare', $logLevel, $isVerbose);
$log->cleanOldLogs((int)($env['LOG_RETENTION_DAYS'] ?? 30));

// ─── Banner ────────────────────────────────────────────────────────────────
$log->info("══════════════════════════════════════════════════════════════");
$log->info("  SIMRS Khanza - Aplicare Bed Availability Update Service");
$log->info("  Version: " . SERVICE_VERSION . " | PHP " . PHP_VERSION);
$log->info("  Timestamp: " . date('Y-m-d H:i:s T'));
$log->info("  Mode: " . ($isDryRun ? 'DRY-RUN (no API calls)' : 'PRODUCTION'));
$log->info("══════════════════════════════════════════════════════════════");

// ─── Validate required config ──────────────────────────────────────────────
$required = ['DB_HOST', 'DB_NAME', 'DB_USER', 'APLICARE_CONS_ID', 'APLICARE_SECRET_KEY', 'APLICARE_BASE_URL'];
$missing = [];
foreach ($required as $key) {
    if (empty($env[$key])) {
        $missing[] = $key;
    }
}
if (!empty($missing)) {
    $log->error("Missing required environment variables: " . implode(', ', $missing));
    exit(1);
}

// ─── Database connection ───────────────────────────────────────────────────
$log->info("[DB] Connecting to {$env['DB_HOST']}:{$env['DB_PORT']}/{$env['DB_NAME']}...");
try {
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $env['DB_HOST'],
        $env['DB_PORT'] ?? '3306',
        $env['DB_NAME']
    );
    $pdo = new PDO($dsn, $env['DB_USER'], $env['DB_PASS'] ?? '', [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
    ]);
    $log->info("[DB] Connection established successfully.");
} catch (PDOException $e) {
    $log->error("[DB] Connection failed: " . $e->getMessage());
    exit(1);
}

// ─── Resolve kode PPK ─────────────────────────────────────────────────────
$kodePPK = $env['KODE_PPK'] ?? '';
if (empty($kodePPK)) {
    $log->info("[PPK] KODE_PPK not set in .env, fetching from `setting` table...");
    try {
        $stmt = $pdo->query("SELECT kode_ppk FROM setting LIMIT 1");
        $row = $stmt->fetch();
        $kodePPK = $row['kode_ppk'] ?? '';
    } catch (PDOException $e) {
        $log->error("[PPK] Failed to fetch kode_ppk: " . $e->getMessage());
        exit(1);
    }
    if (empty($kodePPK)) {
        $log->error("[PPK] kode_ppk is empty in `setting` table and not configured in .env");
        exit(1);
    }
    $log->info("[PPK] Resolved kode_ppk = {$kodePPK}");
} else {
    $log->info("[PPK] Using kode_ppk from .env = {$kodePPK}");
}

// ─── Query bed availability ───────────────────────────────────────────────
$log->info("[QUERY] Fetching bed availability data...");
$sql = <<<SQL
SELECT
    akk.kode_kelas_aplicare,
    akk.kd_bangsal,
    b.nm_bangsal,
    COUNT(k.kd_kamar) AS kapasitas,
    SUM(k.status = 'KOSONG') AS tersedia
FROM aplicare_ketersediaan_kamar akk
INNER JOIN bangsal b ON akk.kd_bangsal = b.kd_bangsal
LEFT JOIN kamar k ON b.kd_bangsal = k.kd_bangsal AND k.statusdata = '1'
GROUP BY akk.kd_bangsal
SQL;

$log->debug("[SQL] " . preg_replace('/\s+/', ' ', $sql));

try {
    $stmt = $pdo->query($sql);
    $rooms = $stmt->fetchAll();
} catch (PDOException $e) {
    $log->error("[QUERY] Failed: " . $e->getMessage());
    exit(1);
}

$totalRooms = count($rooms);
if ($totalRooms === 0) {
    $log->warning("[QUERY] No rooms found in aplicare_ketersediaan_kamar. Nothing to update.");
    $log->info("[DONE] Finished with 0 rooms processed.");
    exit(0);
}
$log->info("[QUERY] Found {$totalRooms} room(s) to update.");

// ─── Process each room ────────────────────────────────────────────────────
$successCount = 0;
$failCount = 0;
$baseUrl = rtrim($env['APLICARE_BASE_URL'], '/');
$consId = $env['APLICARE_CONS_ID'];
$secretKey = $env['APLICARE_SECRET_KEY'];
$headerConsId = $env['APLICARE_HEADER_CONS_ID'] ?: $consId;

$log->info("[API] Base URL: {$baseUrl}");
$log->info("[API] Target endpoint: /rest/bed/update/{$kodePPK}");
$log->info("──────────────────────────────────────────────────────────────");

foreach ($rooms as $index => $room) {
    $roomNum = $index + 1;
    $kodeKelas = $room['kode_kelas_aplicare'];
    $kdBangsal = $room['kd_bangsal'];
    $namaBangsal = $room['nm_bangsal'];
    $kapasitas = (int)$room['kapasitas'];
    $tersedia = (int)($room['tersedia'] ?? 0);

    $log->info("[{$roomNum}/{$totalRooms}] Processing: {$namaBangsal} (kelas={$kodeKelas}, bangsal={$kdBangsal})");
    $log->info("  Kapasitas: {$kapasitas} | Tersedia: {$tersedia}");

    // Build JSON payload
    $payload = json_encode([
        'kodekelas'          => $kodeKelas,
        'koderuang'          => $kdBangsal,
        'namaruang'          => $namaBangsal,
        'kapasitas'          => $kapasitas,
        'tersedia'           => $tersedia,
        'tersediapria'       => $tersedia,
        'tersediawanita'     => $tersedia,
        'tersediapriawanita' => $tersedia,
    ], JSON_UNESCAPED_UNICODE);

    $log->debug("  [HTTP] Request JSON: {$payload}");

    if ($isDryRun) {
        $log->info("  [DRY-RUN] Skipped API call.");
        $successCount++;
        continue;
    }

    // Generate BPJS signature
    $timestamp = time();
    $signature = generateSignature($consId, $secretKey, $timestamp);

    $url = "{$baseUrl}/rest/bed/update/{$kodePPK}";

    // Send HTTP request
    $result = sendRequest($url, $payload, [
        'Content-Type: application/json',
        'X-Cons-ID: ' . $headerConsId,
        'X-Timestamp: ' . $timestamp,
        'X-Signature: ' . $signature,
    ]);

    $log->debug("  [HTTP] URL: {$url}");
    $log->debug("  [HTTP] Status: {$result['http_code']}");

    if ($result['error']) {
        $log->error("  [HTTP] cURL error: {$result['error']}");
        $failCount++;
        continue;
    }

    // Parse response
    $response = json_decode($result['body'], true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        $log->error("  [HTTP] Invalid JSON response: " . substr($result['body'], 0, 500));
        $failCount++;
        continue;
    }

    $metadata = $response['metadata'] ?? null;
    if ($metadata) {
        $code = $metadata['code'] ?? '?';
        $message = $metadata['message'] ?? 'No message';
        $logMsg = "  [BPJS] Response: {$code} - {$message}";
        if ((int)$code === 200 || (int)$code === 1) {
            $log->info($logMsg);
            $successCount++;
        } else {
            $log->warning($logMsg);
            $failCount++;
        }
    } else {
        $log->warning("  [BPJS] Unexpected response structure: " . substr($result['body'], 0, 500));
        $failCount++;
    }
}

// ─── Summary ───────────────────────────────────────────────────────────────
$log->info("──────────────────────────────────────────────────────────────");
$log->info("[SUMMARY] Total: {$totalRooms} | Success: {$successCount} | Failed: {$failCount}");
$log->info("[DONE] Finished at " . date('Y-m-d H:i:s T'));
$log->info("══════════════════════════════════════════════════════════════");

$pdo = null;
exit($failCount > 0 ? 2 : 0);

// ═══════════════════════════════════════════════════════════════════════════
// Helper Functions
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Parse a .env file into an associative array.
 * Supports comments (#), empty lines, and quoted values.
 */
function parseEnvFile(string $path): array
{
    $vars = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        if (!str_contains($line, '=')) continue;

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        // Strip surrounding quotes
        if (preg_match('/^(["\'])(.*)\\1$/', $value, $m)) {
            $value = $m[2];
        }
        $vars[$key] = $value;
    }
    return $vars;
}

/**
 * Generate BPJS HMAC-SHA256 signature.
 * Data = consId + "&" + timestamp, key = secretKey
 */
function generateSignature(string $consId, string $secretKey, int $timestamp): string
{
    $data = $consId . '&' . $timestamp;
    $hmac = hash_hmac('sha256', $data, $secretKey, true);
    return base64_encode($hmac);
}

/**
 * Send an HTTP POST request using cURL.
 * Returns ['body' => string, 'http_code' => int, 'error' => string|null]
 */
function sendRequest(string $url, string $body, array $headers): array
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch) ?: null;
    curl_close($ch);

    return ['body' => $response ?: '', 'http_code' => $httpCode, 'error' => $error];
}


