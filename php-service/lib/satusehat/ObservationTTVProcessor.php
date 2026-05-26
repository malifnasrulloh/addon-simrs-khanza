<?php

/**
 * ObservationTTVProcessor - Orchestrator for Satu Sehat Observation TTV sync.
 *
 * Runs through a dictionary of vital signs and pushes pending observations to the API.
 *
 * @author malifnasrulloh (converted from Java by Antigravity)
 */

declare(strict_types=1);

require_once __DIR__ . '/ObservationTTVDictionary.php';

class SatuSehatObservationTTVProcessor
{
    private SatuSehatDatabase $db;
    private SatuSehatClient $api;
    private SatuSehatConfig $config;
    private Logger $log;

    private int $successCount = 0;
    private int $failCount    = 0;
    private int $skipCount    = 0;

    public function __construct(SatuSehatDatabase $db, SatuSehatClient $api, SatuSehatConfig $config, Logger $log)
    {
        $this->db     = $db;
        $this->api    = $api;
        $this->config = $config;
        $this->log    = $log;
    }

    public function run(?array $preFetchedObservations = null): array
    {
        $this->successCount = 0;
        $this->failCount    = 0;
        $this->skipCount    = 0;

        if ($this->config->lookbackDays > 0) {
            $dateTo = date('Y-m-d', strtotime('-1 day'));
            $dateFrom = date('Y-m-d', strtotime('-' . $this->config->lookbackDays . ' days', strtotime(date('Y-m-d'))));
            $this->log->info("  Date Range: {$dateFrom} to {$dateTo} (Lookback: {$this->config->lookbackDays} days)");
        } else {
            $dateFrom = $this->config->dateFrom;
            $dateTo = $this->config->dateTo;
            $this->log->info("  Date Range: {$dateFrom} to {$dateTo} (Configured)");
        }

        $definitions = ObservationTTVDictionary::getDefinitions();

        foreach ($definitions as $ttvType => $def) {
            $this->log->info("──────────────────────────────────────────────────────────────");
            $this->log->info("[SYNC] Processing Observation: " . strtoupper($ttvType));
            $records = $preFetchedObservations[$ttvType] ?? null;
            $this->processObservation($ttvType, $def, $dateFrom, $dateTo, $records);
        }

        return [
            'success' => $this->successCount,
            'fail'    => $this->failCount,
            'skip'    => $this->skipCount,
        ];
    }

    private function processObservation(string $ttvTypeKey, array $def, string $dateFrom, string $dateTo, ?array $patients = null): void
    {
        if ($patients === null) {
            $patients = $this->db->fetchPendingObservations($ttvTypeKey, $def, $dateFrom, $dateTo);
        }
        
        if (empty($patients)) {
            $this->log->info("  No pending {$ttvTypeKey} records.");
            return;
        }

        $this->log->info("  Found " . count($patients) . " pending {$ttvTypeKey} record(s).");

        foreach ($patients as $p) {
            $noRawat = $p['no_rawat'];
            $tglObs  = $p['tgl_observasi'];
            $jamObs  = $p['jam_observasi'];

            $localState = $this->db->getObservationLocalState($ttvTypeKey, $noRawat, $tglObs, $jamObs);
            if ($localState === 'sent') {
                $this->skipCount++;
                continue;
            }

            $nik = $p['no_ktp'];
            $nikDokter = $p['ktpdokter'];

            $idPasien = $this->db->getIhsPatient($nik);
            $idDokter = $this->db->getIhsPractitioner($nikDokter);

            if (!$idPasien || !$idDokter) {
                $this->log->warning("  [SKIP] {$noRawat}: Missing IHS ID for Patient or Doctor.");
                $this->skipCount++;
                continue;
            }

            $payload = SatuSehatPayloadBuilder::observationTTV($p, $idPasien, $idDokter, $def);

            $this->log->info("  [POST] {$noRawat} / {$ttvTypeKey} = {$p['value']}");
            $result = $this->api->post('/Observation', $payload);

            if ($result['success'] && isset($result['data']['id'])) {
                $idObservation = $result['data']['id'];
                
                // Save to DB
                $this->db->saveObservationTTV(
                    $def['state_table'], 
                    $def['state_id_col'] ?? 'id_observation', 
                    $noRawat, 
                    $tglObs, 
                    $jamObs, 
                    $p['status_lanjut'], 
                    $idObservation
                );

                // Update Tracker
                $this->db->updateObservationLocalState($ttvTypeKey, $noRawat, $tglObs, $jamObs, 'sent');
                $this->log->info("    ✓ Created {$idObservation}");
                $this->successCount++;
            } else {
                $errorMessage = $result['data']['issue'][0]['diagnostics'] ?? $result['message'];
                
                // Duplicate handling for Observation
                if (stripos($errorMessage, 'duplicate') !== false || $result['code'] === 409 || $result['code'] === 400) {
                    $this->log->warning("    ! Duplicated. Attempting to recover...");
                    $idObservation = $this->resolveDuplicateObservation($idPasien, $p['id_encounter'], $def['code']);

                    if ($idObservation) {
                        $this->db->saveObservationTTV(
                            $def['state_table'], 
                            $def['state_id_col'] ?? 'id_observation', 
                            $noRawat, 
                            $tglObs, 
                            $jamObs, 
                            $p['status_lanjut'], 
                            $idObservation
                        );
                        $this->db->updateObservationLocalState($ttvTypeKey, $noRawat, $tglObs, $jamObs, 'sent');
                        $this->log->info("    ✓ Recovered {$idObservation} from Server");
                        $this->successCount++;
                    } else {
                        $this->log->error("    ✗ Failed to recover duplicate.");
                        $this->failCount++;
                    }
                } else {
                    $this->log->warning("    ✗ Failed -> " . $errorMessage);
                    $this->failCount++;
                }
            }
        }
    }

    /**
     * Resolves a duplicate Observation by searching the Satu Sehat API.
     */
    private function resolveDuplicateObservation(string $idPasien, string $idEncounter, string $loincCode): ?string
    {
        $endpoint = "/Observation?patient={$idPasien}&encounter={$idEncounter}&code={$loincCode}";
        $result = $this->api->get($endpoint);

        if (!$result['success'] || empty($result['data']['entry'])) {
            return null;
        }

        foreach ($result['data']['entry'] as $entry) {
            $res = $entry['resource'] ?? [];
            return $res['id'] ?? null; // Returns the first matching observation
        }

        return null;
    }
}