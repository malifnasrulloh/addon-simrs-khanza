#!/usr/bin/env php
<?php
/**
 * SIMRS Khanza - Satu Sehat Patient Sync Service
 * Background Cron Job to synchronize missing Patient IHS Numbers in bulk.
 */

declare(strict_types=1);

define('SERVICE_NAME', 'KhanzaSatuSehatPatientSync');
define('BASE_DIR', __DIR__);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

require_once BASE_DIR . '/lib/Logger.php';
require_once BASE_DIR . '/lib/satusehat/Config.php';
require_once BASE_DIR . '/lib/satusehat/SatuSehatClient.php';
require_once BASE_DIR . '/lib/satusehat/Database.php';
require_once BASE_DIR . '/lib/satusehat/BatchCursor.php';

try {
    $config = new SatuSehatConfig(BASE_DIR . '/.env');
    $log = new Logger($config->logDir, 'satusehat_patient_sync', $config->logLevel, true);
    $client = new SatuSehatClient($config, $log);
    $db = new SatuSehatDatabase($config, $log, $client);
} catch (Exception $e) {
    fwrite(STDERR, "[FATAL] Initialization failed: {$e->getMessage()}\n");
    exit(1);
}

$log->info("==================================================================");
$log->info(" Starting Bulk Patient IHS Synchronization");
$log->info("==================================================================");

// Run Diagnostics
$db->printSyncDiagnostics('patient', '', '');

$batchSize = $config->batchSize;
$successCount = 0;
$failCount = 0;
$totalCount = 0;

// Fetch and process patients in batches using cursor
$cursor = new SatuSehatBatchCursor($db, 'fetchPendingPatients', [], $batchSize, $log, 'patient');

foreach ($cursor->batches() as $batch) {
    $batchTotal = count($batch);

    foreach ($batch as $idx => $patient) {
        $globalIdx = $totalCount + $idx + 1;
        $nik = $patient['nik'];
        $rm = $patient['no_rkm_medis'];
        $name = $patient['nm_pasien'];

        $log->info(sprintf("[%03d/%03d] Fetching IHS for RM: %s | NIK: %s | Name: %s", $globalIdx, $totalCount + $batchTotal, $rm, $nik, $name));

        try {
            $ihsNumber = $db->getIhsPatient($nik);
            if ($ihsNumber) {
                $log->info("  -> Success: Mapped IHS {$ihsNumber}");
                $successCount++;
            } else {
                $log->warning("  -> Failed or skipped.");
                $failCount++;
            }

            // Sleep to avoid hammering the API rate limits (e.g., 200ms)
            usleep(200000);
        } catch (Exception $e) {
            $log->error("  -> API Error: " . $e->getMessage());
            $failCount++;
            usleep(1000000); // Backoff on error
        }
    }

    $totalCount += $batchTotal;
    $cursor->tick();
}

$log->info("==================================================================");
$log->info(" Synchronization Completed.");
$log->info(" Total: {$totalCount} | Success: {$successCount} | Failed/Not Found: {$failCount}");
$log->info("==================================================================");
exit($failCount > 0 ? 2 : 0);
