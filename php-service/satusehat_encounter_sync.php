#!/usr/bin/env php
<?php
/**
 * SIMRS Khanza - Satu Sehat Encounter Sync Service
 *
 * CLI service to synchronize patient Encounter data with Satu Sehat API.
 * Supports multi-process parallel execution (--parallel) using pcntl_fork for blazing fast speeds.
 * 
 * Designed to run as a cron job.
 *
 * Usage:
 *   php satusehat_encounter_sync.php                  # Normal run
 *   php satusehat_encounter_sync.php --verbose        # Extra debug output
 *   php satusehat_encounter_sync.php --parallel       # Run with 4 concurrent workers
 *   php satusehat_encounter_sync.php --parallel=8     # Run with 8 concurrent workers
 *   php satusehat_encounter_sync.php --help           # Show help
 *
 * @author malifnasrulloh (converted from Java by Antigravity)
 * @version 1.1.0
 */

declare(strict_types=1);

define('SERVICE_NAME', 'KhanzaSatuSehatEncounterSync');
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
    ║  SIMRS Khanza - Satu Sehat Encounter Sync Service            ║
    ║  Version {$ver}                                              ║
    ╚══════════════════════════════════════════════════════════════╝

    Usage:
      php satusehat_encounter_sync.php [options]

    Options:
      --help            Show this help message
      --verbose         Enable debug-level logging to terminal
      --parallel[=N]    Run N parallel workers (defaults to 4 if value omitted)

    Environment:
      Configure SATUSEHAT_* variables in .env.

    Cron (every 5 minutes):
      */5 * * * * cd /path/to/php-service && php satusehat_encounter_sync.php --parallel

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
$log = new Logger($config->logDir, 'satusehat_encounter', $logLevel, $isVerbose);
$log->cleanOldLogs($config->logRetentionDays);

$log->info("══════════════════════════════════════════════════════════════");
$log->info("  SIMRS Khanza - Satu Sehat Encounter Sync Service");
$log->info("  Version: " . SERVICE_VERSION . " | PHP " . PHP_VERSION);
$log->info("  Concurrency: " . ($isParallel ? "Parallel ({$numWorkers} Workers)" : "Single Process"));
$log->info("  Timestamp: " . date('Y-m-d H:i:s T'));
$log->info("  API Base: " . $config->baseUrl);
$log->info("══════════════════════════════════════════════════════════════");

require_once BASE_DIR . '/lib/satusehat/SatuSehatClient.php';
require_once BASE_DIR . '/lib/satusehat/Database.php';
require_once BASE_DIR . '/lib/satusehat/PayloadBuilder.php';
require_once BASE_DIR . '/lib/satusehat/Supervisor.php';
require_once BASE_DIR . '/lib/satusehat/BatchCursor.php';
require_once BASE_DIR . '/lib/satusehat/EncounterProcessor.php';

$client = new SatuSehatClient($config, $log);

try {
    $db = new SatuSehatDatabase($config, $log, $client);
} catch (\PDOException $e) {
    $log->error("[DB] Connection failed: " . $e->getMessage());
    exit(1);
}

$startTime = microtime(true);

// Resolve dates matching EncounterProcessor
if ($config->lookbackDays > 0) {
    $dateTo = date('Y-m-d', strtotime('-1 day'));
    $dateFrom = date('Y-m-d', strtotime('-' . $config->lookbackDays . ' days', strtotime(date('Y-m-d'))));
} else {
    $dateFrom = $config->dateFrom;
    $dateTo = $config->dateTo;
}

// Run Diagnostics
$db->printSyncDiagnostics('encounter', $dateFrom, $dateTo);

$batchSize = $config->batchSize;

/**
 * Process records for a given phase in batch iterations.
 * Returns accumulated stats array ['success' => int, 'fail' => int, 'skip' => int].
 */
function processPhase(
    SatuSehatBatchCursor $cursor,
    SatuSehatEncounterProcessor $processor,
    Logger $logger,
    string $label,
    string $batchFor // which param to fill: 'arrived', 'in-progress', 'finished'
): array {
    $stats = ['success' => 0, 'fail' => 0, 'skip' => 0];
    $batchIdx = 0;

    foreach ($cursor->batches() as $batch) {
        $batchIdx++;
        $logger->info("[BATCH {$batchIdx}] Processing " . count($batch) . " {$label} encounters...");

        $arr = ($batchFor === 'arrived') ? $batch : [];
        $prog = ($batchFor === 'in-progress') ? $batch : [];
        $fin = ($batchFor === 'finished') ? $batch : [];

        $s = $processor->run($arr, $prog, $fin);
        $stats['success'] += $s['success'];
        $stats['fail'] += $s['fail'];
        $stats['skip'] += $s['skip'];

        $cursor->tick();
    }

    return $stats;
}

$logGlobal = $log; // alias for use in helper functions

// ── SINGLE PROCESS PATH (Batch Cursor) ──
if (!$isParallel) {
    $processor = new SatuSehatEncounterProcessor($db, $client, $config, $log);

    $totalSuccess = 0;
    $totalFail = 0;
    $totalSkip = 0;

    try {
        // Phase 1: Arrived
        $log->info("──────────────────────────────────────────────────────────────");
        $log->info("[SYNC] Phase 1: POST 'arrived' Encounters (batches of {$batchSize})");
        $cursor = new SatuSehatBatchCursor($db, 'fetchPendingArrived', [$dateFrom, $dateTo], $batchSize, $log, 'arrived');
        $s = processPhase($cursor, $processor, $logGlobal, 'arrived', 'arrived');
        $totalSuccess += $s['success']; $totalFail += $s['fail']; $totalSkip += $s['skip'];
        $log->info("[PHASE 1] Arrived: Success={$s['success']} Fail={$s['fail']} Skip={$s['skip']}");

        // Phase 2: In-Progress
        $log->info("──────────────────────────────────────────────────────────────");
        $log->info("[SYNC] Phase 2: PUT 'in-progress' Encounters (batches of {$batchSize})");
        $cursor = new SatuSehatBatchCursor($db, 'fetchPendingInProgress', [$dateFrom, $dateTo], $batchSize, $log, 'in-progress');
        $s = processPhase($cursor, $processor, $logGlobal, 'in-progress', 'in-progress');
        $totalSuccess += $s['success']; $totalFail += $s['fail']; $totalSkip += $s['skip'];
        $log->info("[PHASE 2] In-Progress: Success={$s['success']} Fail={$s['fail']} Skip={$s['skip']}");

        // Phase 3: Finished
        $log->info("──────────────────────────────────────────────────────────────");
        $log->info("[SYNC] Phase 3: PUT 'finished' Encounters (batches of {$batchSize})");
        $cursor = new SatuSehatBatchCursor($db, 'fetchPendingFinished', [$dateFrom, $dateTo], $batchSize, $log, 'finished');
        $s = processPhase($cursor, $processor, $logGlobal, 'finished', 'finished');
        $totalSuccess += $s['success']; $totalFail += $s['fail']; $totalSkip += $s['skip'];
        $log->info("[PHASE 3] Finished: Success={$s['success']} Fail={$s['fail']} Skip={$s['skip']}");

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
}

// ── PARALLEL PATH (Date-window split + Batch Cursor per worker) ──
$log->info("[CONCURRENCY] Splitting date range among {$numWorkers} parallel workers...");

$dateWindows = SatuSehatBatchCursor::splitDateRange($dateFrom, $dateTo, $numWorkers);
$workers = [];
$db->close();

for ($i = 0; $i < $numWorkers; $i++) {
    [$wFrom, $wTo] = $dateWindows[$i];
    $log->info("[WORKER {$i}] Date window: {$wFrom} to {$wTo}");

    $pid = pcntl_fork();

    if ($pid === -1) {
        $log->error("[CONCURRENCY] Failed to fork worker {$i}");
    } elseif ($pid === 0) {
        // ── CHILD PROCESS WORKER ──
        $childClient = new SatuSehatClient($config, $log);
        try {
            $childDb = new SatuSehatDatabase($config, $log, $childClient);
        } catch (\Throwable $e) {
            $log->error("[WORKER {$i}] Failed to connect to database: " . $e->getMessage());
            exit(1);
        }

        $processor = new SatuSehatEncounterProcessor($childDb, $childClient, $config, $log);
        $workerFail = 0;

        // Arrived
        $cursor = new SatuSehatBatchCursor($childDb, 'fetchPendingArrived', [$wFrom, $wTo], $batchSize, $log, "W{$i}/arrived");
        foreach ($cursor->batches() as $batch) {
            $s = $processor->run($batch, [], []);
            $workerFail += $s['fail'];
            $cursor->tick();
        }

        // In-Progress
        $cursor = new SatuSehatBatchCursor($childDb, 'fetchPendingInProgress', [$wFrom, $wTo], $batchSize, $log, "W{$i}/prog");
        foreach ($cursor->batches() as $batch) {
            $s = $processor->run([], $batch, []);
            $workerFail += $s['fail'];
            $cursor->tick();
        }

        // Finished
        $cursor = new SatuSehatBatchCursor($childDb, 'fetchPendingFinished', [$wFrom, $wTo], $batchSize, $log, "W{$i}/fin");
        foreach ($cursor->batches() as $batch) {
            $s = $processor->run([], [], $batch);
            $workerFail += $s['fail'];
            $cursor->tick();
        }

        $childDb->close();
        exit($workerFail > 0 ? 2 : 0);
    } else {
        // ── PARENT PROCESS ──
        $workers[] = $pid;
    }
}

// Wait for all workers to finish
$supervisor = new SatuSehatSupervisor($log);
$allSuccess = $supervisor->monitor($workers);

$elapsed = round(microtime(true) - $startTime, 2);
$log->info("══════════════════════════════════════════════════════════════");
$log->info("[SUMMARY] Parallel Sync Finished. All Workers Completed.");
$log->info("[SUMMARY] Elapsed: {$elapsed}s");
$log->info("[DONE] Finished at " . date('Y-m-d H:i:s T'));
$log->info("══════════════════════════════════════════════════════════════");
exit($allSuccess ? 0 : 2);
