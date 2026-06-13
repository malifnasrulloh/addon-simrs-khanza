#!/usr/bin/env php
<?php
/**
 * SIMRS Khanza - Satu Sehat ImagingStudy Webhook Self-Healing Service
 *
 * CLI service to scan and self-heal failed or pending webhook records
 * by querying Satu Sehat directly by accession number (ACSN) identifier.
 *
 * Usage:
 *   php satusehat_imagingstudy_selfhealing.php                  # Heal all unsynced records
 *   php satusehat_imagingstudy_selfhealing.php --date-from=2026-06-01 --date-to=2026-06-15
 *   php satusehat_imagingstudy_selfhealing.php --verbose        # Extra debug output
 *   php satusehat_imagingstudy_selfhealing.php --help           # Show help
 *
 * @author malifnasrulloh (converted from Java by Antigravity)
 * @version 1.0.0
 */

declare(strict_types=1);

define('SERVICE_NAME', 'KhanzaSatuSehatImagingStudySelfHealing');
define('SERVICE_VERSION', '1.0.0');
define('BASE_DIR', __DIR__);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

$options = getopt('', ['help', 'verbose', 'date-from::', 'date-to::']);

if (isset($options['help'])) {
    $ver = SERVICE_VERSION;
    echo <<<HELP
    ╔══════════════════════════════════════════════════════════════╗
    ║  SIMRS Khanza - Satu Sehat ImagingStudy Self-Healing Service ║
    ║  Version {$ver}                                              ║
    ╚══════════════════════════════════════════════════════════════╝

    Usage:
      php satusehat_imagingstudy_selfhealing.php [options]

    Options:
      --help                Show this help message
      --verbose             Enable debug-level logging to terminal
      --date-from[=YYYY-MM-DD] Limit healing to records requested starting this date
      --date-to[=YYYY-MM-DD]   Limit healing to records requested ending this date

    Environment:
      Configure SATUSEHAT_* variables in .env.

    HELP;
    exit(0);
}

$isVerbose = isset($options['verbose']);
$dateFrom = $options['date-from'] ?? null;
$dateTo = $options['date-to'] ?? null;

// Allow empty string to mean null
if ($dateFrom === '') $dateFrom = null;
if ($dateTo === '') $dateTo = null;

require_once BASE_DIR . '/lib/Logger.php';
require_once BASE_DIR . '/lib/satusehat/Config.php';

try {
    $config = new SatuSehatConfig(BASE_DIR . '/.env');
} catch (\RuntimeException $e) {
    fwrite(STDERR, "[FATAL] Configuration error: {$e->getMessage()}\n");
    exit(1);
}

$logLevel = $isVerbose ? 'DEBUG' : $config->logLevel;
$log = new Logger($config->logDir, 'satusehat_imagingstudy_selfhealing', $logLevel, $isVerbose);
$log->cleanOldLogs($config->logRetentionDays);

$log->info("══════════════════════════════════════════════════════════════");
$log->info("  SIMRS Khanza - Satu Sehat ImagingStudy Self-Healing");
$log->info("  Version: " . SERVICE_VERSION . " | PHP " . PHP_VERSION);
$log->info("  Timestamp: " . date('Y-m-d H:i:s T'));
$log->info("  API Base: " . $config->baseUrl);
if ($dateFrom || $dateTo) {
    $log->info("  Date Range: " . ($dateFrom ?? 'any') . " to " . ($dateTo ?? 'any'));
}
$log->info("══════════════════════════════════════════════════════════════");

require_once BASE_DIR . '/lib/satusehat/SatuSehatClient.php';
require_once BASE_DIR . '/lib/satusehat/Database.php';

$client = new SatuSehatClient($config, $log);

try {
    $db = new SatuSehatDatabase($config, $log, $client);
} catch (\PDOException $e) {
    $log->error("[DB] Connection failed: " . $e->getMessage());
    exit(1);
}

$startTime = microtime(true);

try {
    $healed = $db->healFailedImagingStudies($dateFrom, $dateTo);
} catch (\Throwable $e) {
    $log->error("[FATAL] Unhandled exception during healing: " . $e->getMessage());
    $log->error("[FATAL] Stack trace: " . $e->getTraceAsString());
    $db->close();
    exit(1);
}

$elapsed = round(microtime(true) - $startTime, 2);
$log->info("══════════════════════════════════════════════════════════════");
$log->info("[SUMMARY] Self-healing completed in {$elapsed}s. Healed {$healed} records.");
$log->info("[DONE] Finished at " . date('Y-m-d H:i:s T'));
$log->info("══════════════════════════════════════════════════════════════");

$db->close();
exit(0);
