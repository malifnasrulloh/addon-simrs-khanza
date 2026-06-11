<?php
/**
 * SIMRS Khanza - Mobile JKN Queue Sync Service
 *
 * CLI service to synchronize patient queue data with BPJS Mobile JKN API.
 * Sends Task IDs (3, 4, 5, 6, 7, 99), pharmacy queues, and cancellations.
 * Designed to run as a cron job (replaces Java KhanzaHMSServiceMobileJKN).
 *
 * Usage:
 *   php mobilejkn_sync.php                  # Normal run
 *   php mobilejkn_sync.php --dry-run        # Simulate without sending API requests
 *   php mobilejkn_sync.php --verbose        # Extra debug output
 *   php mobilejkn_sync.php --help           # Show help
 *
 * Cron example (every 10 minutes):
 *   * /10 * * * * cd /path/to/php-service && php mobilejkn_sync.php >> /dev/null 2>&1
 *
 * @author  malifnasrulloh (converted from Java by Antigravity)
 * @version 2.0.0
 */

declare(strict_types=1);

// ─── Bootstrap ─────────────────────────────────────────────────────────────
define('SERVICE_NAME', 'KhanzaMobileJKNSync');
define('SERVICE_VERSION', '2.0.0');
define('BASE_DIR', __DIR__);

// ─── Load configuration ───────────────────────────────────────────────────
require_once BASE_DIR . '/lib/mobilejkn/Config.php';

try {
    $config = new MobileJknConfig(BASE_DIR . '/.env');
} catch (\RuntimeException $e) {
    if (php_sapi_name() === 'cli') {
        fwrite(STDERR, "[FATAL] Configuration error: {$e->getMessage()}\n");
    } else {
        http_response_code(500);
        echo "[FATAL] Configuration error: {$e->getMessage()}\n";
    }
    exit(1);
}

// ─── Web Trigger Setup ─────────────────────────────────────────────────────
if (php_sapi_name() !== 'cli') {
    // Web execution setup
    set_time_limit(0);
    header('Content-Type: text/plain; charset=utf-8');
    
    $isDryRun  = isset($_GET['dry-run']);
    $isVerbose = isset($_GET['verbose']);
} else {
    // ─── Parse CLI arguments ───────────────────────────────────────────────────
    $options = getopt('', ['help', 'dry-run', 'verbose']);

    if (isset($options['help'])) {
        $ver = SERVICE_VERSION;
        echo <<<HELP
    ╔══════════════════════════════════════════════════════════════╗
    ║  SIMRS Khanza - Mobile JKN Queue Sync Service               ║
    ║  Version {$ver}                                               ║
    ╚══════════════════════════════════════════════════════════════╝

    Usage:
      php mobilejkn_sync.php [options]

    Options:
      --help       Show this help message
      --dry-run    Simulate run without sending API requests
      --verbose    Enable debug-level logging to terminal

    Environment:
      Configure MOBILEJKN_* variables in .env (or reuse APLICARE_* credentials).

    Cron (every 10 minutes, matches original Java scheduler):
      */10 * * * * cd /path/to/php-service && php mobilejkn_sync.php

    Task ID Reference:
      3   Patient file sent to polyclinic (waiting)
      4   Patient file received at polyclinic (service starts)
      5   Outpatient examination completed
      6   Prescription created
      7   Prescription dispensed
      99  Visit cancelled

    HELP;
        exit(0);
    }

    $isDryRun  = isset($options['dry-run']);
    $isVerbose = isset($options['verbose']);
}

// ─── Logger setup ──────────────────────────────────────────────────────────
require_once BASE_DIR . '/lib/Logger.php';

$logLevel = $isVerbose ? 'DEBUG' : $config->logLevel;
$log = new Logger($config->logDir, 'mobilejkn', $logLevel, $isVerbose, php_sapi_name() !== 'cli');
$log->cleanOldLogs($config->logRetentionDays);

// ─── Banner ────────────────────────────────────────────────────────────────
$log->info("══════════════════════════════════════════════════════════════");
$log->info("  SIMRS Khanza - Mobile JKN Queue Sync Service");
$log->info("  Version: " . SERVICE_VERSION . " | PHP " . PHP_VERSION);
$log->info("  Timestamp: " . date('Y-m-d H:i:s T'));
$log->info("  Mode: " . ($isDryRun ? 'DRY-RUN (no API calls)' : 'PRODUCTION'));
$log->info("  Batch Size: " . $config->batchSize);
$log->info("  Lookback Days: " . $config->lookbackDays);
$log->info("  Non-JKN: " . ($config->includeNonJkn ? 'Enabled' : 'Disabled'));
$log->info("  API Base: " . $config->baseUrl);
$log->info("══════════════════════════════════════════════════════════════");

// ─── Initialize components ────────────────────────────────────────────────
require_once BASE_DIR . '/lib/mobilejkn/Database.php';
require_once BASE_DIR . '/lib/mobilejkn/BpjsAntreanClient.php';
require_once BASE_DIR . '/lib/mobilejkn/QueueProcessor.php';

try {
    $db = new MobileJknDatabase($config, $log);
} catch (\PDOException $e) {
    $log->error("[DB] Connection failed: " . $e->getMessage());
    exit(1);
}

$api = new BpjsAntreanClient(
    $config->consId,
    $config->secretKey,
    $config->userKey,
    $config->baseUrl,
    $config->batchSize,
    $log,
    $isDryRun
);



$processor = new QueueProcessor($db, $api, $config, $log);

// ─── Execute ───────────────────────────────────────────────────────────────
$startTime = microtime(true);

try {
    $stats = $processor->run();
} catch (\Throwable $e) {
    $log->error("[FATAL] Unhandled exception: " . $e->getMessage());
    $log->error("[FATAL] Stack trace: " . $e->getTraceAsString());
    $db->close();
    exit(1);
}

$elapsed = round(microtime(true) - $startTime, 2);

// ─── Summary ───────────────────────────────────────────────────────────────
$log->info("══════════════════════════════════════════════════════════════");
$log->info("[SUMMARY] Success: {$stats['success']} | Failed: {$stats['fail']} | Skipped: {$stats['skip']}");
$log->info("[SUMMARY] Elapsed: {$elapsed}s");
$log->info("[DONE] Finished at " . date('Y-m-d H:i:s T'));
$log->info("══════════════════════════════════════════════════════════════");

$db->close();
exit($stats['fail'] > 0 ? 2 : 0);
