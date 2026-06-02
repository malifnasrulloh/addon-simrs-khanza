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

try {
    $config = new SatuSehatConfig(BASE_DIR . '/.env');
    $log = new Logger($config->logDir, 'satusehat_patient_sync', $config->logLevel, true);
    $client = new SatuSehatClient($config, $log);
    $db = new SatuSehatDatabase($config, $log, $client);
    $pdo = $db->getMysql();
} catch (Exception $e) {
    fwrite(STDERR, "[FATAL] Initialization failed: {$e->getMessage()}\n");
    exit(1);
}

$log->info("==================================================================");
$log->info(" Starting Bulk Patient IHS Synchronization");
$log->info("==================================================================");

// Run Diagnostics
$db->printSyncDiagnostics('patient', '', '');

// Fetch patients with a valid NIK (16 digits) but no IHS number yet
$stmt = $pdo->prepare("
    SELECT p.no_ktp as nik, p.nm_pasien, p.no_rkm_medis 
    FROM pasien p 
    LEFT JOIN satu_sehat_ihs_patient i ON p.no_ktp = i.nikpasien 
    WHERE i.ihspasien IS NULL 
      AND p.no_ktp REGEXP '^[0-9]{16}$'
");
$stmt->execute();
$patients = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($patients)) {
    $log->info("No pending patients found for synchronization.");
    exit(0);
}

$log->info("Found " . count($patients) . " patients to synchronize. Processing...");

$successCount = 0;
$failCount = 0;

foreach ($patients as $idx => $patient) {
    $nik = $patient['nik'];
    $rm = $patient['no_rkm_medis'];
    $name = $patient['nm_pasien'];
    
    $log->info(sprintf("[%03d/%03d] Fetching IHS for RM: %s | NIK: %s | Name: %s", $idx + 1, count($patients), $rm, $nik, $name));
    
    try {
        $endpoint = "/Patient?identifier=https://fhir.kemkes.go.id/id/nik|{$nik}";
        $res = $client->get($endpoint);
        
        if (!empty($res['entry'])) {
            $resource = $res['entry'][0]['resource'];
            $ihsNumber = $resource['id'];
            
            $insertStmt = $pdo->prepare("REPLACE INTO satu_sehat_ihs_patient (nikpasien, ihspasien) VALUES (:nik, :ihs)");
            $insertStmt->execute(['nik' => $nik, 'ihs' => $ihsNumber]);
            
            $log->info("  -> Success: Mapped IHS {$ihsNumber}");
            $successCount++;
        } else {
            $log->info("  -> Failed: Not found in Satu Sehat.");
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

$log->info("==================================================================");
$log->info(" Synchronization Completed.");
$log->info(" Success: {$successCount} | Failed/Not Found: {$failCount}");
$log->info("==================================================================");
