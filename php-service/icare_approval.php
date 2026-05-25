#!/usr/bin/env php
<?php
/**
 * SIMRS Khanza - iCare Auto Approval Service
 *
 * CLI script to automate BPJS iCare validation and approval for today's
 * outpatient (Ralan) registrations. Runs headlessly via cURL.
 *
 * Usage:
 *   php icare_approval.php                  # Normal run
 *   php icare_approval.php --dry-run        # DB query only, no API calls
 *   php icare_approval.php --verbose        # Extra debug output
 *   php icare_approval.php --no-cache       # Ignore cache, re-process all
 *   php icare_approval.php --help           # Show help
 *
 * Cron example (every 30 minutes during work hours):
 *   *30 7-17 * * 1-6 cd /path/to/php-service && php icare_approval.php
 *
 * @author  malifnasrulloh (converted from Java by Antigravity)
 * @version 1.0.0
 */

declare(strict_types=1);

// ─── Bootstrap ─────────────────────────────────────────────────────────────
define('SERVICE_NAME', 'KhanzaICareAutoApprove');
define('SERVICE_VERSION', '1.0.0');
define('BASE_DIR', __DIR__);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

// ─── Load libraries ───────────────────────────────────────────────────────
require_once BASE_DIR . '/lib/Logger.php';
require_once BASE_DIR . '/lib/icare/LZString.php';
require_once BASE_DIR . '/lib/icare/BPJSICareApi.php';
require_once BASE_DIR . '/lib/icare/HeadlessApproval.php';
require_once BASE_DIR . '/lib/icare/PatientCache.php';

// ─── CLI arguments ────────────────────────────────────────────────────────
$options = getopt('', ['help', 'dry-run', 'verbose', 'no-cache']);

if (isset($options['help'])) {
    $ver = SERVICE_VERSION;
    echo <<<HELP
    ╔══════════════════════════════════════════════════════════════╗
    ║  SIMRS Khanza - iCare Auto Approval Service                  ║
    ║  Version {$ver}                                              ║
    ╚══════════════════════════════════════════════════════════════╝

    Usage:
      php icare_approval.php [options]

    Options:
      --help       Show this help message
      --dry-run    Query DB only, skip API calls and approval
      --verbose    Enable debug-level logging
      --no-cache   Ignore daily cache, re-process all patients

    Flow:
      1. Fetch today's Ralan patients from DB
      2. For each patient (NIK preferred, fallback to No Kartu):
         a. POST to BPJS wsihs /api/RS/validate
         b. Decrypt response (AES-256-CBC + LZString)
         c. Simulate browser approval flow via cURL
      3. Cache successful approvals to avoid re-processing

    Environment:
      Copy .env.example to .env and fill in ICARE_* credentials.

    Cron (every 30 min, work hours):
      */30 7-17 * * 1-6 cd /path/to/php-service && php icare_approval.php

    HELP;
    exit(0);
}

$isDryRun  = isset($options['dry-run']);
$isVerbose = isset($options['verbose']);
$noCache   = isset($options['no-cache']);

// ─── Load .env ────────────────────────────────────────────────────────────
$envFile = BASE_DIR . '/.env';
if (!file_exists($envFile)) {
    fwrite(STDERR, "[FATAL] .env file not found. Copy .env.example to .env and configure it.\n");
    exit(1);
}
$env = parseEnvFile($envFile);

date_default_timezone_set($env['TIMEZONE'] ?? 'Asia/Jakarta');

// ─── Logger ───────────────────────────────────────────────────────────────
$logDir = $env['LOG_DIR'] ?? 'logs';
$logLevel = $isVerbose ? 'DEBUG' : strtoupper($env['LOG_LEVEL'] ?? 'INFO');
$log = new Logger($logDir, 'icare', $logLevel, $isVerbose);
$retentionDays = (int)($env['LOG_RETENTION_DAYS'] ?? 30);
$log->cleanOldLogs($retentionDays);

// ─── Banner ───────────────────────────────────────────────────────────────
$log->info('══════════════════════════════════════════════════════════════');
$log->info('  SIMRS Khanza - iCare Auto Approval Service');
$log->info('  Version: ' . SERVICE_VERSION . ' | PHP ' . PHP_VERSION);
$log->info('  Timestamp: ' . date('Y-m-d H:i:s T'));
$log->info('  Mode: ' . ($isDryRun ? 'DRY-RUN' : 'PRODUCTION') . ($noCache ? ' | CACHE-DISABLED' : ''));
$log->info('══════════════════════════════════════════════════════════════');

// ─── Validate config ──────────────────────────────────────────────────────
$required = ['DB_HOST', 'DB_NAME', 'DB_USER', 'ICARE_CONS_ID', 'ICARE_SECRET_KEY', 'ICARE_USER_KEY', 'ICARE_BASE_URL'];
$missing = [];
foreach ($required as $key) {
    if (empty($env[$key])) $missing[] = $key;
}
if (!empty($missing)) {
    $log->error('Missing required env vars: ' . implode(', ', $missing));
    exit(1);
}

// ─── Database ─────────────────────────────────────────────────────────────
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
    ]);
    $log->info('[DB] Connected.');
} catch (PDOException $e) {
    $log->error('[DB] Connection failed: ' . $e->getMessage());
    exit(1);
}

// ─── Fetch patients ───────────────────────────────────────────────────────
$log->info('[QUERY] Fetching today\'s Ralan patients...');
try {
    $stmt = $pdo->query("
        SELECT 
            p.kd_dokter,
            p.no_rkm_medis,
            m.kd_dokter_bpjs,
            pasien.no_ktp AS nik,
            pasien.no_peserta AS no_kartu_bpjs
        FROM reg_periksa p
        LEFT JOIN maping_dokter_dpjpvclaim m ON p.kd_dokter = m.kd_dokter
        LEFT JOIN pasien ON p.no_rkm_medis = pasien.no_rkm_medis
        WHERE p.tgl_registrasi = CURDATE() 
          AND p.status_lanjut = 'Ralan'
    ");
    $patients = $stmt->fetchAll();
} catch (PDOException $e) {
    $log->error('[QUERY] Failed: ' . $e->getMessage());
    exit(1);
}

$totalPatients = count($patients);
if ($totalPatients === 0) {
    $log->info('[QUERY] No Ralan patients found for today.');
    $log->info('[DONE] Nothing to process.');
    exit(0);
}
$log->info("[QUERY] Found {$totalPatients} patient(s).");

// ─── Initialize services ─────────────────────────────────────────────────
$api = new BPJSICareApi(
    $env['ICARE_CONS_ID'],
    $env['ICARE_SECRET_KEY'],
    $env['ICARE_USER_KEY'],
    $env['ICARE_BASE_URL'],
    $log
);

$browser = new HeadlessApproval($log, $log->getLogDir() . '/tmp');
$cache   = new PatientCache($log->getLogDir());
$cache->cleanOld($retentionDays);

// ─── Process each patient ─────────────────────────────────────────────────
$log->info('──────────────────────────────────────────────────────────────');

$successCount = 0;
$failCount    = 0;
$skippedCount = 0;

foreach ($patients as $idx => $patient) {
    $num = $idx + 1;
    $noRkm      = $patient['no_rkm_medis'] ?? '';
    $kdDokterBpjs = $patient['kd_dokter_bpjs'] ?? '';
    $nik        = trim($patient['nik'] ?? '');
    $noKartu    = trim($patient['no_kartu_bpjs'] ?? '');

    // Determine which identifier to use: NIK (16 digit) first, fallback no_kartu
    $param = '';
    $paramType = '';
    if (strlen($nik) === 16) {
        $param = $nik;
        $paramType = 'NIK';
    } elseif (!empty($noKartu)) {
        $param = $noKartu;
        $paramType = 'No.Kartu';
    }

    $log->info("[{$num}/{$totalPatients}] Patient: {$noRkm} | Dokter BPJS: {$kdDokterBpjs} | {$paramType}: {$param}");

    // ── Validation checks ──
    if (empty($kdDokterBpjs)) {
        $log->warning("  [SKIP] No BPJS doctor mapping for kd_dokter={$patient['kd_dokter']}");
        $failCount++;
        continue;
    }
    if (empty($param)) {
        $log->warning("  [SKIP] No valid NIK (16 digit) or No.Kartu BPJS for patient {$noRkm}");
        $failCount++;
        continue;
    }

    // ── Cache check ──
    if (!$noCache && $cache->isApproved($param, $kdDokterBpjs)) {
        $log->info("  [CACHE] Already approved today, skipping.");
        $skippedCount++;
        continue;
    }

    if ($isDryRun) {
        $log->info("  [DRY-RUN] Would process {$paramType}={$param}, dokter={$kdDokterBpjs}");
        $skippedCount++;
        continue;
    }

    // ── Anti-bruteforce delay ──
    if ($idx > 0) {
        $delay = rand(2, 5);
        $log->debug("  [DELAY] Sleeping {$delay}s...");
        sleep($delay);
    }

    // ── Step 1: API Validation ──
    $log->info("  [API] Validating with BPJS wsihs...");
    $result = $api->validate($param, (int) $kdDokterBpjs);

    if (!$result['success']) {
        $log->error("  [API] Failed: {$result['message']}");
        $cache->mark($param, $kdDokterBpjs, 'failed', $result['message']);
        $failCount++;
        continue;
    }

    $log->info("  [API] Validation OK. Decrypted URL received.");
    $log->debug("  [API] URL: {$result['url']}");

    // ── Step 2: Headless Approval Flow ──
    $log->info("  [BROWSER] Starting headless approval flow...");
    $approval = $browser->approve($result['url']);

    if ($approval['success']) {
        $log->info("  [SUCCESS] ✓ Patient {$noRkm} approved: {$approval['message']}");
        $cache->mark($param, $kdDokterBpjs, 'success', $approval['message']);
        $successCount++;
    } else {
        $log->error("  [FAILED] ✗ Patient {$noRkm}: {$approval['message']}");
        $cache->mark($param, $kdDokterBpjs, 'failed', $approval['message']);
        $failCount++;
    }

    sleep(rand(15, 30));
}

// ─── Summary ──────────────────────────────────────────────────────────────
$log->info('──────────────────────────────────────────────────────────────');
$cacheSummary = $cache->getSummary();
$log->info("[SUMMARY] Processed: {$totalPatients} | Success: {$successCount} | Failed: {$failCount} | Skipped/Cached: {$skippedCount}");
$log->info("[CACHE] Today's cache: {$cacheSummary['total']} entries ({$cacheSummary['success']} success, {$cacheSummary['failed']} failed)");
$log->info('[DONE] Finished at ' . date('Y-m-d H:i:s T'));
$log->info('══════════════════════════════════════════════════════════════');

$pdo = null;
exit($failCount > 0 ? 2 : 0);

// ═══════════════════════════════════════════════════════════════════════════
// Helper Functions
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Parse a .env file into an associative array.
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
        if (preg_match('/^(["\'])(.*)\\1$/', $value, $m)) {
            $value = $m[2];
        }
        $vars[$key] = $value;
    }
    return $vars;
}
