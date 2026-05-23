#!/usr/bin/env php
<?php
/**
 * SIMRS Khanza - Satu Sehat Medication Sync Service
 *
 * CLI service to synchronize drug/medication master inventory mappings with Satu Sehat API.
 * Automatically POSTs new medications and PUTs updates. Seamlessly handles duplicates.
 * 
 * Designed to run as a cron job.
 *
 * Usage:
 *   php satusehat_medication_sync.php                  # Normal run
 *   php satusehat_medication_sync.php --verbose        # Extra debug output
 *   php satusehat_medication_sync.php --help           # Show help
 *
 * Cron example (every hour):
 *   0 * * * * cd /path/to/php-service && php satusehat_medication_sync.php >> /dev/null 2>&1
 *
 * @author malifnasrulloh (converted from Java by Antigravity)
 * @version 1.0.0
 */

declare(strict_types=1);

define('SERVICE_NAME', 'KhanzaSatuSehatMedicationSync');
define('SERVICE_VERSION', '1.0.0');
define('BASE_DIR', __DIR__);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

$options = getopt('', ['help', 'verbose']);

if (isset($options['help'])) {
    $ver = SERVICE_VERSION;
    echo <<<HELP
    ╔══════════════════════════════════════════════════════════════╗
    ║  SIMRS Khanza - Satu Sehat Medication Sync Service           ║
    ║  Version {$ver}                                              ║
    ╚══════════════════════════════════════════════════════════════╝

    Usage:
      php satusehat_medication_sync.php [options]

    Options:
      --help       Show this help message
      --verbose    Enable debug-level logging to terminal

    Environment:
      Configure SATUSEHAT_* variables in .env.

    Cron (every hour):
      0 * * * * cd /path/to/php-service && php satusehat_medication_sync.php

    HELP;
    exit(0);
}

$isVerbose = isset($options['verbose']);

require_once BASE_DIR . '/lib/Logger.php';
require_once BASE_DIR . '/lib/satusehat/Config.php';

try {
    $config = new SatuSehatConfig(BASE_DIR . '/.env');
} catch (\RuntimeException $e) {
    fwrite(STDERR, "[FATAL] Configuration error: {$e->getMessage()}\n");
    exit(1);
}

$logLevel = $isVerbose ? 'DEBUG' : $config->logLevel;
$log = new Logger($config->logDir, 'satusehat', $logLevel, $isVerbose);
$log->cleanOldLogs($config->logRetentionDays);

$log->info("══════════════════════════════════════════════════════════════");
$log->info("  SIMRS Khanza - Satu Sehat Medication Sync Service");
$log->info("  Version: " . SERVICE_VERSION . " | PHP " . PHP_VERSION);
$log->info("  Timestamp: " . date('Y-m-d H:i:s T'));
$log->info("  API Base: " . $config->baseUrl);
$log->info("══════════════════════════════════════════════════════════════");

require_once BASE_DIR . '/lib/satusehat/SatuSehatClient.php';
require_once BASE_DIR . '/lib/satusehat/Database.php';
require_once BASE_DIR . '/lib/satusehat/PayloadBuilder.php';
require_once BASE_DIR . '/lib/satusehat/MedicationProcessor.php';

$client = new SatuSehatClient($config, $log);

try {
    $db = new SatuSehatDatabase($config, $log, $client);
} catch (\PDOException $e) {
    $log->error("[DB] Connection failed: " . $e->getMessage());
    exit(1);
}

$processor = new SatuSehatMedicationProcessor($db, $client, $config, $log);

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

$log->info("══════════════════════════════════════════════════════════════");
$log->info("[SUMMARY] Success: {$stats['success']} | Failed: {$stats['fail']} | Skipped: {$stats['skip']}");
$log->info("[SUMMARY] Elapsed: {$elapsed}s");
$log->info("[DONE] Finished at " . date('Y-m-d H:i:s T'));
$log->info("══════════════════════════════════════════════════════════════");

$db->close();
exit($stats['fail'] > 0 ? 2 : 0);
