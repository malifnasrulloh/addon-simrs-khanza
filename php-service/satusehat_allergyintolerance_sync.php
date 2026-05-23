#!/usr/bin/env php
<?php
/**
 * SIMRS Khanza - Satu Sehat Allergy Intolerance Sync Service
 *
 * CLI service to synchronize patient Allergy data with BPJS Satu Sehat API.
 * Automatically POSTs new allergies and PUTs updates. Seamlessly handles duplicates
 * and maintains a self-learning local dictionary in cache/alergisatusehat.iyem.
 * 
 * Designed to run as a cron job.
 *
 * Usage:
 *   php satusehat_allergyintolerance_sync.php                  # Normal run
 *   php satusehat_allergyintolerance_sync.php --verbose        # Extra debug output
 *   php satusehat_allergyintolerance_sync.php --help           # Show help
 *
 * Cron example (every 5 minutes):
 *   * /5 * * * * cd /path/to/php-service && php satusehat_allergyintolerance_sync.php >> /dev/null 2>&1
 *
 * @author malifnasrulloh (converted from Java by Antigravity)
 * @version 1.0.0
 */

declare(strict_types=1);

define('SERVICE_NAME', 'KhanzaSatuSehatAllergyIntoleranceSync');
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
    ║  SIMRS Khanza - Satu Sehat Allergy Intolerance Sync Service  ║
    ║  Version {$ver}                                              ║
    ╚══════════════════════════════════════════════════════════════╝

    Usage:
      php satusehat_allergyintolerance_sync.php [options]

    Options:
      --help       Show this help message
      --verbose    Enable debug-level logging to terminal

    Environment:
      Configure SATUSEHAT_* variables in .env.

    Cron (every 5 minutes):
      */5 * * * * cd /path/to/php-service && php satusehat_allergyintolerance_sync.php

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
$log->info("  SIMRS Khanza - Satu Sehat AllergyIntolerance Sync Service");
$log->info("  Version: " . SERVICE_VERSION . " | PHP " . PHP_VERSION);
$log->info("  Timestamp: " . date('Y-m-d H:i:s T'));
$log->info("  API Base: " . $config->baseUrl);
$log->info("══════════════════════════════════════════════════════════════");

require_once BASE_DIR . '/lib/satusehat/SatuSehatClient.php';
require_once BASE_DIR . '/lib/satusehat/Database.php';
require_once BASE_DIR . '/lib/satusehat/AllergyDictionary.php';
require_once BASE_DIR . '/lib/satusehat/PayloadBuilder.php';
require_once BASE_DIR . '/lib/satusehat/AllergyIntoleranceProcessor.php';

$client = new SatuSehatClient($config, $log);

try {
    $db = new SatuSehatDatabase($config, $log, $client);
} catch (\PDOException $e) {
    $log->error("[DB] Connection failed: " . $e->getMessage());
    exit(1);
}

// Ensure the cache directory exists
$cacheDir = BASE_DIR . '/cache';
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0777, true);
}
$dictionary = new SatuSehatAllergyDictionary($cacheDir . '/alergisatusehat.iyem', $log);

$processor = new SatuSehatAllergyIntoleranceProcessor($db, $client, $config, $log, $dictionary);

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
