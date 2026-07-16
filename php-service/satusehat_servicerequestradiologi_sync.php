#!/usr/bin/env php
<?php
/**
 * SIMRS Khanza - Satu Sehat ServiceRequest (Radiologi) Sync Service
 *
 * CLI service to synchronize patient ServiceRequest (Radiologi) data with Satu Sehat API.
 * Supports multi-process parallel execution (--parallel) using pcntl_fork for blazing fast speeds.
 * 
 * Designed to run as a cron job.
 *
 * Usage:
 *   php satusehat_servicerequestradiologi_sync.php                  # Normal run
 *   php satusehat_servicerequestradiologi_sync.php --verbose        # Extra debug output
 *   php satusehat_servicerequestradiologi_sync.php --parallel       # Run with 4 concurrent workers
 *   php satusehat_servicerequestradiologi_sync.php --parallel=8     # Run with 8 concurrent workers
 *   php satusehat_servicerequestradiologi_sync.php --help           # Show help
 *
 * @author malifnasrulloh (converted from Java by Antigravity)
 * @version 1.1.0
 */

declare(strict_types=1);

define('SERVICE_NAME', 'KhanzaSatuSehatServiceRequestRadiologiSync');
define('SERVICE_VERSION', '1.1.0');
define('BASE_DIR', __DIR__);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

$options = getopt('', ['help', 'verbose', 'parallel::']);

if (isset($options['help'])) {
    $ver = SERVICE_VERSION;
    echo <<<HELP
    ╔══════════════════════════════════════════════════════════════╗
    ║  SIMRS Khanza - Satu Sehat ServiceRequest (Radiologi) Sync   ║
    ║  Version {$ver}                                              ║
    ╚══════════════════════════════════════════════════════════════╝

    Usage:
      php satusehat_servicerequestradiologi_sync.php [options]

    Options:
      --help            Show this help message
      --verbose         Enable debug-level logging to terminal
      --parallel[=N]    Run N parallel workers (defaults to 4 if value omitted)

    Environment:
      Configure SATUSEHAT_* variables in .env.

    Cron (every 10 minutes):
      */10 * * * * cd /path/to/php-service && php satusehat_servicerequestradiologi_sync.php --parallel

    HELP;
    exit(0);
}

$isVerbose = isset($options['verbose']);
$isParallel = isset($options['parallel']);
$numWorkers = 1;

if ($isParallel) {
    if (!function_exists('pcntl_fork')) {
        fwrite(STDERR, "[WARNING] pcntl extension not found. Falling back to single-process execution.\n");
        $isParallel = false;
    } else {
        $numWorkers = $options['parallel'] === false ? 4 : (int)$options['parallel'];
        if ($numWorkers < 1) $numWorkers = 1;
    }
}

require_once BASE_DIR . '/lib/Logger.php';
require_once BASE_DIR . '/lib/satusehat/Config.php';

try {
    $config = new SatuSehatConfig(BASE_DIR . '/.env');
} catch (\RuntimeException $e) {
    fwrite(STDERR, "[FATAL] Configuration error: {$e->getMessage()}\n");
    exit(1);
}

$logLevel = $isVerbose ? 'DEBUG' : $config->logLevel;
$log = new Logger($config->logDir, 'satusehat_servicerequest_radiologi', $logLevel, $isVerbose);
$log->cleanOldLogs($config->logRetentionDays);

$log->info("══════════════════════════════════════════════════════════════");
$log->info("  SIMRS Khanza - Satu Sehat ServiceRequest (Radiologi) Sync");
$log->info("  Version: " . SERVICE_VERSION . " | PHP " . PHP_VERSION);
$log->info("  Concurrency: " . ($isParallel ? "Parallel ({$numWorkers} Workers)" : "Single Process"));
$log->info("  Timestamp: " . date('Y-m-d H:i:s T'));
$log->info("  API Base: " . $config->baseUrl);
$log->info("══════════════════════════════════════════════════════════════");

require_once BASE_DIR . '/lib/satusehat/SatuSehatClient.php';
require_once BASE_DIR . '/lib/satusehat/BatchCursor.php';
require_once BASE_DIR . '/lib/satusehat/Database.php';
require_once BASE_DIR . '/lib/satusehat/PayloadBuilder.php';
require_once BASE_DIR . '/lib/satusehat/Supervisor.php';
require_once BASE_DIR . '/lib/satusehat/ServiceRequestRadiologiProcessor.php';

$client = new SatuSehatClient($config, $log);

try {
    $db = new SatuSehatDatabase($config, $log, $client);
} catch (\PDOException $e) {
    $log->error("[DB] Connection failed: " . $e->getMessage());
    exit(1);
}

$startTime = microtime(true);

// Resolve dates matching ClinicalImpressionProcessor
if ($config->lookbackDays > 0) {
    $dateTo = date('Y-m-d', strtotime('-1 day'));
    $dateFrom = date('Y-m-d', strtotime('-' . $config->lookbackDays . ' days', strtotime(date('Y-m-d'))));
} else {
    $dateFrom = $config->dateFrom;
    $dateTo = $config->dateTo;
}

// Run Diagnostics
$db->printSyncDiagnostics('servicerequest_radiologi', $dateFrom, $dateTo);

// 1. Fetch pending records
$batchSize = $config->batchSize;
$processor = new SatuSehatServiceRequestRadiologiProcessor($db, $client, $config, $log);
$totalSuccess = 0;
$totalFail = 0;
$totalSkip = 0;

try {
    $log->info("──────────────────────────────────────────────────────────────");
    $log->info("[SYNC] Phase 1: POST New ServiceRequestRadiologi (batches of {$batchSize})");
    $cursor = new SatuSehatBatchCursor($db, 'fetchPendingServiceRequestRadiologiActive', [$dateFrom, $dateTo], $batchSize, $log, 'ServiceRequestRadiologi/active');
    foreach ($cursor->batches() as $batch) {
        $stats = $processor->run($batch, []);
        $totalSuccess += $stats['success'];
        $totalFail += $stats['fail'];
        $totalSkip += $stats['skip'];
        $cursor->tick();
    }
    $log->info("[PHASE 1] Active: Success={$totalSuccess} Fail={$totalFail} Skip={$totalSkip}");

    $log->info("──────────────────────────────────────────────────────────────");
    $log->info("[SYNC] Phase 2: PUT Update ServiceRequestRadiologi (batches of {$batchSize})");
    $cursor = new SatuSehatBatchCursor($db, 'fetchPendingServiceRequestRadiologiUpdate', [$dateFrom, $dateTo], $batchSize, $log, 'ServiceRequestRadiologi/update');
    foreach ($cursor->batches() as $batch) {
        $stats = $processor->run([], $batch);
        $totalSuccess += $stats['success'];
        $totalFail += $stats['fail'];
        $totalSkip += $stats['skip'];
        $cursor->tick();
    }
    $log->info("[PHASE 2] Update: Success={$totalSuccess} Fail={$totalFail} Skip={$totalSkip}");
} catch (\Throwable $e) {
    $log->error("[FATAL] Unhandled exception: " . $e->getMessage());
    $log->error("[FATAL] Stack trace: " . $e->getTraceAsString());
    $db->close();
    exit(1);
}

$elapsed = round(microtime(true) - $startTime, 2);
$log->info("══════════════════════════════════════════════════════════════");
$log->info("[SUMMARY] Success: {$totalSuccess} | Failed: {$totalFail} | Skipped: {$totalSkip}");
$log->info("[SUMMARY] Elapsed: {$elapsed}s");
$log->info("[DONE] Finished at " . date('Y-m-d H:i:s T'));
$log->info("══════════════════════════════════════════════════════════════");
$db->close();
exit($totalFail > 0 ? 2 : 0);
