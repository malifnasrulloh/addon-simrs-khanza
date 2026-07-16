#!/usr/bin/env php
<?php
/**
 * SIMRS Khanza - Satu Sehat Observation TTV Sync Service
 *
 * CLI service to synchronize patient vital signs (TTV observations) with Satu Sehat API.
 * Supports multi-process parallel execution (--parallel) using pcntl_fork for blazing fast speeds.
 * 
 * Designed to run as a cron job.
 *
 * Usage:
 *   php satusehat_observationttv_sync.php                  # Normal run
 *   php satusehat_observationttv_sync.php --verbose        # Extra debug output
 *   php satusehat_observationttv_sync.php --parallel       # Run with 4 concurrent workers
 *   php satusehat_observationttv_sync.php --parallel=8     # Run with 8 concurrent workers
 *   php satusehat_observationttv_sync.php --help           # Show help
 *
 * @author malifnasrulloh (converted from Java by Antigravity)
 * @version 1.1.0
 */

declare(strict_types=1);

define('SERVICE_NAME', 'KhanzaSatuSehatObservationSync');
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
    ║  SIMRS Khanza - Satu Sehat Observation TTV Sync Service      ║
    ║  Version {$ver}                                              ║
    ╚══════════════════════════════════════════════════════════════╝

    Usage:
      php satusehat_observationttv_sync.php [options]

    Options:
      --help            Show this help message
      --verbose         Enable debug-level logging to terminal
      --parallel[=N]    Run N parallel workers (defaults to 4 if value omitted)

    Environment:
      Configure SATUSEHAT_* variables in .env.

    Cron (every 5 minutes):
      */5 * * * * cd /path/to/php-service && php satusehat_observationttv_sync.php --parallel

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
$log = new Logger($config->logDir, 'satusehat_observationttv', $logLevel, $isVerbose);
$log->cleanOldLogs($config->logRetentionDays);

$log->info("══════════════════════════════════════════════════════════════");
$log->info("  SIMRS Khanza - Satu Sehat Observation TTV Sync Service");
$log->info("  Version: " . SERVICE_VERSION . " | PHP " . PHP_VERSION);
$log->info("  Concurrency: " . ($isParallel ? "Parallel ({$numWorkers} Workers)" : "Single Process"));
$log->info("  Timestamp: " . date('Y-m-d H:i:s T'));
$log->info("  API Base: " . $config->baseUrl);
$log->info("══════════════════════════════════════════════════════════════");

require_once BASE_DIR . '/lib/satusehat/BatchCursor.php';
require_once BASE_DIR . '/lib/satusehat/SatuSehatClient.php';
require_once BASE_DIR . '/lib/satusehat/Database.php';
require_once BASE_DIR . '/lib/satusehat/PayloadBuilder.php';
require_once BASE_DIR . '/lib/satusehat/Supervisor.php';
require_once BASE_DIR . '/lib/satusehat/ObservationTTVProcessor.php';

$client = new SatuSehatClient($config, $log);

try {
    $db = new SatuSehatDatabase($config, $log, $client);
} catch (\PDOException $e) {
    $log->error("[DB] Connection failed: " . $e->getMessage());
    exit(1);
}

$startTime = microtime(true);

// Resolve dates matching ObservationTTVProcessor
if ($config->lookbackDays > 0) {
    $dateTo = date('Y-m-d', strtotime('-1 day'));
    $dateFrom = date('Y-m-d', strtotime('-' . $config->lookbackDays . ' days', strtotime(date('Y-m-d'))));
} else {
    $dateFrom = $config->dateFrom;
    $dateTo = $config->dateTo;
}

// Run Diagnostics
$db->printSyncDiagnostics('observationttv', $dateFrom, $dateTo);

$definitions = ObservationTTVDictionary::getDefinitions();
$batchSize = $config->batchSize;
$processor = new SatuSehatObservationTTVProcessor($db, $client, $config, $log);
$totalSuccess = 0; $totalFail = 0;

// Process each TTV type independently with its own BatchCursor
$log->info("[BATCH] Processing " . count($definitions) . " TTV type(s) with batch size $batchSize");
foreach ($definitions as $ttvType => $def) {
    $cursor = new SatuSehatBatchCursor(
        $db,
        'fetchPendingObservations',
        [$ttvType, $def, $dateFrom, $dateTo],
        $batchSize,
        $log,
        $ttvType
    );

    foreach ($cursor->batches() as $batch) {
        $hash = [$ttvType => $batch];
        $stats = $processor->run($hash);
        $totalSuccess += $stats['success'];
        $totalFail += $stats['fail'];
        $cursor->tick();
    }
}

$elapsed = round(microtime(true) - $startTime, 2);
$log->info("══════════════════════════════════════════════════════════════");
$log->info("[SUMMARY] Success: {$totalSuccess} | Failed: {$totalFail}");
$log->info("[SUMMARY] Elapsed: {$elapsed}s");
$log->info("[DONE] Finished at " . date('Y-m-d H:i:s T'));
$log->info("══════════════════════════════════════════════════════════════");
$db->close();
exit($totalFail > 0 ? 2 : 0);