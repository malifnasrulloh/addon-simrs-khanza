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

// 1. Fetch pending records
$arrivedRecords = $db->fetchPendingArrived($dateFrom, $dateTo);
$inProgressRecords = $db->fetchPendingInProgress($dateFrom, $dateTo);
$finishedRecords = $db->fetchPendingFinished($dateFrom, $dateTo);

$totalArrived = count($arrivedRecords);
$totalInProgress = count($inProgressRecords);
$totalFinished = count($finishedRecords);
$totalPending = $totalArrived + $totalInProgress + $totalFinished;

if ($totalPending === 0) {
    $log->info("No pending Encounter records found.");
    $log->info("══════════════════════════════════════════════════════════════");
    $db->close();
    exit(0);
}

$log->info("[INIT] Found {$totalArrived} arrived, {$totalInProgress} in-progress, and {$totalFinished} finished encounters.");

// 2. Execution path: Parallel workers vs Single process
if ($isParallel && $totalPending > 1) {
    $log->info("[CONCURRENCY] Splitting work among {$numWorkers} parallel workers...");

    // Split arrays into chunks safely
    $arrivedChunks = $totalArrived > 0 ? (array_chunk($arrivedRecords, (int) ceil($totalArrived / $numWorkers)) ?: []) : [];
    $inProgressChunks = $totalInProgress > 0 ? (array_chunk($inProgressRecords, (int) ceil($totalInProgress / $numWorkers)) ?: []) : [];
    $finishedChunks = $totalFinished > 0 ? (array_chunk($finishedRecords, (int) ceil($totalFinished / $numWorkers)) ?: []) : [];

    $workers = [];
    $db->close(); // Close DB handle before fork to avoid shared-connection issues!

    for ($i = 0; $i < $numWorkers; $i++) {
        $arrChunk = $arrivedChunks[$i] ?? [];
        $progChunk = $inProgressChunks[$i] ?? [];
        $finChunk = $finishedChunks[$i] ?? [];

        if (empty($arrChunk) && empty($progChunk) && empty($finChunk)) {
            continue;
        }

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
            $stats = $processor->run($arrChunk, $progChunk, $finChunk);

            $childDb->close();
            exit($stats['fail'] > 0 ? 2 : 0);
        } else {
            // ── PARENT PROCESS ──
            $workers[] = $pid;
        }
    }

    // Wait for all workers to finish
    $allSuccess = true;
    foreach ($workers as $pid) {
        pcntl_waitpid($pid, $status);
        if (pcntl_wifexited($status)) {
            $exitCode = pcntl_wexitstatus($status);
            if ($exitCode !== 0) {
                $allSuccess = false;
            }
        } else {
            $allSuccess = false;
        }
    }

    $elapsed = round(microtime(true) - $startTime, 2);
    $log->info("══════════════════════════════════════════════════════════════");
    $log->info("[SUMMARY] Parallel Sync Finished. All Workers Completed.");
    $log->info("[SUMMARY] Elapsed: {$elapsed}s");
    $log->info("[DONE] Finished at " . date('Y-m-d H:i:s T'));
    $log->info("══════════════════════════════════════════════════════════════");
    exit($allSuccess ? 0 : 2);

} else {
    // ── SINGLE PROCESS FALLBACK ──
    $processor = new SatuSehatEncounterProcessor($db, $client, $config, $log);
    try {
        $stats = $processor->run($arrivedRecords, $inProgressRecords, $finishedRecords);
    } catch (\Throwable $e) {
        $log->error("[FATAL] Unhandled exception: " . $e->getMessage());
        $log->error("[FATAL] Stack trace: " . $e->getTraceAsString());
        $db->close();
        exit(1);
    }

    $elapsed = round(microtime(true) - $startTime, 2);
    $log->info("══════════════════════════════════════════════════════════════");
    $log->info("[SUMMARY] Success: {$stats['success']} | Failed: {$stats['fail']} | Skipped: {$stats['skip']}");
    $log->info("[SUMMARY] Elapsed: {$elapsed}s");
    $log->info("[DONE] Finished at " . date('Y-m-d H:i:s T'));
    $log->info("══════════════════════════════════════════════════════════════");

    $db->close();
    exit($stats['fail'] > 0 ? 2 : 0);
}
