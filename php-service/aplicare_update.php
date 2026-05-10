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
$logDir = rtrim($env['LOG_DIR'] ?? 'logs', '/');
if (!str_starts_with($logDir, '/')) {
    $logDir = BASE_DIR . '/' . $logDir;
}
if (!is_dir($logDir) && !mkdir($logDir, 0755, true)) {
    fwrite(STDERR, "[FATAL] Cannot create log directory: {$logDir}\n");
    exit(1);
}
$logFile = $logDir . '/aplicare_' . date('Y-m-d') . '.log';
$logLevel = strtoupper($env['LOG_LEVEL'] ?? 'INFO');
$logLevels = ['DEBUG' => 0, 'INFO' => 1, 'WARNING' => 2, 'ERROR' => 3];
$minLogLevel = $logLevels[$logLevel] ?? 1;

// Clean old logs
cleanOldLogs($logDir, (int)($env['LOG_RETENTION_DAYS'] ?? 30));

// ─── Banner ────────────────────────────────────────────────────────────────
logInfo("══════════════════════════════════════════════════════════════");
logInfo("  SIMRS Khanza - Aplicare Bed Availability Update Service");
logInfo("  Version: " . SERVICE_VERSION . " | PHP " . PHP_VERSION);
logInfo("  Timestamp: " . date('Y-m-d H:i:s T'));
logInfo("  Mode: " . ($isDryRun ? 'DRY-RUN (no API calls)' : 'PRODUCTION'));
logInfo("══════════════════════════════════════════════════════════════");

// ─── Validate required config ──────────────────────────────────────────────
$required = ['DB_HOST', 'DB_NAME', 'DB_USER', 'APLICARE_CONS_ID', 'APLICARE_SECRET_KEY', 'APLICARE_BASE_URL'];
$missing = [];
foreach ($required as $key) {
    if (empty($env[$key])) {
        $missing[] = $key;
    }
}
if (!empty($missing)) {
    logError("Missing required environment variables: " . implode(', ', $missing));
    exit(1);
}

// ─── Database connection ───────────────────────────────────────────────────
logInfo("[DB] Connecting to {$env['DB_HOST']}:{$env['DB_PORT']}/{$env['DB_NAME']}...");
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
    logInfo("[DB] Connection established successfully.");
} catch (PDOException $e) {
    logError("[DB] Connection failed: " . $e->getMessage());
    exit(1);
}

// ─── Resolve kode PPK ─────────────────────────────────────────────────────
$kodePPK = $env['KODE_PPK'] ?? '';
if (empty($kodePPK)) {
    logInfo("[PPK] KODE_PPK not set in .env, fetching from `setting` table...");
    try {
        $stmt = $pdo->query("SELECT kode_ppk FROM setting LIMIT 1");
        $row = $stmt->fetch();
        $kodePPK = $row['kode_ppk'] ?? '';
    } catch (PDOException $e) {
        logError("[PPK] Failed to fetch kode_ppk: " . $e->getMessage());
        exit(1);
    }
    if (empty($kodePPK)) {
        logError("[PPK] kode_ppk is empty in `setting` table and not configured in .env");
        exit(1);
    }
    logInfo("[PPK] Resolved kode_ppk = {$kodePPK}");
} else {
    logInfo("[PPK] Using kode_ppk from .env = {$kodePPK}");
}

// ─── Query bed availability ───────────────────────────────────────────────
logInfo("[QUERY] Fetching bed availability data...");
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

logDebug("[SQL] " . preg_replace('/\s+/', ' ', $sql));

try {
    $stmt = $pdo->query($sql);
    $rooms = $stmt->fetchAll();
} catch (PDOException $e) {
    logError("[QUERY] Failed: " . $e->getMessage());
    exit(1);
}

$totalRooms = count($rooms);
if ($totalRooms === 0) {
    logWarning("[QUERY] No rooms found in aplicare_ketersediaan_kamar. Nothing to update.");
    logInfo("[DONE] Finished with 0 rooms processed.");
    exit(0);
}
logInfo("[QUERY] Found {$totalRooms} room(s) to update.");

// ─── Process each room ────────────────────────────────────────────────────
$successCount = 0;
$failCount = 0;
$baseUrl = rtrim($env['APLICARE_BASE_URL'], '/');
$consId = $env['APLICARE_CONS_ID'];
$secretKey = $env['APLICARE_SECRET_KEY'];
$headerConsId = $env['APLICARE_HEADER_CONS_ID'] ?: $consId;

logInfo("[API] Base URL: {$baseUrl}");
logInfo("[API] Target endpoint: /rest/bed/update/{$kodePPK}");
logInfo("──────────────────────────────────────────────────────────────");

foreach ($rooms as $index => $room) {
    $roomNum = $index + 1;
    $kodeKelas = $room['kode_kelas_aplicare'];
    $kdBangsal = $room['kd_bangsal'];
    $namaBangsal = $room['nm_bangsal'];
    $kapasitas = (int)$room['kapasitas'];
    $tersedia = (int)($room['tersedia'] ?? 0);

    logInfo("[{$roomNum}/{$totalRooms}] Processing: {$namaBangsal} (kelas={$kodeKelas}, bangsal={$kdBangsal})");
    logInfo("  Kapasitas: {$kapasitas} | Tersedia: {$tersedia}");

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

    logDebug("  [HTTP] Request JSON: {$payload}");

    if ($isDryRun) {
        logInfo("  [DRY-RUN] Skipped API call.");
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

    logDebug("  [HTTP] URL: {$url}");
    logDebug("  [HTTP] Status: {$result['http_code']}");

    if ($result['error']) {
        logError("  [HTTP] cURL error: {$result['error']}");
        $failCount++;
        continue;
    }

    // Parse response
    $response = json_decode($result['body'], true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        logError("  [HTTP] Invalid JSON response: " . substr($result['body'], 0, 500));
        $failCount++;
        continue;
    }

    $metadata = $response['metadata'] ?? null;
    if ($metadata) {
        $code = $metadata['code'] ?? '?';
        $message = $metadata['message'] ?? 'No message';
        $logMsg = "  [BPJS] Response: {$code} - {$message}";
        if ((int)$code === 200 || (int)$code === 1) {
            logInfo($logMsg);
            $successCount++;
        } else {
            logWarning($logMsg);
            $failCount++;
        }
    } else {
        logWarning("  [BPJS] Unexpected response structure: " . substr($result['body'], 0, 500));
        $failCount++;
    }
}

// ─── Summary ───────────────────────────────────────────────────────────────
logInfo("──────────────────────────────────────────────────────────────");
logInfo("[SUMMARY] Total: {$totalRooms} | Success: {$successCount} | Failed: {$failCount}");
logInfo("[DONE] Finished at " . date('Y-m-d H:i:s T'));
logInfo("══════════════════════════════════════════════════════════════");

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

/**
 * Delete log files older than $days days.
 */
function cleanOldLogs(string $dir, int $days): void
{
    if ($days <= 0) return;
    $cutoff = time() - ($days * 86400);
    foreach (glob($dir . '/aplicare_*.log') as $file) {
        if (filemtime($file) < $cutoff) {
            unlink($file);
        }
    }
}

// ─── Logging functions ────────────────────────────────────────────────────
function writeLog(string $level, string $message): void
{
    global $logFile, $minLogLevel, $logLevels, $isVerbose;
    $levelNum = $logLevels[$level] ?? 1;
    if ($levelNum < $minLogLevel && !$isVerbose) return;

    $timestamp = date('Y-m-d H:i:s');
    $formatted = "[{$timestamp}] [{$level}] {$message}";

    // Always write to log file
    file_put_contents($logFile, $formatted . PHP_EOL, FILE_APPEND | LOCK_EX);

    // Always output to terminal (for cron logs / manual runs)
    $stream = ($level === 'ERROR' || $level === 'WARNING') ? STDERR : STDOUT;
    fwrite($stream, $formatted . PHP_EOL);
}

function logDebug(string $msg): void   { writeLog('DEBUG', $msg); }
function logInfo(string $msg): void    { writeLog('INFO', $msg); }
function logWarning(string $msg): void { writeLog('WARNING', $msg); }
function logError(string $msg): void   { writeLog('ERROR', $msg); }
